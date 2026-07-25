<?php
declare(strict_types=1);

namespace Compta\Modules\Facturation;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Compta\EntryService;
use Compta\Modules\Tva\VatCalculator;
use Compta\Modules\Tva\VatLineService;
use PDO;
use Throwable;

final class PaymentService
{
    private bool $transactionActive = false;
    private VatLineService $vat;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly EntryService $entries,
    ) {
        $this->vat = new VatLineService($pdo, $audit);
    }

    public function create(
        int $organisationId,
        int $dossierId,
        int $contactId,
        string $direction,
        string $date,
        int $amountCents,
        string $reference = '',
        ?int $treasuryAccountId = null,
        ?int $actorId = null,
    ): int {
        if (
            !in_array($direction, ['encaissement', 'decaissement'], true)
            || !$this->validDate($date)
            || $amountCents <= 0
        ) {
            throw new BillingException('Paiement invalide.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO paiements
             (organisation_id, dossier_id, contact_id, sens, date_paiement,
              montant_centimes, reference, compte_tresorerie_id, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId, $dossierId, $contactId, $direction, $date,
            $amountCents, trim($reference), $treasuryAccountId, $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'facturation.paiement_saisi',
            $actorId,
            $organisationId,
            $dossierId,
            'paiement',
            (string) $id,
            ['sens' => $direction, 'montant_centimes' => $amountCents]
        );
        return $id;
    }

    public function allocatePayment(
        int $organisationId,
        int $dossierId,
        int $paymentId,
        int $documentId,
        int $amountCents,
        ?int $actorId = null,
    ): int {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $paymentId,
            $documentId,
            $amountCents,
            $actorId
        ): int {
            $payment = $this->payment($organisationId, $dossierId, $paymentId);
            $document = $this->document($organisationId, $dossierId, $documentId);
            $expectedType = $payment['sens'] === 'encaissement'
                ? 'facture_client'
                : 'facture_fournisseur';
            if (
                $payment['statut'] !== 'valide'
                || $document['type'] !== $expectedType
                || (int) $document['contact_id'] !== (int) $payment['contact_id']
            ) {
                throw new BillingException('Paiement et facture incompatibles.');
            }
            $this->assertAllocationCapacity(
                'paiement_id',
                $paymentId,
                (int) $payment['montant_centimes'],
                $documentId,
                abs((int) $document['total_brut_centimes']),
                $amountCents
            );
            $allocationId = $this->insertAllocation(
                $organisationId,
                $dossierId,
                $paymentId,
                null,
                $documentId,
                $amountCents,
                $actorId
            );
            $this->recordVatAllocation(
                $organisationId,
                $dossierId,
                $documentId,
                $allocationId,
                (string) $payment['date_paiement'],
                $actorId
            );
            return $allocationId;
        }, true);
    }

    public function allocateCredit(
        int $organisationId,
        int $dossierId,
        int $creditId,
        int $documentId,
        int $amountCents,
        ?int $actorId = null,
    ): int {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $creditId,
            $documentId,
            $amountCents,
            $actorId
        ): int {
            $credit = $this->document($organisationId, $dossierId, $creditId);
            $document = $this->document($organisationId, $dossierId, $documentId);
            $expected = $credit['type'] === 'avoir_client'
                ? 'facture_client'
                : ($credit['type'] === 'avoir_fournisseur'
                    ? 'facture_fournisseur'
                    : '');
            if (
                $expected === ''
                || $document['type'] !== $expected
                || (int) $document['contact_id'] !== (int) $credit['contact_id']
                || !in_array($credit['statut'], ['emis', 'comptabilise'], true)
            ) {
                throw new BillingException('Avoir et facture incompatibles.');
            }
            $this->assertAllocationCapacity(
                'avoir_id',
                $creditId,
                abs((int) $credit['total_brut_centimes']),
                $documentId,
                abs((int) $document['total_brut_centimes']),
                $amountCents
            );
            return $this->insertAllocation(
                $organisationId,
                $dossierId,
                null,
                $creditId,
                $documentId,
                $amountCents,
                $actorId
            );
        }, true);
    }

    public function post(
        int $organisationId,
        int $dossierId,
        int $paymentId,
        int $collectiveAccountId,
        int $exerciseId,
        int $journalId,
        ?int $actorId = null,
    ): int {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $paymentId,
            $collectiveAccountId,
            $exerciseId,
            $journalId,
            $actorId
        ): int {
            $payment = $this->payment($organisationId, $dossierId, $paymentId);
            if ($payment['ecriture_id'] !== null) {
                return (int) $payment['ecriture_id'];
            }
            if ($payment['compte_tresorerie_id'] === null || $payment['statut'] !== 'valide') {
                throw new BillingException('Compte de trésorerie absent ou paiement annulé.');
            }
            $amount = (int) $payment['montant_centimes'];
            $incoming = $payment['sens'] === 'encaissement';
            $entryId = $this->entries->postGenerated([
                'organisation_id' => $organisationId,
                'dossier_id' => $dossierId,
                'exercice_id' => $exerciseId,
                'journal_id' => $journalId,
                'date_comptable' => $payment['date_paiement'],
                'libelle' => 'Paiement — ' . ($payment['reference'] ?: $paymentId),
                'reference' => $payment['reference'],
                'source_type' => 'paiement',
                'source_id' => (string) $paymentId,
                'source_action' => 'comptabiliser',
                'lignes' => [
                    [
                        'compte_id' => $incoming
                            ? (int) $payment['compte_tresorerie_id']
                            : $collectiveAccountId,
                        'libelle' => 'Paiement',
                        'debit_centimes' => $amount,
                    ],
                    [
                        'compte_id' => $incoming
                            ? $collectiveAccountId
                            : (int) $payment['compte_tresorerie_id'],
                        'libelle' => 'Paiement',
                        'credit_centimes' => $amount,
                    ],
                ],
            ], 'paiement:' . $paymentId . ':comptabiliser', $actorId);
            $this->pdo->prepare(
                'UPDATE paiements SET ecriture_id = ? WHERE id = ? AND ecriture_id IS NULL'
            )->execute([$entryId, $paymentId]);
            $this->audit->log(
                'facturation.paiement_comptabilise',
                $actorId,
                $organisationId,
                $dossierId,
                'paiement',
                (string) $paymentId,
                ['ecriture_id' => $entryId]
            );
            return $entryId;
        });
    }

    /** @return list<array<string,mixed>> */
    public function payments(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.*, c.raison_sociale, c.prenom, c.nom,
                    COALESCE((
                        SELECT SUM(a.montant_centimes) FROM allocations a
                        WHERE a.paiement_id = p.id AND a.statut = 'valide'
                    ), 0) AS alloue_centimes
             FROM paiements p
             JOIN contacts c ON c.id = p.contact_id
             WHERE p.organisation_id = ? AND p.dossier_id = ?
             ORDER BY p.date_paiement DESC, p.id DESC"
        );
        $stmt->execute([$organisationId, $dossierId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['non_alloue_centimes'] = max(
                0,
                (int) $row['montant_centimes'] - (int) $row['alloue_centimes']
            );
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,mixed> */
    private function payment(int $organisationId, int $dossierId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM paiements
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$id, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new BillingException('Paiement absent du dossier.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function document(int $organisationId, int $dossierId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM documents_financiers
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND statut IN ('emis', 'comptabilise')"
        );
        $stmt->execute([$id, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new BillingException('Facture absente ou non émise.');
        }
        return $row;
    }

    private function assertAllocationCapacity(
        string $sourceColumn,
        int $sourceId,
        int $sourceTotal,
        int $documentId,
        int $documentTotal,
        int $amountCents,
    ): void {
        if ($amountCents <= 0) {
            throw new BillingException('Le montant alloué doit être positif.');
        }
        $source = $this->pdo->prepare(
            "SELECT COALESCE(SUM(montant_centimes), 0) FROM allocations
             WHERE {$sourceColumn} = ? AND statut = 'valide'"
        );
        $source->execute([$sourceId]);
        $target = $this->pdo->prepare(
            "SELECT COALESCE(SUM(montant_centimes), 0) FROM allocations
             WHERE document_id = ? AND statut = 'valide'"
        );
        $target->execute([$documentId]);
        if (
            (int) $source->fetchColumn() + $amountCents > $sourceTotal
            || (int) $target->fetchColumn() + $amountCents > $documentTotal
        ) {
            throw new BillingException('Surallocation refusée, même pour un centime.');
        }
    }

    private function insertAllocation(
        int $organisationId,
        int $dossierId,
        ?int $paymentId,
        ?int $creditId,
        int $documentId,
        int $amountCents,
        ?int $actorId,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO allocations
             (organisation_id, dossier_id, paiement_id, avoir_id,
              document_id, montant_centimes, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId, $dossierId, $paymentId, $creditId,
            $documentId, $amountCents, $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'facturation.allocation_creee',
            $actorId,
            $organisationId,
            $dossierId,
            'allocation',
            (string) $id,
            [
                'document_id' => $documentId,
                'montant_centimes' => $amountCents,
            ]
        );
        return $id;
    }

    private function recordVatAllocation(
        int $organisationId,
        int $dossierId,
        int $documentId,
        int $allocationId,
        string $date,
        ?int $actorId,
    ): void {
        $documentAllocation = $this->pdo->prepare(
            "SELECT COALESCE(SUM(montant_centimes), 0) FROM allocations
             WHERE document_id = ? AND statut = 'valide'"
        );
        $documentAllocation->execute([$documentId]);
        $cumulative = (int) $documentAllocation->fetchColumn();
        $stmt = $this->pdo->prepare(
            'SELECT id, total_brut_centimes
             FROM tva_lignes
             WHERE document_type IN (\'facture_client\', \'facture_fournisseur\')
               AND document_id = ?
             ORDER BY id'
        );
        $stmt->execute([(string) $documentId]);
        $lines = $stmt->fetchAll();
        if ($lines === []) {
            return;
        }
        $grossTotal = array_sum(array_map(
            static fn (array $line): int => abs((int) $line['total_brut_centimes']),
            $lines
        ));
        if ($grossTotal <= 0) {
            return;
        }
        $distributed = 0;
        $count = count($lines);
        foreach ($lines as $index => $line) {
            $alreadyStmt = $this->pdo->prepare(
                'SELECT COALESCE(SUM(montant_brut_centimes), 0)
                 FROM tva_encaissements WHERE tva_ligne_id = ?'
            );
            $alreadyStmt->execute([(int) $line['id']]);
            $already = abs((int) $alreadyStmt->fetchColumn());
            $target = $index === $count - 1
                ? $cumulative - $distributed
                : VatCalculator::divideRounded(
                    $cumulative * abs((int) $line['total_brut_centimes']),
                    $grossTotal
                );
            $distributed += $target;
            $delta = $target - $already;
            if ($delta > 0) {
                $sign = (int) $line['total_brut_centimes'] < 0 ? -1 : 1;
                $this->vat->recordPayment(
                    $organisationId,
                    $dossierId,
                    (int) $line['id'],
                    $date,
                    $sign * $delta,
                    'allocation',
                    (string) $allocationId,
                    $actorId
                );
            }
        }
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
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
        } catch (Throwable $e) {
            if ($this->transactionActive) {
                $immediate ? $this->pdo->exec('ROLLBACK') : $this->pdo->rollBack();
                $this->transactionActive = false;
            }
            throw $e;
        }
    }
}
