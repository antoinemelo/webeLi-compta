<?php
declare(strict_types=1);

namespace Compta\Modules\Compta;

use Compta\Core\Audit\AuditLogger;
use PDO;
use Throwable;

final class ChartOfAccountsService
{
    public const TYPES = [
        'actif', 'passif', 'produit', 'charge', 'hors_bilan',
    ];

    public const TYPE_LABELS = [
        'actif' => 'Actif',
        'passif' => 'Passif',
        'produit' => 'Produit',
        'charge' => 'Charge',
        'hors_bilan' => 'Hors bilan',
    ];

    public const STRUCTURE_LEVELS = [
        'classe', 'groupe_principal', 'groupe', 'sous_groupe',
    ];

    private const PARENT_LEVEL = [
        'classe' => null,
        'groupe_principal' => 'classe',
        'groupe' => 'groupe_principal',
        'sous_groupe' => 'groupe',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
    ) {
    }

    public function create(
        int $organisationId,
        int $dossierId,
        string $number,
        string $label,
        string $type,
        string $normalSide,
        ?int $parentId = null,
        bool $postable = true,
        string $tag = '',
        ?int $actorId = null,
    ): int {
        return $this->insertAccount(
            $organisationId,
            $dossierId,
            $number,
            $label,
            $type,
            $normalSide,
            $normalSide,
            $parentId,
            $postable,
            $tag,
            $actorId,
            null
        );
    }

    public function createConfigured(
        int $organisationId,
        int $dossierId,
        string $number,
        string $label,
        string $type,
        string $senseMode = 'automatique',
        ?int $actorId = null,
        ?int $rubricId = null,
    ): int {
        $this->assertNumber($number);
        $derivedType = $this->accountRubricType(
            $organisationId,
            $dossierId,
            $rubricId
        );
        if ($derivedType !== null) {
            $type = $derivedType;
        }
        $normalSide = $this->normalSideForMode(
            $organisationId,
            $dossierId,
            $number,
            $senseMode
        );
        return $this->insertAccount(
            $organisationId,
            $dossierId,
            $number,
            $label,
            $type,
            $normalSide,
            $senseMode,
            null,
            true,
            '',
            $actorId,
            $rubricId
        );
    }

