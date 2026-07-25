<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie;

use Compta\Modules\Compta\EntryService;
use PDO;

final class InternalTransferService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly EntryService $entries,
    ) {
    }

    public function post(
        int $organisationId,
        int $dossierId,
        int $sourceTreasuryId,
        int $destinationTreasuryId,
        int $exerciseId,
        int $journalId,
        string $date,
        int $amountCents,
        string $label,
        string $idempotencyKey,
        ?int $actorId = null,
    ): int {
        if (
            $sourceTreasuryId === $destinationTreasuryId
            || $amountCents <= 0
            || trim($label) === ''
            || trim($idempotencyKey) === ''
        ) {
            throw new TreasuryException('Transfert interne invalide.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, compte_comptable_id, monnaie
             FROM comptes_tresorerie
             WHERE organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND id IN (?, ?)'
        );
        $stmt->execute([
            $organisationId, $dossierId, $sourceTreasuryId, $destinationTreasuryId,
        ]);
        $accounts = [];
        foreach ($stmt->fetchAll() as $account) {
            $accounts[(int) $account['id']] = $account;
        }
        if (count($accounts) !== 2) {
            throw new TreasuryException('Un compte de transfert est hors du dossier.');
        }
        if ($accounts[$sourceTreasuryId]['monnaie'] !== $accounts[$destinationTreasuryId]['monnaie']) {
            throw new TreasuryException('Les transferts multidevises ne sont pas pris en charge.');
        }
        return $this->entries->postGenerated([
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
            'exercice_id' => $exerciseId,
            'journal_id' => $journalId,
            'date_comptable' => $date,
            'libelle' => trim($label),
            'source_type' => 'transfert_interne',
            'source_id' => $sourceTreasuryId . ':' . $destinationTreasuryId,
            'source_action' => 'transfert',
            'lignes' => [
                [
                    'compte_id' => (int) $accounts[$destinationTreasuryId]['compte_comptable_id'],
                    'libelle' => trim($label),
                    'debit_centimes' => $amountCents,
                ],
                [
                    'compte_id' => (int) $accounts[$sourceTreasuryId]['compte_comptable_id'],
                    'libelle' => trim($label),
                    'credit_centimes' => $amountCents,
                ],
            ],
        ], 'treasury-transfer:' . trim($idempotencyKey), $actorId);
    }
}
