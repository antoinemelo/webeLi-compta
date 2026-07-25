<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Compta\EntryService;
use PDO;

final class SuggestionService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly EntryService $entries,
    ) {
    }

    public function propose(
        int $organisationId,
        int $dossierId,
        int $bankLineId,
        int $counterpartAccountId,
        string $label,
        int $confidence = 0,
        string $reason = '',
        ?int $actorId = null,
    ): int {
        if ($confidence < 0 || $confidence > 100 || trim($label) === '') {
            throw new TreasuryException('Suggestion de comptabilisation invalide.');
        }
        $this->assertScope($organisationId, $dossierId, $bankLineId, $counterpartAccountId);
        $stmt = $this->pdo->prepare(
            'INSERT INTO suggestions_comptabilisation
             (organisation_id, dossier_id, ligne_bancaire_id, compte_contrepartie_id,
              libelle, confiance, raison, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId, $dossierId, $bankLineId, $counterpartAccountId,
            trim($label), $confidence, trim($reason), $actorId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function accept(
        int $organisationId,
        int $dossierId,
        int $suggestionId,
        int $exerciseId,
        int $journalId,
        ?int $actorId = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, l.date_comptabilisation, l.montant_centimes,
                    l.compte_tresorerie_id, t.compte_comptable_id
             FROM suggestions_comptabilisation s
             JOIN lignes_bancaires l ON l.id = s.ligne_bancaire_id
             JOIN comptes_tresorerie t ON t.id = l.compte_tresorerie_id
             WHERE s.id = ? AND s.organisation_id = ? AND s.dossier_id = ?'
        );
        $stmt->execute([$suggestionId, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new TreasuryException('Suggestion introuvable dans ce dossier.');
        }
        if ($row['statut'] === 'acceptee') {
            return (int) $row['ecriture_id'];
        }
        if ($row['statut'] !== 'proposee') {
            throw new TreasuryException('Cette suggestion ne peut plus être acceptée.');
        }
        $amount = (int) $row['montant_centimes'];
        $lines = $amount > 0
            ? [
                ['compte_id' => (int) $row['compte_comptable_id'], 'debit_centimes' => $amount],
                ['compte_id' => (int) $row['compte_contrepartie_id'], 'credit_centimes' => $amount],
            ]
            : [
                ['compte_id' => (int) $row['compte_contrepartie_id'], 'debit_centimes' => abs($amount)],
                ['compte_id' => (int) $row['compte_comptable_id'], 'credit_centimes' => abs($amount)],
            ];
        $this->pdo->beginTransaction();
        try {
            $entryId = $this->entries->postGenerated([
                'organisation_id' => $organisationId,
                'dossier_id' => $dossierId,
                'exercice_id' => $exerciseId,
                'journal_id' => $journalId,
                'date_comptable' => $row['date_comptabilisation'],
                'libelle' => $row['libelle'],
                'source_type' => 'ligne_bancaire',
                'source_id' => (string) $row['ligne_bancaire_id'],
                'source_action' => 'suggestion_acceptee',
                'lignes' => $lines,
            ], 'treasury-suggestion:' . $suggestionId, $actorId);
            $update = $this->pdo->prepare(
                "UPDATE suggestions_comptabilisation
                 SET statut = 'acceptee', ecriture_id = ?, decidee_le = datetime('now'),
                     decidee_par = ?
                 WHERE id = ? AND statut = 'proposee'"
            );
            $update->execute([$entryId, $actorId, $suggestionId]);
            if ($update->rowCount() !== 1) {
                throw new TreasuryException('Suggestion modifiée simultanément.');
            }
            $this->audit->log(
                'tresorerie.suggestion_acceptee',
                $actorId,
                $organisationId,
                $dossierId,
                'suggestion_comptabilisation',
                (string) $suggestionId,
                ['ecriture_id' => $entryId]
            );
            $this->pdo->exec('COMMIT');
            return $entryId;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK');
            }
            throw $exception;
        }
    }

    private function assertScope(
        int $organisationId,
        int $dossierId,
        int $bankLineId,
        int $counterpartAccountId,
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM lignes_bancaires l
             JOIN comptes c ON c.id = ?
             WHERE l.id = ? AND l.organisation_id = ? AND l.dossier_id = ?
               AND c.organisation_id = ? AND c.dossier_id = ? AND c.imputable = 1'
        );
        $stmt->execute([
            $counterpartAccountId, $bankLineId, $organisationId, $dossierId,
            $organisationId, $dossierId,
        ]);
        if ($stmt->fetchColumn() === false) {
            throw new TreasuryException('Ligne bancaire ou compte hors du dossier.');
        }
    }
}
