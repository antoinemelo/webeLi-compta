<?php
declare(strict_types=1);

namespace Compta\Modules\Tva;

use Compta\Modules\Compta\EntryService;
use PDO;

final class VatSettlementService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly EntryService $entries,
        private readonly VatStatementService $statements,
    ) {
    }

    public function post(
        int $organisationId,
        int $dossierId,
        int $statementId,
        int $exerciseId,
        int $journalId,
        string $date,
        ?int $actorId = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'SELECT d.*, p.regime_tva_id
             FROM tva_decomptes d
             JOIN tva_periodes p ON p.id = d.periode_tva_id
             WHERE d.id = ? AND d.organisation_id = ? AND d.dossier_id = ?
               AND d.statut IN (\'controle\', \'exporte\', \'declare\')'
        );
        $stmt->execute([$statementId, $organisationId, $dossierId]);
        $statement = $stmt->fetch();
        if ($statement === false) {
            throw new VatException('Décompte TVA non contrôlé ou hors scope.');
        }
        $regimeStmt = $this->pdo->prepare('SELECT * FROM tva_regimes WHERE id = ?');
        $regimeStmt->execute([$statement['regime_tva_id']]);
        $regime = $regimeStmt->fetch();
        if (
            $regime === false
            || $regime['compte_tva_due_id'] === null
            || $regime['compte_decompte_tva_id'] === null
        ) {
            throw new VatException('Comptes de décompte TVA incomplets.');
        }
        $lines = [];
        if ($statement['methode_snapshot'] === 'effective') {
            $this->appendSigned(
                $lines,
                (int) $regime['compte_tva_due_id'],
                (int) $statement['tva_due_centimes']
            );
            $input = $this->pdo->prepare(
                'SELECT COALESCE(c.compte_tva_id,
                       CASE WHEN t.chiffre_afc_snapshot = \'400\'
                         THEN r.compte_impot_prealable_materiel_id
                         ELSE r.compte_impot_prealable_investissements_id END) AS compte_id,
                        SUM(s.tva_deductible_centimes) AS montant
                 FROM tva_decompte_sources s
                 JOIN tva_lignes t ON t.id = s.tva_ligne_id
                 JOIN tva_codes c ON c.id = t.code_tva_id
                 JOIN tva_decomptes d ON d.id = s.decompte_tva_id
                 JOIN tva_periodes p ON p.id = d.periode_tva_id
                 JOIN tva_regimes r ON r.id = p.regime_tva_id
                 WHERE s.decompte_tva_id = ? AND t.nature_snapshot = \'prealable\'
                 GROUP BY compte_id'
            );
            $input->execute([$statementId]);
            foreach ($input->fetchAll() as $row) {
                if ($row['compte_id'] !== null) {
                    $this->appendSigned($lines, (int) $row['compte_id'], -(int) $row['montant']);
                }
            }
            $this->appendSigned(
                $lines,
                (int) $regime['compte_corrections_id'],
                (int) $statement['corrections_centimes']
            );
        } else {
            $reconciliation = $this->statements->generalLedgerReconciliation(
                $organisationId,
                $dossierId,
                $statementId
            );
            $legalVat = (int) $reconciliation['vat_due_ledger_cents'];
            $this->appendSigned($lines, (int) $regime['compte_tva_due_id'], $legalVat);
            $difference = $legalVat - (int) $statement['solde_centimes'];
            $this->appendSigned(
                $lines,
                (int) $regime['compte_corrections_id'],
                -$difference
            );
        }
        $this->appendSigned(
            $lines,
            (int) $regime['compte_decompte_tva_id'],
            -(int) $statement['solde_centimes']
        );
        if ($lines === []) {
            throw new VatException('Aucun montant à comptabiliser.');
        }
        $debits = array_sum(array_column($lines, 'debit_centimes'));
        $credits = array_sum(array_column($lines, 'credit_centimes'));
        if ($debits !== $credits) {
            throw new VatException('Écriture de décompte TVA déséquilibrée.');
        }
        return $this->entries->postGenerated([
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
            'exercice_id' => $exerciseId,
            'journal_id' => $journalId,
            'date_comptable' => $date,
            'libelle' => 'Décompte TVA #' . $statementId,
            'reference' => 'TVA-' . $statementId,
            'source_type' => 'decompte_tva',
            'source_id' => (string) $statementId,
            'source_action' => 'comptabilisation',
            'lignes' => $lines,
        ], 'vat-statement:' . $statementId, $actorId);
    }

    /** @param list<array<string,int|string>> $lines */
    private function appendSigned(array &$lines, int $accountId, int $amount): void
    {
        if ($amount === 0) {
            return;
        }
        if ($accountId < 1) {
            throw new VatException('Compte requis pour comptabiliser le décompte TVA.');
        }
        $lines[] = $amount > 0
            ? [
                'compte_id' => $accountId,
                'libelle' => 'Décompte TVA',
                'debit_centimes' => $amount,
                'credit_centimes' => 0,
            ]
            : [
                'compte_id' => $accountId,
                'libelle' => 'Décompte TVA',
                'debit_centimes' => 0,
                'credit_centimes' => abs($amount),
            ];
    }
}
