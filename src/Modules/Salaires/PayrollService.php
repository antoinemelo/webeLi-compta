<?php
declare(strict_types=1);

namespace Compta\Modules\Salaires;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Compta\EntryService;
use PDO;
use Throwable;

final class PayrollService
{
    private PayrollConfigurationService $configuration;
    private PayrollCalculator $calculator;
    private bool $transactionActive = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly EntryService $entries,
    ) {
        $this->configuration = new PayrollConfigurationService($pdo, $audit);
        $this->calculator = new PayrollCalculator();
    }

    /**
     * @param list<array{
     *   libelle:string,unite_libelle:string,heures_unite_milli:int,
     *   quantite_milli:int,taux_horaire_centimes:int
     * }> $lines
     */
    public function createDraft(
        int $organisationId,
        int $dossierId,
        int $employeeId,
        int $year,
        int $month,
        array $lines,
        ?int $vacationPpm = null,
        ?int $sourceTaxPpm = null,
        ?int $actorId = null,
    ): int {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $employeeId,
            $year,
            $month,
            $lines,
            $vacationPpm,
            $sourceTaxPpm,
            $actorId
        ): int {
            $employee = $this->configuration->employee(
                $organisationId,
                $dossierId,
                $employeeId
            );
            $employer = $this->configuration->employer($organisationId, $dossierId);
            $rates = $this->configuration->rates(
                $organisationId,
                $dossierId,
                $year
            );
            [$normalizedLines, $hoursMilli, $workCents] = $this->calculateLines($lines);
            $effective = $this->calculator->effectiveAccidentRates(
                $rates,
                $hoursMilli / 1000,
                $year,
                $month
            );
            $rateSnapshot = $this->rateSnapshot($rates) + $effective;
            $employeeCalculation = [
                'supplement_vacances_ppm' => $vacationPpm
                    ?? (int) $employee['supplement_vacances_ppm'],
                'procedure' => (string) $employee['procedure'],
                'impot_source_ppm' => $sourceTaxPpm
                    ?? (int) $employee['impot_source_ppm'],
            ];
            $calculation = $this->calculator->calculate(
                $employeeCalculation,
                $workCents,
                $rateSnapshot
            );
            $employeeSnapshot = $employee;
            $employeeSnapshot['supplement_vacances_ppm'] =
                $employeeCalculation['supplement_vacances_ppm'];
            $employeeSnapshot['impot_source_ppm'] =
                $employeeCalculation['impot_source_ppm'];
            $stmt = $this->pdo->prepare(
                'INSERT INTO fiches_salaires
                 (organisation_id, dossier_id, employe_id, annee, mois,
                  employe_snapshot_json, employeur_snapshot_json,
                  taux_snapshot_json, nombre_heures_milli,
                  salaire_travail_centimes, supplement_centimes, brut_centimes,
                  ded_avs_centimes, ded_ac_centimes, ded_amat_centimes,
                  ded_laa_centimes, ded_lpp_centimes,
                  ded_impot_source_centimes, total_deductions_centimes,
                  net_centimes, emp_avs_centimes, emp_ac_centimes,
                  emp_amat_centimes, emp_af_centimes, emp_laa_centimes,
                  emp_frais_centimes, emp_cpe_centimes, emp_lfp_centimes,
                  emp_lpp_centimes, total_charges_employeur_centimes,
                  cout_total_centimes, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                         ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId, $dossierId, $employeeId, $year, $month,
                $this->json($employeeSnapshot), $this->json($employer),
                $this->json($rateSnapshot + [
                    'impot_source_ppm' => $employeeCalculation['impot_source_ppm'],
                    'supplement_vacances_ppm' =>
                        $employeeCalculation['supplement_vacances_ppm'],
                ]),
                $hoursMilli,
                $calculation['salaire_travail_centimes'],
                $calculation['supplement_centimes'],
                $calculation['brut_centimes'],
                $calculation['ded_avs_centimes'],
                $calculation['ded_ac_centimes'],
                $calculation['ded_amat_centimes'],
                $calculation['ded_laa_centimes'],
                $calculation['ded_lpp_centimes'],
                $calculation['ded_impot_source_centimes'],
                $calculation['total_deductions_centimes'],
                $calculation['net_centimes'],
                $calculation['emp_avs_centimes'],
                $calculation['emp_ac_centimes'],
                $calculation['emp_amat_centimes'],
                $calculation['emp_af_centimes'],
                $calculation['emp_laa_centimes'],
                $calculation['emp_frais_centimes'],
                $calculation['emp_cpe_centimes'],
                $calculation['emp_lfp_centimes'],
                $calculation['emp_lpp_centimes'],
                $calculation['total_charges_employeur_centimes'],
                $calculation['cout_total_centimes'],
                $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->insertLines($id, $normalizedLines);
            $this->insertComponents($id, $calculation, $rateSnapshot, $employeeCalculation);
            $this->audit->log(
                'salaires.fiche_brouillon_creee',
                $actorId,
                $organisationId,
                $dossierId,
                'fiche_salaire',
                (string) $id,
                ['annee' => $year, 'mois' => $month]
            );
            return $id;
        }, true);
    }

    public function validate(
        int $organisationId,
        int $dossierId,
        int $payrollId,
        int $expectedVersion,
        ?int $actorId = null,
    ): void {
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $payrollId,
            $expectedVersion,
            $actorId
        ): void {
            $payroll = $this->payroll($organisationId, $dossierId, $payrollId, true);
            if (
                $payroll['statut'] !== 'brouillon'
                || (int) $payroll['version'] !== $expectedVersion
            ) {
                throw new PayrollException('Brouillon absent ou modifié simultanément.');
            }
            $mapping = $this->configuration->mapping($organisationId, $dossierId);
            $update = $this->pdo->prepare(
                "UPDATE fiches_salaires SET statut = 'validee',
                    validee_le = datetime('now'), version = version + 1
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND statut = 'brouillon' AND version = ?"
            );
            $update->execute([
                $payrollId, $organisationId, $dossierId, $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new PayrollException('Conflit de validation de la fiche.');
            }
            foreach ($this->liabilities($payroll, $mapping) as $type => $liability) {
                if ($liability['amount'] <= 0) {
                    continue;
                }
                $this->pdo->prepare(
                    'INSERT INTO dettes_salaires
                     (organisation_id, dossier_id, fiche_salaire_id, type,
                      montant_centimes, compte_dette_id)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([
                    $organisationId, $dossierId, $payrollId, $type,
                    $liability['amount'], $liability['account'],
                ]);
            }
            $this->audit->log(
                'salaires.fiche_validee',
                $actorId,
                $organisationId,
                $dossierId,
                'fiche_salaire',
                (string) $payrollId
            );
        }, true);
    }

    public function post(
        int $organisationId,
        int $dossierId,
        int $payrollId,
        int $exerciseId,
        int $journalId,
        string $accountingDate,
        ?int $actorId = null,
    ): int {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $payrollId,
            $exerciseId,
            $journalId,
            $accountingDate,
            $actorId
        ): int {
            $payroll = $this->payroll($organisationId, $dossierId, $payrollId, true);
            if ($payroll['ecriture_id'] !== null) {
                return (int) $payroll['ecriture_id'];
            }
            if ($payroll['statut'] !== 'validee') {
                throw new PayrollException('La fiche doit être validée avant comptabilisation.');
            }
            $mapping = $this->configuration->mapping($organisationId, $dossierId);
            $lines = [];
            $this->debit(
                $lines,
                (int) $mapping['charge_salaires_id'],
                (int) $payroll['brut_centimes'],
                'Salaire brut'
            );
            foreach ([
                'emp_avs_centimes' => 'AVS employeur',
                'emp_ac_centimes' => 'AC employeur',
                'emp_amat_centimes' => 'A.mat employeur',
                'emp_af_centimes' => 'AF employeur',
                'emp_frais_centimes' => 'Frais sociaux employeur',
                'emp_cpe_centimes' => 'CPE employeur',
                'emp_lfp_centimes' => 'LFP employeur',
            ] as $field => $label) {
                $this->debit(
                    $lines,
                    (int) $mapping['charge_ocas_id'],
                    (int) $payroll[$field],
                    $label
                );
            }
            $this->debit(
                $lines,
                (int) $mapping['charge_laa_id'],
                (int) $payroll['emp_laa_centimes'],
                'LAA employeur'
            );
            $this->debit(
                $lines,
                (int) $mapping['charge_lpp_id'],
                (int) $payroll['emp_lpp_centimes'],
                'LPP employeur'
            );
            foreach ($this->liabilities($payroll, $mapping) as $type => $liability) {
                $this->credit(
                    $lines,
                    $liability['account'],
                    $liability['amount'],
                    match ($type) {
                        'net' => 'Salaire net à payer',
                        'ocas' => 'OCAS à payer',
                        'laa' => 'LAA à payer',
                        'lpp' => 'LPP à payer',
                        'impot_source' => 'Impôt à la source à payer',
                    }
                );
            }
            $entryId = $this->entries->postGenerated([
                'organisation_id' => $organisationId,
                'dossier_id' => $dossierId,
                'exercice_id' => $exerciseId,
                'journal_id' => $journalId,
                'date_comptable' => $accountingDate,
                'libelle' => sprintf(
                    'Paie %02d/%04d',
                    (int) $payroll['mois'],
                    (int) $payroll['annee']
                ),
                'reference' => 'SAL-' . $payrollId,
                'source_type' => 'fiche_salaire',
                'source_id' => (string) $payrollId,
                'source_action' => 'comptabiliser',
                'lignes' => $lines,
            ], 'fiche-salaire:' . $payrollId . ':comptabiliser', $actorId);
            $this->pdo->prepare(
                "UPDATE fiches_salaires SET statut = 'comptabilisee',
                    ecriture_id = ?, comptabilisee_le = datetime('now'),
                    version = version + 1 WHERE id = ?"
            )->execute([$entryId, $payrollId]);
            $this->audit->log(
                'salaires.fiche_comptabilisee',
                $actorId,
                $organisationId,
                $dossierId,
                'fiche_salaire',
                (string) $payrollId,
                ['ecriture_id' => $entryId]
            );
            return $entryId;
        });
    }

    public function cancel(
        int $organisationId,
        int $dossierId,
        int $payrollId,
        string $date,
        ?int $actorId = null,
    ): ?int {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $payrollId,
            $date,
            $actorId
        ): ?int {
            $payroll = $this->payroll($organisationId, $dossierId, $payrollId, true);
            if ($payroll['statut'] === 'payee') {
                throw new PayrollException('Une fiche payée doit d’abord être désallouée.');
            }
            if ($payroll['statut'] === 'annulee') {
                return $payroll['ecriture_annulation_id'] === null
                    ? null
                    : (int) $payroll['ecriture_annulation_id'];
            }
            $reversal = null;
            if ($payroll['ecriture_id'] !== null) {
                $reversal = $this->entries->reverse(
                    $organisationId,
                    $dossierId,
                    (int) $payroll['ecriture_id'],
                    $date,
                    'Annulation fiche salaire ' . $payrollId,
                    $actorId
                );
            }
            $this->pdo->prepare(
                "UPDATE fiches_salaires SET statut = 'annulee',
                    ecriture_annulation_id = ?, annulee_le = datetime('now'),
                    version = version + 1 WHERE id = ?"
            )->execute([$reversal, $payrollId]);
            $this->audit->log(
                'salaires.fiche_annulee',
                $actorId,
                $organisationId,
                $dossierId,
                'fiche_salaire',
                (string) $payrollId,
                ['contrepassation_id' => $reversal]
            );
            return $reversal;
        });
    }

    /** @return array<string,mixed> */
    public function payroll(
        int $organisationId,
        int $dossierId,
        int $payrollId,
        bool $revealPii = false,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT f.*, e.prenom, e.nom, e.email, e.numero_avs
             FROM fiches_salaires f JOIN employes e ON e.id = f.employe_id
             WHERE f.id = ? AND f.organisation_id = ? AND f.dossier_id = ?'
        );
        $stmt->execute([$payrollId, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new PayrollException('Fiche de salaire absente du dossier.');
        }
        if (!$revealPii) {
            $row['numero_avs'] = $this->maskedAvs((string) $row['numero_avs']);
            $row['email'] = '';
            $row['employe_snapshot_json'] = '{}';
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function payrolls(
        int $organisationId,
        int $dossierId,
        bool $revealPii = false,
    ): array {
        $stmt = $this->pdo->prepare(
            "SELECT f.*, e.prenom, e.nom, e.numero_avs,
                    COALESCE((
                      SELECT SUM(d.montant_centimes) FROM dettes_salaires d
                      WHERE d.fiche_salaire_id = f.id
                    ), 0) AS dettes_centimes,
                    COALESCE((
                      SELECT SUM(a.montant_centimes)
                      FROM allocations_salaires a
                      JOIN dettes_salaires d ON d.id = a.dette_salaire_id
                      WHERE d.fiche_salaire_id = f.id AND a.statut = 'valide'
                    ), 0) AS alloue_centimes
             FROM fiches_salaires f JOIN employes e ON e.id = f.employe_id
             WHERE f.organisation_id = ? AND f.dossier_id = ?
             ORDER BY f.annee DESC, f.mois DESC, e.nom, e.prenom"
        );
        $stmt->execute([$organisationId, $dossierId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            if (!$revealPii) {
                $row['numero_avs'] = $this->maskedAvs((string) $row['numero_avs']);
                $row['employe_snapshot_json'] = '{}';
            }
            $row['solde_dettes_centimes'] = max(
                0,
                (int) $row['dettes_centimes'] - (int) $row['alloue_centimes']
            );
        }
        unset($row);
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function lines(int $payrollId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM lignes_prestation
             WHERE fiche_salaire_id = ? ORDER BY ordre'
        );
        $stmt->execute([$payrollId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function components(int $payrollId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM composants_fiche
             WHERE fiche_salaire_id = ? ORDER BY ordre'
        );
        $stmt->execute([$payrollId]);
        return $stmt->fetchAll();
    }

    public function queueEmail(
        int $organisationId,
        int $dossierId,
        int $payrollId,
        string $recipient,
        ?int $actorId = null,
    ): int {
        $payroll = $this->payroll($organisationId, $dossierId, $payrollId, true);
        if (
            !in_array($payroll['statut'], ['validee', 'comptabilisee', 'payee'], true)
            || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new PayrollException('Fiche non validée ou destinataire invalide.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO emails_salaires
             (organisation_id, dossier_id, fiche_salaire_id, destinataire, cree_par)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId, $dossierId, $payrollId, $recipient, $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'salaires.email_mis_en_attente',
            $actorId,
            $organisationId,
            $dossierId,
            'email_salaire',
            (string) $id,
            ['fiche_id' => $payrollId]
        );
        return $id;
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @return array{list<array<string,int|string>>,int,int}
     */
    private function calculateLines(array $lines): array
    {
        if ($lines === []) {
            throw new PayrollException('La fiche exige au moins une prestation.');
        }
        $normalized = [];
        $totalHours = 0;
        $totalAmount = 0;
        foreach (array_values($lines) as $index => $line) {
            $label = trim((string) ($line['libelle'] ?? ''));
            $unitLabel = trim((string) ($line['unite_libelle'] ?? ''));
            $unitHours = (int) ($line['heures_unite_milli'] ?? 0);
            $quantity = (int) ($line['quantite_milli'] ?? 0);
            $hourly = (int) ($line['taux_horaire_centimes'] ?? 0);
            if (
                $label === '' || $unitLabel === '' || $unitHours <= 0
                || $quantity <= 0 || $hourly <= 0
            ) {
                throw new PayrollException('Ligne de prestation invalide.');
            }
            $hours = $this->divideRounded($unitHours * $quantity, 1000);
            $amount = $this->divideRounded($hourly * $hours, 1000);
            $normalized[] = [
                'ordre' => $index + 1,
                'libelle' => $label,
                'unite_libelle' => $unitLabel,
                'heures_unite_milli' => $unitHours,
                'quantite_milli' => $quantity,
                'taux_horaire_centimes' => $hourly,
                'nombre_heures_milli' => $hours,
                'montant_centimes' => $amount,
            ];
            $totalHours += $hours;
            $totalAmount += $amount;
        }
        return [$normalized, $totalHours, $totalAmount];
    }

    /** @param list<array<string,int|string>> $lines */
    private function insertLines(int $payrollId, array $lines): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO lignes_prestation
             (fiche_salaire_id, ordre, libelle, unite_libelle_snapshot,
              heures_unite_milli, quantite_milli, taux_horaire_centimes,
              nombre_heures_milli, montant_centimes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $line) {
            $stmt->execute([
                $payrollId, $line['ordre'], $line['libelle'],
                $line['unite_libelle'], $line['heures_unite_milli'],
                $line['quantite_milli'], $line['taux_horaire_centimes'],
                $line['nombre_heures_milli'], $line['montant_centimes'],
            ]);
        }
    }

    /** @param array<string,int> $calculation @param array<string,int> $rates */
    private function insertComponents(
        int $payrollId,
        array $calculation,
        array $rates,
        array $employee,
    ): void {
        $components = [
            ['travail', 'Salaire du travail', 'gain', 0, 'salaire_travail_centimes'],
            ['vacances', 'Supplément vacances', 'gain',
                $employee['supplement_vacances_ppm'], 'supplement_centimes'],
            ['avs', 'AVS / AI / APG', 'retenue_employe', $rates['avs_ppm'], 'ded_avs_centimes'],
            ['ac', 'Assurance chômage', 'retenue_employe', $rates['ac_ppm'], 'ded_ac_centimes'],
            ['amat', 'Assurance maternité', 'retenue_employe', $rates['amat_ppm'], 'ded_amat_centimes'],
            ['laa', 'LAA', 'retenue_employe', $rates['laa_ppm'], 'ded_laa_centimes'],
            ['lpp', 'LPP', 'retenue_employe', $rates['lpp_ppm'], 'ded_lpp_centimes'],
            ['impot_source', 'Impôt à la source', 'retenue_employe',
                $employee['impot_source_ppm'], 'ded_impot_source_centimes'],
            ['emp_avs', 'AVS employeur', 'charge_employeur', $rates['emp_avs_ppm'], 'emp_avs_centimes'],
            ['emp_ac', 'AC employeur', 'charge_employeur', $rates['emp_ac_ppm'], 'emp_ac_centimes'],
            ['emp_amat', 'A.mat employeur', 'charge_employeur', $rates['emp_amat_ppm'], 'emp_amat_centimes'],
            ['emp_af', 'AF employeur', 'charge_employeur', $rates['emp_af_ppm'], 'emp_af_centimes'],
            ['emp_laa', 'LAA employeur', 'charge_employeur', $rates['emp_laa_ppm'], 'emp_laa_centimes'],
            ['emp_frais', 'Frais sociaux employeur', 'charge_employeur', $rates['emp_frais_ppm'], 'emp_frais_centimes'],
            ['emp_cpe', 'CPE employeur', 'charge_employeur', $rates['emp_cpe_ppm'], 'emp_cpe_centimes'],
            ['emp_lfp', 'LFP employeur', 'charge_employeur', $rates['emp_lfp_ppm'], 'emp_lfp_centimes'],
            ['emp_lpp', 'LPP employeur', 'charge_employeur', $rates['emp_lpp_ppm'], 'emp_lpp_centimes'],
            ['net', 'Salaire net', 'net', 0, 'net_centimes'],
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO composants_fiche
             (fiche_salaire_id, code, libelle, categorie, base_centimes,
              taux_ppm, montant_centimes, ordre)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($components as $index => [$code, $label, $category, $rate, $field]) {
            $stmt->execute([
                $payrollId, $code, $label, $category,
                in_array($code, ['travail', 'vacances', 'net'], true)
                    ? (int) $calculation['salaire_travail_centimes']
                    : (int) $calculation['brut_centimes'],
                $rate, (int) $calculation[$field], $index + 1,
            ]);
        }
    }

    /** @return array<string,int> */
    private function rateSnapshot(array $rates): array
    {
        $result = [];
        foreach (PayrollConfigurationService::RATE_FIELDS as $field) {
            $result[$field] = (int) $rates[$field];
        }
        return $result;
    }

    /** @return array<string,array{amount:int,account:int}> */
    private function liabilities(array $payroll, array $mapping): array
    {
        return [
            'net' => [
                'amount' => (int) $payroll['net_centimes'],
                'account' => (int) $mapping['dette_net_id'],
            ],
            'ocas' => [
                'amount' => array_sum(array_map(
                    static fn (string $field): int => (int) $payroll[$field],
                    [
                        'ded_avs_centimes', 'ded_ac_centimes', 'ded_amat_centimes',
                        'emp_avs_centimes', 'emp_ac_centimes', 'emp_amat_centimes',
                        'emp_af_centimes', 'emp_frais_centimes',
                        'emp_cpe_centimes', 'emp_lfp_centimes',
                    ]
                )),
                'account' => (int) $mapping['dette_ocas_id'],
            ],
            'laa' => [
                'amount' => (int) $payroll['ded_laa_centimes']
                    + (int) $payroll['emp_laa_centimes'],
                'account' => (int) $mapping['dette_laa_id'],
            ],
            'lpp' => [
                'amount' => (int) $payroll['ded_lpp_centimes']
                    + (int) $payroll['emp_lpp_centimes'],
                'account' => (int) $mapping['dette_lpp_id'],
            ],
            'impot_source' => [
                'amount' => (int) $payroll['ded_impot_source_centimes'],
                'account' => (int) $mapping['dette_impot_id'],
            ],
        ];
    }

    /** @param list<array<string,int|string>> $lines */
    private function debit(array &$lines, int $account, int $amount, string $label): void
    {
        if ($amount > 0) {
            $lines[] = [
                'compte_id' => $account,
                'libelle' => $label,
                'debit_centimes' => $amount,
            ];
        }
    }

    /** @param list<array<string,int|string>> $lines */
    private function credit(array &$lines, int $account, int $amount, string $label): void
    {
        if ($amount > 0) {
            $lines[] = [
                'compte_id' => $account,
                'libelle' => $label,
                'credit_centimes' => $amount,
            ];
        }
    }

    private function divideRounded(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function maskedAvs(string $avs): string
    {
        $digits = (string) preg_replace('/\D+/', '', $avs);
        return strlen($digits) === 13
            ? '756.****.****.' . substr($digits, -2)
            : '***';
    }

    private function transaction(callable $callback, bool $immediate = false): mixed
    {
        if ($this->transactionActive || $this->pdo->inTransaction()) {
            return $callback();
        }
        if ($immediate) {
            $this->pdo->exec('BEGIN IMMEDIATE');
        } else {
            $this->pdo->beginTransaction();
        }
        $this->transactionActive = true;
        try {
            $result = $callback();
            $immediate ? $this->pdo->exec('COMMIT') : $this->pdo->commit();
            $this->transactionActive = false;
            return $result;
        } catch (Throwable $exception) {
            if ($this->transactionActive) {
                $immediate ? $this->pdo->exec('ROLLBACK') : $this->pdo->rollBack();
                $this->transactionActive = false;
            }
            throw $exception;
        }
    }
}
