<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie;

use Compta\Core\Audit\AuditLogger;
use PDO;
use Throwable;

final class ReconciliationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param list<int> $bankLineIds
     * @param list<int> $accountingLineIds
     */
    public function reconcile(
        int $organisationId,
        int $dossierId,
        int $treasuryAccountId,
        array $bankLineIds,
        array $accountingLineIds,
        int $toleranceCents = 0,
        string $label = '',
        ?int $actorId = null,
    ): int {
        $bankLineIds = array_values(array_unique(array_map('intval', $bankLineIds)));
        $accountingLineIds = array_values(array_unique(array_map('intval', $accountingLineIds)));
        if ($bankLineIds === [] || $accountingLineIds === [] || $toleranceCents < 0) {
            throw new TreasuryException('Sélection de rapprochement invalide.');
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $bank = $this->rows(
                'SELECT id, montant_centimes FROM lignes_bancaires
                 WHERE organisation_id = ? AND dossier_id = ?
                   AND compte_tresorerie_id = ? AND id IN (%s)',
                [$organisationId, $dossierId, $treasuryAccountId],
                $bankLineIds
            );
            $accounting = $this->rows(
                'SELECT l.id, (l.debit_centimes - l.credit_centimes) AS montant_centimes
                 FROM lignes_ecriture l
                 JOIN ecritures e ON e.id = l.ecriture_id
                 JOIN comptes_tresorerie t ON t.compte_comptable_id = l.compte_id
                 WHERE e.organisation_id = ? AND e.dossier_id = ?
                   AND t.id = ? AND e.statut IN (\'validee\', \'contre_passee\')
                   AND EXISTS (
                       SELECT 1 FROM periodes p
                       WHERE p.exercice_id = e.exercice_id
                         AND e.date_comptable BETWEEN p.date_debut AND p.date_fin
                         AND p.statut = \'ouverte\'
                   )
                   AND l.id IN (%s)',
                [$organisationId, $dossierId, $treasuryAccountId],
                $accountingLineIds
            );
            if (count($bank) !== count($bankLineIds) || count($accounting) !== count($accountingLineIds)) {
                throw new TreasuryException('Une ligne est absente, hors scope ou non validée.');
            }
            $bankTotal = array_sum(array_column($bank, 'montant_centimes'));
            $accountingTotal = array_sum(array_column($accounting, 'montant_centimes'));
            $difference = $bankTotal - $accountingTotal;
            if (abs($difference) > $toleranceCents) {
                throw new TreasuryException('Les montants sélectionnés ne concordent pas.');
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO rapprochements_bancaires
                 (organisation_id, dossier_id, compte_tresorerie_id, libelle,
                  total_banque_centimes, total_comptable_centimes,
                  difference_centimes, tolerance_centimes, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $organisationId, $dossierId, $treasuryAccountId, trim($label),
                $bankTotal, $accountingTotal, $difference, $toleranceCents, $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $bankInsert = $this->pdo->prepare(
                'INSERT INTO rapprochement_lignes_bancaires
                 (rapprochement_id, ligne_bancaire_id, montant_centimes)
                 VALUES (?, ?, ?)'
            );
            foreach ($bank as $line) {
                $bankInsert->execute([$id, $line['id'], $line['montant_centimes']]);
            }
            $accountingInsert = $this->pdo->prepare(
                'INSERT INTO rapprochement_lignes_comptables
                 (rapprochement_id, ligne_ecriture_id, montant_centimes)
                 VALUES (?, ?, ?)'
            );
            foreach ($accounting as $line) {
                $accountingInsert->execute([$id, $line['id'], $line['montant_centimes']]);
            }
            $this->audit->log(
                'tresorerie.rapprochement_confirme',
                $actorId,
                $organisationId,
                $dossierId,
                'rapprochement_bancaire',
                (string) $id,
                ['banque' => $bankLineIds, 'comptabilite' => $accountingLineIds]
            );
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $id;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function cancel(
        int $organisationId,
        int $dossierId,
        int $reconciliationId,
        int $expectedVersion,
        ?int $actorId = null,
    ): void {
        $ownsTransaction = !$this->pdo->inTransaction();
        $transactionOpen = false;
        if ($ownsTransaction) {
            $this->pdo->exec('BEGIN IMMEDIATE');
            $transactionOpen = true;
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT statut, version FROM rapprochements_bancaires
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
            );
            $stmt->execute([$reconciliationId, $organisationId, $dossierId]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new TreasuryException('Rapprochement absent du dossier.');
            }
            if ($row['statut'] === 'annule') {
                if ($ownsTransaction) {
                    $this->pdo->exec('COMMIT');
                    $transactionOpen = false;
                }
                return;
            }
            if ((int) $row['version'] !== $expectedVersion) {
                throw new TreasuryException('Rapprochement modifié simultanément.');
            }
            $closed = $this->pdo->prepare(
                "SELECT COUNT(*)
                 FROM rapprochement_lignes_comptables rc
                 JOIN lignes_ecriture l ON l.id = rc.ligne_ecriture_id
                 JOIN ecritures e ON e.id = l.ecriture_id
                 WHERE rc.rapprochement_id = ? AND rc.actif = 1
                   AND NOT EXISTS (
                       SELECT 1 FROM periodes p
                       WHERE p.exercice_id = e.exercice_id
                         AND e.date_comptable BETWEEN p.date_debut AND p.date_fin
                         AND p.statut = 'ouverte'
                   )"
            );
            $closed->execute([$reconciliationId]);
            if ((int) $closed->fetchColumn() > 0) {
                throw new TreasuryException(
                    'Une période close interdit l’annulation du rapprochement.'
                );
            }
            $update = $this->pdo->prepare(
                "UPDATE rapprochements_bancaires
                 SET statut = 'annule', annule_le = datetime('now'),
                     annule_par = ?, version = version + 1
                 WHERE id = ? AND statut = 'confirme' AND version = ?"
            );
            $update->execute([$actorId, $reconciliationId, $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new TreasuryException('Rapprochement modifié simultanément.');
            }
            $this->pdo->prepare(
                'UPDATE rapprochement_lignes_bancaires
                 SET actif = 0 WHERE rapprochement_id = ?'
            )->execute([$reconciliationId]);
            $this->pdo->prepare(
                'UPDATE rapprochement_lignes_comptables
                 SET actif = 0 WHERE rapprochement_id = ?'
            )->execute([$reconciliationId]);
            $this->audit->log(
                'tresorerie.rapprochement_annule',
                $actorId,
                $organisationId,
                $dossierId,
                'rapprochement_bancaire',
                (string) $reconciliationId
            );
            if ($ownsTransaction) {
                $this->pdo->exec('COMMIT');
                $transactionOpen = false;
            }
        } catch (Throwable $exception) {
            if ($transactionOpen) {
                $this->pdo->exec('ROLLBACK');
            }
            throw $exception;
        }
    }

    /** @param list<int> $ids @param list<int> $prefix @return list<array<string,mixed>> */
    private function rows(string $sql, array $prefix, array $ids): array
    {
        $stmt = $this->pdo->prepare(sprintf($sql, implode(',', array_fill(0, count($ids), '?'))));
        $stmt->execute([...$prefix, ...$ids]);
        return $stmt->fetchAll();
    }
}
