<?php
declare(strict_types=1);

namespace Compta\Modules\Facturation;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Compta\EntryService;
use Compta\Modules\Configuration\Application\PaymentTermsService;
use Compta\Modules\Devises\ExchangeRateService;
use Compta\Modules\Tva\VatCalculator;
use Compta\Modules\Tva\VatLineService;
use PDO;
use Throwable;

final class BillingService
{
    private bool $transactionActive = false;
    private ContactService $contacts;
    private PaymentTermsService $paymentTerms;
    private VatLineService $vat;
    private ExchangeRateService $exchange;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly EntryService $entries,
    ) {
        $this->contacts = new ContactService($pdo, $audit);
        $this->paymentTerms = new PaymentTermsService($pdo);
        $this->vat = new VatLineService($pdo, $audit);
        $this->exchange = new ExchangeRateService($pdo, $audit);
    }

    /**
     * @param list<array{
     *   libelle:string,quantite_milli:int,prix_unitaire_centimes:int,
     *   mode_saisie:string,compte_id:int,code_tva_id:int,date_prestation:string,
     *   deduction_bp?:?int,motif_correction?:string,tdfn_id?:?int
     * }> $lines
     */
    public function createDraft(
        int $organisationId,
        int $dossierId,
        string $type,
        int $contactId,
        string $documentDate,
        string $dueDate,
        array $lines,
        ?int $collectiveAccountId = null,
        string $externalNumber = '',
        ?int $originDocumentId = null,
        ?int $attachmentId = null,
        ?int $actorId = null,
        string $workflow = 'facturation',
        string $generationKey = '',
        string $currency = '',
        ?int $exchangeRateId = null,
    ): int {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $type,
            $contactId,
            $documentDate,
            $dueDate,
            $lines,
            $collectiveAccountId,
            $externalNumber,
            $originDocumentId,
            $attachmentId,
            $actorId,
            $workflow,
            $generationKey,
            $currency,
            $exchangeRateId
        ): int {
            $this->assertType($type);
            if (!in_array($workflow, ['facturation', 'depense'], true)) {
                throw new BillingException('Workflow de document invalide.');
            }
            $this->assertDate($documentDate);
            $paymentTerm = null;
            if (trim($dueDate) === '') {
                $direction = str_contains($type, 'fournisseur')
                    ? 'fournisseur'
                    : 'client';
                $paymentTerm = $this->paymentTerms->resolveDefault(
                    $organisationId,
                    $dossierId,
                    $direction,
                    $documentDate
                );
                if ($paymentTerm === null) {
                    throw new BillingException(
                        'Aucune condition de paiement par défaut ne couvre cette date.'
                    );
                }
                $dueDate = $paymentTerm['due_date'];
            }
            $this->assertDate($dueDate);
            if ($dueDate < $documentDate || $lines === []) {
                throw new BillingException('Dates ou lignes du document invalides.');
            }
            if (
                str_contains($type, 'fournisseur')
                && trim($externalNumber) === ''
            ) {
                throw new BillingException(
                    'La référence de facture fournisseur est requise.'
                );
            }
            $this->assertDraftReferences(
                $organisationId,
                $dossierId,
                $type,
                $contactId,
                $collectiveAccountId,
                $lines
            );
            $contact = $this->contacts->snapshot($organisationId, $dossierId, $contactId);
            if (trim($currency) === '') {
                $baseCurrency = $this->pdo->prepare(
                    'SELECT monnaie FROM dossiers
                     WHERE id = ? AND organisation_id = ?'
                );
                $baseCurrency->execute([$dossierId, $organisationId]);
                $currency = (string) $baseCurrency->fetchColumn();
            }
            $rate = $this->exchange->snapshot(
                $organisationId,
                $dossierId,
                $currency,
                $documentDate,
                $exchangeRateId
            );
            $snapshot = json_encode($contact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $address = json_encode(
                $contact['adresse'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            );
            $stmt = $this->pdo->prepare(
                'INSERT INTO documents_financiers
                 (organisation_id, dossier_id, contact_id, type, date_document,
                  date_echeance, adresse_snapshot_json, contact_snapshot_json,
                  compte_collectif_id, numero_externe, document_origine_id,
                  justificatif_id, condition_paiement_id,
                  condition_paiement_snapshot_json, cree_par, workflow,
                  cle_generation, monnaie, devise_base,
                  taux_change_numerateur, taux_change_denominateur,
                  taux_change_date, taux_change_source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                         ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId, $dossierId, $contactId, $type,
                $documentDate, $dueDate, $address, $snapshot,
                $collectiveAccountId, trim($externalNumber),
                $originDocumentId, $attachmentId,
                $paymentTerm['condition_id'] ?? null,
                json_encode(
                    $paymentTerm['snapshot'] ?? [],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                ),
                $actorId,
                $workflow,
                trim($generationKey),
                $rate['currency'],
                $rate['base_currency'],
                $rate['numerator'],
                $rate['denominator'],
                $rate['rate_date'],
                $rate['source'],
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->replaceLines(
                $organisationId,
                $dossierId,
                $id,
                $lines,
                1,
                workflow: $workflow
            );
            $this->audit->log(
                'facturation.document_brouillon_cree',
                $actorId,
                $organisationId,
                $dossierId,
                'document_financier',
                (string) $id,
                ['type' => $type]
            );
            return $id;
        }, true);
    }

    /**
     * @param list<array<string,mixed>> $lines
     */
    public function updateDraft(
        int $organisationId,
        int $dossierId,
        int $documentId,
        int $expectedVersion,
        string $type,
        int $contactId,
        string $documentDate,
        string $dueDate,
        array $lines,
        int $collectiveAccountId,
        string $externalNumber = '',
        ?int $attachmentId = null,
        ?int $actorId = null,
        string $currency = '',
        ?int $exchangeRateId = null,
    ): void {
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $documentId,
            $expectedVersion,
            $type,
            $contactId,
            $documentDate,
            $dueDate,
            $lines,
            $collectiveAccountId,
            $externalNumber,
            $attachmentId,
            $actorId,
            $currency,
            $exchangeRateId
        ): void {
            $document = $this->document($organisationId, $dossierId, $documentId);
            if (
                $document['statut'] !== 'brouillon'
                || $document['workflow'] !== 'facturation'
                || (int) $document['version'] !== $expectedVersion
                || (string) $document['type'] !== $type
            ) {
                throw new BillingException(
                    'Brouillon absent, émis ou modifié par un autre utilisateur.'
                );
            }
            $this->assertDate($documentDate);
            $this->assertDate($dueDate);
            if ($dueDate < $documentDate || $lines === []) {
                throw new BillingException('Dates ou lignes du document invalides.');
            }
            if (
                str_contains($type, 'fournisseur')
                && trim($externalNumber) === ''
            ) {
                throw new BillingException(
                    'La référence de facture fournisseur est requise.'
                );
            }
            $this->assertDraftReferences(
                $organisationId,
                $dossierId,
                $type,
                $contactId,
                $collectiveAccountId,
                $lines
            );
            $contact = $this->contacts->snapshot(
                $organisationId,
                $dossierId,
                $contactId
            );
            if (trim($currency) === '') {
                $currency = (string) $document['monnaie'];
            }
            $rate = $this->exchange->snapshot(
                $organisationId,
                $dossierId,
                $currency,
                $documentDate,
                $exchangeRateId
            );
            $snapshot = json_encode(
                $contact,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            );
            $address = json_encode(
                $contact['adresse'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            );
            $update = $this->pdo->prepare(
                "UPDATE documents_financiers
                 SET contact_id = ?, date_document = ?, date_echeance = ?,
                     adresse_snapshot_json = ?, contact_snapshot_json = ?,
                     compte_collectif_id = ?, numero_externe = ?,
                     justificatif_id = CASE WHEN ? IS NULL
                       THEN justificatif_id ELSE ? END,
                     condition_paiement_id = NULL,
                     condition_paiement_snapshot_json = '{}',
                     monnaie = ?, devise_base = ?,
                     taux_change_numerateur = ?,
                     taux_change_denominateur = ?, taux_change_date = ?,
                     taux_change_source = ?, modifie_le = datetime('now'),
                     version = version + 1
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND statut = 'brouillon' AND workflow = 'facturation'
                   AND version = ?"
            );
            $update->execute([
                $contactId,
                $documentDate,
                $dueDate,
                $address,
                $snapshot,
                $collectiveAccountId,
                trim($externalNumber),
                $attachmentId,
                $attachmentId,
                $rate['currency'],
                $rate['base_currency'],
                $rate['numerator'],
                $rate['denominator'],
                $rate['rate_date'],
                $rate['source'],
                $documentId,
                $organisationId,
                $dossierId,
                $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new BillingException('Conflit de version du brouillon.');
            }
            $this->replaceLines(
                $organisationId,
                $dossierId,
                $documentId,
                $lines,
                $expectedVersion + 1,
                $actorId
            );
            $this->audit->log(
                'facturation.document_brouillon_modifie',
                $actorId,
                $organisationId,
                $dossierId,
                'document_financier',
                (string) $documentId,
                ['type' => $type, 'lignes' => count($lines)]
            );
        }, true);
    }

    /**
     * @param list<array<string,mixed>> $lines
     */
    public function replaceLines(
        int $organisationId,
        int $dossierId,
        int $documentId,
        array $lines,
        int $expectedVersion,
        ?int $actorId = null,
        string $workflow = 'facturation',
    ): void {
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $documentId,
            $lines,
            $expectedVersion,
            $actorId,
            $workflow
        ): void {
            $document = $this->document($organisationId, $dossierId, $documentId);
            if (
                $document['statut'] !== 'brouillon'
                || $document['workflow'] !== $workflow
                || (int) $document['version'] !== $expectedVersion
                || $lines === []
            ) {
                throw new BillingException(
                    'Brouillon absent, émis ou modifié par un autre utilisateur.'
                );
            }
            $this->pdo->prepare('DELETE FROM lignes_document WHERE document_id = ?')
                ->execute([$documentId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO lignes_document
                 (document_id, ordre, libelle, quantite_milli,
                  prix_unitaire_centimes, mode_saisie, compte_id, code_tva_id,
                  date_prestation, deduction_bp, motif_correction, tdfn_id,
                  base_nette_centimes, tva_centimes, total_brut_centimes,
                  taux_tva_snapshot_bp, code_tva_snapshot,
                  traitement_tva_snapshot, nature_tva_snapshot,
                  chiffre_afc_snapshot, deduction_snapshot_bp,
                  tva_deductible_centimes, activite_tdfn_snapshot,
                  taux_tdfn_snapshot_bp)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $net = 0;
            $vat = 0;
            $gross = 0;
            $sign = str_starts_with((string) $document['type'], 'avoir_') ? -1 : 1;
            foreach (array_values($lines) as $index => $line) {
                $quantity = (int) ($line['quantite_milli'] ?? 0);
                $unitPrice = (int) ($line['prix_unitaire_centimes'] ?? -1);
                $label = trim((string) ($line['libelle'] ?? ''));
                if ($quantity <= 0 || $unitPrice < 0 || $label === '') {
                    throw new BillingException('Ligne de document invalide.');
                }
                $amount = $sign * VatCalculator::divideRounded(
                    $unitPrice * $quantity,
                    1000
                );
                $serviceDate = (string) $line['date_prestation'];
                $vatStatus = $this->vatStatusAt(
                    $organisationId,
                    $dossierId,
                    $serviceDate
                );
                $codeId = (int) ($line['code_tva_id'] ?? 0);
                $quote = $vatStatus === 'non_assujetti' && $codeId === 0
                    ? [
                        'net_cents' => $amount,
                        'vat_cents' => 0,
                        'gross_cents' => $amount,
                        'rate_bp' => 0,
                        'code' => '',
                        'treatment' => 'non_taxable',
                        'nature' => 'non_taxable',
                        'afc_box' => '',
                        'deduction_bp' => 0,
                        'deductible_vat_cents' => 0,
                        'activity_id' => '',
                        'tdfn_rate_bp' => null,
                    ]
                    : $this->vat->quote(
                        $organisationId,
                        $dossierId,
                        $codeId,
                        $serviceDate,
                        $amount,
                        (string) $line['mode_saisie'],
                        isset($line['deduction_bp'])
                            ? (int) $line['deduction_bp']
                            : null,
                        (string) ($line['motif_correction'] ?? ''),
                        isset($line['tdfn_id']) ? (int) $line['tdfn_id'] : null
                    );
                $insert->execute([
                    $documentId, $index + 1, $label, $quantity, $unitPrice,
                    $line['mode_saisie'], (int) $line['compte_id'],
                    $codeId > 0 ? $codeId : null, $line['date_prestation'],
                    $line['deduction_bp'] ?? null,
                    trim((string) ($line['motif_correction'] ?? '')),
                    $line['tdfn_id'] ?? null,
                    $quote['net_cents'], $quote['vat_cents'], $quote['gross_cents'],
                    $quote['rate_bp'], $quote['code'], $quote['treatment'],
                    $quote['nature'], $quote['afc_box'], $quote['deduction_bp'],
                    $quote['deductible_vat_cents'], $quote['activity_id'],
                    $quote['tdfn_rate_bp'],
                ]);
                $net += (int) $quote['net_cents'];
                $vat += (int) $quote['vat_cents'];
                $gross += (int) $quote['gross_cents'];
            }
            $baseNet = ExchangeRateService::convert(
                $net,
                (int) $document['taux_change_numerateur'],
                (int) $document['taux_change_denominateur']
            );
            $baseGross = ExchangeRateService::convert(
                $gross,
                (int) $document['taux_change_numerateur'],
                (int) $document['taux_change_denominateur']
            );
            $update = $this->pdo->prepare(
                "UPDATE documents_financiers
                 SET total_net_centimes = ?, total_tva_centimes = ?,
                     total_brut_centimes = ?, total_net_base_centimes = ?,
                     total_tva_base_centimes = ?,
                     total_brut_base_centimes = ?,
                     modifie_le = datetime('now'),
                     version = version + 1
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND statut = 'brouillon' AND version = ?"
            );
            $update->execute([
                $net, $vat, $gross, $baseNet, $baseGross - $baseNet,
                $baseGross, $documentId,
                $organisationId, $dossierId, $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new BillingException('Conflit de version du brouillon.');
            }
            $this->audit->log(
                'facturation.brouillon_modifie',
                $actorId,
                $organisationId,
                $dossierId,
                'document_financier',
                (string) $documentId,
                ['lignes' => count($lines)]
            );
        }, true);
    }

    public function issue(
        int $organisationId,
        int $dossierId,
        int $documentId,
        int $expectedVersion,
        ?int $actorId = null,
    ): string {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $documentId,
            $expectedVersion,
            $actorId
        ): string {
            $document = $this->document($organisationId, $dossierId, $documentId);
            if (
                in_array($document['statut'], ['emis', 'comptabilise'], true)
                && trim((string) $document['numero']) !== ''
            ) {
                return (string) $document['numero'];
            }
            if (
                $document['statut'] !== 'brouillon'
                || $document['workflow'] !== 'facturation'
                || (int) $document['version'] !== $expectedVersion
                || (int) $document['total_brut_centimes'] === 0
            ) {
                throw new BillingException('Document vide, déjà émis ou modifié.');
            }
            $prefix = match ($document['type']) {
                'facture_client' => 'FV',
                'avoir_client' => 'NC',
                'facture_fournisseur' => 'FA',
                'avoir_fournisseur' => 'NCA',
            };
            $year = (int) substr((string) $document['date_document'], 0, 4);
            $this->pdo->prepare(
                'INSERT OR IGNORE INTO sequences_documents
                 (dossier_id, annee, prefixe, dernier_numero) VALUES (?, ?, ?, 0)'
            )->execute([$dossierId, $year, $prefix]);
            $this->pdo->prepare(
                'UPDATE sequences_documents SET dernier_numero = dernier_numero + 1
                 WHERE dossier_id = ? AND annee = ? AND prefixe = ?'
            )->execute([$dossierId, $year, $prefix]);
            $sequence = $this->pdo->prepare(
                'SELECT dernier_numero FROM sequences_documents
                 WHERE dossier_id = ? AND annee = ? AND prefixe = ?'
            );
            $sequence->execute([$dossierId, $year, $prefix]);
            $number = sprintf('%s-%04d-%03d', $prefix, $year, (int) $sequence->fetchColumn());
            $scor = str_contains((string) $document['type'], 'client')
                && $document['type'] === 'facture_client'
                ? ScorReference::create($number)
                : '';
            $update = $this->pdo->prepare(
                "UPDATE documents_financiers
                 SET numero = ?, reference_scor = ?, statut = 'emis',
                     emis_le = datetime('now'), modifie_le = datetime('now'),
                     version = version + 1
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND statut = 'brouillon' AND version = ?"
            );
            $update->execute([
                $number, $scor, $documentId, $organisationId, $dossierId, $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new BillingException('Conflit pendant la numérotation.');
            }
            $this->audit->log(
                'facturation.document_emis',
                $actorId,
                $organisationId,
                $dossierId,
                'document_financier',
                (string) $documentId,
                ['numero' => $number]
            );
            return $number;
        }, true);
    }

    public function post(
        int $organisationId,
        int $dossierId,
        int $documentId,
        int $exerciseId,
        int $journalId,
        ?int $actorId = null,
        string $workflow = 'facturation',
    ): int {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $documentId,
            $exerciseId,
            $journalId,
            $actorId,
            $workflow
        ): int {
            $document = $this->document($organisationId, $dossierId, $documentId);
            if ($document['workflow'] !== $workflow) {
                throw new BillingException('Workflow de document incompatible.');
            }
            if ($document['ecriture_id'] !== null) {
                return (int) $document['ecriture_id'];
            }
            if (
                !in_array($document['statut'], ['emis', 'approuve'], true)
                || $document['compte_collectif_id'] === null
            ) {
                throw new BillingException(
                    'Le document doit être émis ou approuvé et posséder un compte collectif.'
                );
            }
            $lines = $this->lines($documentId);
            $postingLines = [];
            $client = str_contains((string) $document['type'], 'client');
            $total = (int) $document['total_brut_centimes'];
            $totalBase = (int) $document['total_brut_base_centimes'];
            $this->appendConvertedSigned(
                $postingLines,
                (int) $document['compte_collectif_id'],
                $client ? $totalBase : -$totalBase,
                $client ? $total : -$total,
                $document,
                'Compte collectif'
            );
            foreach ($lines as $line) {
                $primary = $client
                    ? -(int) $line['base_nette_centimes']
                    : (int) $line['total_brut_centimes']
                        - (int) $line['tva_deductible_centimes'];
                $this->appendConvertedSigned(
                    $postingLines,
                    (int) $line['compte_id'],
                    ExchangeRateService::convert(
                        $primary,
                        (int) $document['taux_change_numerateur'],
                        (int) $document['taux_change_denominateur']
                    ),
                    $primary,
                    $document,
                    'document:' . $documentId . ':ligne:' . $line['id']
                );
                $vatAmount = $client
                    ? -(int) $line['tva_centimes']
                    : (int) $line['tva_deductible_centimes'];
                if ($vatAmount !== 0) {
                    if ($line['compte_tva_id'] === null) {
                        throw new BillingException(
                            'Le code TVA de la ligne ne possède pas de compte comptable.'
                        );
                    }
                    $this->appendConvertedSigned(
                        $postingLines,
                        (int) $line['compte_tva_id'],
                        ExchangeRateService::convert(
                            $vatAmount,
                            (int) $document['taux_change_numerateur'],
                            (int) $document['taux_change_denominateur']
                        ),
                        $vatAmount,
                        $document,
                        'TVA ' . $line['code_tva_snapshot']
                    );
                }
            }
            $this->balanceConvertedLines($postingLines);
            $entryId = $this->entries->postGenerated([
                'organisation_id' => $organisationId,
                'dossier_id' => $dossierId,
                'exercice_id' => $exerciseId,
                'journal_id' => $journalId,
                'date_comptable' => $document['date_document'],
                'libelle' => $document['numero'] . ' — document financier',
                'reference' => $document['numero_externe'] ?: $document['numero'],
                'source_type' => 'document_financier',
                'source_id' => (string) $documentId,
                'source_action' => 'comptabiliser',
                'lignes' => $postingLines,
            ], 'document:' . $documentId . ':comptabiliser', $actorId);

            $findAccountingLine = $this->pdo->prepare(
                'SELECT id FROM lignes_ecriture
                 WHERE ecriture_id = ? AND libelle = ? LIMIT 1'
            );
            foreach ($lines as $line) {
                $marker = 'document:' . $documentId . ':ligne:' . $line['id'];
                $findAccountingLine->execute([$entryId, $marker]);
                $accountingLineId = $findAccountingLine->fetchColumn();
                if ($accountingLineId === false) {
                    throw new BillingException('Ligne comptable de document introuvable.');
                }
                $inputAmount = VatCalculator::divideRounded(
                    (int) $line['prix_unitaire_centimes']
                        * (int) $line['quantite_milli'],
                    1000
                );
                if (str_starts_with((string) $document['type'], 'avoir_')) {
                    $inputAmount *= -1;
                }
                $inputAmount = ExchangeRateService::convert(
                    $inputAmount,
                    (int) $document['taux_change_numerateur'],
                    (int) $document['taux_change_denominateur']
                );
                if ((int) ($line['code_tva_id'] ?? 0) < 1) {
                    continue;
                }
                $this->vat->attach(
                    $organisationId,
                    $dossierId,
                    (int) $accountingLineId,
                    (int) $line['code_tva_id'],
                    (string) $line['date_prestation'],
                    $inputAmount,
                    (string) $line['mode_saisie'],
                    $line['deduction_bp'] === null ? null : (int) $line['deduction_bp'],
                    (string) $line['motif_correction'],
                    $line['tdfn_id'] === null ? null : (int) $line['tdfn_id'],
                    document: [
                        'type' => (string) $document['type'],
                        'id' => (string) $documentId,
                        'line_id' => (string) $line['id'],
                    ],
                    actorId: $actorId
                );
            }
            $this->pdo->prepare(
                "UPDATE documents_financiers
                 SET statut = 'comptabilise', ecriture_id = ?,
                     comptabilise_le = datetime('now'), version = version + 1
                 WHERE id = ?"
            )->execute([$entryId, $documentId]);
            $this->audit->log(
                'facturation.document_comptabilise',
                $actorId,
                $organisationId,
                $dossierId,
                'document_financier',
                (string) $documentId,
                ['ecriture_id' => $entryId]
            );
            return $entryId;
        });
    }

    public function creditFrom(
        int $organisationId,
        int $dossierId,
        int $documentId,
        string $date,
        ?int $actorId = null,
    ): int {
        $source = $this->document($organisationId, $dossierId, $documentId);
        if (
            $source['workflow'] !== 'facturation'
            || !in_array(
                $source['type'],
                ['facture_client', 'facture_fournisseur'],
                true
            )
            || !in_array($source['statut'], ['emis', 'comptabilise'], true)
        ) {
            throw new BillingException('Seule une facture émise peut produire un avoir.');
        }
        $lines = array_map(
            static fn (array $line): array => [
                'libelle' => 'Avoir — ' . $line['libelle'],
                'quantite_milli' => (int) $line['quantite_milli'],
                'prix_unitaire_centimes' => (int) $line['prix_unitaire_centimes'],
                'mode_saisie' => $line['mode_saisie'],
                'compte_id' => (int) $line['compte_id'],
                'code_tva_id' => (int) $line['code_tva_id'],
                'date_prestation' => $line['date_prestation'],
                'deduction_bp' => $line['deduction_bp'],
                'motif_correction' => $line['motif_correction'],
                'tdfn_id' => $line['tdfn_id'],
            ],
            $this->lines($documentId)
        );
        $type = $source['type'] === 'facture_client'
            ? 'avoir_client'
            : 'avoir_fournisseur';
        if ($source['compte_collectif_id'] === null) {
            throw new BillingException('La facture ne possède pas de compte collectif.');
        }
        return $this->createDraft(
            $organisationId,
            $dossierId,
            $type,
            (int) $source['contact_id'],
            $date,
            $date,
            $lines,
            (int) $source['compte_collectif_id'],
            $source['type'] === 'facture_fournisseur'
                ? 'AV-' . $source['numero_externe']
                : '',
            $documentId,
            actorId: $actorId,
            currency: (string) $source['monnaie']
        );
    }

    public function markCancelledByCredit(
        int $organisationId,
        int $dossierId,
        int $documentId,
        int $creditId,
        ?int $actorId = null,
    ): void {
        $source = $this->document($organisationId, $dossierId, $documentId);
        $credit = $this->document($organisationId, $dossierId, $creditId);
        if (
            $source['workflow'] !== 'facturation'
            || $credit['workflow'] !== 'facturation'
            || (int) $credit['document_origine_id'] !== $documentId
            || $credit['statut'] !== 'comptabilise'
            || $source['statut'] !== 'comptabilise'
        ) {
            throw new BillingException('L’avoir doit être comptabilisé avant l’annulation.');
        }
        $this->pdo->prepare(
            "UPDATE documents_financiers
             SET statut = 'annule', annule_le = datetime('now'), version = version + 1
             WHERE id = ?"
        )->execute([$documentId]);
        $this->audit->log(
            'facturation.document_annule_par_avoir',
            $actorId,
            $organisationId,
            $dossierId,
            'document_financier',
            (string) $documentId,
            ['avoir_id' => $creditId]
        );
    }

    /** @return array<string,mixed> */
    public function document(int $organisationId, int $dossierId, int $documentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM documents_financiers
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$documentId, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new BillingException('Document absent du dossier.');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function documents(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT d.*, c.raison_sociale, c.prenom, c.nom,
                    COALESCE((
                      SELECT SUM(a.montant_centimes) FROM allocations a
                      WHERE a.document_id = d.id AND a.statut = 'valide'
                    ), 0) AS alloue_centimes
             FROM documents_financiers d
             JOIN contacts c ON c.id = d.contact_id
             WHERE d.organisation_id = ? AND d.dossier_id = ?
               AND d.workflow = 'facturation'
             ORDER BY d.date_document DESC, d.id DESC"
        );
        $stmt->execute([$organisationId, $dossierId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $total = abs((int) $row['total_brut_centimes']);
            $allocated = (int) $row['alloue_centimes'];
            $row['solde_centimes'] = max(0, $total - $allocated);
            $row['etat_paiement'] = $this->derivedState($row);
        }
        unset($row);
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function lines(int $documentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.*, c.compte_tva_id,
                    c.code AS code_tva, c.libelle AS libelle_tva,
                    a.numero AS compte_numero, a.libelle AS compte_libelle
             FROM lignes_document l
             LEFT JOIN tva_codes c ON c.id = l.code_tva_id
             JOIN comptes a ON a.id = l.compte_id
             WHERE l.document_id = ? ORDER BY l.ordre'
        );
        $stmt->execute([$documentId]);
        return $stmt->fetchAll();
    }

    public function remind(
        int $organisationId,
        int $dossierId,
        int $documentId,
        int $level,
        string $channel,
        string $note = '',
        ?int $actorId = null,
    ): int {
        $this->document($organisationId, $dossierId, $documentId);
        $stmt = $this->pdo->prepare(
            'INSERT INTO rappels_factures
             (organisation_id, dossier_id, document_id, niveau, canal, note, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId, $dossierId, $documentId,
            $level, $channel, trim($note), $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'facturation.rappel_trace',
            $actorId,
            $organisationId,
            $dossierId,
            'rappel_facture',
            (string) $id,
            ['document_id' => $documentId, 'niveau' => $level, 'canal' => $channel]
        );
        return $id;
    }

    /** @return array<string,list<array<string,mixed>>> */
    public function catalog(int $organisationId, int $dossierId): array
    {
        $accounts = $this->pdo->prepare(
            'SELECT id, numero, libelle FROM comptes
             WHERE organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND imputable = 1
             ORDER BY length(numero), numero, ordre, id'
        );
        $accounts->execute([$organisationId, $dossierId]);
        $vatCodes = $this->pdo->prepare(
            'SELECT id, code, libelle, nature, date_debut, date_fin FROM tva_codes
             WHERE organisation_id = ? AND dossier_id = ? AND actif = 1
             ORDER BY code'
        );
        $vatCodes->execute([$organisationId, $dossierId]);
        $exercises = $this->pdo->prepare(
            "SELECT id, libelle, date_debut, date_fin FROM exercices
             WHERE dossier_id = ? AND statut = 'ouvert' ORDER BY date_debut DESC"
        );
        $exercises->execute([$dossierId]);
        $journals = $this->pdo->prepare(
            'SELECT id, code, libelle, type FROM journaux
             WHERE organisation_id = ? AND dossier_id = ? AND actif = 1
             ORDER BY code'
        );
        $journals->execute([$organisationId, $dossierId]);
        $vatRegimes = $this->pdo->prepare(
            'SELECT id, statut, date_debut, date_fin
             FROM tva_regimes
             WHERE organisation_id = ? AND dossier_id = ?
             ORDER BY date_debut DESC, id DESC'
        );
        $vatRegimes->execute([$organisationId, $dossierId]);
        $paymentDefaults = $this->pdo->prepare(
            'SELECT d.direction, d.date_debut, d.date_fin,
                    c.id AS condition_id, c.code, c.libelle,
                    c.delai_jours, c.fin_de_mois
             FROM defauts_conditions_paiement d
             JOIN conditions_paiement c ON c.id = d.condition_id
             WHERE d.organisation_id = ? AND d.dossier_id = ?
               AND c.actif = 1
             ORDER BY d.direction, d.date_debut DESC, d.id DESC'
        );
        $paymentDefaults->execute([$organisationId, $dossierId]);
        $currencyConfiguration = $this->exchange->configuration(
            $organisationId,
            $dossierId
        );
        return [
            'accounts' => $accounts->fetchAll(),
            'vat_codes' => $vatCodes->fetchAll(),
            'exercises' => $exercises->fetchAll(),
            'journals' => $journals->fetchAll(),
            'vat_regimes' => $vatRegimes->fetchAll(),
            'payment_defaults' => $paymentDefaults->fetchAll(),
            'currencies' => array_values(array_filter(
                $currencyConfiguration['currencies'],
                static fn (array $item): bool => (bool) $item['active']
            )),
            'exchange_rates' => array_values(array_filter(
                $currencyConfiguration['rates'],
                static fn (array $item): bool => (bool) $item['active']
            )),
        ];
    }

    /** @return array<string,string> */
    public function creditorProfile(int $organisationId, int $dossierId): array
    {
        $organisation = $this->pdo->prepare(
            'SELECT nom, raison_sociale, adresse_ligne1, adresse_ligne2,
                    code_postal, localite, pays
             FROM organisations WHERE id = ?'
        );
        $organisation->execute([$organisationId]);
        $identity = $organisation->fetch();
        if ($identity === false) {
            throw new BillingException('Organisation absente.');
        }
        $params = $this->pdo->prepare(
            "SELECT cle, valeur FROM parametres_organisation
             WHERE organisation_id = ? AND cle IN (
                 'adresse_ligne1', 'adresse_ligne2', 'code_postal',
                 'localite', 'pays'
             )"
        );
        $params->execute([$organisationId]);
        $values = [];
        foreach ($params->fetchAll() as $row) {
            $values[(string) $row['cle']] = (string) $row['valeur'];
        }
        foreach ([
            'adresse_ligne1', 'adresse_ligne2', 'code_postal',
            'localite', 'pays',
        ] as $key) {
            if (trim((string) ($identity[$key] ?? '')) !== '') {
                $values[$key] = (string) $identity[$key];
            }
        }
        $billingProfile = $this->pdo->prepare(
            'SELECT d.nom AS dossier_nom, t.iban
             FROM dossiers d
             LEFT JOIN comptes_tresorerie t
               ON t.id = d.compte_tresorerie_facturation_id
              AND t.organisation_id = d.organisation_id
              AND t.dossier_id = d.id
              AND t.actif = 1
             WHERE d.id = ? AND d.organisation_id = ?'
        );
        $billingProfile->execute([$dossierId, $organisationId]);
        $dossier = $billingProfile->fetch() ?: [];
        $values['iban_facturation'] = (string) (
            $dossier['iban'] ?? ''
        );
        return [
            'nom' => trim((string) $identity['raison_sociale']) !== ''
                ? (string) $identity['raison_sociale']
                : (string) $identity['nom'],
            'dossier' => (string) ($dossier['dossier_nom'] ?? ''),
            'ligne1' => $values['adresse_ligne1'] ?? '',
            'ligne2' => $values['adresse_ligne2'] ?? '',
            'code_postal' => $values['code_postal'] ?? '',
            'localite' => $values['localite'] ?? '',
            'pays' => $values['pays'] ?? 'CH',
            'iban' => $values['iban_facturation'] ?? '',
        ];
    }

    /** @param array<string,string> $profile */
    public function saveCreditorProfile(
        int $organisationId,
        int $dossierId,
        array $profile,
        ?int $actorId = null,
    ): void {
        $required = ['adresse_ligne1', 'code_postal', 'localite', 'pays'];
        foreach ($required as $key) {
            if (trim((string) ($profile[$key] ?? '')) === '') {
                throw new BillingException('Coordonnées du créancier incomplètes.');
            }
        }
        $country = strtoupper(trim((string) $profile['pays']));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            throw new BillingException('Pays du créancier invalide.');
        }
        $scope = $this->pdo->prepare(
            'SELECT 1 FROM dossiers WHERE id = ? AND organisation_id = ?'
        );
        $scope->execute([$dossierId, $organisationId]);
        if ($scope->fetchColumn() === false) {
            throw new BillingException('Dossier hors organisation.');
        }
        $values = [
            'adresse_ligne1' => trim((string) $profile['adresse_ligne1']),
            'adresse_ligne2' => trim((string) ($profile['adresse_ligne2'] ?? '')),
            'code_postal' => trim((string) $profile['code_postal']),
            'localite' => trim((string) $profile['localite']),
            'pays' => $country,
        ];
        $stmt = $this->pdo->prepare(
            'INSERT INTO parametres_organisation (organisation_id, cle, valeur)
             VALUES (?, ?, ?)
             ON CONFLICT (organisation_id, cle) DO UPDATE SET valeur = excluded.valeur'
        );
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $values,
            $stmt,
            $actorId
        ): void {
            foreach ($values as $key => $value) {
                $stmt->execute([$organisationId, $key, $value]);
            }
            $this->audit->log(
                'facturation.coordonnees_qr_modifiees',
                $actorId,
                $organisationId,
                $dossierId,
                'organisation',
                (string) $organisationId,
                ['pays' => $values['pays']]
            );
        }, true);
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed> $snapshot
     */
    private function appendConvertedSigned(
        array &$lines,
        int $accountId,
        int $baseSigned,
        int $originalSigned,
        array $snapshot,
        string $label,
    ): void {
        if ($baseSigned === 0) {
            return;
        }
        $line = [
            'compte_id' => $accountId,
            'libelle' => $label,
            'devise_origine' => (string) $snapshot['monnaie'],
            'montant_origine_centimes' => $originalSigned,
            'devise_base' => (string) $snapshot['devise_base'],
            'taux_change_numerateur' => (int) $snapshot['taux_change_numerateur'],
            'taux_change_denominateur' => (int) $snapshot['taux_change_denominateur'],
            'taux_change_date' => (string) $snapshot['taux_change_date'],
            'taux_change_source' => (string) $snapshot['taux_change_source'],
            'montant_base_centimes' => $baseSigned,
            'ecart_arrondi_centimes' => 0,
        ];
        if ($baseSigned > 0) {
            $line['debit_centimes'] = $baseSigned;
        } else {
            $line['credit_centimes'] = abs($baseSigned);
        }
        $lines[] = $line;
    }

    /** @param list<array<string,mixed>> $lines */
    private function balanceConvertedLines(array &$lines): void
    {
        $balance = 0;
        foreach ($lines as $line) {
            $balance += (int) ($line['debit_centimes'] ?? 0)
                - (int) ($line['credit_centimes'] ?? 0);
        }
        if ($balance === 0 || count($lines) < 2) {
            return;
        }
        $index = count($lines) - 1;
        $signed = (int) ($lines[$index]['debit_centimes'] ?? 0)
            - (int) ($lines[$index]['credit_centimes'] ?? 0);
        $adjusted = $signed - $balance;
        if ($adjusted === 0 || (($signed > 0) !== ($adjusted > 0))) {
            throw new BillingException(
                'L’arrondi de change ne peut pas être imputé sans inverser une ligne.'
            );
        }
        unset($lines[$index]['debit_centimes'], $lines[$index]['credit_centimes']);
        if ($adjusted > 0) {
            $lines[$index]['debit_centimes'] = $adjusted;
        } else {
            $lines[$index]['credit_centimes'] = abs($adjusted);
        }
        $lines[$index]['montant_base_centimes'] = $adjusted;
        $lines[$index]['ecart_arrondi_centimes'] =
            (int) $lines[$index]['ecart_arrondi_centimes'] - $balance;
    }

    /** @param array<string,mixed> $document */
    private function derivedState(array $document): string
    {
        if ($document['statut'] === 'brouillon') {
            return 'brouillon';
        }
        if ($document['statut'] === 'annule') {
            return 'annule';
        }
        if ((int) $document['solde_centimes'] === 0) {
            return 'paye';
        }
        if ((int) $document['alloue_centimes'] > 0) {
            return 'partiellement_paye';
        }
        return $document['date_echeance'] < date('Y-m-d') ? 'en_retard' : 'ouvert';
    }

    private function assertType(string $type): void
    {
        if (!in_array($type, [
            'facture_client', 'avoir_client',
            'facture_fournisseur', 'avoir_fournisseur',
        ], true)) {
            throw new BillingException('Type de document invalide.');
        }
    }

    /** @param list<array<string,mixed>> $lines */
    private function assertDraftReferences(
        int $organisationId,
        int $dossierId,
        string $type,
        int $contactId,
        ?int $collectiveAccountId,
        array $lines,
    ): void {
        $role = str_contains($type, 'fournisseur') ? 'fournisseur' : 'client';
        $contact = $this->pdo->prepare(
            'SELECT 1 FROM contacts c
             JOIN contact_roles r ON r.contact_id = c.id AND r.role = ?
             WHERE c.id = ? AND c.organisation_id = ? AND c.dossier_id = ?
               AND c.actif = 1'
        );
        $contact->execute([$role, $contactId, $organisationId, $dossierId]);
        if ($contact->fetchColumn() === false) {
            throw new BillingException('Contact absent, inactif ou sans le rôle requis.');
        }
        $accountIds = array_values(array_unique(array_filter([
            $collectiveAccountId,
            ...array_map(
                static fn (array $line): int => (int) ($line['compte_id'] ?? 0),
                $lines
            ),
        ], static fn (mixed $id): bool => is_int($id) && $id > 0)));
        if ($accountIds !== []) {
            $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
            $accounts = $this->pdo->prepare(
                "SELECT COUNT(*) FROM comptes
                 WHERE organisation_id = ? AND dossier_id = ?
                   AND actif = 1 AND imputable = 1
                   AND id IN ({$placeholders})"
            );
            $accounts->execute([$organisationId, $dossierId, ...$accountIds]);
            if ((int) $accounts->fetchColumn() !== count($accountIds)) {
                throw new BillingException('Compte de document absent ou hors du dossier.');
            }
        }
        $allowedNatures = str_contains($type, 'fournisseur')
            ? ['prealable', 'acquisition', 'non_taxable', 'correction']
            : ['collectee', 'non_taxable', 'correction'];
        $vatCode = $this->pdo->prepare(
            "SELECT nature FROM tva_codes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND date_debut <= ?
               AND COALESCE(date_fin, '9999-12-31') >= ?"
        );
        foreach ($lines as $line) {
            $date = (string) ($line['date_prestation'] ?? '');
            $vatStatus = $this->vatStatusAt(
                $organisationId,
                $dossierId,
                $date
            );
            if (
                $vatStatus === 'non_assujetti'
                && (int) ($line['code_tva_id'] ?? 0) === 0
            ) {
                continue;
            }
            $vatCode->execute([
                (int) ($line['code_tva_id'] ?? 0),
                $organisationId,
                $dossierId,
                $date,
                $date,
            ]);
            $nature = $vatCode->fetchColumn();
            if ($nature === false || !in_array((string) $nature, $allowedNatures, true)) {
                throw new BillingException(
                    'Code TVA absent, expiré ou incompatible avec le sens du document.'
                );
            }
        }
    }

    private function vatStatusAt(
        int $organisationId,
        int $dossierId,
        string $date,
    ): string {
        $stmt = $this->pdo->prepare(
            'SELECT statut FROM tva_regimes
             WHERE organisation_id = ? AND dossier_id = ?
               AND date_debut <= ?
               AND COALESCE(date_fin, \'9999-12-31\') >= ?
             ORDER BY date_debut DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$organisationId, $dossierId, $date, $date]);
        $status = $stmt->fetchColumn();
        if ($status === false) {
            throw new BillingException(
                'Aucun régime TVA ne couvre la date du document. '
                . 'Configurez le statut TVA sous Configuration → Entité.'
            );
        }
        return (string) $status;
    }

    private function assertDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new BillingException('Date de document invalide.');
        }
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
