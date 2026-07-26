<?php
declare(strict_types=1);

namespace Compta\Modules\Configuration\Application;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Tresorerie\BankCoordinates;
use Compta\Modules\Tresorerie\TreasuryException;
use DateTimeImmutable;
use PDO;
use Throwable;

final class ConfigurationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly ModuleAccessService $modules,
    ) {
    }

    /** @return array<string,mixed> */
    public function read(int $organisationId, int $dossierId): array
    {
        $this->assertScope($organisationId, $dossierId);
        return [
            'identity' => $this->identity($organisationId, $dossierId),
            'modules' => $this->modules->modules($organisationId, $dossierId),
            'payment_terms' => $this->paymentTerms($organisationId, $dossierId),
            'audit' => $this->recentAudit($organisationId, $dossierId),
            'definitions' => [
                'contacts' => 'Le registre unique reste celui de Facturation.',
                'historical_values' => 'Les taux et conditions utilisés sont figés dans les documents et fiches.',
                'payment_due_date' => 'Date du document + délai, puis fin du mois obtenu si l’option est active.',
            ],
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public function updateIdentity(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): array {
        $identity = $this->identity($organisationId, $dossierId);
        $organizationVersion = (int) ($data['organization_version'] ?? 0);
        $dossierVersion = (int) ($data['dossier_version'] ?? 0);
        if (
            $organizationVersion !== (int) $identity['organization']['version']
            || $dossierVersion !== (int) $identity['dossier']['version']
        ) {
            throw new ConfigurationException(
                'La configuration a été modifiée par un autre utilisateur.'
            );
        }
        $organization = $this->validateIdentity($data);
        $billingIban = BankCoordinates::normalizeIban(
            (string) ($data['billing_iban'] ?? '')
        );
        try {
            if ($billingIban !== '') {
                BankCoordinates::assertIban($billingIban);
            }
        } catch (TreasuryException) {
            throw new ConfigurationException('IBAN de facturation invalide.');
        }
        if (
            $billingIban !== ''
            && !str_starts_with($billingIban, 'CH')
            && !str_starts_with($billingIban, 'LI')
        ) {
            throw new ConfigurationException(
                'La QR-facture exige un IBAN suisse ou liechtensteinois.'
            );
        }
        $currency = mb_strtoupper(trim((string) ($data['base_currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new ConfigurationException('La devise doit être un code ISO de trois lettres.');
        }
        if (
            $organization['uid'] !== ''
            && (int) $this->scalar(
                'SELECT COUNT(*) FROM organisations
                 WHERE numero_ide = ? AND id <> ?',
                [$organization['uid'], $organisationId]
            ) > 0
        ) {
            throw new ConfigurationException(
                'Ce numéro IDE est déjà attribué à une autre organisation.'
            );
        }
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $organizationVersion,
            $dossierVersion,
            $organization,
            $billingIban,
            $currency,
            $actorId,
            $identity
        ): void {
            $updateOrganization = $this->pdo->prepare(
                'UPDATE organisations
                 SET nom = ?, raison_sociale = ?, forme_juridique = ?,
                     numero_ide = ?, adresse_ligne1 = ?, adresse_ligne2 = ?,
                     code_postal = ?, localite = ?, canton = ?, pays = ?,
                     telephone = ?, email = ?, site_web = ?,
                     modifie_le = datetime(\'now\'), version = version + 1
                 WHERE id = ? AND version = ?'
            );
            $updateOrganization->execute([
                $organization['name'],
                $organization['legal_name'],
                $organization['legal_form'],
                $organization['uid'],
                $organization['address_line1'],
                $organization['address_line2'],
                $organization['postal_code'],
                $organization['city'],
                $organization['canton'],
                $organization['country'],
                $organization['phone'],
                $organization['email'],
                $organization['website'],
                $organisationId,
                $organizationVersion,
            ]);
            $updateDossier = $this->pdo->prepare(
                'UPDATE dossiers
                 SET monnaie = ?, version = version + 1
                 WHERE id = ? AND organisation_id = ? AND version = ?'
            );
            $updateDossier->execute([
                $currency, $dossierId, $organisationId, $dossierVersion,
            ]);
            $billingIbanStatement = $this->pdo->prepare(
                'INSERT INTO parametres_organisation (organisation_id, cle, valeur)
                 VALUES (?, \'iban_facturation\', ?)
                 ON CONFLICT (organisation_id, cle)
                 DO UPDATE SET valeur = excluded.valeur'
            );
            $billingIbanStatement->execute([$organisationId, $billingIban]);
            if ($updateOrganization->rowCount() !== 1 || $updateDossier->rowCount() !== 1) {
                throw new ConfigurationException(
                    'Conflit de version pendant l’enregistrement.'
                );
            }
            $changed = [];
            foreach ($organization as $key => $value) {
                if (($identity['organization'][$key] ?? null) !== $value) {
                    $changed[] = $key;
                }
            }
            if ($identity['dossier']['base_currency'] !== $currency) {
                $changed[] = 'base_currency';
            }
            if (($identity['organization']['billing_iban'] ?? '') !== $billingIban) {
                $changed[] = 'billing_iban';
            }
            $this->audit->log(
                'configuration.identite_modifiee',
                $actorId,
                $organisationId,
                $dossierId,
                'organisation',
                (string) $organisationId,
                ['champs' => implode(',', $changed)]
            );
        });
        return $this->identity($organisationId, $dossierId);
    }

    public function setModule(
        int $organisationId,
        int $dossierId,
        string $code,
        bool $enabled,
        int $expectedVersion,
        int $actorId,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE modules_dossier
             SET actif = ?, modifie_le = datetime(\'now\'), modifie_par = ?,
                 version = version + 1
             WHERE organisation_id = ? AND dossier_id = ?
               AND module_code = ? AND version = ?'
        );
        $stmt->execute([
            $enabled ? 1 : 0,
            $actorId,
            $organisationId,
            $dossierId,
            $code,
            $expectedVersion,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new ConfigurationException(
                'Module inconnu ou modifié par un autre utilisateur.'
            );
        }
        $this->audit->log(
            $enabled ? 'configuration.module_active' : 'configuration.module_desactive',
            $actorId,
            $organisationId,
            $dossierId,
            'module_dossier',
            $code,
            ['actif' => $enabled ? 1 : 0]
        );
    }

    /** @param array<string,mixed> $data */
    public function createPaymentTerm(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): int {
        $this->assertScope($organisationId, $dossierId);
        $code = mb_strtoupper(trim((string) ($data['code'] ?? '')));
        $label = trim((string) ($data['label'] ?? ''));
        $direction = (string) ($data['direction'] ?? 'tous');
        $days = (int) ($data['days'] ?? -1);
        $validFrom = (string) ($data['valid_from'] ?? '');
        $validUntil = trim((string) ($data['valid_until'] ?? ''));
        $this->date($validFrom);
        if ($validUntil !== '') {
            $this->date($validUntil);
        }
        if (
            preg_match('/^[A-Z0-9_-]{1,20}$/', $code) !== 1
            || $label === ''
            || !in_array($direction, ['client', 'fournisseur', 'tous'], true)
            || $days < 0
            || $days > 3650
            || ($validUntil !== '' && $validUntil < $validFrom)
        ) {
            throw new ConfigurationException('Condition de paiement invalide.');
        }
        if ((int) $this->scalar(
            'SELECT COUNT(*) FROM conditions_paiement
             WHERE dossier_id = ? AND code = ? AND date_debut = ?',
            [$dossierId, $code, $validFrom]
        ) > 0) {
            throw new ConfigurationException(
                'Une condition avec ce code existe déjà à cette date.'
            );
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO conditions_paiement
             (organisation_id, dossier_id, code, libelle, direction,
              delai_jours, fin_de_mois, date_debut, date_fin, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId,
            $dossierId,
            $code,
            $label,
            $direction,
            $days,
            !empty($data['end_of_month']) ? 1 : 0,
            $validFrom,
            $validUntil === '' ? null : $validUntil,
            $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'configuration.condition_paiement_creee',
            $actorId,
            $organisationId,
            $dossierId,
            'condition_paiement',
            (string) $id,
            ['code' => $code, 'direction' => $direction]
        );
        return $id;
    }

    public function setPaymentDefault(
        int $organisationId,
        int $dossierId,
        string $direction,
        int $conditionId,
        string $validFrom,
        int $actorId,
    ): int {
        if (!in_array($direction, ['client', 'fournisseur'], true)) {
            throw new ConfigurationException('Direction de paiement invalide.');
        }
        $date = $this->date($validFrom);
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $direction,
            $conditionId,
            $validFrom,
            $date,
            $actorId
        ): int {
            $condition = $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM conditions_paiement
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND actif = 1
                   AND date_debut <= ?
                   AND (date_fin IS NULL OR date_fin >= ?)
                   AND (direction = ? OR direction = \'tous\')'
            );
            $condition->execute([
                $conditionId,
                $organisationId,
                $dossierId,
                $validFrom,
                $validFrom,
                $direction,
            ]);
            if ((int) $condition->fetchColumn() !== 1) {
                throw new ConfigurationException(
                    'La condition de paiement ne convient pas à ce dossier, cette direction ou cette date.'
                );
            }
            $latest = $this->pdo->prepare(
                'SELECT MAX(date_debut)
                 FROM defauts_conditions_paiement
                 WHERE organisation_id = ? AND dossier_id = ? AND direction = ?'
            );
            $latest->execute([$organisationId, $dossierId, $direction]);
            $latestDate = $latest->fetchColumn();
            if (is_string($latestDate) && $validFrom <= $latestDate) {
                throw new ConfigurationException(
                    'Le nouveau défaut doit prendre effet après le dernier défaut enregistré.'
                );
            }
            $this->pdo->prepare(
                'UPDATE defauts_conditions_paiement
                 SET date_fin = ?
                 WHERE organisation_id = ? AND dossier_id = ? AND direction = ?
                   AND date_fin IS NULL'
            )->execute([
                $date->modify('-1 day')->format('Y-m-d'),
                $organisationId,
                $dossierId,
                $direction,
            ]);
            $stmt = $this->pdo->prepare(
                'INSERT INTO defauts_conditions_paiement
                 (organisation_id, dossier_id, direction, condition_id,
                  date_debut, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId,
                $dossierId,
                $direction,
                $conditionId,
                $validFrom,
                $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit->log(
                'configuration.condition_defaut_modifiee',
                $actorId,
                $organisationId,
                $dossierId,
                'defaut_condition_paiement',
                (string) $id,
                [
                    'direction' => $direction,
                    'condition_id' => $conditionId,
                    'date_debut' => $validFrom,
                ]
            );
            return $id;
        });
    }

    /** @return array<string,mixed> */
    private function identity(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.id AS organisation_id, o.nom, o.raison_sociale,
                    o.forme_juridique, o.numero_ide, o.adresse_ligne1,
                    o.adresse_ligne2, o.code_postal, o.localite, o.canton,
                    o.pays, o.telephone, o.email, o.site_web,
                    COALESCE((
                        SELECT p.valeur FROM parametres_organisation p
                        WHERE p.organisation_id = o.id
                          AND p.cle = \'iban_facturation\'
                    ), \'\') AS billing_iban,
                    o.version AS organisation_version,
                    d.id AS dossier_id, d.nom AS dossier_nom, d.monnaie,
                    d.version AS dossier_version
             FROM organisations o
             JOIN dossiers d ON d.organisation_id = o.id
             WHERE o.id = ? AND d.id = ?'
        );
        $stmt->execute([$organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ConfigurationException('Dossier introuvable.');
        }
        return [
            'organization' => [
                'id' => (int) $row['organisation_id'],
                'name' => (string) $row['nom'],
                'legal_name' => (string) $row['raison_sociale'],
                'legal_form' => (string) $row['forme_juridique'],
                'uid' => (string) $row['numero_ide'],
                'address_line1' => (string) $row['adresse_ligne1'],
                'address_line2' => (string) $row['adresse_ligne2'],
                'postal_code' => (string) $row['code_postal'],
                'city' => (string) $row['localite'],
                'canton' => (string) $row['canton'],
                'country' => (string) $row['pays'],
                'phone' => (string) $row['telephone'],
                'email' => (string) $row['email'],
                'website' => (string) $row['site_web'],
                'billing_iban' => (string) $row['billing_iban'],
                'version' => (int) $row['organisation_version'],
            ],
            'dossier' => [
                'id' => (int) $row['dossier_id'],
                'name' => (string) $row['dossier_nom'],
                'base_currency' => (string) $row['monnaie'],
                'version' => (int) $row['dossier_version'],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function paymentTerms(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*,
                    EXISTS (
                        SELECT 1 FROM defauts_conditions_paiement d
                        WHERE d.condition_id = c.id
                          AND d.date_debut <= date(\'now\')
                          AND (d.date_fin IS NULL OR d.date_fin >= date(\'now\'))
                    ) AS est_defaut
             FROM conditions_paiement c
             WHERE c.organisation_id = ? AND c.dossier_id = ?
             ORDER BY c.actif DESC, c.date_debut DESC, c.code'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'label' => (string) $row['libelle'],
            'direction' => (string) $row['direction'],
            'days' => (int) $row['delai_jours'],
            'end_of_month' => (int) $row['fin_de_mois'] === 1,
            'valid_from' => (string) $row['date_debut'],
            'valid_until' => $row['date_fin'] === null ? null : (string) $row['date_fin'],
            'active' => (int) $row['actif'] === 1,
            'is_default' => (int) $row['est_defaut'] === 1,
            'version' => (int) $row['version'],
        ], $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    private function recentAudit(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.action, a.cible_type, a.cible_id, a.cree_le,
                    COALESCE(NULLIF(TRIM(u.prenom || \' \' || u.nom), \'\'), u.email) AS actor
             FROM audit_events a
             LEFT JOIN utilisateurs u ON u.id = a.utilisateur_id
             WHERE a.organisation_id = ? AND a.dossier_id = ?
             ORDER BY a.id DESC
             LIMIT 20'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'action' => (string) $row['action'],
            'target_type' => (string) $row['cible_type'],
            'target_id' => (string) $row['cible_id'],
            'actor' => $row['actor'] === null ? 'Système' : (string) $row['actor'],
            'created_at' => (string) $row['cree_le'],
        ], $stmt->fetchAll());
    }

    /** @param array<string,mixed> $data @return array<string,string> */
    private function validateIdentity(array $data): array
    {
        $fields = [
            'name', 'legal_name', 'legal_form', 'uid', 'address_line1',
            'address_line2', 'postal_code', 'city', 'canton', 'country',
            'phone', 'email', 'website',
        ];
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = trim((string) ($data[$field] ?? ''));
        }
        $values['country'] = mb_strtoupper($values['country']);
        $values['canton'] = mb_strtoupper($values['canton']);
        $values['uid'] = mb_strtoupper($values['uid']);
        if ($values['name'] === '') {
            throw new ConfigurationException('Le nom de l’organisation est requis.');
        }
        if (
            $values['country'] !== ''
            && preg_match('/^[A-Z]{2}$/', $values['country']) !== 1
        ) {
            throw new ConfigurationException('Le pays doit être un code ISO de deux lettres.');
        }
        if (
            $values['email'] !== ''
            && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new ConfigurationException('Adresse e-mail invalide.');
        }
        if (
            $values['website'] !== ''
            && filter_var($values['website'], FILTER_VALIDATE_URL) === false
        ) {
            throw new ConfigurationException('Adresse de site web invalide.');
        }
        return $values;
    }

    private function assertScope(int $organisationId, int $dossierId): void
    {
        if ((int) $this->scalar(
            'SELECT COUNT(*) FROM dossiers WHERE id = ? AND organisation_id = ?',
            [$dossierId, $organisationId]
        ) !== 1) {
            throw new ConfigurationException('Dossier introuvable.');
        }
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ConfigurationException('Date invalide.');
        }
        return $date;
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /** @template T @param callable():T $callback @return T */
    private function transaction(callable $callback): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($owns) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
