<?php
declare(strict_types=1);

namespace Compta\Modules\Dashboard\Application;

use Compta\Modules\Compta\ReportingService;
use PDO;

final class DashboardReadService
{
    private const RECENT_ENTRY_LIMIT = 10;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ReportingService $reports,
    ) {
    }

    /** @return array<string, mixed> */
    public function projection(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $asOfDate,
    ): array {
        $scope = $this->scope($organisationId, $dossierId, $exerciseId);
        if ($scope === null) {
            throw new DashboardQueryException(
                'exercise_id',
                'Exercice absent du dossier courant.'
            );
        }
        if ($asOfDate < $scope['start_date'] || $asOfDate > $scope['end_date']) {
            throw new DashboardQueryException(
                'as_of_date',
                'La date d’arrêté doit appartenir à l’exercice sélectionné.'
            );
        }

        $income = $this->reports->incomeStatement(
            $organisationId,
            $dossierId,
            $exerciseId,
            $asOfDate
        );
        $treasury = $this->treasury(
            $organisationId,
            $dossierId,
            $exerciseId,
            $asOfDate,
            $scope['base_currency']
        );
        $openItems = $this->openItems($organisationId, $dossierId, $asOfDate);
        $bankLines = $this->unreconciledBankLines(
            $organisationId,
            $dossierId,
            $asOfDate
        );
        $payments = $this->paymentsToProcess(
            $organisationId,
            $dossierId,
            $asOfDate
        );
        $recentEntries = $this->recentEntries(
            $organisationId,
            $dossierId,
            $exerciseId,
            $asOfDate
        );
        $hasTreasuryValue = false;
        foreach ($treasury['accounts'] as $account) {
            if (
                (int) $account['accounting_balance_cents'] !== 0
                || $account['bank_balance_cents'] !== null
            ) {
                $hasTreasuryValue = true;
                break;
            }
        }
        $isEmpty = !$hasTreasuryValue
            && (int) $openItems['receivables']['open_count'] === 0
            && (int) $openItems['payables']['open_count'] === 0
            && (int) $bankLines['count'] === 0
            && (int) $payments['count'] === 0
            && $recentEntries === [];

        return [
            'scope' => [
                'exercise' => [
                    'id' => (int) $scope['id'],
                    'label' => $scope['label'],
                    'start_date' => $scope['start_date'],
                    'end_date' => $scope['end_date'],
                    'status' => $scope['status'],
                ],
                'period' => $this->period(
                    $organisationId,
                    $dossierId,
                    $exerciseId,
                    $asOfDate
                ),
                'as_of_date' => $asOfDate,
                'base_currency' => $scope['base_currency'],
            ],
            'treasury' => $treasury,
            'profit_and_loss' => [
                'revenue_cents' => (int) $income['produits_centimes'],
                'expenses_cents' => (int) $income['charges_centimes'],
                'result_cents' => (int) $income['resultat_centimes'],
            ],
            'open_items' => $openItems,
            'operations' => [
                'unreconciled_bank_lines' => $bankLines,
                'payments_to_process' => $payments,
            ],
            'recent_entries' => $recentEntries,
            'empty_state' => [
                'is_empty' => $isEmpty,
                'code' => $isEmpty ? 'NO_ACTIVITY_AT_DATE' : null,
                'message' => $isEmpty
                    ? 'Aucune activité comptable ou opérationnelle à cette date.'
                    : null,
            ],
            'calculation' => [
                'calculated_at' => gmdate('c'),
                'ledger_statuses' => ['validee', 'contre_passee'],
                'revenue_definition' =>
                    'Comptes de type produit : crédits moins débits.',
                'expenses_definition' =>
                    'Comptes de type charge : débits moins crédits.',
                'open_items_definition' =>
                    'Documents émis ou comptabilisés, nets des allocations valides ; '
                    . 'les avoirs non alloués portent un signe négatif.',
                'overdue_definition' =>
                    'Échéance strictement antérieure à la date d’arrêté.',
                'aging_buckets' => [
                    'not_due',
                    'days_1_30',
                    'days_31_60',
                    'days_61_90',
                    'days_91_plus',
                ],
                'recent_entry_limit' => self::RECENT_ENTRY_LIMIT,
                'cache' => false,
            ],
        ];
    }

    /**
     * @return array{
     *   id:int,label:string,start_date:string,end_date:string,status:string,
     *   base_currency:string
     * }|null
     */
    private function scope(int $organisationId, int $dossierId, int $exerciseId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id, e.libelle, e.date_debut, e.date_fin, e.statut, d.monnaie
             FROM exercices e
             JOIN dossiers d ON d.id = e.dossier_id
             WHERE e.id = ? AND e.dossier_id = ?
               AND d.organisation_id = ? AND d.actif = 1'
        );
        $stmt->execute([$exerciseId, $dossierId, $organisationId]);
        $row = $stmt->fetch();
        return $row === false ? null : [
            'id' => (int) $row['id'],
            'label' => (string) $row['libelle'],
            'start_date' => (string) $row['date_debut'],
            'end_date' => (string) $row['date_fin'],
            'status' => (string) $row['statut'],
            'base_currency' => (string) $row['monnaie'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function period(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $asOfDate,
    ): ?array {
        $stmt = $this->pdo->prepare(
            'SELECT id, libelle, date_debut, date_fin, statut
             FROM periodes
             WHERE organisation_id = ? AND dossier_id = ? AND exercice_id = ?
               AND date_debut <= ? AND date_fin >= ?
             ORDER BY date_debut DESC, id DESC LIMIT 1'
        );
        $stmt->execute([
            $organisationId,
            $dossierId,
            $exerciseId,
            $asOfDate,
            $asOfDate,
        ]);
        $row = $stmt->fetch();
        return $row === false ? null : [
            'id' => (int) $row['id'],
            'label' => (string) $row['libelle'],
            'start_date' => (string) $row['date_debut'],
            'end_date' => (string) $row['date_fin'],
            'status' => (string) $row['statut'],
        ];
    }

    /** @return array<string, mixed> */
    private function treasury(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $asOfDate,
        string $baseCurrency,
    ): array {
        $stmt = $this->pdo->prepare(
            "WITH accounting AS (
                SELECT l.compte_id,
                       SUM(l.debit_centimes - l.credit_centimes) AS balance_cents
                FROM lignes_ecriture l
                JOIN ecritures e ON e.id = l.ecriture_id
                WHERE e.organisation_id = :organisation
                  AND e.dossier_id = :dossier
                  AND e.exercice_id = :exercise
                  AND e.date_comptable <= :as_of_date
                  AND e.statut IN ('validee', 'contre_passee')
                GROUP BY l.compte_id
             ),
             ranked_bank AS (
                SELECT sb.compte_tresorerie_id, sb.montant_centimes,
                       sb.date_solde, sb.monnaie, sb.type,
                       ROW_NUMBER() OVER (
                         PARTITION BY sb.compte_tresorerie_id
                         ORDER BY sb.date_solde DESC,
                           CASE sb.type WHEN 'CLBD' THEN 0 WHEN 'ITBD' THEN 1 ELSE 2 END,
                           sb.id DESC
                       ) AS rang
                FROM soldes_bancaires sb
                WHERE sb.organisation_id = :organisation
                  AND sb.dossier_id = :dossier
                  AND sb.date_solde <= :as_of_date
             )
             SELECT t.id, t.libelle, t.type, t.monnaie,
                    t.multiplicateur_comptable,
                    c.id AS ledger_account_id, c.numero AS ledger_account_number,
                    c.libelle AS ledger_account_label,
                    COALESCE(a.balance_cents, 0) AS raw_accounting_balance_cents,
                    b.montant_centimes AS bank_balance_cents,
                    b.date_solde AS bank_balance_date,
                    b.monnaie AS bank_balance_currency
             FROM comptes_tresorerie t
             JOIN comptes c ON c.id = t.compte_comptable_id
             LEFT JOIN accounting a ON a.compte_id = t.compte_comptable_id
             LEFT JOIN ranked_bank b
               ON b.compte_tresorerie_id = t.id AND b.rang = 1
             WHERE t.organisation_id = :organisation
               AND t.dossier_id = :dossier AND t.actif = 1
             ORDER BY t.type, t.libelle, t.id"
        );
        $stmt->execute([
            'organisation' => $organisationId,
            'dossier' => $dossierId,
            'exercise' => $exerciseId,
            'as_of_date' => $asOfDate,
        ]);
        $accounts = [];
        $accountingTotal = 0;
        $comparableAccountingTotal = 0;
        $bankTotal = 0;
        $comparableBankCount = 0;
        foreach ($stmt->fetchAll() as $row) {
            $accounting = (int) $row['raw_accounting_balance_cents']
                * (int) $row['multiplicateur_comptable'];
            $bank = $row['bank_balance_cents'] === null
                ? null
                : (int) $row['bank_balance_cents'];
            $bankCurrency = $row['bank_balance_currency'] === null
                ? null
                : (string) $row['bank_balance_currency'];
            $comparable = $bank !== null
                && (string) $row['monnaie'] === $baseCurrency
                && $bankCurrency === $baseCurrency;
            $difference = $comparable ? $bank - $accounting : null;
            $accountingTotal += $accounting;
            if ($comparable) {
                $comparableAccountingTotal += $accounting;
                $bankTotal += $bank;
                $comparableBankCount++;
            }
            $accounts[] = [
                'id' => (int) $row['id'],
                'label' => (string) $row['libelle'],
                'type' => (string) $row['type'],
                'currency' => (string) $row['monnaie'],
                'ledger_account' => [
                    'id' => (int) $row['ledger_account_id'],
                    'number' => (string) $row['ledger_account_number'],
                    'label' => (string) $row['ledger_account_label'],
                ],
                'accounting_balance_cents' => $accounting,
                'bank_balance_cents' => $bank,
                'bank_balance_date' => $row['bank_balance_date'] === null
                    ? null
                    : (string) $row['bank_balance_date'],
                'bank_balance_currency' => $bankCurrency,
                'difference_cents' => $difference,
                'comparable_in_base_currency' => $comparable,
            ];
        }
        $bankTotalValue = $comparableBankCount > 0 ? $bankTotal : null;
        return [
            'accounts' => $accounts,
            'accounting_balance_cents' => $accountingTotal,
            'bank_balance_cents' => $bankTotalValue,
            'difference_cents' => $bankTotalValue === null
                ? null
                : $bankTotalValue - $comparableAccountingTotal,
            'bank_balance_coverage' => [
                'comparable_accounts' => $comparableBankCount,
                'total_accounts' => count($accounts),
                'comparable_accounting_balance_cents' => $comparableAccountingTotal,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function openItems(
        int $organisationId,
        int $dossierId,
        string $asOfDate,
    ): array {
        $stmt = $this->pdo->prepare(
            "WITH target_allocations AS (
                SELECT a.document_id, SUM(a.montant_centimes) AS allocated_cents
                FROM allocations a
                LEFT JOIN paiements p ON p.id = a.paiement_id
                LEFT JOIN documents_financiers credit ON credit.id = a.avoir_id
                WHERE a.organisation_id = :organisation
                  AND a.dossier_id = :dossier
                  AND a.statut = 'valide'
                  AND (
                    (p.id IS NOT NULL AND p.statut = 'valide'
                      AND p.date_paiement <= :as_of_date)
                    OR (credit.id IS NOT NULL
                      AND credit.statut IN ('emis', 'comptabilise')
                      AND credit.date_document <= :as_of_date)
                  )
                GROUP BY a.document_id
             ),
             credit_allocations AS (
                SELECT a.avoir_id, SUM(a.montant_centimes) AS allocated_cents
                FROM allocations a
                JOIN documents_financiers credit ON credit.id = a.avoir_id
                WHERE a.organisation_id = :organisation
                  AND a.dossier_id = :dossier
                  AND a.statut = 'valide'
                  AND credit.statut IN ('emis', 'comptabilise')
                  AND credit.date_document <= :as_of_date
                GROUP BY a.avoir_id
             ),
             open_documents AS (
                SELECT CASE
                         WHEN d.type IN ('facture_client', 'avoir_client')
                           THEN 'receivables'
                         ELSE 'payables'
                       END AS side,
                       d.date_echeance,
                       CASE
                         WHEN d.type IN ('facture_client', 'facture_fournisseur')
                           THEN MAX(
                             0,
                             ABS(d.total_brut_centimes)
                               - COALESCE(target.allocated_cents, 0)
                           )
                         ELSE -MAX(
                           0,
                           ABS(d.total_brut_centimes)
                             - COALESCE(credit.allocated_cents, 0)
                         )
                       END AS open_cents
                FROM documents_financiers d
                LEFT JOIN target_allocations target ON target.document_id = d.id
                LEFT JOIN credit_allocations credit ON credit.avoir_id = d.id
                WHERE d.organisation_id = :organisation
                  AND d.dossier_id = :dossier
                  AND d.statut IN ('emis', 'comptabilise')
                  AND d.date_document <= :as_of_date
             ),
             bucketed AS (
                SELECT side, open_cents,
                       CASE
                         WHEN date_echeance >= :as_of_date THEN 'not_due'
                         WHEN CAST(julianday(:as_of_date) - julianday(date_echeance) AS INTEGER)
                              BETWEEN 1 AND 30 THEN 'days_1_30'
                         WHEN CAST(julianday(:as_of_date) - julianday(date_echeance) AS INTEGER)
                              BETWEEN 31 AND 60 THEN 'days_31_60'
                         WHEN CAST(julianday(:as_of_date) - julianday(date_echeance) AS INTEGER)
                              BETWEEN 61 AND 90 THEN 'days_61_90'
                         ELSE 'days_91_plus'
                       END AS bucket
                FROM open_documents
                WHERE open_cents <> 0
             )
             SELECT side, bucket, COUNT(*) AS item_count,
                    COALESCE(SUM(open_cents), 0) AS amount_cents
             FROM bucketed
             GROUP BY side, bucket"
        );
        $stmt->execute([
            'organisation' => $organisationId,
            'dossier' => $dossierId,
            'as_of_date' => $asOfDate,
        ]);
        $blank = static fn (): array => [
            'open_cents' => 0,
            'overdue_cents' => 0,
            'open_count' => 0,
            'overdue_count' => 0,
            'aging' => [
                'not_due' => 0,
                'days_1_30' => 0,
                'days_31_60' => 0,
                'days_61_90' => 0,
                'days_91_plus' => 0,
            ],
        ];
        $result = [
            'receivables' => $blank(),
            'payables' => $blank(),
        ];
        foreach ($stmt->fetchAll() as $row) {
            $side = (string) $row['side'];
            $bucket = (string) $row['bucket'];
            $amount = (int) $row['amount_cents'];
            $count = (int) $row['item_count'];
            $result[$side]['aging'][$bucket] = $amount;
            $result[$side]['open_cents'] += $amount;
            $result[$side]['open_count'] += $count;
            if ($bucket !== 'not_due') {
                $result[$side]['overdue_cents'] += $amount;
                $result[$side]['overdue_count'] += $count;
            }
        }
        return $result;
    }

    /** @return array{count:int,net_cents:int,absolute_cents:int} */
    private function unreconciledBankLines(
        int $organisationId,
        int $dossierId,
        string $asOfDate,
    ): array {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS line_count,
                    COALESCE(SUM(l.montant_centimes), 0) AS net_cents,
                    COALESCE(SUM(ABS(l.montant_centimes)), 0) AS absolute_cents
             FROM lignes_bancaires l
             JOIN imports_bancaires i ON i.id = l.import_id AND i.statut = 'confirme'
             WHERE l.organisation_id = ? AND l.dossier_id = ?
               AND l.date_comptabilisation <= ?
               AND NOT EXISTS (
                 SELECT 1 FROM rapprochement_lignes_bancaires r
                 WHERE r.ligne_bancaire_id = l.id
               )"
        );
        $stmt->execute([$organisationId, $dossierId, $asOfDate]);
        $row = $stmt->fetch() ?: [];
        return [
            'count' => (int) ($row['line_count'] ?? 0),
            'net_cents' => (int) ($row['net_cents'] ?? 0),
            'absolute_cents' => (int) ($row['absolute_cents'] ?? 0),
        ];
    }

    /** @return array<string, int> */
    private function paymentsToProcess(
        int $organisationId,
        int $dossierId,
        string $asOfDate,
    ): array {
        $stmt = $this->pdo->prepare(
            "WITH allocated AS (
                SELECT paiement_id, SUM(montant_centimes) AS allocated_cents
                FROM allocations
                WHERE organisation_id = :organisation
                  AND dossier_id = :dossier
                  AND statut = 'valide' AND paiement_id IS NOT NULL
                GROUP BY paiement_id
             ),
             pending AS (
                SELECT p.sens,
                       p.montant_centimes - COALESCE(a.allocated_cents, 0)
                         AS remaining_cents
                FROM paiements p
                LEFT JOIN allocated a ON a.paiement_id = p.id
                WHERE p.organisation_id = :organisation
                  AND p.dossier_id = :dossier
                  AND p.statut = 'valide'
                  AND p.date_paiement <= :as_of_date
                  AND p.montant_centimes > COALESCE(a.allocated_cents, 0)
             )
             SELECT sens, COUNT(*) AS payment_count,
                    COALESCE(SUM(remaining_cents), 0) AS amount_cents
             FROM pending GROUP BY sens"
        );
        $stmt->execute([
            'organisation' => $organisationId,
            'dossier' => $dossierId,
            'as_of_date' => $asOfDate,
        ]);
        $result = [
            'count' => 0,
            'amount_cents' => 0,
            'incoming_count' => 0,
            'incoming_cents' => 0,
            'outgoing_count' => 0,
            'outgoing_cents' => 0,
        ];
        foreach ($stmt->fetchAll() as $row) {
            $count = (int) $row['payment_count'];
            $amount = (int) $row['amount_cents'];
            $result['count'] += $count;
            $result['amount_cents'] += $amount;
            if ((string) $row['sens'] === 'encaissement') {
                $result['incoming_count'] = $count;
                $result['incoming_cents'] = $amount;
            } else {
                $result['outgoing_count'] = $count;
                $result['outgoing_cents'] = $amount;
            }
        }
        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function recentEntries(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $asOfDate,
    ): array {
        $stmt = $this->pdo->prepare(
            "SELECT e.id, e.numero, e.date_comptable, e.libelle, e.reference,
                    e.statut, e.source_type, e.source_id, j.code AS journal,
                    SUM(l.debit_centimes) AS amount_cents
             FROM ecritures e
             JOIN journaux j ON j.id = e.journal_id
             JOIN lignes_ecriture l ON l.ecriture_id = e.id
             WHERE e.organisation_id = :organisation
               AND e.dossier_id = :dossier
               AND e.exercice_id = :exercise
               AND e.date_comptable <= :as_of_date
               AND e.statut IN ('validee', 'contre_passee')
             GROUP BY e.id
             ORDER BY e.date_comptable DESC, e.id DESC
             LIMIT :entry_limit"
        );
        $stmt->bindValue(':organisation', $organisationId, PDO::PARAM_INT);
        $stmt->bindValue(':dossier', $dossierId, PDO::PARAM_INT);
        $stmt->bindValue(':exercise', $exerciseId, PDO::PARAM_INT);
        $stmt->bindValue(':as_of_date', $asOfDate, PDO::PARAM_STR);
        $stmt->bindValue(':entry_limit', self::RECENT_ENTRY_LIMIT, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(function (array $row): array {
            $sourceType = (string) $row['source_type'];
            $sourceId = (string) $row['source_id'];
            return [
                'id' => (int) $row['id'],
                'number' => (string) $row['numero'],
                'date' => (string) $row['date_comptable'],
                'label' => (string) $row['libelle'],
                'reference' => (string) $row['reference'],
                'journal' => (string) $row['journal'],
                'status' => (string) $row['statut'],
                'amount_cents' => (int) $row['amount_cents'],
                'source' => [
                    'type' => $sourceType,
                    'id' => $sourceId,
                    'path' => $this->sourcePath(
                        $sourceType,
                        $sourceId,
                        (int) $row['id']
                    ),
                ],
            ];
        }, $stmt->fetchAll());
    }

    private function sourcePath(string $type, string $sourceId, int $entryId): string
    {
        $encoded = rawurlencode($sourceId);
        return match ($type) {
            'document_financier' => '/facturation/factures?document_id=' . $encoded,
            'paiement' => '/facturation/paiements?payment_id=' . $encoded,
            'fiche_salaire' => '/salaires/fiches?payroll_id=' . $encoded,
            'paiement_salaire' => '/salaires/paiements?payment_id=' . $encoded,
            'ligne_bancaire' => '/liquidites/rapprochement?bank_line_id=' . $encoded,
            'decompte_tva' => '/compta/tva?statement_id=' . $encoded,
            default => '/compta/journal?entry_id=' . $entryId,
        };
    }
}
