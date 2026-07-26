<?php
declare(strict_types=1);

namespace Compta\Modules\Configuration\Application;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Compta\AccountingSetupService;
use Compta\Modules\Facturation\ContactService;
use Compta\Modules\Salaires\PayrollConfigurationService;
use Compta\Modules\Tresorerie\TreasuryAccountService;
use Compta\Modules\Tva\VatConfigurationService;
use PDO;
use Throwable;

final class ManagedReferencesService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ContactService $contacts,
        private readonly VatConfigurationService $vat,
        private readonly PayrollConfigurationService $payroll,
        private readonly TreasuryAccountService $treasury,
        private readonly AccountingSetupService $accountingSetup,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function read(int $organisationId, int $dossierId): array
    {
        return [
            'contacts' => array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'type' => (string) $row['type_personne'],
                    'company' => (string) $row['raison_sociale'],
                    'first_name' => (string) $row['prenom'],
                    'last_name' => (string) $row['nom'],
                    'email' => (string) $row['email'],
                    'phone' => (string) $row['telephone'],
                    'payment_iban' => (string) $row['iban_paiement'],
                    'payment_bic' => (string) $row['bic_paiement'],
                    'language' => (string) $row['langue'],
                    'roles' => array_values(array_filter(
                        explode(',', (string) $row['roles'])
                    )),
                    'address_line1' => (string) ($row['ligne1'] ?? ''),
                    'address_line2' => (string) ($row['ligne2'] ?? ''),
                    'postal_code' => (string) ($row['code_postal'] ?? ''),
                    'city' => (string) ($row['localite'] ?? ''),
                    'country' => (string) ($row['pays'] ?? 'CH'),
                    'version' => (int) $row['version'],
                ],
                $this->contacts->all($organisationId, $dossierId)
            ),
            'vat' => [
                'codes' => $this->vatCodes($organisationId, $dossierId),
                'legal_rates' => $this->legalVatRates(),
                'accounts' => $this->accounts($organisationId, $dossierId),
            ],
            'payroll' => [
                'rates' => $this->payrollRates($organisationId, $dossierId),
                'fields' => PayrollConfigurationService::RATE_FIELDS,
                'suggested_rates' => [
                    'year' => 2026,
                    'avs_ppm' => 53000,
                    'ac_ppm' => 11000,
                    'amat_ppm' => 290,
                    'laa_reduit_ppm' => 5300,
                    'laa_plein_ppm' => 9600,
                    'lpp_ppm' => 70000,
                    'emp_avs_ppm' => 53000,
                    'emp_ac_ppm' => 11000,
                    'emp_amat_ppm' => 290,
                    'emp_af_ppm' => 22200,
                    'emp_laa_reduit_ppm' => 5300,
                    'emp_laa_plein_ppm' => 9600,
                    'emp_frais_ppm' => 0,
                    'emp_cpe_ppm' => 700,
                    'emp_lfp_ppm' => 820,
                    'emp_lpp_ppm' => 80000,
                    'source' => 'Lasso — TAUX_DEFAUT / OCAS Genève 2026',
                    'verified_on' => '2026-07-25',
                ],
            ],
            'treasury' => [
                'accounts' => $this->treasuryAccounts($organisationId, $dossierId),
                'ledger_accounts' => $this->accounts($organisationId, $dossierId),
            ],
            'accounting_setup' => [
                'journals' => $this->journals($organisationId, $dossierId),
                'journal_types' => AccountingSetupService::JOURNAL_TYPES,
                'exercises' => $this->exercises($organisationId, $dossierId),
                'periods' => $this->periods($organisationId, $dossierId),
            ],
            'access' => [
                'users' => $this->users($organisationId, $dossierId),
                'roles' => $this->roles(),
            ],
        ];
    }

    /** @param array<string,mixed> $data */
    public function createContact(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): int {
        if ($data['id'] > 0) {
            $this->contacts->update(
                $organisationId,
                $dossierId,
                $data['id'],
                $data['version'],
                [
                    'type_personne' => $data['type'],
                    'raison_sociale' => $data['company'],
                    'prenom' => $data['first_name'],
                    'nom' => $data['last_name'],
                    'email' => $data['email'],
                    'telephone' => $data['phone'],
                    'iban_paiement' => $data['payment_iban'],
                    'bic_paiement' => $data['payment_bic'],
                    'langue' => $data['language'],
                ],
                $data['roles'],
                [
                    'ligne1' => $data['address_line1'],
                    'ligne2' => $data['address_line2'],
                    'code_postal' => $data['postal_code'],
                    'localite' => $data['city'],
                    'pays' => $data['country'],
                ],
                $actorId
            );
            return $data['id'];
        }
        return $this->contacts->create(
            $organisationId,
            $dossierId,
            [
                'type_personne' => $data['type'],
                'raison_sociale' => $data['company'],
                'prenom' => $data['first_name'],
                'nom' => $data['last_name'],
                'email' => $data['email'],
                'telephone' => $data['phone'],
                'iban_paiement' => $data['payment_iban'],
                'bic_paiement' => $data['payment_bic'],
                'langue' => $data['language'],
            ],
            $data['roles'],
            [
                'ligne1' => $data['address_line1'],
                'ligne2' => $data['address_line2'],
                'code_postal' => $data['postal_code'],
                'localite' => $data['city'],
                'pays' => $data['country'],
                'type' => 'facturation',
            ],
            $actorId
        );
    }

    /** @param array<string,mixed> $data */
    public function saveVatCode(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): int {
        $payload = [
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
            'code' => $data['code'],
            'libelle' => $data['label'],
            'traitement' => $data['treatment'],
            'nature' => $data['nature'],
            'taux_legal_id' => $data['legal_rate_id'],
            'droit_deduction' => $data['deduction_right'],
            'deduction_defaut_bp' => $data['default_deduction_bp'],
            'chiffre_afc' => $data['afc_box'],
            'compte_tva_id' => $data['account_id'],
            'date_debut' => $data['valid_from'],
            'date_fin' => $data['valid_until'],
            'actif' => $data['active'],
        ];
        if ($data['id'] > 0) {
            $this->vat->updateCode(
                $organisationId,
                $dossierId,
                $data['id'],
                $payload,
                $actorId
            );
            return $data['id'];
        }
        return $this->vat->addCode($payload, $actorId);
    }

    public function deleteVatCode(
        int $organisationId,
        int $dossierId,
        int $codeId,
        int $actorId,
    ): void {
        $this->vat->deleteCode(
            $organisationId,
            $dossierId,
            $codeId,
            $actorId
        );
    }

    /** @param array<string,mixed> $data */
    public function savePayrollRates(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): int {
        return $this->payroll->saveRates(
            $organisationId,
            $dossierId,
            $data['year'],
            $data,
            $actorId
        );
    }

    /** @param array<string,mixed> $data */
    public function saveTreasuryAccount(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): int {
        $payload = [
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
            'compte_comptable_id' => $data['ledger_account_id'],
            'libelle' => $data['label'],
            'type' => $data['type'],
            'iban' => $data['iban'],
            'bic' => $data['bic'],
            'monnaie' => $data['currency'],
            'multiplicateur_comptable' => $data['accounting_multiplier'],
            'actif' => $data['active'],
        ];
        if ($data['id'] > 0) {
            $this->treasury->update(
                $organisationId,
                $dossierId,
                $data['id'],
                $data['version'],
                $payload,
                $actorId
            );
            return $data['id'];
        }
        return $this->treasury->create($payload, $actorId);
    }

    /** @param array<string,mixed> $data */
    public function saveJournal(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): int {
        if ($data['id'] > 0) {
            $this->accountingSetup->updateJournal(
                $organisationId,
                $dossierId,
                $data['id'],
                $data['version'],
                $data['code'],
                $data['label'],
                $data['type'],
                $data['active'],
                $actorId
            );
            return $data['id'];
        }
        return $this->accountingSetup->createJournal(
            $organisationId,
            $dossierId,
            $data['code'],
            $data['label'],
            $data['type'],
            $actorId,
            $data['active']
        );
    }

    /** @param array<string,mixed> $data */
    public function saveExercise(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): int {
        if ($data['id'] > 0) {
            $this->accountingSetup->setExerciseStatus(
                $organisationId,
                $dossierId,
                $data['id'],
                $data['version'],
                $data['status'],
                $actorId
            );
            return $data['id'];
        }
        return $this->accountingSetup->createExercise(
            $organisationId,
            $dossierId,
            $data['label'],
            $data['start_date'],
            $data['end_date'],
            $actorId
        );
    }

    /** @param array<string,mixed> $data */
    public function savePeriod(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): int {
        if ($data['id'] > 0) {
            $this->accountingSetup->setPeriodStatus(
                $organisationId,
                $dossierId,
                $data['id'],
                $data['version'],
                $data['status'],
                $actorId
            );
            return $data['id'];
        }
        return $this->accountingSetup->createPeriod(
            $organisationId,
            $dossierId,
            $data['exercise_id'],
            $data['label'],
            $data['start_date'],
            $data['end_date'],
            $actorId
        );
    }

    /** @param array{user_id:int,role_ids:list<int>} $data */
    public function saveDossierAccess(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): int {
        if ($data['user_id'] === $actorId) {
            throw new ConfigurationException(
                'Votre propre accès ne peut pas être modifié depuis ce dossier.'
            );
        }
        $user = $this->pdo->prepare(
            'SELECT 1 FROM utilisateurs u
             WHERE u.id = ? AND u.actif = 1
               AND (
                   EXISTS (
                       SELECT 1 FROM utilisateur_roles_organisation uro
                       WHERE uro.utilisateur_id = u.id
                         AND uro.organisation_id = ?
                   )
                   OR EXISTS (
                       SELECT 1 FROM utilisateur_roles_dossier urd
                       WHERE urd.utilisateur_id = u.id
                         AND urd.dossier_id = ?
                   )
               )'
        );
        $user->execute([$data['user_id'], $organisationId, $dossierId]);
        if ($user->fetchColumn() === false) {
            throw new ConfigurationException(
                'Utilisateur absent du périmètre administrable.'
            );
        }
        if ($data['role_ids'] !== []) {
            $placeholders = implode(',', array_fill(0, count($data['role_ids']), '?'));
            $roles = $this->pdo->prepare(
                "SELECT COUNT(*) FROM roles WHERE id IN ({$placeholders})"
            );
            $roles->execute($data['role_ids']);
            if ((int) $roles->fetchColumn() !== count($data['role_ids'])) {
                throw new ConfigurationException('Un rôle sélectionné est invalide.');
            }
        }
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $data,
            $actorId
        ): void {
            $this->pdo->prepare(
                'DELETE FROM utilisateur_roles_dossier
                 WHERE utilisateur_id = ? AND dossier_id = ?'
            )->execute([$data['user_id'], $dossierId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO utilisateur_roles_dossier
                 (utilisateur_id, dossier_id, role_id) VALUES (?, ?, ?)'
            );
            foreach ($data['role_ids'] as $roleId) {
                $insert->execute([$data['user_id'], $dossierId, $roleId]);
            }
            $this->audit->log(
                'configuration.acces_dossier_modifie',
                $actorId,
                $organisationId,
                $dossierId,
                'utilisateur',
                (string) $data['user_id'],
                ['role_ids' => $data['role_ids']]
            );
        });
        return $data['user_id'];
    }

    /** @return list<array<string,mixed>> */
    private function vatCodes(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, r.libelle AS taux_libelle, r.taux_bp,
                    a.numero AS compte_numero, a.libelle AS compte_libelle
             FROM tva_codes c
             LEFT JOIN tva_taux_legaux r ON r.id = c.taux_legal_id
             LEFT JOIN comptes a ON a.id = c.compte_tva_id
             WHERE c.organisation_id = ? AND c.dossier_id = ?
             ORDER BY c.actif DESC, c.code, c.date_debut DESC'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'label' => (string) $row['libelle'],
            'treatment' => (string) $row['traitement'],
            'nature' => (string) $row['nature'],
            'legal_rate_id' => $row['taux_legal_id'] === null
                ? null
                : (int) $row['taux_legal_id'],
            'legal_rate_label' => (string) ($row['taux_libelle'] ?? ''),
            'rate_bp' => $row['taux_bp'] === null ? null : (int) $row['taux_bp'],
            'deduction_right' => (int) $row['droit_deduction'] === 1,
            'default_deduction_bp' => (int) $row['deduction_defaut_bp'],
            'afc_box' => (string) $row['chiffre_afc'],
            'account_id' => $row['compte_tva_id'] === null
                ? null
                : (int) $row['compte_tva_id'],
            'account' => trim(
                (string) ($row['compte_numero'] ?? '') . ' '
                . (string) ($row['compte_libelle'] ?? '')
            ),
            'valid_from' => (string) $row['date_debut'],
            'valid_until' => $row['date_fin'] === null
                ? null
                : (string) $row['date_fin'],
            'active' => (int) $row['actif'] === 1,
            'used' => $this->vatCodeUsed(
                $organisationId,
                $dossierId,
                (int) $row['id']
            ),
        ], $stmt->fetchAll());
    }

    private function vatCodeUsed(
        int $organisationId,
        int $dossierId,
        int $codeId,
    ): bool {
        $stmt = $this->pdo->prepare(
            'SELECT EXISTS (
                SELECT 1
                FROM lignes_document l
                JOIN documents_financiers d ON d.id = l.document_id
                WHERE l.code_tva_id = ?
                  AND d.organisation_id = ? AND d.dossier_id = ?
             ) OR EXISTS (
                SELECT 1 FROM tva_lignes
                WHERE code_tva_id = ?
                  AND organisation_id = ? AND dossier_id = ?
             ) OR EXISTS (
                SELECT 1 FROM modeles_factures_recurrentes m
                WHERE m.organisation_id = ? AND m.dossier_id = ?
                  AND EXISTS (
                      SELECT 1 FROM json_each(m.lignes_json) j
                      WHERE CAST(json_extract(j.value, \'$.code_tva_id\') AS INTEGER) = ?
                  )
             ) OR EXISTS (
                SELECT 1 FROM modeles_depenses_recurrentes m
                WHERE m.organisation_id = ? AND m.dossier_id = ?
                  AND EXISTS (
                      SELECT 1 FROM json_each(m.lignes_json) j
                      WHERE CAST(json_extract(j.value, \'$.code_tva_id\') AS INTEGER) = ?
                  )
             )'
        );
        $stmt->execute([
            $codeId, $organisationId, $dossierId,
            $codeId, $organisationId, $dossierId,
            $organisationId, $dossierId, $codeId,
            $organisationId, $dossierId, $codeId,
        ]);
        return (int) $stmt->fetchColumn() === 1;
    }

    /** @return list<array<string,mixed>> */
    private function legalVatRates(): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'category' => (string) $row['categorie'],
            'label' => (string) $row['libelle'],
            'rate_bp' => (int) $row['taux_bp'],
            'valid_from' => (string) $row['date_debut'],
            'valid_until' => $row['date_fin'] === null
                ? null
                : (string) $row['date_fin'],
            'source_url' => (string) $row['source_url'],
            'verified_on' => (string) $row['verifie_le'],
        ], $this->pdo->query(
            'SELECT * FROM tva_taux_legaux
             ORDER BY date_debut DESC, taux_bp DESC'
        )->fetchAll());
    }

    /** @return list<array{id:int,number:string,label:string}> */
    private function accounts(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, numero, libelle FROM comptes
             WHERE organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND imputable = 1
             ORDER BY numero'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'number' => (string) $row['numero'],
            'label' => (string) $row['libelle'],
        ], $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    private function payrollRates(int $organisationId, int $dossierId): array
    {
        $columns = implode(', ', PayrollConfigurationService::RATE_FIELDS);
        $stmt = $this->pdo->prepare(
            "SELECT id, annee, {$columns}, source, verifie_le, cree_le,
                    modifie_le, version
             FROM taux_salaires_annuels
             WHERE organisation_id = ? AND dossier_id = ?
             ORDER BY annee DESC"
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(static function (array $row): array {
            $result = [
                'id' => (int) $row['id'],
                'year' => (int) $row['annee'],
                'source' => (string) $row['source'],
                'verified_on' => (string) $row['verifie_le'],
                'created_at' => (string) $row['cree_le'],
                'updated_at' => $row['modifie_le'] === null
                    ? null
                    : (string) $row['modifie_le'],
                'version' => (int) $row['version'],
            ];
            foreach (PayrollConfigurationService::RATE_FIELDS as $field) {
                $result[$field] = (int) $row[$field];
            }
            return $result;
        }, $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    private function treasuryAccounts(int $organisationId, int $dossierId): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'ledger_account_id' => (int) $row['compte_comptable_id'],
            'ledger_account_number' => (string) $row['numero_comptable'],
            'label' => (string) $row['libelle'],
            'type' => (string) $row['type'],
            'iban' => (string) $row['iban'],
            'bic' => (string) $row['bic'],
            'currency' => (string) $row['monnaie'],
            'accounting_multiplier' => (int) $row['multiplicateur_comptable'],
            'active' => (int) $row['actif'] === 1,
            'version' => (int) $row['version'],
        ], $this->treasury->list($organisationId, $dossierId));
    }

    /** @return list<array<string,mixed>> */
    private function journals(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code, libelle, type, actif, version
             FROM journaux
             WHERE organisation_id = ? AND dossier_id = ?
             ORDER BY actif DESC, code'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'label' => (string) $row['libelle'],
            'type' => (string) $row['type'],
            'active' => (int) $row['actif'] === 1,
            'version' => (int) $row['version'],
        ], $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    private function exercises(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT x.id, x.libelle, x.date_debut, x.date_fin, x.statut,
                    x.version
             FROM exercices x
             JOIN dossiers d ON d.id = x.dossier_id
             WHERE x.dossier_id = ? AND d.organisation_id = ?
             ORDER BY x.date_debut DESC'
        );
        $stmt->execute([$dossierId, $organisationId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'label' => (string) $row['libelle'],
            'start_date' => (string) $row['date_debut'],
            'end_date' => (string) $row['date_fin'],
            'status' => (string) $row['statut'],
            'version' => (int) $row['version'],
        ], $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    private function periods(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.exercice_id, x.libelle AS exercice,
                    p.libelle, p.date_debut, p.date_fin, p.statut, p.version
             FROM periodes p
             JOIN exercices x ON x.id = p.exercice_id
             WHERE p.organisation_id = ? AND p.dossier_id = ?
             ORDER BY p.date_debut DESC'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'exercise_id' => (int) $row['exercice_id'],
            'exercise' => (string) $row['exercice'],
            'label' => (string) $row['libelle'],
            'start_date' => (string) $row['date_debut'],
            'end_date' => (string) $row['date_fin'],
            'status' => (string) $row['statut'],
            'version' => (int) $row['version'],
        ], $stmt->fetchAll());
    }

    /** @return list<array{id:int,code:string,label:string}> */
    private function roles(): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'label' => (string) $row['libelle'],
        ], $this->pdo->query(
            'SELECT id, code, libelle FROM roles ORDER BY id'
        )->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    private function users(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT u.id, u.email, u.prenom, u.nom, u.actif,
                    (
                        SELECT GROUP_CONCAT(urd.role_id)
                        FROM utilisateur_roles_dossier urd
                        WHERE urd.utilisateur_id = u.id
                          AND urd.dossier_id = :dossier
                    ) AS dossier_role_ids,
                    (
                        SELECT GROUP_CONCAT(r.libelle, ', ')
                        FROM utilisateur_roles_organisation uro
                        JOIN roles r ON r.id = uro.role_id
                        WHERE uro.utilisateur_id = u.id
                          AND uro.organisation_id = :organisation
                    ) AS organisation_roles,
                    (
                        SELECT GROUP_CONCAT(r.libelle, ', ')
                        FROM utilisateur_roles_installation uri
                        JOIN roles r ON r.id = uri.role_id
                        WHERE uri.utilisateur_id = u.id
                    ) AS installation_roles
             FROM utilisateurs u
             WHERE EXISTS (
                 SELECT 1 FROM utilisateur_roles_organisation uro
                 WHERE uro.utilisateur_id = u.id
                   AND uro.organisation_id = :organisation
             )
                OR EXISTS (
                 SELECT 1 FROM utilisateur_roles_dossier urd
                 WHERE urd.utilisateur_id = u.id
                   AND urd.dossier_id = :dossier
             )
             ORDER BY u.actif DESC, u.email"
        );
        $stmt->execute([
            'organisation' => $organisationId,
            'dossier' => $dossierId,
        ]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'name' => trim(
                (string) $row['prenom'] . ' ' . (string) $row['nom']
            ),
            'active' => (int) $row['actif'] === 1,
            'dossier_role_ids' => $row['dossier_role_ids'] === null
                ? []
                : array_map('intval', explode(',', (string) $row['dossier_role_ids'])),
            'inherited_roles' => array_values(array_filter([
                (string) ($row['installation_roles'] ?? ''),
                (string) ($row['organisation_roles'] ?? ''),
            ])),
        ], $stmt->fetchAll());
    }

    /** @param callable():void $callback */
    private function transaction(callable $callback): void
    {
        $this->pdo->beginTransaction();
        try {
            $callback();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
