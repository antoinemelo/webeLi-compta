<?php
declare(strict_types=1);

namespace Compta\Modules\Compta;

use DateTimeImmutable;
use PDO;

final class FinancialReportingService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ReportingService $reports,
    ) {
    }

    /** @return array<string,mixed> */
    public function read(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $dateStart,
        string $dateEnd,
    ): array {
        $exercise = $this->exercise(
            $organisationId,
            $dossierId,
            $exerciseId
        );
        $this->assertPeriod($exercise, $dateStart, $dateEnd);
        $trial = $this->reports->trialBalance(
            $organisationId,
            $dossierId,
            $exerciseId,
            $dateEnd
        );
        $balanceSheet = $this->reports->balanceSheet(
            $organisationId,
            $dossierId,
            $exerciseId,
            $dateEnd
        );
        $income = $this->reports->incomeStatement(
            $organisationId,
            $dossierId,
            $exerciseId,
            $dateEnd
        );
        $cashFlow = $this->cashFlow(
            $organisationId,
            $dossierId,
            $exerciseId,
            $dateStart,
            $dateEnd
        );
        $comparison = $this->incomeComparison(
            $organisationId,
            $dossierId,
            $exercise,
            $income
        );
        $trialResult = 0;
        foreach ($trial['items'] as $item) {
            if ($item['type'] === 'produit') {
                $trialResult += (int) $item['solde_centimes'];
            } elseif ($item['type'] === 'charge') {
                $trialResult -= (int) $item['solde_centimes'];
            }
        }
        return [
            'parameters' => [
                'exercise_id' => $exerciseId,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'ledger_statuses' => ['validee', 'contre_passee'],
            ],
            'general_ledger' => $this->reports->generalLedger(
                $organisationId,
                $dossierId,
                $exerciseId,
                $dateStart,
                $dateEnd
            ),
            'trial_balance' => $trial,
            'balance_sheet' => $balanceSheet,
            'income_statement' => $comparison,
            'cash_flow' => $cashFlow,
            'controls' => [
                'debit_equals_credit' => (bool) $trial['equilibree'],
                'balance_sheet_balanced' => (bool) $balanceSheet['equilibre'],
                'trial_result_cents' => $trialResult,
                'income_result_cents' => (int) $income['resultat_centimes'],
                'balance_result_cents' => $this->balanceResult($balanceSheet),
                'result_reconciled' =>
                    $trialResult === (int) $income['resultat_centimes']
                    && $this->balanceResult($balanceSheet)
                        === (int) $income['resultat_centimes'],
                'cash_reconciled' =>
                    (int) $cashFlow['reconciliation_difference_cents'] === 0,
            ],
            'definitions' => [
                'read_only' =>
                    'Tous les rapports lisent uniquement les écritures validées ou contre-passées.',
                'cash_flow' =>
                    'Méthode directe depuis les comptes de liquidités configurés ; les catégories proposées restent à valider par le préparateur.',
                'comparison' =>
                    'Le comparatif reprend le dernier exercice antérieur complet disponible.',
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function cashFlow(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $dateStart,
        string $dateEnd,
    ): array {
        $accounts = $this->pdo->prepare(
            "SELECT t.id, t.libelle, t.type, t.monnaie,
                    c.id AS account_id, c.numero, c.libelle AS account_label,
                    COALESCE(SUM(CASE
                      WHEN e.id IS NOT NULL
                       AND (e.source_type = 'ouverture' OR e.date_comptable < :start)
                      THEN (l.debit_centimes - l.credit_centimes)
                           * t.multiplicateur_comptable ELSE 0 END), 0)
                        AS opening_cents,
                    COALESCE(SUM(CASE
                      WHEN e.id IS NOT NULL AND e.date_comptable <= :end
                      THEN (l.debit_centimes - l.credit_centimes)
                           * t.multiplicateur_comptable ELSE 0 END), 0)
                        AS closing_cents
             FROM comptes_tresorerie t
             JOIN comptes c ON c.id = t.compte_comptable_id
             LEFT JOIN lignes_ecriture l ON l.compte_id = c.id
             LEFT JOIN ecritures e ON e.id = l.ecriture_id
               AND e.exercice_id = :exercise
               AND e.statut IN ('validee', 'contre_passee')
             WHERE t.organisation_id = :organisation
               AND t.dossier_id = :dossier AND t.actif = 1
             GROUP BY t.id
             ORDER BY t.libelle, t.id"
        );
        $accounts->execute([
            'organisation' => $organisationId,
            'dossier' => $dossierId,
            'exercise' => $exerciseId,
            'start' => $dateStart,
            'end' => $dateEnd,
        ]);
        $accountRows = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'label' => (string) $row['libelle'],
            'type' => (string) $row['type'],
            'currency' => (string) $row['monnaie'],
            'ledger_account_id' => (int) $row['account_id'],
            'ledger_number' => (string) $row['numero'],
            'ledger_label' => (string) $row['account_label'],
            'opening_cents' => (int) $row['opening_cents'],
            'closing_cents' => (int) $row['closing_cents'],
            'change_cents' =>
                (int) $row['closing_cents'] - (int) $row['opening_cents'],
        ], $accounts->fetchAll());

        $movements = $this->pdo->prepare(
            "SELECT e.id, e.numero, e.date_comptable, e.libelle,
                    e.source_type, e.source_id,
                    SUM(
                      (l.debit_centimes - l.credit_centimes)
                      * t.multiplicateur_comptable
                    ) AS amount_cents
             FROM ecritures e
             JOIN lignes_ecriture l ON l.ecriture_id = e.id
             JOIN comptes_tresorerie t
               ON t.compte_comptable_id = l.compte_id
              AND t.organisation_id = e.organisation_id
              AND t.dossier_id = e.dossier_id
              AND t.actif = 1
             WHERE e.organisation_id = ? AND e.dossier_id = ?
               AND e.exercice_id = ?
               AND e.statut IN ('validee', 'contre_passee')
               AND e.source_type <> 'ouverture'
               AND e.date_comptable BETWEEN ? AND ?
             GROUP BY e.id
             HAVING amount_cents <> 0
             ORDER BY e.date_comptable, e.id"
        );
        $movements->execute([
            $organisationId,
            $dossierId,
            $exerciseId,
            $dateStart,
            $dateEnd,
        ]);
        $items = [];
        $inflows = 0;
        $outflows = 0;
        foreach ($movements->fetchAll() as $row) {
            $amount = (int) $row['amount_cents'];
            $inflows += max(0, $amount);
            $outflows += max(0, -$amount);
            $items[] = [
                'entry_id' => (int) $row['id'],
                'number' => (string) $row['numero'],
                'date' => (string) $row['date_comptable'],
                'label' => (string) $row['libelle'],
                'source_type' => (string) $row['source_type'],
                'source_id' => (string) $row['source_id'],
                'category' => $this->cashCategory((string) $row['source_type']),
                'amount_cents' => $amount,
            ];
        }
        $opening = array_sum(array_column($accountRows, 'opening_cents'));
        $closing = array_sum(array_column($accountRows, 'closing_cents'));
        $net = $inflows - $outflows;
        return [
            'method' => 'directe_grand_livre',
            'method_label' =>
                'Méthode directe — mouvements des comptes de liquidités',
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'opening_cash_cents' => $opening,
            'inflows_cents' => $inflows,
            'outflows_cents' => $outflows,
            'net_change_cents' => $net,
            'closing_cash_cents' => $closing,
            'reconciled_closing_cents' => $opening + $net,
            'reconciliation_difference_cents' => $closing - ($opening + $net),
            'classification_status' => 'a_valider',
            'accounts' => $accountRows,
            'items' => $items,
        ];
    }

    public function ledgerFingerprint(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $dateEnd,
    ): string {
        $stmt = $this->pdo->prepare(
            "SELECT e.id, e.numero, e.date_comptable, e.statut,
                    e.source_type, e.source_id, e.source_action,
                    l.ordre, l.compte_id, l.debit_centimes, l.credit_centimes
             FROM ecritures e
             JOIN lignes_ecriture l ON l.ecriture_id = e.id
             WHERE e.organisation_id = ? AND e.dossier_id = ?
               AND e.exercice_id = ?
               AND e.statut IN ('validee', 'contre_passee')
               AND e.date_comptable <= ?
             ORDER BY e.id, l.ordre, l.id"
        );
        $stmt->execute([
            $organisationId,
            $dossierId,
            $exerciseId,
            $dateEnd,
        ]);
        return hash('sha256', $this->json($stmt->fetchAll()));
    }

    /** @return array<string,mixed> */
    private function incomeComparison(
        int $organisationId,
        int $dossierId,
        array $exercise,
        array $current,
    ): array {
        $previousStmt = $this->pdo->prepare(
            'SELECT id, libelle, date_debut, date_fin
             FROM exercices
             WHERE dossier_id = ? AND date_fin < ?
             ORDER BY date_fin DESC LIMIT 1'
        );
        $previousStmt->execute([$dossierId, $exercise['date_debut']]);
        $previousExercise = $previousStmt->fetch();
        $previous = $previousExercise === false
            ? [
                'items' => [],
                'produits_centimes' => 0,
                'charges_centimes' => 0,
                'resultat_centimes' => 0,
            ]
            : $this->reports->incomeStatement(
                $organisationId,
                $dossierId,
                (int) $previousExercise['id'],
                (string) $previousExercise['date_fin']
            );
        $rows = [];
        foreach ([$current['items'], $previous['items']] as $setIndex => $items) {
            foreach ($items as $item) {
                $number = (string) $item['numero'];
                $rows[$number] ??= [
                    'number' => $number,
                    'label' => (string) $item['libelle'],
                    'type' => (string) $item['type'],
                    'rubric_path' => (string) $item['rubrique_chemin'],
                    'current_cents' => 0,
                    'previous_cents' => 0,
                    'delta_cents' => 0,
                ];
                $key = $setIndex === 0 ? 'current_cents' : 'previous_cents';
                $rows[$number][$key] = (int) $item['solde_centimes'];
            }
        }
        foreach ($rows as &$row) {
            $row['delta_cents'] =
                (int) $row['current_cents'] - (int) $row['previous_cents'];
        }
        unset($row);
        ksort($rows, SORT_NATURAL);
        return [
            'items' => array_values($rows),
            'current' => [
                'label' => (string) $exercise['libelle'],
                'products_cents' => (int) $current['produits_centimes'],
                'expenses_cents' => (int) $current['charges_centimes'],
                'result_cents' => (int) $current['resultat_centimes'],
            ],
            'previous' => [
                'exercise_id' => $previousExercise === false
                    ? null : (int) $previousExercise['id'],
                'label' => $previousExercise === false
                    ? null : (string) $previousExercise['libelle'],
                'products_cents' => (int) $previous['produits_centimes'],
                'expenses_cents' => (int) $previous['charges_centimes'],
                'result_cents' => (int) $previous['resultat_centimes'],
            ],
            'delta' => [
                'products_cents' =>
                    (int) $current['produits_centimes']
                    - (int) $previous['produits_centimes'],
                'expenses_cents' =>
                    (int) $current['charges_centimes']
                    - (int) $previous['charges_centimes'],
                'result_cents' =>
                    (int) $current['resultat_centimes']
                    - (int) $previous['resultat_centimes'],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function exercise(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT x.*
             FROM exercices x
             JOIN dossiers d ON d.id = x.dossier_id
             WHERE x.id = ? AND x.dossier_id = ? AND d.organisation_id = ?'
        );
        $stmt->execute([$exerciseId, $dossierId, $organisationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new AccountingException('Exercice absent du dossier.');
        }
        return $row;
    }

    private function assertPeriod(
        array $exercise,
        string $dateStart,
        string $dateEnd,
    ): void {
        foreach ([$dateStart, $dateEnd] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new AccountingException('Période de rapport invalide.');
            }
        }
        if (
            $dateStart > $dateEnd
            || $dateStart < (string) $exercise['date_debut']
            || $dateEnd > (string) $exercise['date_fin']
        ) {
            throw new AccountingException('Période de rapport hors exercice.');
        }
    }

    private function balanceResult(array $balanceSheet): int
    {
        foreach ($balanceSheet['items'] as $item) {
            if ((string) $item['numero'] === 'RÉSULTAT') {
                return (int) $item['solde_centimes'];
            }
        }
        return 0;
    }

    private function cashCategory(string $sourceType): string
    {
        return match ($sourceType) {
            'transfert_interne' => 'transfert_interne',
            'paiement', 'paiement_salaire', 'lot_paiement_sortant',
            'decompte_tva', 'ligne_bancaire' => 'exploitation',
            default => 'a_classer',
        };
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
        );
    }
}
