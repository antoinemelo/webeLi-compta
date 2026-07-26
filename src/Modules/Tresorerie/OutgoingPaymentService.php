<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Compta\EntryService;
use Compta\Modules\Facturation\PaymentService;
use PDO;
use Throwable;

final class OutgoingPaymentService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly EntryService $entries,
        private readonly PaymentService $payments,
        private readonly ReconciliationService $reconciliations,
        private readonly Pain001Generator $generator = new Pain001Generator(),
    ) {
    }

    /**
     * @param list<array{document_id:int,amount_cents:int}> $orders
     */
    public function prepare(
        int $organisationId,
        int $dossierId,
        int $treasuryAccountId,
        string $executionDate,
        array $orders,
        string $idempotencyKey,
        ?int $actorId = null,
    ): int {
        if (!$this->validDate($executionDate) || $orders === [] || trim($idempotencyKey) === '') {
            throw new TreasuryException('Lot de paiements invalide.');
        }
        $messageId = 'COMPTA-' . $dossierId . '-'
            . strtoupper(substr(hash('sha256', trim($idempotencyKey)), 0, 20));
        $transactionOpen = false;
        $this->pdo->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;
        try {
            $existing = $this->pdo->prepare(
                'SELECT id, compte_tresorerie_id, date_execution
                 FROM lots_paiements_sortants
                 WHERE dossier_id = ? AND message_id = ?'
            );
            $existing->execute([$dossierId, $messageId]);
            $existingBatch = $existing->fetch();
            if ($existingBatch !== false) {
                $savedOrders = $this->pdo->prepare(
                    'SELECT document_id, montant_centimes
                     FROM ordres_paiement_sortants WHERE lot_id = ? ORDER BY id'
                );
                $savedOrders->execute([(int) $existingBatch['id']]);
                $saved = array_map(
                    static fn (array $row): array => [
                        'document_id' => (int) $row['document_id'],
                        'amount_cents' => (int) $row['montant_centimes'],
                    ],
                    $savedOrders->fetchAll()
                );
                $requested = array_map(
                    static fn (array $order): array => [
                        'document_id' => (int) ($order['document_id'] ?? 0),
                        'amount_cents' => (int) ($order['amount_cents'] ?? 0),
                    ],
                    $orders
                );
                if (
                    (int) $existingBatch['compte_tresorerie_id'] !== $treasuryAccountId
                    || (string) $existingBatch['date_execution'] !== $executionDate
                    || $saved !== $requested
                ) {
                    throw new TreasuryException(
                        'Clé idempotente déjà utilisée pour un autre lot.'
                    );
                }
                $this->pdo->exec('COMMIT');
                $transactionOpen = false;
                return (int) $existingBatch['id'];
            }
            $treasury = $this->treasuryAccount(
                $organisationId,
                $dossierId,
                $treasuryAccountId
            );
            BankCoordinates::assertIban((string) $treasury['iban']);
            BankCoordinates::assertBic((string) $treasury['bic']);

            $selected = [];
            $seen = [];
            $total = 0;
            foreach ($orders as $order) {
                $documentId = (int) ($order['document_id'] ?? 0);
                $amount = (int) ($order['amount_cents'] ?? 0);
                if ($documentId < 1 || $amount < 1 || isset($seen[$documentId])) {
                    throw new TreasuryException('Sélection de dettes invalide.');
                }
                $seen[$documentId] = true;
                $document = $this->payableDocument(
                    $organisationId,
                    $dossierId,
                    $documentId
                );
                if ($amount > (int) $document['ouvert_centimes']) {
                    throw new TreasuryException(
                        'Le paiement dépasse le solde ouvert de la dette.'
                    );
                }
                if ($document['monnaie'] !== $treasury['monnaie']) {
                    throw new TreasuryException(
                        'La dette et le compte de paiement doivent avoir la même devise.'
                    );
                }
                $document['iban_paiement'] = BankCoordinates::assertIban(
                    (string) $document['iban_paiement']
                );
                $document['bic_paiement'] = BankCoordinates::assertBic(
                    (string) $document['bic_paiement']
                );
                $document['montant_ordre'] = $amount;
                $selected[] = $document;
                $total += $amount;
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO lots_paiements_sortants
                 (organisation_id, dossier_id, compte_tresorerie_id, message_id,
                  date_execution, monnaie, nombre_ordres, total_centimes, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $organisationId,
                $dossierId,
                $treasuryAccountId,
                $messageId,
                $executionDate,
                $treasury['monnaie'],
                count($selected),
                $total,
                $actorId,
            ]);
            $batchId = (int) $this->pdo->lastInsertId();
            $insertOrder = $this->pdo->prepare(
                'INSERT INTO ordres_paiement_sortants
                 (lot_id, organisation_id, dossier_id, document_id, contact_id,
                  beneficiaire_snapshot, adresse_snapshot_json, iban_snapshot,
                  bic_snapshot, reference, montant_centimes, monnaie)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($selected as $document) {
                $name = trim((string) $document['raison_sociale']) !== ''
                    ? (string) $document['raison_sociale']
                    : trim($document['prenom'] . ' ' . $document['nom']);
                $address = [
                    'ligne1' => (string) ($document['ligne1'] ?? ''),
                    'ligne2' => (string) ($document['ligne2'] ?? ''),
                    'code_postal' => (string) ($document['code_postal'] ?? ''),
                    'localite' => (string) ($document['localite'] ?? ''),
                    'pays' => (string) ($document['pays'] ?? 'CH'),
                ];
                $insertOrder->execute([
                    $batchId,
                    $organisationId,
                    $dossierId,
                    $document['id'],
                    $document['contact_id'],
                    $name,
                    json_encode(
                        $address,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                    ),
                    $document['iban_paiement'],
                    $document['bic_paiement'],
                    $document['numero_externe'] ?: $document['numero'],
                    $document['montant_ordre'],
                    $document['monnaie'],
                ]);
            }
            $this->audit->log(
                'paiements.lot_prepare',
                $actorId,
                $organisationId,
                $dossierId,
                'lot_paiement_sortant',
                (string) $batchId,
                ['nombre_ordres' => count($selected), 'total_centimes' => $total]
            );
            $this->pdo->exec('COMMIT');
            $transactionOpen = false;
            return $batchId;
        } catch (Throwable $exception) {
            if ($transactionOpen) {
                $this->pdo->exec('ROLLBACK');
            }
            throw $exception;
        }
    }

    /** @return array{content:string,hash:string,filename:string} */
    public function export(
        int $organisationId,
        int $dossierId,
        int $batchId,
        int $expectedVersion,
        ?int $actorId = null,
    ): array {
        $transactionOpen = false;
        $this->pdo->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;
        try {
            $batch = $this->batch($organisationId, $dossierId, $batchId);
            if ($batch['statut'] !== 'prepare') {
                $this->pdo->exec('COMMIT');
                $transactionOpen = false;
                return $this->exportResult($batch);
            }
            if ((int) $batch['version'] !== $expectedVersion) {
                throw new TreasuryException('Lot de paiements modifié simultanément.');
            }
            $orders = $this->orders($batchId);
            $content = $this->generator->generate([
                'message_id' => (string) $batch['message_id'],
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'execution_date' => (string) $batch['date_execution'],
                'currency' => (string) $batch['monnaie'],
                'debtor_name' => (string) $batch['organisation_nom'],
                'debtor_iban' => (string) $batch['compte_iban'],
                'debtor_bic' => (string) $batch['compte_bic'],
            ], array_map(static fn (array $order): array => [
                'instruction_id' => 'ORD-' . $order['id'],
                'end_to_end_id' => (string) $order['reference'],
                'amount_cents' => (int) $order['montant_centimes'],
                'creditor_name' => (string) $order['beneficiaire_snapshot'],
                'creditor_iban' => (string) $order['iban_snapshot'],
                'creditor_bic' => (string) $order['bic_snapshot'],
                'address' => json_decode(
                    (string) $order['adresse_snapshot_json'],
                    true,
                    16,
                    JSON_THROW_ON_ERROR
                ),
                'remittance' => (string) $order['reference'],
            ], $orders));
            $hash = hash('sha256', $content);
            $update = $this->pdo->prepare(
                "UPDATE lots_paiements_sortants
                 SET statut = 'exporte', contenu_pain001 = ?, empreinte_sha256 = ?,
                     exporte_le = datetime('now'), exporte_par = ?,
                     version = version + 1
                 WHERE id = ? AND statut = 'prepare' AND version = ?"
            );
            $update->execute([$content, $hash, $actorId, $batchId, $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new TreasuryException('Lot de paiements modifié simultanément.');
            }
            $this->pdo->prepare(
                "UPDATE ordres_paiement_sortants
                 SET statut = 'exporte' WHERE lot_id = ? AND statut = 'prepare'"
            )->execute([$batchId]);
            $this->audit->log(
                'paiements.pain001_exporte',
                $actorId,
                $organisationId,
                $dossierId,
                'lot_paiement_sortant',
                (string) $batchId,
                ['empreinte_sha256' => $hash, 'transmis' => false]
            );
            $this->pdo->exec('COMMIT');
            $transactionOpen = false;
            return [
                'content' => $content,
                'hash' => $hash,
                'filename' => (string) $batch['message_id'] . '.xml',
            ];
        } catch (Throwable $exception) {
            if ($transactionOpen) {
                $this->pdo->exec('ROLLBACK');
            }
            throw $exception;
        }
    }

    public function confirmFromStatement(
        int $organisationId,
        int $dossierId,
        int $batchId,
        int $bankLineId,
        int $exerciseId,
        int $journalId,
        ?int $feeAccountId,
        ?int $actorId = null,
    ): int {
        $this->pdo->beginTransaction();
        try {
            $batch = $this->batch($organisationId, $dossierId, $batchId);
            if ($batch['statut'] === 'confirme') {
                $this->pdo->exec('COMMIT');
                return (int) $batch['rapprochement_id'];
            }
            if ($batch['statut'] !== 'exporte') {
                throw new TreasuryException(
                    'Le lot doit être exporté avant confirmation bancaire.'
                );
            }
            $line = $this->bankLine(
                $organisationId,
                $dossierId,
                $bankLineId,
                (int) $batch['compte_tresorerie_id']
            );
            $difference = abs((int) $line['montant_centimes'])
                - (int) $batch['total_centimes'];
            if (
                (int) $line['montant_centimes'] >= 0
                || $difference < 0
                || $difference !== (int) $line['frais_centimes']
            ) {
                throw new TreasuryException(
                    'Le relevé ne concorde pas avec le lot et ses frais.'
                );
            }
            if ($difference > 0) {
                $this->assertAccount(
                    $organisationId,
                    $dossierId,
                    (int) $feeAccountId
                );
            }
            $orders = $this->orders($batchId);
            $accountingLines = [];
            foreach ($orders as $order) {
                $document = $this->payableDocument(
                    $organisationId,
                    $dossierId,
                    (int) $order['document_id']
                );
                if ((int) $order['montant_centimes'] > (int) $document['ouvert_centimes']) {
                    throw new TreasuryException(
                        'Le solde d’une dette a changé depuis la préparation.'
                    );
                }
                $paymentId = $this->payments->create(
                    $organisationId,
                    $dossierId,
                    (int) $order['contact_id'],
                    'decaissement',
                    (string) $line['date_comptabilisation'],
                    (int) $order['montant_centimes'],
                    (string) $order['reference'],
                    (int) $batch['compte_comptable_id'],
                    $actorId,
                    $bankLineId,
                    (string) $batch['monnaie']
                );
                $this->payments->allocatePayment(
                    $organisationId,
                    $dossierId,
                    $paymentId,
                    (int) $order['document_id'],
                    (int) $order['montant_centimes'],
                    $actorId
                );
                $entryId = $this->payments->post(
                    $organisationId,
                    $dossierId,
                    $paymentId,
                    (int) $document['compte_collectif_id'],
                    $exerciseId,
                    $journalId,
                    $actorId
                );
                $accountingLines[] = $this->bankAccountingLine(
                    $entryId,
                    (int) $batch['compte_comptable_id']
                );
                $this->pdo->prepare(
                    "UPDATE ordres_paiement_sortants
                     SET statut = 'confirme', paiement_id = ?
                     WHERE id = ? AND statut = 'exporte'"
                )->execute([$paymentId, $order['id']]);
            }
            if ($difference > 0) {
                $feeEntry = $this->entries->postGenerated([
                    'organisation_id' => $organisationId,
                    'dossier_id' => $dossierId,
                    'exercice_id' => $exerciseId,
                    'journal_id' => $journalId,
                    'date_comptable' => (string) $line['date_comptabilisation'],
                    'libelle' => 'Frais bancaires — ' . $batch['message_id'],
                    'source_type' => 'lot_paiement_sortant',
                    'source_id' => (string) $batchId,
                    'source_action' => 'frais_bancaires',
                    'lignes' => [
                        [
                            'compte_id' => (int) $feeAccountId,
                            'debit_centimes' => $difference,
                        ],
                        [
                            'compte_id' => (int) $batch['compte_comptable_id'],
                            'credit_centimes' => $difference,
                        ],
                    ],
                ], 'lot-paiement:' . $batchId . ':frais', $actorId);
                $accountingLines[] = $this->bankAccountingLine(
                    $feeEntry,
                    (int) $batch['compte_comptable_id']
                );
            }
            $reconciliationId = $this->reconciliations->reconcile(
                $organisationId,
                $dossierId,
                (int) $batch['compte_tresorerie_id'],
                [$bankLineId],
                $accountingLines,
                0,
                'Lot ' . $batch['message_id'],
                $actorId
            );
            $this->pdo->prepare(
                "UPDATE lots_paiements_sortants
                 SET statut = 'confirme', confirme_le = datetime('now'),
                     confirme_par = ?, ligne_bancaire_id = ?,
                     rapprochement_id = ?, frais_centimes = ?,
                     version = version + 1
                 WHERE id = ? AND statut = 'exporte'"
            )->execute([
                $actorId,
                $bankLineId,
                $reconciliationId,
                $difference,
                $batchId,
            ]);
            $this->audit->log(
                'paiements.lot_confirme_par_releve',
                $actorId,
                $organisationId,
                $dossierId,
                'lot_paiement_sortant',
                (string) $batchId,
                [
                    'ligne_bancaire_id' => $bankLineId,
                    'rapprochement_id' => $reconciliationId,
                    'frais_centimes' => $difference,
                ]
            );
            $this->pdo->exec('COMMIT');
            return $reconciliationId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK');
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function treasuryAccount(int $organisationId, int $dossierId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, c.id AS compte_comptable_id
             FROM comptes_tresorerie t
             JOIN comptes c ON c.id = t.compte_comptable_id
             WHERE t.id = ? AND t.organisation_id = ? AND t.dossier_id = ?
               AND t.actif = 1 AND c.actif = 1'
        );
        $stmt->execute([$id, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new TreasuryException('Compte de paiement absent du dossier.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function payableDocument(
        int $organisationId,
        int $dossierId,
        int $documentId,
    ): array {
        $stmt = $this->pdo->prepare(
            "SELECT d.*, c.raison_sociale, c.prenom, c.nom,
                    c.iban_paiement, c.bic_paiement,
                    a.ligne1, a.ligne2, a.code_postal, a.localite, a.pays,
                    abs(d.total_brut_centimes) - COALESCE((
                        SELECT SUM(x.montant_centimes) FROM allocations x
                        WHERE x.document_id = d.id AND x.statut = 'valide'
                    ), 0) AS ouvert_centimes
             FROM documents_financiers d
             JOIN contacts c ON c.id = d.contact_id
             JOIN contact_roles cr ON cr.contact_id = c.id AND cr.role = 'fournisseur'
             LEFT JOIN adresses_contacts a ON a.id = (
                 SELECT a2.id FROM adresses_contacts a2
                 WHERE a2.contact_id = c.id AND a2.actif = 1
                 ORDER BY CASE a2.type WHEN 'facturation' THEN 0 ELSE 1 END, a2.id
                 LIMIT 1
             )
             WHERE d.id = ? AND d.organisation_id = ? AND d.dossier_id = ?
               AND d.type = 'facture_fournisseur'
               AND d.workflow = 'depense' AND d.statut = 'comptabilise'"
        );
        $stmt->execute([$documentId, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false || (int) $row['ouvert_centimes'] <= 0) {
            throw new TreasuryException(
                'Dette fournisseur absente, non comptabilisée ou déjà soldée.'
            );
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function batch(int $organisationId, int $dossierId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.*, o.nom AS organisation_nom,
                    t.iban AS compte_iban, t.bic AS compte_bic,
                    t.compte_comptable_id
             FROM lots_paiements_sortants l
             JOIN organisations o ON o.id = l.organisation_id
             JOIN comptes_tresorerie t ON t.id = l.compte_tresorerie_id
             WHERE l.id = ? AND l.organisation_id = ? AND l.dossier_id = ?'
        );
        $stmt->execute([$id, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new TreasuryException('Lot de paiements absent du dossier.');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function orders(int $batchId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ordres_paiement_sortants WHERE lot_id = ? ORDER BY id'
        );
        $stmt->execute([$batchId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed> */
    private function bankLine(
        int $organisationId,
        int $dossierId,
        int $lineId,
        int $treasuryAccountId,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT l.* FROM lignes_bancaires l
             WHERE l.id = ? AND l.organisation_id = ? AND l.dossier_id = ?
               AND l.compte_tresorerie_id = ?
               AND NOT EXISTS (
                   SELECT 1 FROM rapprochement_lignes_bancaires rb
                   WHERE rb.ligne_bancaire_id = l.id AND rb.actif = 1
               )'
        );
        $stmt->execute([
            $lineId,
            $organisationId,
            $dossierId,
            $treasuryAccountId,
        ]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new TreasuryException(
                'Ligne bancaire absente, déjà rapprochée ou hors du compte.'
            );
        }
        return $row;
    }

    private function assertAccount(int $organisationId, int $dossierId, int $accountId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM comptes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND imputable = 1'
        );
        $stmt->execute([$accountId, $organisationId, $dossierId]);
        if ($stmt->fetchColumn() === false) {
            throw new TreasuryException('Compte de frais absent du dossier.');
        }
    }

    private function bankAccountingLine(int $entryId, int $accountId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM lignes_ecriture
             WHERE ecriture_id = ? AND compte_id = ?'
        );
        $stmt->execute([$entryId, $accountId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new TreasuryException('Ligne comptable bancaire introuvable.');
        }
        return (int) $id;
    }

    /** @param array<string,mixed> $batch
     * @return array{content:string,hash:string,filename:string}
     */
    private function exportResult(array $batch): array
    {
        if ($batch['contenu_pain001'] === null) {
            throw new TreasuryException('Archive pain.001 absente.');
        }
        return [
            'content' => (string) $batch['contenu_pain001'],
            'hash' => (string) $batch['empreinte_sha256'],
            'filename' => (string) $batch['message_id'] . '.xml',
        ];
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