    /** @return list<array<string,mixed>> */
    public function accounts(
        int $organisationId,
        int $dossierId,
        bool $includeInactive = true,
    ): array {
        $sql = 'SELECT c.*
                FROM comptes c
                WHERE c.organisation_id = :organisation
                  AND c.dossier_id = :dossier
                  AND c.imputable = 1';
        if (!$includeInactive) {
            $sql .= ' AND c.actif = 1';
        }
        $sql .= ' ORDER BY c.ordre, c.numero COLLATE NOCASE';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'organisation' => $organisationId,
            'dossier' => $dossierId,
        ]);
        $rows = $stmt->fetchAll();
        $rubrics = $this->rubrics($organisationId, $dossierId, true);
        $byId = [];
        foreach ($rubrics as $rubric) {
            $byId[(int) $rubric['id']] = $rubric;
        }
        foreach ($rows as &$row) {
            $rubric = $byId[(int) ($row['rubrique_id'] ?? 0)] ?? null;
            $row['rubrique_code'] = $rubric['code'] ?? '';
            $row['rubrique_libelle'] = $rubric['libelle'] ?? '';
            $row['rubrique_chemin'] = $rubric['chemin'] ?? '';
        }
        unset($row);
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function accountTypes(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT t.*
             FROM types_comptes t
             WHERE t.organisation_id = ? AND t.dossier_id = ? AND t.actif = 1
             ORDER BY t.ordre, t.id"
        );
        $stmt->execute([$organisationId, $dossierId]);
        return $stmt->fetchAll();
    }

    public function renameAccountType(
        int $organisationId,
        int $dossierId,
        int $typeId,
        string $label,
        int $expectedVersion,
        ?int $actorId = null,
    ): void {
        $label = trim($label);
        if ($label === '') {
            throw new AccountingException('Le libellé du type est requis.');
        }
        $stmt = $this->pdo->prepare(
            "UPDATE types_comptes
             SET libelle = ?, modifie_le = datetime('now'), version = version + 1
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND version = ? AND actif = 1"
        );
        $stmt->execute([
            $label,
            $typeId,
            $organisationId,
            $dossierId,
            $expectedVersion,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new AccountingException(
                'Type de compte absent ou modifié par un autre utilisateur.'
            );
        }
        $this->audit->log(
            'compta.type_compte_renomme',
            $actorId,
            $organisationId,
            $dossierId,
            'type_compte',
            (string) $typeId,
            ['libelle' => $label]
        );
    }

    /** @param list<array{id:int,libelle:string,version:int}> $rows */
    public function renameAccountTypesBatch(
        int $organisationId,
        int $dossierId,
        array $rows,
        ?int $actorId = null,
    ): void {
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $rows,
            $actorId
        ): void {
            foreach ($rows as $row) {
                $this->renameAccountType(
                    $organisationId,
                    $dossierId,
                    (int) $row['id'],
                    (string) $row['libelle'],
                    (int) $row['version'],
                    $actorId
                );
            }
        });
    }

    /** @return list<string> */
    public function creditPrefixes(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT prefixe FROM regles_sens_comptes
             WHERE organisation_id = ? AND dossier_id = ?
             ORDER BY length(prefixe), prefixe'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(
            static fn (mixed $value): string => (string) $value,
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    /** @param list<string> $prefixes */
    public function replaceCreditPrefixes(
        int $organisationId,
        int $dossierId,
        array $prefixes,
        ?int $actorId = null,
    ): void {
        $this->assertDossierScope($organisationId, $dossierId);
        $normalized = [];
        foreach ($prefixes as $prefix) {
            $prefix = trim($prefix);
            $this->assertPrefix($prefix);
            $normalized[$prefix] = $prefix;
        }
        uksort(
            $normalized,
            static fn (string $a, string $b): int => strlen($a) <=> strlen($b)
                ?: strcmp($a, $b)
        );
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $normalized,
            $actorId
        ): void {
            $before = $this->creditPrefixes($organisationId, $dossierId);
            $this->pdo->prepare(
                'DELETE FROM regles_sens_comptes
                 WHERE organisation_id = ? AND dossier_id = ?'
            )->execute([$organisationId, $dossierId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO regles_sens_comptes
                    (organisation_id, dossier_id, prefixe, cree_par)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($normalized as $prefix) {
                $insert->execute([
                    $organisationId,
                    $dossierId,
                    $prefix,
                    $actorId,
                ]);
            }
            $this->synchronizeAutomaticSides($organisationId, $dossierId);
            $this->audit->log(
                'compta.regles_sens_modifiees',
                $actorId,
                $organisationId,
                $dossierId,
                'plan_comptable',
                (string) $dossierId,
                ['avant' => $before, 'apres' => array_values($normalized)]
            );
        });
    }

    /** @return list<array<string,mixed>> */
    public function rubrics(
        int $organisationId,
        int $dossierId,
        bool $includeInactive = false,
    ): array {
        $sql = "SELECT * FROM rubriques_comptables
                WHERE organisation_id = ? AND dossier_id = ?";
        if (!$includeInactive) {
            $sql .= ' AND actif = 1';
        }
        $sql .= " ORDER BY CASE niveau_structure
                    WHEN 'classe' THEN 1
                    WHEN 'groupe_principal' THEN 2
                    WHEN 'groupe' THEN 3
                    ELSE 4 END,
                  ordre, code, id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$organisationId, $dossierId]);
        $rows = $stmt->fetchAll();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        foreach ($rows as &$row) {
            $row['chemin'] = $this->rubricPath($row, $byId);
            $parent = $byId[(int) ($row['parent_id'] ?? 0)] ?? null;
            $row['parent_code'] = $parent['code'] ?? '';
            $row['parent_libelle'] = $parent['libelle'] ?? '';
        }
        unset($row);
        return $rows;
    }

    public function saveRubric(
        int $organisationId,
        int $dossierId,
        ?int $rubricId,
        string $structureLevel,
        string $code,
        string $label,
        string $type,
        ?int $parentId,
        int $position = 0,
        ?int $expectedVersion = null,
        ?int $actorId = null,
    ): int {
        $this->assertDossierScope($organisationId, $dossierId);
        $code = trim($code);
        $label = trim($label);
        $this->assertStructureCode($structureLevel, $code);
        if ($label === '') {
            throw new AccountingException('Données de rubrique invalides.');
        }
        $this->assertRubricParent(
            $organisationId,
            $dossierId,
            $structureLevel,
            $parentId
        );
        if ($structureLevel === 'classe') {
            if (!in_array($type, self::TYPES, true)) {
                throw new AccountingException('Type de classe invalide.');
            }
        } else {
            $type = $this->rubricType($organisationId, $dossierId, $parentId);
        }
        if ($code !== '' && str_starts_with($code, '9') && $type !== 'hors_bilan') {
            throw new AccountingException(
                'Une rubrique commençant par 9 doit appartenir au type Hors bilan.'
            );
        }
        if ($rubricId === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO rubriques_comptables
                    (organisation_id, dossier_id, code, libelle,
                     niveau_structure, type, parent_id, ordre, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId,
                $dossierId,
                $code,
                $label,
                $structureLevel,
                $type,
                $parentId,
                $position,
                $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $action = 'creee';
        } else {
            $sql = "UPDATE rubriques_comptables
                    SET code = :code, libelle = :libelle,
                        niveau_structure = :niveau, type = :type,
                        parent_id = :parent, ordre = :ordre, actif = 1,
                        modifie_le = datetime('now'), version = version + 1
                    WHERE id = :id AND organisation_id = :organisation
                      AND dossier_id = :dossier";
            $params = [
                'code' => $code,
                'libelle' => $label,
                'niveau' => $structureLevel,
                'type' => $type,
                'parent' => $parentId,
                'ordre' => $position,
                'id' => $rubricId,
                'organisation' => $organisationId,
                'dossier' => $dossierId,
            ];
            if ($expectedVersion !== null) {
                $sql .= ' AND version = :version';
                $params['version'] = $expectedVersion;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() !== 1) {
                throw new AccountingException(
                    'Rubrique absente ou modifiée par un autre utilisateur.'
                );
            }
            $id = $rubricId;
            $action = 'modifiee';
        }
        $this->synchronizeDerivedTypes($organisationId, $dossierId);
        $this->audit->log(
            'compta.rubrique_' . $action,
            $actorId,
            $organisationId,
            $dossierId,
            'rubrique_comptable',
            (string) $id,
            [
                'niveau' => $structureLevel,
                'code' => $code,
                'libelle' => $label,
                'type' => $type,
                'parent_id' => $parentId,
            ]
        );
        return $id;
    }

    /**
     * @param list<array{
     *   id:int,code:string,libelle:string,type:string,parent_id:?int,
     *   ordre:int,version:int
     * }> $rows
     * @param list<int> $orderedIds
     */
    public function saveRubricsBatch(
        int $organisationId,
        int $dossierId,
        string $structureLevel,
        array $rows,
        array $orderedIds,
        ?int $actorId = null,
    ): void {
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $structureLevel,
            $rows,
            $orderedIds,
            $actorId
        ): void {
            foreach ($rows as $row) {
                $this->saveRubric(
                    $organisationId,
                    $dossierId,
                    (int) $row['id'],
                    $structureLevel,
                    (string) $row['code'],
                    (string) $row['libelle'],
                    (string) $row['type'],
                    $row['parent_id'] === null ? null : (int) $row['parent_id'],
                    (int) $row['ordre'],
                    (int) $row['version'],
                    $actorId
                );
            }
            if ($orderedIds !== []) {
                $this->reorderRubrics(
                    $organisationId,
                    $dossierId,
                    $structureLevel,
                    $orderedIds,
                    $actorId
                );
            }
        });
    }

    /** @param list<int> $rubricIds */
    public function reorderRubrics(
        int $organisationId,
        int $dossierId,
        string $structureLevel,
        array $rubricIds,
        ?int $actorId = null,
    ): void {
        if (!in_array($structureLevel, self::STRUCTURE_LEVELS, true)) {
            throw new AccountingException('Niveau de structure invalide.');
        }
        $rubricIds = array_values(array_unique(array_filter(
            array_map('intval', $rubricIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($rubricIds === []) {
            throw new AccountingException('Aucune rubrique à réordonner.');
        }
        $placeholders = implode(',', array_fill(0, count($rubricIds), '?'));
        $check = $this->pdo->prepare(
            "SELECT COUNT(*) FROM rubriques_comptables
             WHERE organisation_id = ? AND dossier_id = ?
               AND niveau_structure = ? AND id IN ({$placeholders})"
        );
        $check->execute([
            $organisationId,
            $dossierId,
            $structureLevel,
            ...$rubricIds,
        ]);
        if ((int) $check->fetchColumn() !== count($rubricIds)) {
            throw new AccountingException(
                'La liste de tri contient une rubrique hors niveau ou hors dossier.'
            );
        }
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $structureLevel,
            $rubricIds,
            $actorId
        ): void {
            $update = $this->pdo->prepare(
                "UPDATE rubriques_comptables
                 SET ordre = ?, modifie_le = datetime('now'), version = version + 1
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND niveau_structure = ?"
            );
            foreach ($rubricIds as $position => $id) {
                $update->execute([
                    ($position + 1) * 10,
                    $id,
                    $organisationId,
                    $dossierId,
                    $structureLevel,
                ]);
            }
            $this->audit->log(
                'compta.rubriques_reordonnees',
                $actorId,
                $organisationId,
                $dossierId,
                'plan_comptable',
                $structureLevel,
                ['ordre' => $rubricIds]
            );
        });
    }

    public function removeRubric(
        int $organisationId,
        int $dossierId,
        int $rubricId,
        ?int $actorId = null,
    ): void {
        $references = $this->pdo->prepare(
            'SELECT
                EXISTS(SELECT 1 FROM rubriques_comptables WHERE parent_id = :id)
                    AS enfants,
                EXISTS(SELECT 1 FROM comptes WHERE rubrique_id = :id)
                    AS comptes'
        );
        $references->execute(['id' => $rubricId]);
        $used = $references->fetch();
        if ($used !== false && ((int) $used['enfants'] === 1 || (int) $used['comptes'] === 1)) {
            throw new AccountingException(
                'Réaffectez d’abord les sous-rubriques et les comptes avant de retirer cette rubrique.'
            );
        }
        $stmt = $this->pdo->prepare(
            'DELETE FROM rubriques_comptables
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$rubricId, $organisationId, $dossierId]);
        if ($stmt->rowCount() !== 1) {
            throw new AccountingException('Rubrique absente du dossier.');
        }
        $this->audit->log(
            'compta.rubrique_supprimee',
            $actorId,
            $organisationId,
            $dossierId,
            'rubrique_comptable',
            (string) $rubricId
        );
    }

    public function updateAccount(
        int $organisationId,
        int $dossierId,
        int $accountId,
        string $number,
        string $label,
        string $type,
        string $senseMode,
        int $expectedVersion,
        ?int $actorId = null,
        ?int $rubricId = null,
    ): void {
        $this->assertNumber($number);
        $label = trim($label);
        $before = $this->pdo->prepare(
            'SELECT numero, libelle, type, sens_normal, sens_mode, rubrique_id
             FROM comptes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND imputable = 1'
        );
        $before->execute([$accountId, $organisationId, $dossierId]);
        $old = $before->fetch();
        if ($old === false) {
            throw new AccountingException('Compte absent du dossier.');
        }
        if ($rubricId === null) {
            $rubricId = (int) ($old['rubrique_id'] ?? 0) ?: null;
        }
        $derivedType = $this->accountRubricType(
            $organisationId,
            $dossierId,
            $rubricId
        );
        if ($derivedType !== null) {
            $type = $derivedType;
        }
        if (str_starts_with($number, '9') && $type !== 'hors_bilan') {
            throw new AccountingException(
                'Un compte commençant par 9 doit appartenir au type Hors bilan.'
            );
        }
        if ($label === '' || !in_array($type, self::TYPES, true)) {
            throw new AccountingException('Données de compte invalides.');
        }
        $side = $this->normalSideForMode(
            $organisationId,
            $dossierId,
            $number,
            $senseMode
        );
        $stmt = $this->pdo->prepare(
            "UPDATE comptes
             SET numero = ?, libelle = ?, type = ?, sens_normal = ?,
                 sens_mode = ?, rubrique_id = ?,
                 modifie_le = datetime('now'), version = version + 1
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND imputable = 1 AND version = ?"
        );
        $stmt->execute([
            trim($number),
            $label,
            $type,
            $side,
            $senseMode,
            $rubricId,
            $accountId,
            $organisationId,
            $dossierId,
            $expectedVersion,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new AccountingException(
                'Compte modifié par un autre utilisateur. Rechargez la page.'
            );
        }
        $this->audit->log(
            'compta.compte_modifie',
            $actorId,
            $organisationId,
            $dossierId,
            'compte',
            (string) $accountId,
            [
                'avant' => $old,
                'apres' => [
                    'numero' => trim($number),
                    'libelle' => $label,
                    'type' => $type,
                    'sens_normal' => $side,
                    'sens_mode' => $senseMode,
                    'rubrique_id' => $rubricId,
                ],
            ]
        );
    }

    /**
     * @param list<array{
     *   id:int,numero:string,libelle:string,sens_mode:string,
     *   rubrique_id:?int,version:int
     * }> $rows
     * @param list<int> $orderedIds
     */
    public function updateAccountsBatch(
        int $organisationId,
        int $dossierId,
        array $rows,
        array $orderedIds,
        ?int $actorId = null,
    ): void {
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $rows,
            $orderedIds,
            $actorId
        ): void {
            foreach ($rows as $row) {
                $this->updateAccount(
                    $organisationId,
                    $dossierId,
                    (int) $row['id'],
                    (string) $row['numero'],
                    (string) $row['libelle'],
                    '',
                    (string) $row['sens_mode'],
                    (int) $row['version'],
                    $actorId,
                    $row['rubrique_id'] === null
                        ? null
                        : (int) $row['rubrique_id']
                );
            }
            $this->reorderAccounts(
                $organisationId,
                $dossierId,
                $orderedIds,
                $actorId
            );
        });
    }

    /** @param list<int> $accountIds */
    public function reorderAccounts(
        int $organisationId,
        int $dossierId,
        array $accountIds,
        ?int $actorId = null,
    ): void {
        $accountIds = array_values(array_unique(array_filter(
            array_map('intval', $accountIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($accountIds === []) {
            throw new AccountingException('Aucun compte à réordonner.');
        }
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $check = $this->pdo->prepare(
            "SELECT COUNT(*) FROM comptes
             WHERE organisation_id = ? AND dossier_id = ? AND imputable = 1
               AND id IN ({$placeholders})"
        );
        $check->execute([
            $organisationId,
            $dossierId,
            ...$accountIds,
        ]);
        if ((int) $check->fetchColumn() !== count($accountIds)) {
            throw new AccountingException(
                'La liste de tri contient un compte hors dossier.'
            );
        }
        $update = $this->pdo->prepare(
            "UPDATE comptes
             SET ordre = ?, modifie_le = datetime('now'), version = version + 1
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND imputable = 1"
        );
        foreach ($accountIds as $position => $id) {
            $update->execute([
                ($position + 1) * 10,
                $id,
                $organisationId,
                $dossierId,
            ]);
        }
        $this->audit->log(
            'compta.comptes_reordonnes',
            $actorId,
            $organisationId,
            $dossierId,
            'plan_comptable',
            (string) $dossierId,
            ['ordre' => $accountIds]
        );
    }

    /**
     * Supprime un compte jamais utilisé. Un compte référencé est uniquement
     * désactivé afin de préserver l'historique.
     */
    public function removeOrDeactivate(
        int $organisationId,
        int $dossierId,
        int $accountId,
        ?int $actorId = null,
    ): string {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $accountId,
            $actorId
        ): string {
            $account = $this->pdo->prepare(
                'SELECT numero FROM comptes
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND imputable = 1'
            );
            $account->execute([$accountId, $organisationId, $dossierId]);
            $number = $account->fetchColumn();
            if ($number === false) {
                throw new AccountingException('Compte absent du dossier.');
            }
            $used = $this->pdo->prepare(
                'SELECT EXISTS(SELECT 1 FROM lignes_ecriture WHERE compte_id = ?)'
            );
            $used->execute([$accountId]);
            $action = (int) $used->fetchColumn() === 1 ? 'desactive' : 'supprime';
            if ($action === 'desactive') {
                $this->pdo->prepare(
                    "UPDATE comptes
                     SET actif = 0, modifie_le = datetime('now'), version = version + 1
                     WHERE id = ?"
                )->execute([$accountId]);
            } else {
                $this->pdo->prepare('DELETE FROM comptes WHERE id = ?')->execute([$accountId]);
            }
            $this->audit->log(
                'compta.compte_' . $action,
                $actorId,
                $organisationId,
                $dossierId,
                'compte',
                (string) $accountId,
                ['numero' => $number]
            );
            return $action;
        });
    }

    public function ensureOpeningJournal(
        int $organisationId,
        int $dossierId,
        ?int $actorId = null,
    ): int {
        $this->assertDossierScope($organisationId, $dossierId);
        $existing = $this->pdo->prepare(
            "SELECT id FROM journaux
             WHERE organisation_id = ? AND dossier_id = ?
               AND type = 'ouverture' AND actif = 1
             ORDER BY id LIMIT 1"
        );
        $existing->execute([$organisationId, $dossierId]);
        $id = $existing->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $check = $this->pdo->prepare(
            'SELECT 1 FROM journaux WHERE dossier_id = ? AND code = ?'
        );
        $code = '';
        foreach (['OUV', 'OUVERTURE', 'OUV2', 'OUV3'] as $candidate) {
            $check->execute([$dossierId, $candidate]);
            if ($check->fetchColumn() === false) {
                $code = $candidate;
                break;
            }
        }
        if ($code === '') {
            throw new AccountingException(
                'Impossible de déterminer un code libre pour le journal d’ouverture.'
            );
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO journaux
                (organisation_id, dossier_id, code, libelle, type, cree_par)
             VALUES (?, ?, ?, 'Soldes d’ouverture', 'ouverture', ?)"
        );
        $stmt->execute([$organisationId, $dossierId, $code, $actorId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'compta.journal_cree',
            $actorId,
            $organisationId,
            $dossierId,
            'journal',
            (string) $id,
            ['code' => $code, 'type' => 'ouverture']
        );
        return $id;
    }

    private function insertAccount(
        int $organisationId,
        int $dossierId,
        string $number,
        string $label,
        string $type,
        string $normalSide,
        string $senseMode,
        ?int $parentId,
        bool $postable,
        string $tag,
        ?int $actorId,
        ?int $rubricId,
    ): int {
        $number = trim($number);
        $label = trim($label);
        if (
            $number === ''
            || $label === ''
            || !in_array($type, self::TYPES, true)
            || !in_array($normalSide, ['debit', 'credit'], true)
            || !in_array($senseMode, ['automatique', 'debit', 'credit'], true)
        ) {
            throw new AccountingException('Données de compte invalides.');
        }
        if (str_starts_with($number, '9') && $type !== 'hors_bilan') {
            throw new AccountingException(
                'Un compte commençant par 9 doit appartenir au type Hors bilan.'
            );
        }
        $this->assertDossierScope($organisationId, $dossierId);
        $level = 1;
        if ($parentId !== null) {
            $parent = $this->pdo->prepare(
                'SELECT niveau FROM comptes
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
            );
            $parent->execute([$parentId, $organisationId, $dossierId]);
            $parentLevel = $parent->fetchColumn();
            if ($parentLevel === false) {
                throw new AccountingException('Parent du compte hors dossier.');
            }
            $level = (int) $parentLevel + 1;
        }
        $nextOrder = $this->pdo->prepare(
            'SELECT COALESCE(MAX(ordre), 0) + 10
             FROM comptes WHERE organisation_id = ? AND dossier_id = ?'
        );
        $nextOrder->execute([$organisationId, $dossierId]);
        $order = (int) $nextOrder->fetchColumn();
        $stmt = $this->pdo->prepare(
            'INSERT INTO comptes
                (organisation_id, dossier_id, numero, libelle, type, sens_normal,
                 sens_mode, parent_id, rubrique_id, niveau, imputable, marque,
                 ordre, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId,
            $dossierId,
            $number,
            $label,
            $type,
            $normalSide,
            $senseMode,
            $parentId,
            $rubricId,
            $level,
            $postable ? 1 : 0,
            trim($tag),
            $order,
            $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'compta.compte_cree',
            $actorId,
            $organisationId,
            $dossierId,
            'compte',
            (string) $id,
            [
                'numero' => $number,
                'type' => $type,
                'sens_normal' => $normalSide,
                'sens_mode' => $senseMode,
                'rubrique_id' => $rubricId,
            ]
        );
        return $id;
    }

    private function assertRubricParent(
        int $organisationId,
        int $dossierId,
        string $structureLevel,
        ?int $parentId,
    ): void {
        if (!array_key_exists($structureLevel, self::PARENT_LEVEL)) {
            throw new AccountingException('Niveau de structure invalide.');
        }
        $expected = self::PARENT_LEVEL[$structureLevel];
        if ($expected === null) {
            if ($parentId !== null) {
                throw new AccountingException('Une classe ne possède pas de parent.');
            }
            return;
        }
        if ($parentId === null) {
            throw new AccountingException('La rubrique parente est requise.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT niveau_structure FROM rubriques_comptables
             WHERE id = ? AND organisation_id = ? AND dossier_id = ? AND actif = 1'
        );
        $stmt->execute([$parentId, $organisationId, $dossierId]);
        $level = $stmt->fetchColumn();
        if ($level !== $expected) {
            throw new AccountingException(
                'La rubrique parente ne correspond pas au niveau supérieur attendu.'
            );
        }
    }

    private function accountRubricType(
        int $organisationId,
        int $dossierId,
        ?int $rubricId,
    ): ?string {
        if ($rubricId === null) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT type, niveau_structure FROM rubriques_comptables
             WHERE id = ? AND organisation_id = ? AND dossier_id = ? AND actif = 1'
        );
        $stmt->execute([$rubricId, $organisationId, $dossierId]);
        $rubric = $stmt->fetch();
        if ($rubric === false) {
            throw new AccountingException('Rubrique du compte hors dossier ou inactive.');
        }
        if (!in_array(
            $rubric['niveau_structure'],
            ['groupe_principal', 'groupe'],
            true
        )) {
            throw new AccountingException(
                'Le parent direct du compte doit être une rubrique numérotée.'
            );
        }
        return (string) $rubric['type'];
    }

    private function rubricType(
        int $organisationId,
        int $dossierId,
        ?int $rubricId,
    ): string {
        if ($rubricId === null) {
            throw new AccountingException('Rubrique parente requise.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT type FROM rubriques_comptables
             WHERE id = ? AND organisation_id = ? AND dossier_id = ? AND actif = 1'
        );
        $stmt->execute([$rubricId, $organisationId, $dossierId]);
        $type = $stmt->fetchColumn();
        if ($type === false) {
            throw new AccountingException('Rubrique parente absente du dossier.');
        }
        return (string) $type;
    }

    private function synchronizeDerivedTypes(
        int $organisationId,
        int $dossierId,
    ): void {
        foreach (['groupe_principal', 'groupe', 'sous_groupe'] as $level) {
            $this->pdo->prepare(
                "UPDATE rubriques_comptables AS enfant
                 SET type = (
                     SELECT parent.type
                     FROM rubriques_comptables parent
                     WHERE parent.id = enfant.parent_id
                 )
                 WHERE enfant.organisation_id = ?
                   AND enfant.dossier_id = ?
                   AND enfant.niveau_structure = ?"
            )->execute([$organisationId, $dossierId, $level]);
        }
        $this->pdo->prepare(
            "UPDATE comptes AS compte
             SET type = (
                 SELECT rubrique.type
                 FROM rubriques_comptables rubrique
                 WHERE rubrique.id = compte.rubrique_id
             )
             WHERE compte.organisation_id = ?
               AND compte.dossier_id = ?
               AND compte.imputable = 1
               AND compte.rubrique_id IS NOT NULL"
        )->execute([$organisationId, $dossierId]);
    }

    private function normalSideForMode(
        int $organisationId,
        int $dossierId,
        string $number,
        string $senseMode,
    ): string {
        if (in_array($senseMode, ['debit', 'credit'], true)) {
            return $senseMode;
        }
        if ($senseMode !== 'automatique') {
            throw new AccountingException('Mode de fonctionnement du compte invalide.');
        }
        $stmt = $this->pdo->prepare(
            "SELECT EXISTS(
                SELECT 1 FROM regles_sens_comptes
                WHERE organisation_id = ? AND dossier_id = ?
                  AND ? LIKE prefixe || '%'
            )"
        );
        $stmt->execute([$organisationId, $dossierId, trim($number)]);
        return (int) $stmt->fetchColumn() === 1 ? 'credit' : 'debit';
    }

    private function synchronizeAutomaticSides(
        int $organisationId,
        int $dossierId,
    ): void {
        $this->pdo->prepare(
            "UPDATE comptes
             SET sens_normal = CASE WHEN EXISTS (
                    SELECT 1 FROM regles_sens_comptes r
                    WHERE r.organisation_id = comptes.organisation_id
                      AND r.dossier_id = comptes.dossier_id
                      AND comptes.numero LIKE r.prefixe || '%'
                 ) THEN 'credit' ELSE 'debit' END,
                 modifie_le = datetime('now'), version = version + 1
             WHERE organisation_id = ? AND dossier_id = ?
               AND sens_mode = 'automatique'"
        )->execute([$organisationId, $dossierId]);
    }

    /** @param array<string,mixed> $rubric @param array<int,array<string,mixed>> $byId */
    private function rubricPath(array $rubric, array $byId): string
    {
        $parts = [];
        $seen = [];
        $current = $rubric;
        while ($current !== null) {
            $id = (int) $current['id'];
            if (isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;
            $code = trim((string) $current['code']);
            $parts[] = ($code === '' ? '' : $code . ' ') . (string) $current['libelle'];
            $parentId = (int) ($current['parent_id'] ?? 0);
            $current = $parentId > 0 ? ($byId[$parentId] ?? null) : null;
        }
        return implode(' ‹ ', $parts);
    }

    private function assertDossierScope(int $organisationId, int $dossierId): void
    {
        $scope = $this->pdo->prepare(
            'SELECT 1 FROM dossiers
             WHERE id = ? AND organisation_id = ? AND actif = 1'
        );
        $scope->execute([$dossierId, $organisationId]);
        if ($scope->fetchColumn() === false) {
            throw new AccountingException('Scope du plan comptable invalide.');
        }
    }

    private function assertNumber(string $number): void
    {
        if (!preg_match('/^[0-9]{4}$/', trim($number))) {
            throw new AccountingException(
                'Le numéro de compte doit contenir exactement quatre chiffres.'
            );
        }
    }

    private function assertPrefix(string $prefix): void
    {
        if (!preg_match('/^[0-9]{1,20}$/', $prefix)) {
            throw new AccountingException(
                'Un préfixe doit contenir uniquement 1 à 20 chiffres.'
            );
        }
    }

    private function assertStructureCode(string $level, string $code): void
    {
        $valid = match ($level) {
            'classe' => preg_match('/^[0-9]$/', $code) === 1,
            'groupe_principal' => preg_match('/^[0-9]{2}$/', $code) === 1,
            'groupe' => preg_match('/^[0-9]{3}$/', $code) === 1,
            'sous_groupe' => $code === '',
            default => false,
        };
        if (!$valid) {
            throw new AccountingException(match ($level) {
                'classe' => 'Une classe exige un numéro à un chiffre.',
                'groupe_principal' => 'Un groupe principal exige un numéro à deux chiffres.',
                'groupe' => 'Un groupe exige un numéro à trois chiffres.',
                'sous_groupe' => 'Un sous-groupe ne porte pas de numéro.',
                default => 'Niveau de structure invalide.',
            });
        }
    }

    private function transaction(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $callback();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
