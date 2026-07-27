<?php
declare(strict_types=1);

namespace Compta\Modules\Consolidation;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Devises\ExchangeRateService;
use Compta\Modules\Dossiers\OrganisationRegistryService;
use DateTimeImmutable;
use PDO;
use Throwable;

final class ConsolidationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return list<int> */
    public function groupIdsForScope(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT g.id
             FROM groupes_consolidation g
             LEFT JOIN membres_groupe_consolidation m ON m.groupe_id = g.id
             WHERE (
               (g.organisation_pilote_id = ? AND g.dossier_pilote_id = ?)
               OR (m.organisation_id = ? AND m.dossier_id = ?)
             )
             ORDER BY g.id'
        );
        $stmt->execute([
            $organisationId, $dossierId, $organisationId, $dossierId,
        ]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array{organisation_id:int,dossier_id:int}> */
    public function groupScopes(int $groupId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT organisation_id, dossier_id
             FROM membres_groupe_consolidation
             WHERE groupe_id = ? ORDER BY id'
        );
        $stmt->execute([$groupId]);
        $scopes = array_map(static fn (array $row): array => [
            'organisation_id' => (int) $row['organisation_id'],
            'dossier_id' => (int) $row['dossier_id'],
        ], $stmt->fetchAll());
        if ($scopes === []) {
            throw new ConsolidationException('Groupe de consolidation introuvable.');
        }
        return $scopes;
    }

    public function createGroup(
        int $organisationId,
        int $dossierId,
        string $code,
        string $label,
        string $currency,
        string $validFrom,
        int $actorId,
        string $mode = 'consolidation_legale',
    ): int {
        $code = mb_strtoupper(trim($code));
        $label = trim($label);
        $currency = mb_strtoupper(trim($currency));
        $mode = trim($mode);
        $this->date($validFrom);
        if (
            preg_match('/^[A-Z0-9_-]{1,30}$/', $code) !== 1
            || $label === ''
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1
            || !in_array(
                $mode,
                ['agregation_interne', 'consolidation_legale'],
                true
            )
        ) {
            throw new ConsolidationException(
                'Mode, code, libellé ou devise du groupe invalide.'
            );
        }
        return $this->transaction(function () use (
            $organisationId, $dossierId, $code, $label, $currency,
            $mode, $validFrom, $actorId
        ): int {
            $scope = $this->pdo->prepare(
                'SELECT 1 FROM dossiers WHERE id = ? AND organisation_id = ?'
            );
            $scope->execute([$dossierId, $organisationId]);
            if ($scope->fetchColumn() === false) {
                throw new ConsolidationException('Dossier pilote introuvable.');
            }
            $this->pdo->prepare(
                'INSERT INTO groupes_consolidation
                 (organisation_pilote_id, dossier_pilote_id, code, libelle,
                  devise, mode, statut, actif, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, \'brouillon\', 0, ?)'
            )->execute([
                $organisationId, $dossierId, $code, $label, $currency,
                $mode, $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare(
                'INSERT INTO membres_groupe_consolidation
                 (groupe_id, organisation_id, dossier_id, date_debut, cree_par)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$id, $organisationId, $dossierId, $validFrom, $actorId]);
            $this->audit->log(
                'consolidation.groupe_cree',
                $actorId,
                $organisationId,
                $dossierId,
                'groupe_consolidation',
                (string) $id,
                [
                    'code' => $code,
                    'devise' => $currency,
                    'mode' => $mode,
                    'statut' => 'brouillon',
                ]
            );
            return $id;
        });
    }

    public function addMember(
        int $groupId,
        int $organisationId,
        int $dossierId,
        string $validFrom,
        ?string $validUntil,
        int $actorId,
    ): int {
        $this->date($validFrom);
        if ($validUntil !== null) {
            $this->date($validUntil);
        }
        if ($validUntil !== null && $validUntil < $validFrom) {
            throw new ConsolidationException('Période d’appartenance invalide.');
        }
        $group = $this->group($groupId);
        $this->assertGroupWritable($group);
        $scope = $this->pdo->prepare(
            'SELECT 1 FROM dossiers d
             JOIN organisations o ON o.id = d.organisation_id
             WHERE d.id = ? AND d.organisation_id = ?
               AND d.actif = 1 AND o.actif = 1'
        );
        $scope->execute([$dossierId, $organisationId]);
        if ($scope->fetchColumn() === false) {
            throw new ConsolidationException('Dossier membre introuvable ou archivé.');
        }
        if (
            (string) $group['mode'] === 'agregation_interne'
            && (int) $group['organisation_pilote_id'] !== $organisationId
        ) {
            throw new ConsolidationException(
                'Une agrégation interne accepte uniquement les dossiers de son organisation pilote.'
            );
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO membres_groupe_consolidation
             (groupe_id, organisation_id, dossier_id, date_debut, date_fin, cree_par)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $groupId, $organisationId, $dossierId, $validFrom,
            $validUntil, $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'consolidation.membre_ajoute',
            $actorId,
            (int) $group['organisation_pilote_id'],
            (int) $group['dossier_pilote_id'],
            'membre_consolidation',
            (string) $id,
            [
                'organisation_id' => $organisationId,
                'dossier_id' => $dossierId,
            ]
        );
        return $id;
    }

    public function updateGroup(
        int $groupId,
        string $label,
        string $currency,
        string $mode,
        int $version,
        int $actorId,
    ): void {
        $label = trim($label);
        $currency = mb_strtoupper(trim($currency));
        if (
            $label === ''
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1
            || !in_array(
                $mode,
                ['agregation_interne', 'consolidation_legale'],
                true
            )
        ) {
            throw new ConsolidationException('Paramètres du groupe invalides.');
        }
        $group = $this->group($groupId);
        $this->assertGroupWritable($group);
        $hasPeriod = $this->hasPeriods($groupId);
        if (
            $hasPeriod
            && (
                $currency !== (string) $group['devise']
                || $mode !== (string) $group['mode']
            )
        ) {
            throw new ConsolidationException(
                'Le mode et la devise sont figés après la première période.'
            );
        }
        if ($mode === 'agregation_interne') {
            $this->assertInternalMembers($groupId, (int) $group['organisation_pilote_id']);
        }
        $stmt = $this->pdo->prepare(
            'UPDATE groupes_consolidation
             SET libelle = ?, devise = ?, mode = ?,
                 modifie_le = datetime(\'now\'), modifie_par = ?,
                 version = version + 1
             WHERE id = ? AND version = ?'
        );
        $stmt->execute([$label, $currency, $mode, $actorId, $groupId, $version]);
        if ($stmt->rowCount() !== 1) {
            throw new ConsolidationException(
                'Le groupe a été modifié par un autre utilisateur.'
            );
        }
        $this->auditGroup(
            'consolidation.groupe_modifie',
            $actorId,
            $group,
            $groupId,
            [
                'before' => [
                    'label' => (string) $group['libelle'],
                    'currency' => (string) $group['devise'],
                    'mode' => (string) $group['mode'],
                    'version' => (int) $group['version'],
                ],
                'after' => [
                    'label' => $label,
                    'currency' => $currency,
                    'mode' => $mode,
                    'version' => $version + 1,
                ],
            ]
        );
    }

    /** @return array<string,mixed> */
    public function activationPreview(int $groupId): array
    {
        $group = $this->group($groupId);
        $periods = $this->periods($groupId);
        $latest = $periods[0] ?? null;
        $issues = [];
        if (count($this->members($groupId)) < 2) {
            $issues[] = 'Deux dossiers membres au minimum sont requis.';
        }
        try {
            $this->assertModeComposition(
                $groupId,
                (string) $group['mode'],
                (int) $group['organisation_pilote_id']
            );
        } catch (ConsolidationException $exception) {
            $issues[] = $exception->getMessage();
        }
        if ($latest === null) {
            $issues[] = 'Une période avec ses ratios est requise.';
        }
        $balance = null;
        if ($latest !== null) {
            $balance = $this->balance($groupId, (int) $latest['id']);
            if ($balance['unmapped_accounts'] !== []) {
                $issues[] = 'Tous les comptes mouvementés doivent être mappés.';
            }
            if (!$balance['formula_verified']) {
                $issues[] = 'La formule de réconciliation présente un écart.';
            }
        }
        return [
            'group_id' => $groupId,
            'mode' => (string) $group['mode'],
            'status' => (string) $group['statut'],
            'member_count' => count($this->members($groupId)),
            'period_id' => $latest === null ? null : (int) $latest['id'],
            'formula' => 'balances sources converties + éliminations = résultat du groupe',
            'formula_verified' => $balance['formula_verified'] ?? false,
            'source_total_cents' => $balance['source_total_cents'] ?? 0,
            'elimination_total_cents' => $balance['elimination_total_cents'] ?? 0,
            'result_total_cents' => $balance['consolidated_total_cents'] ?? 0,
            'issues' => $issues,
            'ready' => $issues === [],
        ];
    }

    public function activateGroup(
        int $groupId,
        int $version,
        int $actorId,
    ): void {
        $group = $this->group($groupId);
        if ((string) $group['statut'] !== 'brouillon') {
            throw new ConsolidationException(
                'Seul un groupe en brouillon peut être activé.'
            );
        }
        $preview = $this->activationPreview($groupId);
        if (!$preview['ready']) {
            throw new ConsolidationException(
                'Activation impossible : ' . implode(' ', $preview['issues'])
            );
        }
        $stmt = $this->pdo->prepare(
            'UPDATE groupes_consolidation
             SET statut = \'actif\', actif = 1,
                 modifie_le = datetime(\'now\'), modifie_par = ?,
                 version = version + 1
             WHERE id = ? AND version = ? AND statut = \'brouillon\''
        );
        $stmt->execute([$actorId, $groupId, $version]);
        if ($stmt->rowCount() !== 1) {
            throw new ConsolidationException(
                'Le groupe a été modifié par un autre utilisateur.'
            );
        }
        $this->auditGroup(
            'consolidation.groupe_active',
            $actorId,
            $group,
            $groupId,
            $preview
        );
    }

    public function archiveGroup(
        int $groupId,
        int $version,
        int $actorId,
    ): void {
        $group = $this->group($groupId);
        if ((string) $group['statut'] !== 'actif') {
            throw new ConsolidationException('Le groupe n’est pas actif.');
        }
        $this->changeGroupStatus(
            $group,
            $groupId,
            $version,
            'archive',
            0,
            'consolidation.groupe_archive',
            $actorId
        );
    }

    public function reactivateGroup(
        int $groupId,
        int $version,
        int $actorId,
    ): void {
        $group = $this->group($groupId);
        if ((string) $group['statut'] !== 'archive') {
            throw new ConsolidationException('Le groupe n’est pas archivé.');
        }
        $this->assertModeComposition(
            $groupId,
            (string) $group['mode'],
            (int) $group['organisation_pilote_id']
        );
        $this->changeGroupStatus(
            $group,
            $groupId,
            $version,
            'actif',
            1,
            'consolidation.groupe_reactive',
            $actorId
        );
    }

    /** @return array{deleted:bool,valid_until:?string} */
    public function removeMember(
        int $groupId,
        int $memberId,
        int $version,
        ?string $validUntil,
        int $actorId,
    ): array {
        $group = $this->group($groupId);
        $this->assertGroupWritable($group);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM membres_groupe_consolidation
             WHERE id = ? AND groupe_id = ?'
        );
        $stmt->execute([$memberId, $groupId]);
        $member = $stmt->fetch();
        if ($member === false) {
            throw new ConsolidationException('Dossier membre introuvable.');
        }
        if (
            (int) $member['organisation_id'] === (int) $group['organisation_pilote_id']
            && (int) $member['dossier_id'] === (int) $group['dossier_pilote_id']
        ) {
            throw new ConsolidationException(
                'Le dossier pilote ne peut pas être retiré du groupe.'
            );
        }
        $hasData = $this->hasPeriods($groupId)
            || (int) $this->scalar(
                'SELECT COUNT(*) FROM mappings_comptes_consolidation
                 WHERE groupe_id = ? AND membre_id = ?',
                [$groupId, $memberId]
            ) > 0
            || (int) $this->scalar(
                'SELECT COUNT(*) FROM paires_comptes_interentites
                 WHERE groupe_id = ?
                   AND (membre_gauche_id = ? OR membre_droite_id = ?)',
                [$groupId, $memberId, $memberId]
            ) > 0;
        if (!$hasData) {
            $delete = $this->pdo->prepare(
                'DELETE FROM membres_groupe_consolidation
                 WHERE id = ? AND groupe_id = ? AND version = ?'
            );
            $delete->execute([$memberId, $groupId, $version]);
            if ($delete->rowCount() !== 1) {
                throw new ConsolidationException(
                    'Le membre a été modifié par un autre utilisateur.'
                );
            }
            $this->auditGroup(
                'consolidation.membre_supprime',
                $actorId,
                $group,
                $memberId,
                [
                    'organisation_id' => (int) $member['organisation_id'],
                    'dossier_id' => (int) $member['dossier_id'],
                ]
            );
            return ['deleted' => true, 'valid_until' => null];
        }
        if ($validUntil === null) {
            throw new ConsolidationException(
                'Une date de sortie est obligatoire après la première donnée du groupe.'
            );
        }
        $this->date($validUntil);
        if ($validUntil < (string) $member['date_debut']) {
            throw new ConsolidationException('Date de sortie du membre invalide.');
        }
        $update = $this->pdo->prepare(
            'UPDATE membres_groupe_consolidation
             SET date_fin = ?, version = version + 1
             WHERE id = ? AND groupe_id = ? AND version = ?'
        );
        $update->execute([$validUntil, $memberId, $groupId, $version]);
        if ($update->rowCount() !== 1) {
            throw new ConsolidationException(
                'Le membre a été modifié par un autre utilisateur.'
            );
        }
        $this->auditGroup(
            'consolidation.membre_sorti',
            $actorId,
            $group,
            $memberId,
            [
                'organisation_id' => (int) $member['organisation_id'],
                'dossier_id' => (int) $member['dossier_id'],
                'before' => $member['date_fin'],
                'after' => $validUntil,
            ]
        );
        return ['deleted' => false, 'valid_until' => $validUntil];
    }

    /** @param array<string,string> $address */
    public function saveLegalAttributes(
        int $organisationId,
        string $validFrom,
        string $legalName,
        string $legalForm,
        string $uid,
        array $address,
        string $source,
        int $actorId,
    ): int {
        $stmt = $this->pdo->prepare(
            'SELECT version FROM organisations WHERE id = ?'
        );
        $stmt->execute([$organisationId]);
        $version = $stmt->fetchColumn();
        if ($version === false) {
            throw new ConsolidationException('Organisation introuvable.');
        }
        try {
            return (new OrganisationRegistryService($this->pdo, $this->audit))
                ->saveLegalIdentity(
                    $organisationId,
                    (int) $version,
                    [
                        'valid_from' => $validFrom,
                        'legal_name' => $legalName,
                        'legal_form' => $legalForm,
                        'uid' => $uid,
                        'address' => $address,
                        'source' => $source,
                    ],
                    $actorId
                );
        } catch (\Compta\Modules\Dossiers\OrganisationRegistryException $exception) {
            throw new ConsolidationException($exception->getMessage());
        }
    }

    /**
     * @param list<array{
     *   member_id:int,numerator:int,denominator:int,rate_date:string,source:string
     * }> $conversions
     */
    public function createPeriod(
        int $groupId,
        string $label,
        string $start,
        string $end,
        array $conversions,
        int $actorId,
    ): int {
        $this->date($start);
        $this->date($end);
        if (trim($label) === '' || $end < $start) {
            throw new ConsolidationException('Période de consolidation invalide.');
        }
        $group = $this->group($groupId);
        $this->assertGroupWritable($group);
        $this->assertModeComposition(
            $groupId,
            (string) $group['mode'],
            (int) $group['organisation_pilote_id'],
            $start,
            $end
        );
        $members = $this->members($groupId, $start, $end);
        $byMember = [];
        foreach ($conversions as $conversion) {
            $byMember[(int) $conversion['member_id']] = $conversion;
        }
        if (count($byMember) !== count($members)) {
            throw new ConsolidationException(
                'Un taux de conversion documenté est requis pour chaque membre.'
            );
        }
        return $this->transaction(function () use (
            $groupId, $label, $start, $end, $actorId, $group, $members, $byMember
        ): int {
            $this->pdo->prepare(
                'INSERT INTO periodes_consolidation
                 (groupe_id, libelle, date_debut, date_fin, cree_par)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$groupId, trim($label), $start, $end, $actorId]);
            $periodId = (int) $this->pdo->lastInsertId();
            $insert = $this->pdo->prepare(
                'INSERT INTO conversions_membres_consolidation
                 (periode_id, membre_id, devise_source, devise_cible,
                  numerateur, denominateur, date_taux, source, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($members as $member) {
                $conversion = $byMember[(int) $member['id']] ?? null;
                if ($conversion === null) {
                    throw new ConsolidationException('Conversion de membre manquante.');
                }
                $numerator = (int) $conversion['numerator'];
                $denominator = (int) $conversion['denominator'];
                $rateDate = (string) $conversion['rate_date'];
                $source = trim((string) $conversion['source']);
                $this->date($rateDate);
                if ($rateDate > $end || $numerator < 1 || $denominator < 1 || $source === '') {
                    throw new ConsolidationException('Taux de consolidation invalide.');
                }
                if ((string) $member['currency'] === (string) $group['devise']) {
                    if ($numerator !== 1 || $denominator !== 1) {
                        throw new ConsolidationException(
                            'Une devise identique utilise obligatoirement le ratio 1/1.'
                        );
                    }
                }
                $insert->execute([
                    $periodId, (int) $member['id'], (string) $member['currency'],
                    (string) $group['devise'], $numerator, $denominator,
                    $rateDate, $source, $actorId,
                ]);
            }
            $this->audit->log(
                'consolidation.periode_creee',
                $actorId,
                (int) $group['organisation_pilote_id'],
                (int) $group['dossier_pilote_id'],
                'periode_consolidation',
                (string) $periodId,
                ['date_debut' => $start, 'date_fin' => $end]
            );
            return $periodId;
        });
    }

    public function saveMapping(
        int $groupId,
        int $memberId,
        int $sourceAccountId,
        string $targetAccount,
        string $targetLabel,
        string $targetType,
        int $version,
        int $actorId,
        ?string $effectiveFrom = null,
    ): int {
        $targetAccount = trim($targetAccount);
        $targetLabel = trim($targetLabel);
        $types = ['actif', 'passif', 'fonds_propres', 'produit', 'charge', 'hors_bilan'];
        if (
            $targetAccount === '' || $targetLabel === ''
            || !in_array($targetType, $types, true)
        ) {
            throw new ConsolidationException('Mapping de compte incomplet.');
        }
        $group = $this->group($groupId);
        $this->assertGroupWritable($group);
        if (!$this->accountBelongsToMember($groupId, $memberId, $sourceAccountId)) {
            throw new ConsolidationException('Compte source hors du dossier membre.');
        }
        if ($version > 0) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM mappings_comptes_consolidation
                 WHERE groupe_id = ? AND membre_id = ? AND compte_source_id = ?'
            );
            $stmt->execute([$groupId, $memberId, $sourceAccountId]);
            $before = $stmt->fetch();
            if ($before === false || (int) $before['version'] !== $version) {
                throw new ConsolidationException(
                    'Mapping absent ou modifié par un autre utilisateur.'
                );
            }
            $id = (int) $before['id'];
            $this->replaceMappingVersion(
                $before,
                [
                    'target_account' => $targetAccount,
                    'target_label' => $targetLabel,
                    'target_type' => $targetType,
                    'active' => 1,
                ],
                $effectiveFrom,
                $actorId
            );
        } else {
            $start = '0001-01-01';
            $frozenUntil = $this->latestClosedPeriodEnd($groupId);
            if ($frozenUntil !== null) {
                $start = $this->effectiveAfterFrozen($effectiveFrom, $frozenUntil);
            }
            $this->pdo->prepare(
                'INSERT INTO mappings_comptes_consolidation
                 (groupe_id, membre_id, compte_source_id, compte_cible,
                  libelle_cible, type_cible, date_debut, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $groupId, $memberId, $sourceAccountId, $targetAccount,
                $targetLabel, $targetType, $start, $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
        }
        $this->audit->log(
            'consolidation.mapping_enregistre',
            $actorId,
            (int) $group['organisation_pilote_id'],
            (int) $group['dossier_pilote_id'],
            'mapping_consolidation',
            (string) $id,
            [
                'compte_cible' => $targetAccount,
                'version_precedente' => $version,
                'prise_effet' => $effectiveFrom,
            ]
        );
        return $id;
    }

    public function disableMapping(
        int $groupId,
        int $mappingId,
        int $version,
        ?string $effectiveFrom,
        int $actorId,
    ): void {
        $group = $this->group($groupId);
        $this->assertGroupWritable($group);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM mappings_comptes_consolidation
             WHERE id = ? AND groupe_id = ?'
        );
        $stmt->execute([$mappingId, $groupId]);
        $before = $stmt->fetch();
        if (
            $before === false
            || (int) $before['version'] !== $version
            || (int) $before['actif'] !== 1
        ) {
            throw new ConsolidationException(
                'Mapping absent, inactif ou modifié par un autre utilisateur.'
            );
        }
        $this->replaceMappingVersion(
            $before,
            [
                'target_account' => (string) $before['compte_cible'],
                'target_label' => (string) $before['libelle_cible'],
                'target_type' => (string) $before['type_cible'],
                'active' => 0,
            ],
            $effectiveFrom,
            $actorId
        );
        $this->auditGroup(
            'consolidation.mapping_desactive',
            $actorId,
            $group,
            $mappingId,
            [
                'before' => ['active' => true, 'version' => $version],
                'after' => [
                    'active' => false,
                    'version' => $version + 1,
                    'effective_from' => $effectiveFrom,
                ],
            ]
        );
    }

    public function saveIntercompanyPair(
        int $groupId,
        string $label,
        int $leftMemberId,
        int $leftAccountId,
        int $rightMemberId,
        int $rightAccountId,
        int $actorId,
        ?string $effectiveFrom = null,
    ): int {
        $label = trim($label);
        if ($label === '' || $leftMemberId === $rightMemberId) {
            throw new ConsolidationException('Paire inter-entités invalide.');
        }
        $group = $this->group($groupId);
        $this->assertGroupWritable($group);
        foreach (
            [[$leftMemberId, $leftAccountId], [$rightMemberId, $rightAccountId]]
            as [$memberId, $accountId]
        ) {
            if (!$this->accountBelongsToMember($groupId, $memberId, $accountId)) {
                throw new ConsolidationException('Compte inter-entités hors du membre.');
            }
        }
        $start = '0001-01-01';
        $frozenUntil = $this->latestClosedPeriodEnd($groupId);
        if ($frozenUntil !== null) {
            $start = $this->effectiveAfterFrozen($effectiveFrom, $frozenUntil);
        }
        $this->pdo->prepare(
            'INSERT INTO paires_comptes_interentites
             (groupe_id, libelle, membre_gauche_id, compte_gauche_id,
              membre_droite_id, compte_droite_id, date_debut, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $groupId, $label, $leftMemberId, $leftAccountId,
            $rightMemberId, $rightAccountId, $start, $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'consolidation.paire_interentites_creee',
            $actorId,
            (int) $group['organisation_pilote_id'],
            (int) $group['dossier_pilote_id'],
            'paire_interentites',
            (string) $id
        );
        return $id;
    }

    public function disableIntercompanyPair(
        int $groupId,
        int $pairId,
        int $version,
        ?string $effectiveFrom,
        int $actorId,
    ): void {
        $group = $this->group($groupId);
        $this->assertGroupWritable($group);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM paires_comptes_interentites
             WHERE id = ? AND groupe_id = ?'
        );
        $stmt->execute([$pairId, $groupId]);
        $before = $stmt->fetch();
        if (
            $before === false
            || (int) $before['version'] !== $version
            || (int) $before['actif'] !== 1
        ) {
            throw new ConsolidationException(
                'Paire absente, inactive ou modifiée par un autre utilisateur.'
            );
        }
        $this->replacePairVersion($before, 0, $effectiveFrom, $actorId);
        $this->auditGroup(
            'consolidation.paire_interentites_desactivee',
            $actorId,
            $group,
            $pairId,
            [
                'before' => ['active' => true, 'version' => $version],
                'after' => [
                    'active' => false,
                    'version' => $version + 1,
                    'effective_from' => $effectiveFrom,
                ],
            ]
        );
    }

    /**
     * @param list<array{
     *   target_account:string,label:string,debit_cents:int,credit_cents:int
     * }> $lines
     */
    public function createElimination(
        int $groupId,
        int $periodId,
        string $reference,
        string $label,
        string $justification,
        array $lines,
        int $actorId,
    ): int {
        $reference = trim($reference);
        $label = trim($label);
        $justification = trim($justification);
        if ($reference === '' || $label === '' || $justification === '' || count($lines) < 2) {
            throw new ConsolidationException(
                'Référence, libellé, justification et deux lignes sont requis.'
            );
        }
        $period = $this->period($groupId, $periodId);
        if ((string) $period['statut'] !== 'ouverte') {
            throw new ConsolidationException('La période de consolidation est clôturée.');
        }
        $targets = $this->targetAccounts($groupId, $periodId);
        $debit = 0;
        $credit = 0;
        foreach ($lines as $line) {
            $target = trim((string) $line['target_account']);
            $lineDebit = (int) $line['debit_cents'];
            $lineCredit = (int) $line['credit_cents'];
            if (
                !isset($targets[$target])
                || $lineDebit < 0 || $lineCredit < 0
                || (($lineDebit > 0) === ($lineCredit > 0))
            ) {
                throw new ConsolidationException('Ligne d’élimination invalide.');
            }
            $debit += $lineDebit;
            $credit += $lineCredit;
        }
        if ($debit < 1 || $debit !== $credit) {
            throw new ConsolidationException('L’élimination doit être équilibrée au centime.');
        }
        $group = $this->group($groupId);
        return $this->transaction(function () use (
            $periodId, $reference, $label, $justification, $lines,
            $actorId, $group, $debit
        ): int {
            $this->pdo->prepare(
                'INSERT INTO eliminations_consolidation
                 (periode_id, reference, libelle, justification)
                 VALUES (?, ?, ?, ?)'
            )->execute([
                $periodId, $reference, $label, $justification,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $insert = $this->pdo->prepare(
                'INSERT INTO lignes_elimination_consolidation
                 (elimination_id, compte_cible, libelle,
                  debit_centimes, credit_centimes, ordre)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach (array_values($lines) as $index => $line) {
                $insert->execute([
                    $id, trim((string) $line['target_account']),
                    trim((string) $line['label']),
                    (int) $line['debit_cents'], (int) $line['credit_cents'],
                    $index + 1,
                ]);
            }
            $this->pdo->prepare(
                'UPDATE eliminations_consolidation
                 SET statut = \'validee\', validee_le = datetime(\'now\'),
                     validee_par = ? WHERE id = ? AND statut = \'brouillon\''
            )->execute([$actorId, $id]);
            $this->audit->log(
                'consolidation.elimination_validee',
                $actorId,
                (int) $group['organisation_pilote_id'],
                (int) $group['dossier_pilote_id'],
                'elimination_consolidation',
                (string) $id,
                ['reference' => $reference, 'total_centimes' => $debit]
            );
            return $id;
        });
    }

    public function closePeriod(int $groupId, int $periodId, int $actorId): void
    {
        $period = $this->period($groupId, $periodId);
        if ((string) $period['statut'] !== 'ouverte') {
            throw new ConsolidationException('Cette période est déjà clôturée.');
        }
        $balance = $this->balance($groupId, $periodId);
        if ($balance['unmapped_accounts'] !== []) {
            throw new ConsolidationException(
                'Tous les comptes mouvementés doivent être mappés avant clôture.'
            );
        }
        $this->pdo->prepare(
            'UPDATE periodes_consolidation
             SET statut = \'cloturee\', cloturee_le = datetime(\'now\'),
                 cloturee_par = ?, version = version + 1
             WHERE id = ? AND groupe_id = ? AND statut = \'ouverte\''
        )->execute([$actorId, $periodId, $groupId]);
        $group = $this->group($groupId);
        $this->audit->log(
            'consolidation.periode_cloturee',
            $actorId,
            (int) $group['organisation_pilote_id'],
            (int) $group['dossier_pilote_id'],
            'periode_consolidation',
            (string) $periodId
        );
    }

    /**
     * @param list<int> $visibleGroupIds
     * @return array<string,mixed>
     */
    public function read(
        array $visibleGroupIds,
        ?int $selectedGroupId,
        ?int $selectedPeriodId,
    ): array {
        if ($selectedGroupId !== null && !in_array($selectedGroupId, $visibleGroupIds, true)) {
            throw new ConsolidationException('Groupe de consolidation inaccessible.');
        }
        $groups = [];
        foreach ($visibleGroupIds as $groupId) {
            $row = $this->group($groupId);
            $groups[] = [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'label' => (string) $row['libelle'],
                'currency' => (string) $row['devise'],
                'mode' => (string) $row['mode'],
                'status' => (string) $row['statut'],
                'version' => (int) $row['version'],
            ];
        }
        $selectedGroupId ??= $groups === [] ? null : (int) $groups[0]['id'];
        if ($selectedGroupId === null) {
            return [
                'groups' => [],
                'selected_group' => null,
                'periods' => [],
                'selected_period' => null,
                'members' => [],
                'mappings' => [],
                'intercompany_pairs' => [],
                'legal_histories' => [],
                'balance' => null,
                'reconciliation' => [],
                'eliminations' => [],
                'activation_preview' => null,
            ];
        }
        $group = $this->group($selectedGroupId);
        $members = $this->members($selectedGroupId);
        $periods = $this->periods($selectedGroupId);
        if ($selectedPeriodId === null && $periods !== []) {
            $selectedPeriodId = (int) $periods[0]['id'];
        }
        if (
            $selectedPeriodId !== null
            && !in_array(
                $selectedPeriodId,
                array_map(static fn (array $row): int => (int) $row['id'], $periods),
                true
            )
        ) {
            throw new ConsolidationException('Période de consolidation inaccessible.');
        }
        $mappings = $this->mappings($selectedGroupId);
        $pairs = $this->pairs($selectedGroupId);
        $balance = $selectedPeriodId === null
            ? null
            : $this->balance($selectedGroupId, $selectedPeriodId);
        return [
            'groups' => $groups,
            'selected_group' => [
                'id' => (int) $group['id'],
                'code' => (string) $group['code'],
                'label' => (string) $group['libelle'],
                'currency' => (string) $group['devise'],
                'mode' => (string) $group['mode'],
                'status' => (string) $group['statut'],
                'version' => (int) $group['version'],
            ],
            'periods' => $periods,
            'selected_period' => $selectedPeriodId === null
                ? null
                : $this->period($selectedGroupId, $selectedPeriodId),
            'members' => $members,
            'mappings' => $mappings,
            'intercompany_pairs' => $pairs,
            'legal_histories' => $this->legalHistories($members),
            'balance' => $balance,
            'reconciliation' => $selectedPeriodId === null
                ? []
                : $this->reconciliation(
                    $selectedGroupId,
                    $selectedPeriodId,
                    $this->pairs($selectedGroupId, $selectedPeriodId)
                ),
            'eliminations' => $selectedPeriodId === null
                ? []
                : $this->eliminations($selectedPeriodId),
            'activation_preview' => (string) $group['statut'] === 'brouillon'
                ? $this->activationPreview($selectedGroupId)
                : null,
        ];
    }

    /** @return array{filename:string,content:string,hash:string} */
    public function export(int $groupId, int $periodId): array
    {
        $workspace = $this->read([$groupId], $groupId, $periodId);
        $payload = [
            'format' => 'compta-group-report-v2',
            'generated_at' => gmdate('c'),
            'report_kind' => (string) $workspace['selected_group']['mode'],
            'legal_notice' => (string) $workspace['selected_group']['mode']
                === 'agregation_interne'
                ? 'Agrégation interne de dossiers : ce rapport ne constitue pas une consolidation légale.'
                : 'Consolidation légale distincte des livres statutaires de chaque entité.',
            'group' => $workspace['selected_group'],
            'period' => $workspace['selected_period'],
            'members' => $workspace['members'],
            'legal_histories' => $workspace['legal_histories'],
            'mappings' => $workspace['mappings'],
            'balance' => $workspace['balance'],
            'reconciliation' => $workspace['reconciliation'],
            'eliminations' => $workspace['eliminations'],
        ];
        $canonical = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $hash = hash('sha256', $canonical);
        $payload['sha256'] = $hash;
        $content = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) . "\n";
        return [
            'filename' => sprintf(
                '%s-%s-%s.json',
                (string) $workspace['selected_group']['mode']
                    === 'agregation_interne'
                    ? 'agregation'
                    : 'consolidation',
                strtolower((string) $workspace['selected_group']['code']),
                (string) $workspace['selected_period']['date_fin']
            ),
            'content' => $content,
            'hash' => $hash,
        ];
    }

    /** @return array<string,mixed> */
    private function balance(int $groupId, int $periodId): array
    {
        $period = $this->period($groupId, $periodId);
        $sources = $this->sourceBalances($groupId, $periodId);
        $targets = $this->targetAccounts($groupId, $periodId);
        $rows = [];
        foreach ($sources as $source) {
            $target = (string) $source['target_account'];
            $rows[$target] ??= [
                'account' => $target,
                'label' => (string) $source['target_label'],
                'type' => (string) $source['target_type'],
                'source_cents' => 0,
                'elimination_cents' => 0,
                'consolidated_cents' => 0,
                'sources' => [],
            ];
            $rows[$target]['source_cents'] += (int) $source['converted_cents'];
            $rows[$target]['sources'][] = $source;
        }
        $eliminations = $this->eliminations($periodId);
        foreach ($eliminations as $elimination) {
            foreach ($elimination['lines'] as $line) {
                $target = (string) $line['target_account'];
                $rows[$target] ??= [
                    'account' => $target,
                    'label' => (string) ($targets[$target]['label'] ?? $target),
                    'type' => (string) ($targets[$target]['type'] ?? 'hors_bilan'),
                    'source_cents' => 0,
                    'elimination_cents' => 0,
                    'consolidated_cents' => 0,
                    'sources' => [],
                ];
                $rows[$target]['elimination_cents'] +=
                    (int) $line['debit_cents'] - (int) $line['credit_cents'];
            }
        }
        ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($rows as &$row) {
            $row['consolidated_cents'] =
                $row['source_cents'] + $row['elimination_cents'];
        }
        unset($row);
        $unmapped = $this->unmappedAccounts(
            $groupId,
            (string) $period['date_debut'],
            (string) $period['date_fin']
        );
        $sourceTotal = array_sum(array_column($rows, 'source_cents'));
        $eliminationTotal = array_sum(array_column($rows, 'elimination_cents'));
        return [
            'currency' => (string) $this->group($groupId)['devise'],
            'rows' => array_values($rows),
            'source_total_cents' => $sourceTotal,
            'elimination_total_cents' => $eliminationTotal,
            'consolidated_total_cents' => $sourceTotal + $eliminationTotal,
            'formula_verified' => array_reduce(
                $rows,
                static fn (bool $ok, array $row): bool => $ok
                    && (int) $row['source_cents'] + (int) $row['elimination_cents']
                        === (int) $row['consolidated_cents'],
                true
            ),
            'unmapped_accounts' => $unmapped,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function sourceBalances(int $groupId, int $periodId): array
    {
        $period = $this->period($groupId, $periodId);
        $stmt = $this->pdo->prepare(
            "SELECT m.id AS member_id, m.organisation_id, m.dossier_id,
                    o.nom AS organisation, d.nom AS dossier,
                    c.id AS source_account_id, c.numero AS source_account,
                    c.libelle AS source_label,
                    mc.compte_cible AS target_account,
                    mc.libelle_cible AS target_label,
                    mc.type_cible AS target_type,
                    cv.devise_source, cv.devise_cible, cv.numerateur,
                    cv.denominateur, cv.date_taux, cv.source AS rate_source,
                    COALESCE(SUM(CASE WHEN e.id IS NOT NULL
                      THEN l.debit_centimes ELSE 0 END), 0) AS debit_cents,
                    COALESCE(SUM(CASE WHEN e.id IS NOT NULL
                      THEN l.credit_centimes ELSE 0 END), 0) AS credit_cents
             FROM membres_groupe_consolidation m
             JOIN organisations o ON o.id = m.organisation_id
             JOIN dossiers d ON d.id = m.dossier_id
             JOIN (
               SELECT id, groupe_id, membre_id, compte_source_id,
                      compte_cible, libelle_cible, type_cible,
                      actif, date_debut, date_fin, version
               FROM mappings_comptes_consolidation
               UNION ALL
               SELECT mapping_id AS id, groupe_id, membre_id, compte_source_id,
                      compte_cible, libelle_cible, type_cible,
                      actif, date_debut, date_fin, version
               FROM versions_mappings_consolidation
             ) mc
               ON mc.groupe_id = m.groupe_id AND mc.membre_id = m.id
              AND mc.actif = 1 AND mc.date_debut <= ?
              AND COALESCE(mc.date_fin, '9999-12-31') >= ?
             JOIN comptes c ON c.id = mc.compte_source_id
             JOIN conversions_membres_consolidation cv
               ON cv.membre_id = m.id AND cv.periode_id = ?
             LEFT JOIN lignes_ecriture l ON l.compte_id = c.id
             LEFT JOIN ecritures e ON e.id = l.ecriture_id
               AND e.organisation_id = m.organisation_id
               AND e.dossier_id = m.dossier_id
               AND e.statut IN ('validee', 'contre_passee')
               AND e.date_comptable BETWEEN ? AND ?
             WHERE m.groupe_id = ?
               AND m.date_debut <= ?
               AND COALESCE(m.date_fin, '9999-12-31') >= ?
             GROUP BY m.id, c.id, mc.id, cv.membre_id
             HAVING debit_cents <> 0 OR credit_cents <> 0
             ORDER BY mc.compte_cible, o.nom, c.numero"
        );
        $stmt->execute([
            $period['date_fin'], $period['date_debut'], $periodId,
            $period['date_debut'], $period['date_fin'], $groupId,
            $period['date_fin'], $period['date_debut'],
        ]);
        return array_map(static function (array $row): array {
            $source = (int) $row['debit_cents'] - (int) $row['credit_cents'];
            return [
                'member_id' => (int) $row['member_id'],
                'organisation_id' => (int) $row['organisation_id'],
                'dossier_id' => (int) $row['dossier_id'],
                'organisation' => (string) $row['organisation'],
                'dossier' => (string) $row['dossier'],
                'source_account_id' => (int) $row['source_account_id'],
                'source_account' => (string) $row['source_account'],
                'source_label' => (string) $row['source_label'],
                'target_account' => (string) $row['target_account'],
                'target_label' => (string) $row['target_label'],
                'target_type' => (string) $row['target_type'],
                'debit_cents' => (int) $row['debit_cents'],
                'credit_cents' => (int) $row['credit_cents'],
                'source_cents' => $source,
                'converted_cents' => ExchangeRateService::convert(
                    $source,
                    (int) $row['numerateur'],
                    (int) $row['denominateur']
                ),
                'conversion' => [
                    'source_currency' => (string) $row['devise_source'],
                    'target_currency' => (string) $row['devise_cible'],
                    'numerator' => (int) $row['numerateur'],
                    'denominator' => (int) $row['denominateur'],
                    'rate_date' => (string) $row['date_taux'],
                    'source' => (string) $row['rate_source'],
                ],
            ];
        }, $stmt->fetchAll());
    }

    /**
     * @param list<array<string,mixed>> $pairs
     * @return list<array<string,mixed>>
     */
    private function reconciliation(int $groupId, int $periodId, array $pairs): array
    {
        $period = $this->period($groupId, $periodId);
        $rates = [];
        $stmt = $this->pdo->prepare(
            'SELECT membre_id, numerateur, denominateur
             FROM conversions_membres_consolidation WHERE periode_id = ?'
        );
        $stmt->execute([$periodId]);
        foreach ($stmt->fetchAll() as $row) {
            $rates[(int) $row['membre_id']] = [
                (int) $row['numerateur'], (int) $row['denominateur'],
            ];
        }
        foreach ($pairs as &$pair) {
            $left = $this->accountBalance(
                (int) $pair['left_member_id'],
                (int) $pair['left_account_id'],
                (string) $period['date_debut'],
                (string) $period['date_fin']
            );
            $right = $this->accountBalance(
                (int) $pair['right_member_id'],
                (int) $pair['right_account_id'],
                (string) $period['date_debut'],
                (string) $period['date_fin']
            );
            [$ln, $ld] = $rates[(int) $pair['left_member_id']] ?? [0, 0];
            [$rn, $rd] = $rates[(int) $pair['right_member_id']] ?? [0, 0];
            if (min($ln, $ld, $rn, $rd) < 1) {
                throw new ConsolidationException('Conversion inter-entités manquante.');
            }
            $leftConverted = ExchangeRateService::convert($left, $ln, $ld);
            $rightConverted = ExchangeRateService::convert($right, $rn, $rd);
            $pair['left_cents'] = $leftConverted;
            $pair['right_cents'] = $rightConverted;
            $pair['difference_cents'] = $leftConverted + $rightConverted;
            $pair['reconciled'] = $pair['difference_cents'] === 0;
        }
        unset($pair);
        return $pairs;
    }

    /** @return list<array<string,mixed>> */
    private function unmappedAccounts(int $groupId, string $start, string $end): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT m.id AS member_id, m.organisation_id, m.dossier_id,
                    o.nom AS organisation, d.nom AS dossier,
                    c.id AS account_id, c.numero AS account, c.libelle AS label,
                    SUM(l.debit_centimes) AS debit_cents,
                    SUM(l.credit_centimes) AS credit_cents
             FROM membres_groupe_consolidation m
             JOIN organisations o ON o.id = m.organisation_id
             JOIN dossiers d ON d.id = m.dossier_id
             JOIN comptes c ON c.organisation_id = m.organisation_id
               AND c.dossier_id = m.dossier_id
             JOIN lignes_ecriture l ON l.compte_id = c.id
             JOIN ecritures e ON e.id = l.ecriture_id
               AND e.organisation_id = m.organisation_id
               AND e.dossier_id = m.dossier_id
               AND e.statut IN ('validee', 'contre_passee')
               AND e.date_comptable BETWEEN ? AND ?
             LEFT JOIN (
               SELECT id, groupe_id, membre_id, compte_source_id,
                      actif, date_debut, date_fin
               FROM mappings_comptes_consolidation
               UNION ALL
               SELECT mapping_id AS id, groupe_id, membre_id, compte_source_id,
                      actif, date_debut, date_fin
               FROM versions_mappings_consolidation
             ) mc
               ON mc.groupe_id = m.groupe_id AND mc.membre_id = m.id
              AND mc.compte_source_id = c.id AND mc.actif = 1
              AND mc.date_debut <= ?
              AND COALESCE(mc.date_fin, '9999-12-31') >= ?
             WHERE m.groupe_id = ? AND mc.id IS NULL
               AND m.date_debut <= ?
               AND COALESCE(m.date_fin, '9999-12-31') >= ?
             GROUP BY m.id, c.id ORDER BY m.id, c.numero"
        );
        $stmt->execute([
            $start,
            $end,
            $end,
            $start,
            $groupId,
            $end,
            $start,
        ]);
        return array_map(static fn (array $row): array => [
            'member_id' => (int) $row['member_id'],
            'organisation_id' => (int) $row['organisation_id'],
            'dossier_id' => (int) $row['dossier_id'],
            'organisation' => (string) $row['organisation'],
            'dossier' => (string) $row['dossier'],
            'member_label' => (string) $row['organisation']
                . ' — ' . (string) $row['dossier'],
            'account_id' => (int) $row['account_id'],
            'account' => (string) $row['account'],
            'label' => (string) $row['label'],
            'debit_cents' => (int) $row['debit_cents'],
            'credit_cents' => (int) $row['credit_cents'],
        ], $stmt->fetchAll());
    }

    /** @return array<string,mixed> */
    private function group(int $groupId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM groupes_consolidation WHERE id = ?'
        );
        $stmt->execute([$groupId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ConsolidationException('Groupe de consolidation introuvable.');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function members(
        int $groupId,
        ?string $periodStart = null,
        ?string $periodEnd = null,
    ): array {
        $where = 'm.groupe_id = ?';
        $params = [$groupId];
        if ($periodStart !== null && $periodEnd !== null) {
            $where .= " AND m.date_debut <= ?
                AND COALESCE(m.date_fin, '9999-12-31') >= ?";
            $params[] = $periodEnd;
            $params[] = $periodStart;
        }
        $stmt = $this->pdo->prepare(
            "SELECT m.*, o.nom AS organisation, d.nom AS dossier, d.monnaie AS devise
             FROM membres_groupe_consolidation m
             JOIN organisations o ON o.id = m.organisation_id
             JOIN dossiers d ON d.id = m.dossier_id
             WHERE {$where} ORDER BY o.nom, d.nom"
        );
        $stmt->execute($params);
        return array_map(function (array $row): array {
            $accounts = $this->pdo->prepare(
                'SELECT id, numero AS number, libelle AS label, type
                 FROM comptes WHERE organisation_id = ? AND dossier_id = ?
                   AND imputable = 1 AND actif = 1 ORDER BY numero'
            );
            $accounts->execute([
                (int) $row['organisation_id'], (int) $row['dossier_id'],
            ]);
            return [
                'id' => (int) $row['id'],
                'organisation_id' => (int) $row['organisation_id'],
                'dossier_id' => (int) $row['dossier_id'],
                'organisation' => (string) $row['organisation'],
                'dossier' => (string) $row['dossier'],
                'label' => (string) $row['organisation']
                    . ' — ' . (string) $row['dossier'],
                'currency' => (string) $row['devise'],
                'valid_from' => (string) $row['date_debut'],
                'valid_until' => $row['date_fin'] === null
                    ? null : (string) $row['date_fin'],
                'version' => (int) $row['version'],
                'accounts' => array_map(static fn (array $account): array => [
                    'id' => (int) $account['id'],
                    'number' => (string) $account['number'],
                    'label' => (string) $account['label'],
                    'type' => (string) $account['type'],
                ], $accounts->fetchAll()),
            ];
        }, $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    private function periods(int $groupId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM conversions_membres_consolidation c
                     WHERE c.periode_id = p.id) AS conversion_count
             FROM periodes_consolidation p
             WHERE p.groupe_id = ?
             ORDER BY p.date_fin DESC, p.id DESC'
        );
        $stmt->execute([$groupId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'label' => (string) $row['libelle'],
            'date_debut' => (string) $row['date_debut'],
            'date_fin' => (string) $row['date_fin'],
            'status' => (string) $row['statut'],
            'conversion_count' => (int) $row['conversion_count'],
            'version' => (int) $row['version'],
        ], $stmt->fetchAll());
    }

    /** @return array<string,mixed> */
    private function period(int $groupId, int $periodId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, groupe_id, libelle AS label, date_debut, date_fin,
                    statut, version, cloturee_le
             FROM periodes_consolidation WHERE id = ? AND groupe_id = ?'
        );
        $stmt->execute([$periodId, $groupId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ConsolidationException('Période de consolidation introuvable.');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function mappings(int $groupId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT mc.id, mc.membre_id, mc.compte_source_id,
                    c.numero AS source_account, c.libelle AS source_label,
                    o.nom AS organisation, d.nom AS dossier,
                    mc.compte_cible AS target_account,
                    mc.libelle_cible AS target_label,
                    mc.type_cible AS target_type, mc.version,
                    mc.actif, mc.date_debut, mc.date_fin,
                    (SELECT COUNT(*) FROM versions_mappings_consolidation vm
                     WHERE vm.mapping_id = mc.id) AS history_count
             FROM mappings_comptes_consolidation mc
             JOIN membres_groupe_consolidation m ON m.id = mc.membre_id
             JOIN organisations o ON o.id = m.organisation_id
             JOIN dossiers d ON d.id = m.dossier_id
             JOIN comptes c ON c.id = mc.compte_source_id
             WHERE mc.groupe_id = ?
             ORDER BY mc.compte_cible, mc.membre_id, c.numero'
        );
        $stmt->execute([$groupId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'member_id' => (int) $row['membre_id'],
            'source_account_id' => (int) $row['compte_source_id'],
            'source_account' => (string) $row['source_account'],
            'source_label' => (string) $row['source_label'],
            'organisation' => (string) $row['organisation'],
            'dossier' => (string) $row['dossier'],
            'member_label' => (string) $row['organisation']
                . ' — ' . (string) $row['dossier'],
            'target_account' => (string) $row['target_account'],
            'target_label' => (string) $row['target_label'],
            'target_type' => (string) $row['target_type'],
            'active' => (int) $row['actif'] === 1,
            'valid_from' => (string) $row['date_debut'],
            'valid_until' => $row['date_fin'] === null
                ? null : (string) $row['date_fin'],
            'version' => (int) $row['version'],
            'history_count' => (int) $row['history_count'],
        ], $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    private function pairs(int $groupId, ?int $periodId = null): array
    {
        $source = 'paires_comptes_interentites';
        $params = [$groupId];
        $periodWhere = '';
        if ($periodId !== null) {
            $period = $this->period($groupId, $periodId);
            $source = '(
                SELECT id, groupe_id, libelle, membre_gauche_id,
                       compte_gauche_id, membre_droite_id, compte_droite_id,
                       actif, date_debut, date_fin, version
                FROM paires_comptes_interentites
                UNION ALL
                SELECT paire_id AS id, groupe_id, libelle, membre_gauche_id,
                       compte_gauche_id, membre_droite_id, compte_droite_id,
                       actif, date_debut, date_fin, version
                FROM versions_paires_interentites
            )';
            $periodWhere = " AND p.actif = 1 AND p.date_debut <= ?
                AND COALESCE(p.date_fin, '9999-12-31') >= ?";
            $params[] = $period['date_fin'];
            $params[] = $period['date_debut'];
        }
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.libelle AS label,
                    p.membre_gauche_id AS left_member_id,
                    p.compte_gauche_id AS left_account_id,
                    cg.numero AS left_account, cg.libelle AS left_label,
                    ol.nom AS left_organisation, dl.nom AS left_dossier,
                    p.membre_droite_id AS right_member_id,
                    p.compte_droite_id AS right_account_id,
                    cd.numero AS right_account, cd.libelle AS right_label,
                    od.nom AS right_organisation, dd.nom AS right_dossier,
                    p.actif, p.date_debut, p.date_fin, p.version
             FROM {$source} p
             JOIN membres_groupe_consolidation ml ON ml.id = p.membre_gauche_id
             JOIN organisations ol ON ol.id = ml.organisation_id
             JOIN dossiers dl ON dl.id = ml.dossier_id
             JOIN membres_groupe_consolidation md ON md.id = p.membre_droite_id
             JOIN organisations od ON od.id = md.organisation_id
             JOIN dossiers dd ON dd.id = md.dossier_id
             JOIN comptes cg ON cg.id = p.compte_gauche_id
             JOIN comptes cd ON cd.id = p.compte_droite_id
             WHERE p.groupe_id = ?{$periodWhere}
             ORDER BY p.id, p.version"
        );
        $stmt->execute($params);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'left_member_id' => (int) $row['left_member_id'],
            'left_account_id' => (int) $row['left_account_id'],
            'left_account' => (string) $row['left_account'],
            'left_label' => (string) $row['left_label'],
            'left_organisation' => (string) $row['left_organisation'],
            'left_dossier' => (string) $row['left_dossier'],
            'left_member_label' => (string) $row['left_organisation']
                . ' — ' . (string) $row['left_dossier'],
            'right_member_id' => (int) $row['right_member_id'],
            'right_account_id' => (int) $row['right_account_id'],
            'right_account' => (string) $row['right_account'],
            'right_label' => (string) $row['right_label'],
            'right_organisation' => (string) $row['right_organisation'],
            'right_dossier' => (string) $row['right_dossier'],
            'right_member_label' => (string) $row['right_organisation']
                . ' — ' . (string) $row['right_dossier'],
            'active' => (int) $row['actif'] === 1,
            'valid_from' => (string) $row['date_debut'],
            'valid_until' => $row['date_fin'] === null
                ? null : (string) $row['date_fin'],
            'version' => (int) $row['version'],
        ], $stmt->fetchAll());
    }

    /** @param list<array<string,mixed>> $members @return list<array<string,mixed>> */
    private function legalHistories(array $members): array
    {
        $result = [];
        $seen = [];
        $stmt = $this->pdo->prepare(
            'SELECT id, organisation_id, date_debut, date_fin, raison_sociale,
                    forme_juridique, numero_ide, adresse_json, source, cree_le
             FROM attributs_juridiques_organisation
             WHERE organisation_id = ? ORDER BY date_debut DESC'
        );
        foreach ($members as $member) {
            $organisationId = (int) $member['organisation_id'];
            if (isset($seen[$organisationId])) {
                continue;
            }
            $seen[$organisationId] = true;
            $stmt->execute([$organisationId]);
            foreach ($stmt->fetchAll() as $row) {
                $result[] = [
                    'id' => (int) $row['id'],
                    'organisation_id' => (int) $row['organisation_id'],
                    'valid_from' => (string) $row['date_debut'],
                    'valid_until' => $row['date_fin'] === null
                        ? null : (string) $row['date_fin'],
                    'legal_name' => (string) $row['raison_sociale'],
                    'legal_form' => (string) $row['forme_juridique'],
                    'uid' => (string) $row['numero_ide'],
                    'address' => json_decode(
                        (string) $row['adresse_json'],
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    ),
                    'source' => (string) $row['source'],
                    'created_at' => (string) $row['cree_le'],
                ];
            }
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function eliminations(int $periodId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, reference, libelle AS label, justification,
                    statut AS status, validee_le AS validated_at,
                    validee_par AS validated_by
             FROM eliminations_consolidation
             WHERE periode_id = ? AND statut = \'validee\' ORDER BY id'
        );
        $stmt->execute([$periodId]);
        return array_map(function (array $row): array {
            $lines = $this->pdo->prepare(
                'SELECT compte_cible AS target_account, libelle AS label,
                        debit_centimes AS debit_cents,
                        credit_centimes AS credit_cents, ordre AS position
                 FROM lignes_elimination_consolidation
                 WHERE elimination_id = ? ORDER BY ordre'
            );
            $lines->execute([(int) $row['id']]);
            return [
                'id' => (int) $row['id'],
                'reference' => (string) $row['reference'],
                'label' => (string) $row['label'],
                'justification' => (string) $row['justification'],
                'status' => (string) $row['status'],
                'validated_at' => (string) $row['validated_at'],
                'validated_by' => $row['validated_by'] === null
                    ? null : (int) $row['validated_by'],
                'lines' => array_map(static fn (array $line): array => [
                    'target_account' => (string) $line['target_account'],
                    'label' => (string) $line['label'],
                    'debit_cents' => (int) $line['debit_cents'],
                    'credit_cents' => (int) $line['credit_cents'],
                    'position' => (int) $line['position'],
                ], $lines->fetchAll()),
            ];
        }, $stmt->fetchAll());
    }

    /** @return array<string,array{label:string,type:string}> */
    private function targetAccounts(int $groupId, ?int $periodId = null): array
    {
        $source = 'mappings_comptes_consolidation';
        $periodWhere = ' AND actif = 1';
        $params = [$groupId];
        if ($periodId !== null) {
            $period = $this->period($groupId, $periodId);
            $source = '(
                SELECT groupe_id, compte_cible, libelle_cible, type_cible,
                       actif, date_debut, date_fin
                FROM mappings_comptes_consolidation
                UNION ALL
                SELECT groupe_id, compte_cible, libelle_cible, type_cible,
                       actif, date_debut, date_fin
                FROM versions_mappings_consolidation
            )';
            $periodWhere .= " AND date_debut <= ?
                AND COALESCE(date_fin, '9999-12-31') >= ?";
            $params[] = $period['date_fin'];
            $params[] = $period['date_debut'];
        }
        $stmt = $this->pdo->prepare(
            "SELECT compte_cible, MIN(libelle_cible) AS libelle,
                    MIN(type_cible) AS type, COUNT(DISTINCT libelle_cible) AS labels,
                    COUNT(DISTINCT type_cible) AS types
             FROM {$source}
             WHERE groupe_id = ?{$periodWhere} GROUP BY compte_cible"
        );
        $stmt->execute($params);
        $targets = [];
        foreach ($stmt->fetchAll() as $row) {
            if ((int) $row['labels'] !== 1 || (int) $row['types'] !== 1) {
                throw new ConsolidationException(
                    'Les libellés et types d’un compte cible doivent concorder.'
                );
            }
            $targets[(string) $row['compte_cible']] = [
                'label' => (string) $row['libelle'],
                'type' => (string) $row['type'],
            ];
        }
        return $targets;
    }

    private function accountBelongsToMember(
        int $groupId,
        int $memberId,
        int $accountId,
    ): bool {
        return (int) $this->scalar(
            'SELECT COUNT(*)
             FROM membres_groupe_consolidation m
             JOIN comptes c ON c.organisation_id = m.organisation_id
               AND c.dossier_id = m.dossier_id
             WHERE m.id = ? AND m.groupe_id = ? AND c.id = ?',
            [$memberId, $groupId, $accountId]
        ) === 1;
    }

    private function accountBalance(
        int $memberId,
        int $accountId,
        string $start,
        string $end,
    ): int {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(l.debit_centimes - l.credit_centimes), 0)
             FROM membres_groupe_consolidation m
             JOIN comptes c ON c.id = ? AND c.organisation_id = m.organisation_id
               AND c.dossier_id = m.dossier_id
             JOIN lignes_ecriture l ON l.compte_id = c.id
             JOIN ecritures e ON e.id = l.ecriture_id
               AND e.organisation_id = m.organisation_id
               AND e.dossier_id = m.dossier_id
               AND e.statut IN ('validee', 'contre_passee')
               AND e.date_comptable BETWEEN ? AND ?
             WHERE m.id = ?"
        );
        $stmt->execute([$accountId, $start, $end, $memberId]);
        return (int) $stmt->fetchColumn();
    }

    /** @param array<string,mixed> $group */
    private function assertGroupWritable(array $group): void
    {
        if ((string) $group['statut'] === 'archive') {
            throw new ConsolidationException(
                'Le groupe archivé doit être réactivé avant modification.'
            );
        }
    }

    private function hasPeriods(int $groupId): bool
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM periodes_consolidation WHERE groupe_id = ?',
            [$groupId]
        ) > 0;
    }

    private function assertInternalMembers(
        int $groupId,
        int $pilotOrganisationId,
    ): void {
        if ((int) $this->scalar(
            'SELECT COUNT(*) FROM membres_groupe_consolidation
             WHERE groupe_id = ? AND organisation_id <> ?',
            [$groupId, $pilotOrganisationId]
        ) > 0) {
            throw new ConsolidationException(
                'Une agrégation interne ne peut contenir que des dossiers de l’organisation pilote.'
            );
        }
    }

    private function assertModeComposition(
        int $groupId,
        string $mode,
        int $pilotOrganisationId,
        ?string $start = null,
        ?string $end = null,
    ): void {
        $members = $start === null || $end === null
            ? $this->members($groupId)
            : $this->members($groupId, $start, $end);
        if (count($members) < 2) {
            throw new ConsolidationException(
                'Deux dossiers membres au minimum sont requis.'
            );
        }
        $organisationIds = array_values(array_unique(array_map(
            static fn (array $member): int => (int) $member['organisation_id'],
            $members
        )));
        if ($mode === 'agregation_interne') {
            $this->assertInternalMembers($groupId, $pilotOrganisationId);
            return;
        }
        if (count($organisationIds) < 2) {
            throw new ConsolidationException(
                'Une consolidation légale exige au moins deux organisations distinctes.'
            );
        }
    }

    /** @param array<string,mixed> $group */
    private function changeGroupStatus(
        array $group,
        int $groupId,
        int $version,
        string $status,
        int $active,
        string $event,
        int $actorId,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE groupes_consolidation
             SET statut = ?, actif = ?, modifie_le = datetime(\'now\'),
                 modifie_par = ?, version = version + 1
             WHERE id = ? AND version = ?'
        );
        $stmt->execute([$status, $active, $actorId, $groupId, $version]);
        if ($stmt->rowCount() !== 1) {
            throw new ConsolidationException(
                'Le groupe a été modifié par un autre utilisateur.'
            );
        }
        $this->auditGroup(
            $event,
            $actorId,
            $group,
            $groupId,
            [
                'before' => (string) $group['statut'],
                'after' => $status,
                'version' => $version + 1,
            ]
        );
    }

    /**
     * @param array<string,mixed> $group
     * @param array<string,mixed> $summary
     */
    private function auditGroup(
        string $event,
        int $actorId,
        array $group,
        int $targetId,
        array $summary,
    ): void {
        $this->audit->log(
            $event,
            $actorId,
            (int) $group['organisation_pilote_id'],
            (int) $group['dossier_pilote_id'],
            'groupe_consolidation',
            (string) $targetId,
            $summary
        );
    }

    private function latestClosedPeriodEnd(int $groupId): ?string
    {
        $value = $this->scalar(
            'SELECT MAX(date_fin) FROM periodes_consolidation
             WHERE groupe_id = ? AND statut = \'cloturee\'',
            [$groupId]
        );
        return $value === null || $value === false ? null : (string) $value;
    }

    private function effectiveAfterFrozen(
        ?string $effectiveFrom,
        string $frozenUntil,
    ): string {
        if ($effectiveFrom === null) {
            throw new ConsolidationException(
                'Une date de prise d’effet est requise après clôture.'
            );
        }
        $this->date($effectiveFrom);
        if ($effectiveFrom <= $frozenUntil) {
            throw new ConsolidationException(
                'La nouvelle version doit prendre effet après la dernière période clôturée.'
            );
        }
        return $effectiveFrom;
    }

    /**
     * @param array<string,mixed> $before
     * @param array{
     *   target_account:string,target_label:string,target_type:string,active:int
     * } $after
     */
    private function replaceMappingVersion(
        array $before,
        array $after,
        ?string $effectiveFrom,
        int $actorId,
    ): void {
        $frozenUntil = $this->latestClosedPeriodEnd((int) $before['groupe_id']);
        $wasFrozen = $frozenUntil !== null
            && $frozenUntil >= (string) $before['date_debut'];
        $this->transaction(function () use (
            $before,
            $after,
            $effectiveFrom,
            $actorId,
            $frozenUntil,
            $wasFrozen
        ): void {
            $newStart = (string) $before['date_debut'];
            if ($wasFrozen) {
                $newStart = $this->effectiveAfterFrozen(
                    $effectiveFrom,
                    (string) $frozenUntil
                );
                if ($newStart <= (string) $before['date_debut']) {
                    throw new ConsolidationException(
                        'La prise d’effet doit suivre la version courante.'
                    );
                }
                $end = (new DateTimeImmutable($newStart))
                    ->modify('-1 day')
                    ->format('Y-m-d');
                $this->pdo->prepare(
                    'INSERT INTO versions_mappings_consolidation
                     (mapping_id, groupe_id, membre_id, compte_source_id,
                      compte_cible, libelle_cible, type_cible, actif,
                      date_debut, date_fin, version, remplacee_par)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    (int) $before['id'],
                    (int) $before['groupe_id'],
                    (int) $before['membre_id'],
                    (int) $before['compte_source_id'],
                    (string) $before['compte_cible'],
                    (string) $before['libelle_cible'],
                    (string) $before['type_cible'],
                    (int) $before['actif'],
                    (string) $before['date_debut'],
                    $end,
                    (int) $before['version'],
                    $actorId,
                ]);
            }
            $stmt = $this->pdo->prepare(
                'UPDATE mappings_comptes_consolidation
                 SET compte_cible = ?, libelle_cible = ?, type_cible = ?,
                     actif = ?, date_debut = ?, date_fin = NULL,
                     modifie_le = datetime(\'now\'), modifie_par = ?,
                     version = version + 1
                 WHERE id = ? AND version = ?'
            );
            $stmt->execute([
                $after['target_account'],
                $after['target_label'],
                $after['target_type'],
                $after['active'],
                $newStart,
                $actorId,
                (int) $before['id'],
                (int) $before['version'],
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new ConsolidationException(
                    'Le mapping a été modifié par un autre utilisateur.'
                );
            }
        });
    }

    /** @param array<string,mixed> $before */
    private function replacePairVersion(
        array $before,
        int $active,
        ?string $effectiveFrom,
        int $actorId,
    ): void {
        $frozenUntil = $this->latestClosedPeriodEnd((int) $before['groupe_id']);
        $wasFrozen = $frozenUntil !== null
            && $frozenUntil >= (string) $before['date_debut'];
        $this->transaction(function () use (
            $before,
            $active,
            $effectiveFrom,
            $actorId,
            $frozenUntil,
            $wasFrozen
        ): void {
            $newStart = (string) $before['date_debut'];
            if ($wasFrozen) {
                $newStart = $this->effectiveAfterFrozen(
                    $effectiveFrom,
                    (string) $frozenUntil
                );
                $end = (new DateTimeImmutable($newStart))
                    ->modify('-1 day')
                    ->format('Y-m-d');
                $this->pdo->prepare(
                    'INSERT INTO versions_paires_interentites
                     (paire_id, groupe_id, libelle, membre_gauche_id,
                      compte_gauche_id, membre_droite_id, compte_droite_id,
                      actif, date_debut, date_fin, version, remplacee_par)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    (int) $before['id'],
                    (int) $before['groupe_id'],
                    (string) $before['libelle'],
                    (int) $before['membre_gauche_id'],
                    (int) $before['compte_gauche_id'],
                    (int) $before['membre_droite_id'],
                    (int) $before['compte_droite_id'],
                    (int) $before['actif'],
                    (string) $before['date_debut'],
                    $end,
                    (int) $before['version'],
                    $actorId,
                ]);
            }
            $stmt = $this->pdo->prepare(
                'UPDATE paires_comptes_interentites
                 SET actif = ?, date_debut = ?, date_fin = NULL,
                     version = version + 1
                 WHERE id = ? AND version = ?'
            );
            $stmt->execute([
                $active,
                $newStart,
                (int) $before['id'],
                (int) $before['version'],
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new ConsolidationException(
                    'La paire a été modifiée par un autre utilisateur.'
                );
            }
        });
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    private function date(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new ConsolidationException('Date invalide.');
        }
    }

    /** @template T @param callable():T $callback @return T */
    private function transaction(callable $callback): mixed
    {
        $owned = !$this->pdo->inTransaction();
        if ($owned) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($owned) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($owned && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
