<?php
declare(strict_types=1);

namespace Compta\Modules\Configuration\Application;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Compta\AccountingSetupService;
use Compta\Modules\Facturation\ContactService;
use Compta\Modules\Devises\ExchangeRateService;
use Compta\Modules\Salaires\PayrollConfigurationService;
use Compta\Modules\Tresorerie\TreasuryAccountService;
use Compta\Modules\Tva\VatConfigurationService;
use PDO;
use Throwable;

final class ManagedReferencesService
{
    private ExchangeRateService $exchange;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ContactService $contacts,
        private readonly VatConfigurationService $vat,
        private readonly PayrollConfigurationService $payroll,
        private readonly TreasuryAccountService $treasury,
        private readonly AccountingSetupService $accountingSetup,
        private readonly AuditLogger $audit,
    ) {
        $this->exchange = new ExchangeRateService($pdo, $audit);
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
                'suggested_rates' => [],
                ...$this->payrollSettings($organisationId, $dossierId),
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
            'currencies' => $this->exchange->configuration(
                $organisationId,
                $dossierId
            ) + ['accounts' => $this->accounts($organisationId, $dossierId)],
        ];
    }

    public function saveCurrency(
        int $organisationId,
        int $dossierId,
        string $currency,
        bool $active,
        int $actorId,
    ): void {
        $this->exchange->saveCurrency(
            $organisationId,
            $dossierId,
            $currency,
            $active,
            $actorId
        );
    }

    /** @param array<string,mixed> $data */
    public function saveExchangeRate(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): int {
        return $this->exchange->saveRate(
            $organisationId,
            $dossierId,
            $data,
            $actorId
        );
    }

    /** @param array<string,mixed> $data */
    public function saveExchangeMapping(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): void {
        $this->exchange->saveMapping(
            $organisationId,
            $dossierId,
            $data,
            $actorId
        );
    }

    /** @param array<string,mixed> $data */
    public function createContact(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): int {
        return $this->transaction(function () use (
            $organisationId, $dossierId, $data, $actorId
        ): int {
            $identity = [
                'type_personne' => $data['type'],
                'raison_sociale' => $data['company'],
                'prenom' => $data['first_name'],
                'nom' => $data['last_name'],
                'email' => $data['email'],
                'telephone' => $data['phone'],
                'iban_paiement' => $data['payment_iban'],
                'bic_paiement' => $data['payment_bic'],
                'langue' => $data['language'],
            ];
            $address = [
                'ligne1' => $data['address_line1'],
                'ligne2' => $data['address_line2'],
                'code_postal' => $data['postal_code'],
                'localite' => $data['city'],
                'pays' => $data['country'],
            ];
            if ($data['id'] > 0) {
                $contactId = (int) $data['id'];
                $this->contacts->update(
                    $organisationId,
                    $dossierId,
                    $contactId,
                    $data['version'],
                    $identity,
                    $data['roles'],
                    $address,
                    $actorId
                );
            } else {
                $contactId = $this->contacts->create(
                    $organisationId,
                    $dossierId,
                    $identity,
                    $data['roles'],
                    $address + ['type' => 'facturation'],
                    $actorId
                );
            }
            $this->payroll->syncContactEmployee(
                $organisationId,
                $dossierId,
                $contactId,
                $identity + [
                    'rue' => $address['ligne1'],
                    'npa' => $address['code_postal'],
                    'localite' => $address['localite'],
                ],
                in_array('employe', $data['roles'], true),
                $actorId
            );
            return $contactId;
        });
    }

    public function deleteContact(
        int $organisationId,
        int $dossierId,
        int $contactId,
        int $expectedVersion,
        int $actorId,
    ): void {
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $contactId,
            $expectedVersion,
            $actorId
        ): void {
            $contact = $this->pdo->prepare(
                'SELECT type_personne, raison_sociale, prenom, nom, version
                 FROM contacts
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND actif = 1'
            );
            $contact->execute([$contactId, $organisationId, $dossierId]);
            $row = $contact->fetch();
            if ($row === false) {
                throw new ConfigurationException('Contact absent du dossier.');
            }
            if ((int) $row['version'] !== $expectedVersion) {
                throw new ConfigurationException(
                    'Le contact a été modifié par une autre session. Rechargez la page.'
                );
            }

            $dependencies = [
                'documents_financiers' => 'facture, achat ou vente',
                'paiements' => 'paiement',
                'modeles_factures_recurrentes' => 'facture récurrente',
                'modeles_depenses_recurrentes' => 'dépense récurrente',
                'ordres_paiement_sortants' => 'ordre de paiement',
            ];
            $blockers = [];
            foreach ($dependencies as $table => $label) {
                $count = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM {$table}
                     WHERE organisation_id = ? AND dossier_id = ? AND contact_id = ?"
                );
                $count->execute([$organisationId, $dossierId, $contactId]);
                $total = (int) $count->fetchColumn();
                if ($total > 0) {
                    $blockers[] = "{$total} {$label}" . ($total > 1 ? 's' : '');
                }
            }

            $employee = $this->pdo->prepare(
                'SELECT id FROM employes
                 WHERE organisation_id = ? AND dossier_id = ? AND contact_id = ?'
            );
            $employee->execute([$organisationId, $dossierId, $contactId]);
            $employeeId = $employee->fetchColumn();
            if ($employeeId !== false) {
                foreach ([
                    'fiches_salaires' => 'fiche de salaire',
                    'certificats_salaires' => 'certificat de salaire',
                    'paiements_salaires' => 'paiement salarial',
                ] as $table => $label) {
                    $count = $this->pdo->prepare(
                        "SELECT COUNT(*) FROM {$table}
                         WHERE organisation_id = ? AND dossier_id = ?
                           AND employe_id = ?"
                    );
                    $count->execute([
                        $organisationId,
                        $dossierId,
                        (int) $employeeId,
                    ]);
                    $total = (int) $count->fetchColumn();
                    if ($total > 0) {
                        $blockers[] = "{$total} {$label}" . ($total > 1 ? 's' : '');
                    }
                }
            }
            if ($blockers !== []) {
                throw new ConfigurationException(
                    'Suppression impossible : ' . implode(', ', $blockers) . ' lié(s).'
                );
            }

            if ($employeeId !== false) {
                $this->pdo->prepare(
                    'DELETE FROM contrats_salariaux
                     WHERE organisation_id = ? AND dossier_id = ? AND employe_id = ?'
                )->execute([
                    $organisationId,
                    $dossierId,
                    (int) $employeeId,
                ]);
                $this->pdo->prepare(
                    'DELETE FROM employes
                     WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
                )->execute([
                    (int) $employeeId,
                    $organisationId,
                    $dossierId,
                ]);
            }
            $delete = $this->pdo->prepare(
                'DELETE FROM contacts
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND version = ?'
            );
            $delete->execute([
                $contactId,
                $organisationId,
                $dossierId,
                $expectedVersion,
            ]);
            if ($delete->rowCount() !== 1) {
                throw new ConfigurationException(
                    'Le contact a été modifié par une autre session.'
                );
            }
            $name = trim(
                (string) $row['raison_sociale'] . ' '
                . (string) $row['prenom'] . ' '
                . (string) $row['nom']
            );
            $this->audit->log(
                'configuration.contact_supprime',
                $actorId,
                $organisationId,
                $dossierId,
                'contact',
                (string) $contactId,
                ['nom' => $name]
            );
        });
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

    /** @return array{codes:int,regimes:int} */
    public function clearVatConfiguration(
        int $organisationId,
        int $dossierId,
        int $actorId,
    ): array {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $actorId
        ): array {
            $periods = $this->pdo->prepare(
                'SELECT COUNT(*) FROM tva_periodes
                 WHERE organisation_id = ? AND dossier_id = ?'
            );
            $periods->execute([$organisationId, $dossierId]);
            if ((int) $periods->fetchColumn() > 0) {
                throw new ConfigurationException(
                    'La configuration TVA possède des périodes historiques. '
                    . 'Elle doit être conservée pour la traçabilité.'
                );
            }

            $codes = $this->pdo->prepare(
                'SELECT id FROM tva_codes
                 WHERE organisation_id = ? AND dossier_id = ?
                 ORDER BY id'
            );
            $codes->execute([$organisationId, $dossierId]);
            $codeIds = array_map('intval', $codes->fetchAll(PDO::FETCH_COLUMN));
            foreach ($codeIds as $codeId) {
                $this->vat->deleteCode(
                    $organisationId,
                    $dossierId,
                    $codeId,
                    $actorId
                );
            }

            $regimes = $this->pdo->prepare(
                'DELETE FROM tva_regimes
                 WHERE organisation_id = ? AND dossier_id = ?'
            );
            $regimes->execute([$organisationId, $dossierId]);
            $regimeCount = $regimes->rowCount();
            $this->audit->log(
                'configuration.tva_effacee',
                $actorId,
                $organisationId,
                $dossierId,
                'configuration_tva',
                (string) $dossierId,
                [
                    'codes' => count($codeIds),
                    'regimes' => $regimeCount,
                ]
            );
            return [
                'codes' => count($codeIds),
                'regimes' => $regimeCount,
            ];
        });
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

    public function savePayrollEmployerSettings(
        int $organisationId,
        int $dossierId,
        int $weeklyHoursMilli,
        int $actorId,
    ): int {
        return $this->payroll->saveEmployer(
            $organisationId,
            $dossierId,
            ['heures_hebdo_milli' => $weeklyHoursMilli],
            $actorId
        );
    }

    /** @param array<string,int> $mapping */
    public function savePayrollMappingSettings(
        int $organisationId,
        int $dossierId,
        array $mapping,
        int $actorId,
    ): void {
        $this->payroll->saveMapping(
            $organisationId,
            $dossierId,
            $mapping,
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

    /** @return array<string,mixed> */
    private function payrollSettings(int $organisationId, int $dossierId): array
    {
        $configured = true;
        try {
            $employer = $this->payroll->employer($organisationId, $dossierId);
        } catch (\Compta\Modules\Salaires\PayrollException) {
            $configured = false;
            $employer = $this->payroll->employerSuggestion(
                $organisationId,
                $dossierId
            );
        }
        try {
            $mapping = $this->payroll->mapping($organisationId, $dossierId);
        } catch (\Compta\Modules\Salaires\PayrollException) {
            $mapping = null;
        }
        $accounts = $this->pdo->prepare(
            'SELECT id, numero, libelle FROM comptes
             WHERE organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND imputable = 1
             ORDER BY length(numero), numero'
        );
        $accounts->execute([$organisationId, $dossierId]);
        return [
            'employer' => [
                'name' => (string) $employer['nom'],
                'address' => (string) $employer['rue'],
                'postal_code' => (string) $employer['npa'],
                'city' => (string) $employer['localite'],
                'country' => (string) $employer['pays'],
                'phone' => (string) $employer['telephone'],
                'email' => (string) $employer['email'],
                'weekly_hours_milli' => (int) $employer['heures_hebdo_milli'],
                'configured' => $configured,
                'source' => 'Configuration → Entité',
            ],
            'mapping' => $mapping === null
                ? null
                : array_combine(
                    PayrollConfigurationService::MAPPING_FIELDS,
                    array_map(
                        static fn (string $field): int => (int) $mapping[$field],
                        PayrollConfigurationService::MAPPING_FIELDS
                    )
                ),
            'mapping_fields' => PayrollConfigurationService::MAPPING_FIELDS,
            'accounts' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'number' => (string) $row['numero'],
                'label' => (string) $row['libelle'],
            ], $accounts->fetchAll()),
        ];
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

    private function transaction(callable $callback): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
