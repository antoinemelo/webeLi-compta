<?php
declare(strict_types=1);

namespace Compta\Modules\Facturation;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Compta\EntryService;
use Compta\Modules\Devises\ExchangeRateService;
use Compta\Modules\Tva\VatCalculator;
use Compta\Modules\Tva\VatLineService;
use PDO;
use Throwable;

final class PaymentService
{
    private bool $transactionActive = false;
    private VatLineService $vat;
    private ExchangeRateService $exchange;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly EntryService $entries,
    ) {
        $this->vat = new VatLineService($pdo, $audit);
        $this->exchange = new ExchangeRateService($pdo, $audit);
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
        ?int $bankLineId = null,
        string $currency = 'CHF',
        ?int $exchangeRateId = null,
    ): int {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            $stmt = $this->pdo->prepare(
                'SELECT monnaie FROM dossiers WHERE id = ? AND organisation_id = ?'
            );
            $stmt->execute([$dossierId, $organisationId]);
            $currency = strtoupper((string) $stmt->fetchColumn());
        }
        if (
            !in_array($direction, ['encaissement', 'decaissement'], true)
            || !$this->validDate($date)
            || $amountCents <= 0
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1
        ) {
            throw new BillingException('Paiement invalide.');
        }
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $contactId,
            $direction,
            $date,
            $amountCents,
            $reference,
            $treasuryAccountId,
            $actorId,
            $bankLineId,
            $currency,
            $exchangeRateId
        ): int {
            $this->assertPaymentScope(
                $organisationId,
                $dossierId,
                $contactId,
                $treasuryAccountId
            );
            if ($bankLineId !== null) {
                $this->assertBankLineCapacity(
                    $organisationId,
                    $dossierId,
                    $bankLineId,
                    $direction,
                    $amountCents
                );
            }
            $rate = $this->exchange->snapshot(
                $organisationId,
                $dossierId,
                $currency,
                $date,
                $exchangeRateId
            );
            $baseAmount = ExchangeRateService::convert(
                $amountCents,
                $rate['numerator'],
                $rate['denominator']
            );
            $stmt = $this->pdo->prepare(
                'INSERT INTO paiements
                 (organisation_id, dossier_id, contact_id, sens, date_paiement,
                  montant_centimes, monnaie, reference, compte_tresorerie_id,
                  ligne_bancaire_id, cree_par, devise_base,
                  taux_change_numerateur, taux_change_denominateur,
                  taux_change_date, taux_change_source, montant_base_centimes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId, $dossierId, $contactId, $direction, $date,
                $amountCents, $currency, trim($reference), $treasuryAccountId,
                $bankLineId, $actorId,
                $rate['base_currency'], $rate['numerator'], $rate['denominator'],
                $rate['rate_date'], $rate['source'], $baseAmount,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit->log(
                'facturation.paiement_saisi',
                $actorId,
                $organisationId,
                $dossierId,
                'paiement',
                (string) $id,
                [
                    'sens' => $direction,
                    'montant_centimes' => $amountCents,
                    'monnaie' => $currency,
                    'ligne_bancaire_id' => $bankLineId,
                ]
            );
            return $id;
        }, $bankLineId !== null);
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
                || !in_array($document['statut'], ['emis', 'comptabilise'], true)
                || (int) $document['contact_id'] !== (int) $payment['contact_id']
                || (string) $document['monnaie'] !== (string) $payment['monnaie']
            ) {
                throw new BillingException(
                    'Le paiement sélectionné ne peut être lettré qu’avec une facture '
                    . 'émise du même contact, du même sens et dans la même devise.'
                );
            }
            if (
                $payment['ecriture_id'] !== null
                && (string) $payment['monnaie'] !== (string) $payment['devise_base']
            ) {
                throw new BillingException(
                    'Lettrez un paiement en devise avant sa comptabilisation.'
                );
            }
            $this->assertAllocationCapacity(
                'paiement_id',
                $paymentId,
                (int) $payment['montant_centimes'],
                $documentId,
                abs((int) $document['total_brut_centimes']),
                $amountCents
            );
            $documentBase = ExchangeRateService::convert(
                $amountCents,
                (int) $document['taux_change_numerateur'],
                (int) $document['taux_change_denominateur']
            );
            $paymentBase = ExchangeRateService::convert(
                $amountCents,
                (int) $payment['taux_change_numerateur'],
                (int) $payment['taux_change_denominateur']
            );
            $realized = $payment['sens'] === 'encaissement'
                ? $paymentBase - $documentBase
                : $documentBase - $paymentBase;
            $allocationId = $this->insertAllocation(
                $organisationId,
                $dossierId,
                $paymentId,
                null,
                $documentId,
                $amountCents,
                $actorId,
                $documentBase,
                $paymentBase,
                $realized
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
                || (string) $document['monnaie'] !== (string) $credit['monnaie']
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
            $documentBase = ExchangeRateService::convert(
                $amountCents,
                (int) $document['taux_change_numerateur'],
                (int) $document['taux_change_denominateur']
            );
            return $this->insertAllocation(
                $organisationId,
                $dossierId,
                null,
                $creditId,
                $documentId,
                $amountCents,
                $actorId,
                $documentBase,
                $documentBase
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
            $allocatedAccounts = $this->pdo->prepare(
                "SELECT COUNT(DISTINCT d.compte_collectif_id) AS account_count,
                        MIN(d.compte_collectif_id) AS account_id,
                        SUM(CASE WHEN d.statut <> 'comptabilise'
                                 THEN 1 ELSE 0 END) AS unposted_count
                 FROM allocations a
                 JOIN documents_financiers d ON d.id = a.document_id
                 WHERE a.paiement_id = ? AND a.statut = 'valide'"
            );
            $allocatedAccounts->execute([$paymentId]);
            $allocationScope = $allocatedAccounts->fetch() ?: [];
            if (
                (int) ($allocationScope['account_count'] ?? 0) !== 1
                || (int) ($allocationScope['account_id'] ?? 0) !== $collectiveAccountId
                || (int) ($allocationScope['unposted_count'] ?? 0) !== 0
            ) {
                throw new BillingException(
                    'Les factures lettrées doivent être comptabilisées sur le même compte collectif.'
                );
            }
            $amount = (int) $payment['montant_base_centimes'];
            $incoming = $payment['sens'] === 'encaissement';
            $postingLines = [
                $this->paymentLine(
                    $incoming
                        ? (int) $payment['compte_tresorerie_id']
                        : $collectiveAccountId,
                    $amount,
                    true,
                    $payment
                ),
                $this->paymentLine(
                    $incoming
                        ? $collectiveAccountId
                        : (int) $payment['compte_tresorerie_id'],
                    $amount,
                    false,
                    $payment
                ),
            ];
            $differences = $this->pdo->prepare(
                "SELECT COALESCE(SUM(ecart_change_realise_centimes), 0)
                 FROM allocations
                 WHERE paiement_id = ? AND statut = 'valide'"
            );
            $differences->execute([$paymentId]);
            $realized = (int) $differences->fetchColumn();
            if ($realized !== 0) {
                $mapping = $this->exchange->mapping($organisationId, $dossierId);
                if ($realized > 0) {
                    $postingLines[] = [
                        'compte_id' => $collectiveAccountId,
                        'libelle' => 'Gain de change réalisé',
                        'debit_centimes' => $realized,
                    ];
                    $postingLines[] = [
                        'compte_id' => $mapping['realized_gain'],
                        'libelle' => 'Gain de change réalisé',
                        'credit_centimes' => $realized,
                    ];
                } else {
                    $loss = abs($realized);
                    $postingLines[] = [
                        'compte_id' => $mapping['realized_loss'],
                        'libelle' => 'Perte de change réalisée',
                        'debit_centimes' => $loss,
                    ];
                    $postingLines[] = [
                        'compte_id' => $collectiveAccountId,
                        'libelle' => 'Perte de change réalisée',
                        'credit_centimes' => $loss,
                    ];
                }
            }
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
                'lignes' => $postingLines,
            ], 'paiement:' . $paymentId . ':comptabiliser', $actorId);
            $this->pdo->prepare(
                'UPDATE paiements SET ecriture_id = ? WHERE id = ? AND ecriture_id IS NULL'
            )->execute([$entryId, $paymentId]);
            $this->pdo->prepare(
                "UPDATE allocations SET ecriture_ecart_change_id = ?
                 WHERE paiement_id = ? AND statut = 'valide'
                   AND ecart_change_realise_centimes <> 0"
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

    /** @return list<array<string,mixed>> */
    public function allocations(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.*, d.numero AS document_numero, d.type AS document_type,
                    d.date_echeance, p.reference AS paiement_reference,
                    p.date_paiement, p.sens,
                    COALESCE(NULLIF(c.raison_sociale, ''),
                             trim(c.prenom || ' ' || c.nom)) AS contact
             FROM allocations a
             JOIN documents_financiers d ON d.id = a.document_id
             JOIN contacts c ON c.id = d.contact_id
             LEFT JOIN paiements p ON p.id = a.paiement_id
             WHERE a.organisation_id = ? AND a.dossier_id = ?
             ORDER BY a.cree_le DESC, a.id DESC"
        );
        $stmt->execute([$organisationId, $dossierId]);
        return $stmt->fetchAll();
    }

    public function unallocate(
        int $organisationId,
        int $dossierId,
        int $allocationId,
        ?int $actorId = null,
    ): void {
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $allocationId,
            $actorId
        ): void {
            $stmt = $this->pdo->prepare(
                "SELECT a.*, COALESCE(p.date_paiement, d.date_document) AS date_source
                 FROM allocations a
                 JOIN documents_financiers d ON d.id = a.document_id
                 LEFT JOIN paiements p ON p.id = a.paiement_id
                 WHERE a.id = ? AND a.organisation_id = ? AND a.dossier_id = ?"
            );
            $stmt->execute([$allocationId, $organisationId, $dossierId]);
            $allocation = $stmt->fetch();
            if ($allocation === false) {
                throw new BillingException('Allocation absente du dossier.');
            }
            if ($allocation['statut'] === 'annule') {
                return;
            }
            if ($allocation['ecriture_ecart_change_id'] !== null) {
                throw new BillingException(
                    'Contre-passez le paiement comptabilisé avant de supprimer ce lettrage.'
                );
            }
            $open = $this->pdo->prepare(
                "SELECT 1 FROM periodes
                 WHERE organisation_id = ? AND dossier_id = ?
                   AND ? BETWEEN date_debut AND date_fin
                   AND statut = 'ouverte'
                 LIMIT 1"
            );
            $open->execute([
                $organisationId,
                $dossierId,
                (string) $allocation['date_source'],
            ]);
            if ($open->fetchColumn() === false) {
                throw new BillingException(
                    'Une période close interdit le délettrage.'
                );
            }
            $used = $this->pdo->prepare(
                "SELECT COUNT(*)
                 FROM tva_encaissements te
                 JOIN tva_decompte_sources ds ON ds.encaissement_id = te.id
                 WHERE te.dossier_id = ? AND te.source_type = 'allocation'
                   AND te.source_id = ?"
            );
            $used->execute([$dossierId, (string) $allocationId]);
            if ((int) $used->fetchColumn() > 0) {
                throw new BillingException(
                    'Cette allocation figure déjà dans un décompte TVA.'
                );
            }
            $this->pdo->prepare(
                "UPDATE allocations
                 SET statut = 'annule', annule_le = datetime('now'), annule_par = ?
                 WHERE id = ? AND statut = 'valide'"
            )->execute([$actorId, $allocationId]);
            $this->pdo->prepare(
                "INSERT INTO tva_encaissements
                 (organisation_id, dossier_id, tva_ligne_id, date_paiement,
                  montant_brut_centimes, source_type, source_id, cree_par)
                 SELECT organisation_id, dossier_id, tva_ligne_id, ?,
                        -montant_brut_centimes, 'allocation_annulation', ?, ?
                 FROM tva_encaissements
                 WHERE dossier_id = ? AND source_type = 'allocation'
                   AND source_id = ?"
            )->execute([
                (string) $allocation['date_source'],
                (string) $allocationId,
                $actorId,
                $dossierId,
                (string) $allocationId,
            ]);
            $this->audit->log(
                'facturation.allocation_annulee',
                $actorId,
                $organisationId,
                $dossierId,
                'allocation',
                (string) $allocationId
            );
        }, true);
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

    private function assertBankLineCapacity(
        int $organisationId,
        int $dossierId,
        int $bankLineId,
        string $direction,
        int $amountCents,
    ): void {
        $stmt = $this->pdo->prepare(
            "SELECT l.montant_centimes,
                    COALESCE((
                        SELECT SUM(p.montant_centimes)
                        FROM paiements p
                        WHERE p.ligne_bancaire_id = l.id AND p.statut = 'valide'
                    ), 0) AS utilise_centimes
             FROM lignes_bancaires l
             WHERE l.id = ? AND l.organisation_id = ? AND l.dossier_id = ?"
        );
        $stmt->execute([$bankLineId, $organisationId, $dossierId]);
        $line = $stmt->fetch();
        if ($line === false) {
            throw new BillingException('Ligne bancaire absente du dossier.');
        }
        $signed = (int) $line['montant_centimes'];
        if (
            ($direction === 'encaissement' && $signed <= 0)
            || ($direction === 'decaissement' && $signed >= 0)
            || (int) $line['utilise_centimes'] + $amountCents > abs($signed)
        ) {
            throw new BillingException(
                'Le paiement ne concorde pas avec la ligne bancaire.'
            );
        }
    }

    private function assertPaymentScope(
        int $organisationId,
        int $dossierId,
        int $contactId,
        ?int $treasuryAccountId,
    ): void {
        $contact = $this->pdo->prepare(
            'SELECT 1 FROM contacts
             WHERE id = ? AND organisation_id = ? AND dossier_id = ? AND actif = 1'
        );
        $contact->execute([$contactId, $organisationId, $dossierId]);
        if ($contact->fetchColumn() === false) {
            throw new BillingException('Contact absent du dossier.');
        }
        if ($treasuryAccountId === null) {
            return;
        }
        $account = $this->pdo->prepare(
            'SELECT 1 FROM comptes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND imputable = 1'
        );
        $account->execute([$treasuryAccountId, $organisationId, $dossierId]);
        if ($account->fetchColumn() === false) {
            throw new BillingException('Compte de trésorerie absent du dossier.');
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
        int $documentBaseCents = 0,
        int $paymentBaseCents = 0,
        int $realizedExchangeCents = 0,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO allocations
             (organisation_id, dossier_id, paiement_id, avoir_id,
              document_id, montant_centimes, cree_par,
              montant_document_base_centimes,
              montant_paiement_base_centimes,
              ecart_change_realise_centimes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId, $dossierId, $paymentId, $creditId,
            $documentId, $amountCents, $actorId,
            $documentBaseCents, $paymentBaseCents, $realizedExchangeCents,
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

    /**
     * @param array<string,mixed> $payment
     * @return array<string,mixed>
     */
    private function paymentLine(
        int $accountId,
        int $baseAmount,
        bool $debit,
        array $payment,
    ): array {
        $baseSigned = $debit ? $baseAmount : -$baseAmount;
        $original = (int) $payment['montant_centimes'];
        $line = [
            'compte_id' => $accountId,
            'libelle' => 'Paiement',
            'devise_origine' => (string) $payment['monnaie'],
            'montant_origine_centimes' => $debit ? $original : -$original,
            'devise_base' => (string) $payment['devise_base'],
            'taux_change_numerateur' => (int) $payment['taux_change_numerateur'],
            'taux_change_denominateur' => (int) $payment['taux_change_denominateur'],
            'taux_change_date' => (string) $payment['taux_change_date'],
            'taux_change_source' => (string) $payment['taux_change_source'],
            'montant_base_centimes' => $baseSigned,
            'ecart_arrondi_centimes' => 0,
        ];
        $line[$debit ? 'debit_centimes' : 'credit_centimes'] = $baseAmount;
        return $line;
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
