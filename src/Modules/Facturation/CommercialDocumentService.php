<?php
declare(strict_types=1);

namespace Compta\Modules\Facturation;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Tva\VatCalculator;
use Compta\Modules\Tva\VatLineService;
use PDO;
use Throwable;

/**
 * Cycle documentaire commercial, volontairement séparé du grand livre.
 *
 * Une offre, une demande, une réponse ou une commande ne produit jamais
 * d’écriture comptable. Seule une conversion explicite vers BillingService
 * crée un brouillon de facture.
 */
final class CommercialDocumentService
{
    private ContactService $contacts;
    private VatLineService $vat;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly BillingService $billing,
    ) {
        $this->contacts = new ContactService($pdo, $audit);
        $this->vat = new VatLineService($pdo, $audit);
    }

    /** @return list<array<string,mixed>> */
    public function all(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT d.*, COALESCE(NULLIF(c.raison_sociale, ''),
                    trim(c.prenom || ' ' || c.nom)) AS contact
             FROM documents_commerciaux d
             JOIN contacts c ON c.id = d.contact_id
             WHERE d.organisation_id = ? AND d.dossier_id = ?
             ORDER BY d.date_document DESC, d.id DESC"
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['contact_id'] = (int) $row['contact_id'];
            $row['total_net_centimes'] = (int) $row['total_net_centimes'];
            $row['total_tva_centimes'] = (int) $row['total_tva_centimes'];
            $row['total_brut_centimes'] = (int) $row['total_brut_centimes'];
            $row['document_source_id'] = $row['document_source_id'] === null
                ? null : (int) $row['document_source_id'];
            $row['remplace_par_id'] = $row['remplace_par_id'] === null
                ? null : (int) $row['remplace_par_id'];
            $row['version'] = (int) $row['version'];
            $row['lines'] = $this->lines((int) $row['id']);
            $row['links'] = $this->links((int) $row['id']);
            return $row;
        }, $stmt->fetchAll());
    }

    /**
     * @param array<string,mixed> $data
     */
    public function save(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): int {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $data,
            $actorId
        ): int {
            $type = (string) ($data['type'] ?? '');
            $this->assertType($type);
            $contactId = (int) ($data['contact_id'] ?? 0);
            $date = (string) ($data['document_date'] ?? '');
            $validUntil = trim((string) ($data['valid_until'] ?? ''));
            $this->assertDate($date);
            if ($validUntil !== '') {
                $this->assertDate($validUntil);
            }
            if ($validUntil !== '' && $validUntil < $date) {
                throw new BillingException('La validité précède la date du document.');
            }
            $currency = strtoupper(trim((string) ($data['currency'] ?? 'CHF')));
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new BillingException('Devise commerciale invalide.');
            }
            $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
            if ($lines === []) {
                throw new BillingException('Ajoutez au moins une ligne au document.');
            }
            $this->assertContact(
                $organisationId,
                $dossierId,
                $contactId,
                $this->direction($type)
            );
            $sourceId = isset($data['source_document_id'])
                ? (int) $data['source_document_id']
                : 0;
            if ($sourceId > 0) {
                $this->assertSource(
                    $organisationId,
                    $dossierId,
                    $sourceId,
                    $type
                );
            }
            $snapshot = $this->contacts->snapshot(
                $organisationId,
                $dossierId,
                $contactId
            );
            $documentId = (int) ($data['id'] ?? 0);
            if ($documentId > 0) {
                $version = (int) ($data['version'] ?? 0);
                $update = $this->pdo->prepare(
                    "UPDATE documents_commerciaux
                     SET contact_id = ?, date_document = ?, date_validite = ?,
                         monnaie = ?, numero_externe = ?, texte_entete = ?,
                         texte_pied = ?, note_interne = ?,
                         adresse_snapshot_json = ?, contact_snapshot_json = ?,
                         document_source_id = ?, modifie_le = datetime('now'),
                         modifie_par = ?, version = version + 1
                     WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                       AND statut = 'brouillon' AND version = ?"
                );
                $update->execute([
                    $contactId,
                    $date,
                    $validUntil === '' ? null : $validUntil,
                    $currency,
                    trim((string) ($data['external_number'] ?? '')),
                    trim((string) ($data['header_text'] ?? '')),
                    trim((string) ($data['footer_text'] ?? '')),
                    trim((string) ($data['internal_note'] ?? '')),
                    json_encode($snapshot['adresse'], JSON_THROW_ON_ERROR),
                    json_encode($snapshot, JSON_THROW_ON_ERROR),
                    $sourceId > 0 ? $sourceId : null,
                    $actorId,
                    $documentId,
                    $organisationId,
                    $dossierId,
                    $version,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new BillingException(
                        'Document commercial absent, émis ou modifié par une autre session.'
                    );
                }
                $this->pdo->prepare(
                    'DELETE FROM lignes_document_commercial WHERE document_id = ?'
                )->execute([$documentId]);
            } else {
                $insert = $this->pdo->prepare(
                    'INSERT INTO documents_commerciaux
                     (organisation_id, dossier_id, contact_id, type,
                      date_document, date_validite, monnaie, numero_externe,
                      adresse_snapshot_json, contact_snapshot_json,
                      texte_entete, texte_pied, note_interne,
                      document_source_id, cree_par)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    $organisationId,
                    $dossierId,
                    $contactId,
                    $type,
                    $date,
                    $validUntil === '' ? null : $validUntil,
                    $currency,
                    trim((string) ($data['external_number'] ?? '')),
                    json_encode($snapshot['adresse'], JSON_THROW_ON_ERROR),
                    json_encode($snapshot, JSON_THROW_ON_ERROR),
                    trim((string) ($data['header_text'] ?? '')),
                    trim((string) ($data['footer_text'] ?? '')),
                    trim((string) ($data['internal_note'] ?? '')),
                    $sourceId > 0 ? $sourceId : null,
                    $actorId,
                ]);
                $documentId = (int) $this->pdo->lastInsertId();
            }
            $this->replaceLines(
                $organisationId,
                $dossierId,
                $documentId,
                $type,
                $date,
                $lines
            );
            $this->audit->log(
                $documentId === (int) ($data['id'] ?? 0)
                    ? 'facturation.document_commercial_modifie'
                    : 'facturation.document_commercial_cree',
                $actorId,
                $organisationId,
                $dossierId,
                'document_commercial',
                (string) $documentId,
                ['type' => $type]
            );
            return $documentId;
        });
    }

    public function changeStatus(
        int $organisationId,
        int $dossierId,
        int $documentId,
        int $expectedVersion,
        string $status,
        int $actorId,
    ): string {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $documentId,
            $expectedVersion,
            $status,
            $actorId
        ): string {
            $document = $this->document(
                $organisationId,
                $dossierId,
                $documentId
            );
            $allowed = $this->allowedTransitions(
                (string) $document['type'],
                (string) $document['statut']
            );
            if (!in_array($status, $allowed, true)) {
                throw new BillingException('Transition commerciale invalide.');
            }
            $number = (string) $document['numero'];
            if (in_array($status, ['envoye', 'recu'], true) && $number === '') {
                $number = $this->nextNumber(
                    $dossierId,
                    (string) $document['type'],
                    (string) $document['date_document']
                );
            }
            $update = $this->pdo->prepare(
                "UPDATE documents_commerciaux
                 SET statut = ?, numero = ?,
                     emis_le = CASE WHEN ? = 'envoye' THEN datetime('now') ELSE emis_le END,
                     accepte_le = CASE WHEN ? = 'accepte' THEN datetime('now') ELSE accepte_le END,
                     refuse_le = CASE WHEN ? = 'refuse' THEN datetime('now') ELSE refuse_le END,
                     modifie_le = datetime('now'), modifie_par = ?,
                     version = version + 1
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND version = ?"
            );
            $update->execute([
                $status,
                $number,
                $status,
                $status,
                $status,
                $actorId,
                $documentId,
                $organisationId,
                $dossierId,
                $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new BillingException(
                    'Document commercial modifié par une autre session.'
                );
            }
            $this->audit->log(
                'facturation.document_commercial_statut',
                $actorId,
                $organisationId,
                $dossierId,
                'document_commercial',
                (string) $documentId,
                ['statut' => $status, 'numero' => $number]
            );
            return $number;
        });
    }

    /**
     * @param array<string,mixed> $data
     * @return array{kind:'commercial'|'invoice',id:int}
     */
    public function convert(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): array {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $data,
            $actorId
        ): array {
            $sourceId = (int) ($data['source_document_id'] ?? 0);
            $source = $this->document(
                $organisationId,
                $dossierId,
                $sourceId
            );
            $targetType = (string) ($data['target_type'] ?? '');
            $commercialTargets = [
                'offre_client' => ['commande_client'],
                'reponse_offre_fournisseur' => [
                    'commande_fournisseur',
                    'reponse_offre_fournisseur',
                ],
                'demande_offre_fournisseur' => ['reponse_offre_fournisseur'],
                'commande_client' => [],
                'commande_fournisseur' => [],
            ];
            $invoiceType = match ((string) $source['type']) {
                'offre_client', 'commande_client' => 'facture_client',
                'reponse_offre_fournisseur', 'commande_fournisseur' =>
                    'facture_fournisseur',
                default => '',
            };
            $this->assertConversionState(
                (string) $source['type'],
                (string) $source['statut'],
                $targetType
            );
            if (in_array($targetType, $commercialTargets[$source['type']] ?? [], true)) {
                $targetId = $this->save(
                    $organisationId,
                    $dossierId,
                    [
                        'type' => $targetType,
                        'contact_id' => (int) $source['contact_id'],
                        'document_date' => (string) ($data['document_date']
                            ?? date('Y-m-d')),
                        'valid_until' => (string) ($data['valid_until'] ?? ''),
                        'currency' => (string) $source['monnaie'],
                        'external_number' => (string) ($data['external_number'] ?? ''),
                        'source_document_id' => $sourceId,
                        'header_text' => (string) $source['texte_entete'],
                        'footer_text' => (string) $source['texte_pied'],
                        'internal_note' => '',
                        'lines' => array_map(static fn (array $line): array => [
                            'label' => $line['libelle'],
                            'quantity_milli' => $line['quantite_milli'],
                            'unit_price_cents' => $line['prix_unitaire_centimes'],
                            'input_mode' => $line['mode_saisie'],
                            'account_id' => $line['compte_id'],
                            'vat_code_id' => $line['code_tva_id'],
                        ], $this->lines($sourceId)),
                    ],
                    $actorId
                );
                $replacement = (string) $source['type']
                    === 'reponse_offre_fournisseur'
                    && $targetType === 'reponse_offre_fournisseur';
                $linkType = $replacement
                    ? 'remplacement'
                    : ($targetType === 'reponse_offre_fournisseur'
                        ? 'reponse'
                        : 'commande');
                $this->linkCommercial(
                    $organisationId,
                    $dossierId,
                    $sourceId,
                    $targetId,
                    $linkType,
                    $actorId
                );
                if ($replacement) {
                    $this->pdo->prepare(
                        "UPDATE documents_commerciaux
                         SET statut = 'remplace', remplace_par_id = ?,
                             modifie_le = datetime('now'), modifie_par = ?,
                             version = version + 1
                         WHERE id = ?"
                    )->execute([$targetId, $actorId, $sourceId]);
                }
                return ['kind' => 'commercial', 'id' => $targetId];
            }
            if ($targetType !== $invoiceType || $invoiceType === '') {
                throw new BillingException('Conversion commerciale incompatible.');
            }
            $date = (string) ($data['document_date'] ?? date('Y-m-d'));
            $sourceLines = $this->lines($sourceId);
            $lineAccountMap = [];
            foreach ($sourceLines as $line) {
                $lineAccountMap[(int) $line['id']] =
                    (int) ($line['compte_id'] ?? 0);
            }
            foreach (($data['line_accounts'] ?? []) as $mapping) {
                if (is_array($mapping)) {
                    $lineAccountMap[(int) ($mapping['line_id'] ?? 0)] =
                        (int) ($mapping['account_id'] ?? 0);
                }
            }
            foreach ($sourceLines as $line) {
                if (($lineAccountMap[(int) $line['id']] ?? 0) < 1) {
                    throw new BillingException(
                        'Choisissez un compte comptable pour chaque position avant la facturation.'
                    );
                }
            }
            $invoiceId = $this->billing->createDraft(
                $organisationId,
                $dossierId,
                $invoiceType,
                (int) $source['contact_id'],
                $date,
                (string) ($data['due_date'] ?? ''),
                array_map(static fn (array $line): array => [
                    'libelle' => (string) $line['libelle'],
                    'quantite_milli' => (int) $line['quantite_milli'],
                    'prix_unitaire_centimes' => (int) $line['prix_unitaire_centimes'],
                    'mode_saisie' => (string) $line['mode_saisie'],
                    'compte_id' => $lineAccountMap[(int) $line['id']],
                    'code_tva_id' => (int) ($line['code_tva_id'] ?? 0),
                    'date_prestation' => $date,
                ], $sourceLines),
                (int) ($data['collective_account_id'] ?? 0),
                (string) ($data['external_number'] ?? ''),
                actorId: $actorId,
                currency: (string) $source['monnaie'],
            );
            $conversionId = $this->linkInvoice(
                $organisationId,
                $dossierId,
                $sourceId,
                $invoiceId,
                $actorId
            );
            $this->linkInvoiceLines($conversionId, $sourceId, $invoiceId);
            $this->pdo->prepare(
                "UPDATE documents_commerciaux
                 SET statut = 'facture', modifie_le = datetime('now'),
                     modifie_par = ?, version = version + 1
                 WHERE id = ?"
            )->execute([$actorId, $sourceId]);
            return ['kind' => 'invoice', 'id' => $invoiceId];
        });
    }

    /** @param list<array<string,mixed>> $lines */
    private function replaceLines(
        int $organisationId,
        int $dossierId,
        int $documentId,
        string $type,
        string $date,
        array $lines,
    ): void {
        $insert = $this->pdo->prepare(
            'INSERT INTO lignes_document_commercial
             (document_id, ordre, libelle, quantite_milli,
              prix_unitaire_centimes, mode_saisie, compte_id, code_tva_id,
              base_nette_centimes, tva_centimes, total_brut_centimes,
              taux_tva_snapshot_bp, code_tva_snapshot)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $net = 0;
        $vat = 0;
        $gross = 0;
        $vatStatus = $this->vatStatusAt($organisationId, $dossierId, $date);
        $accountIds = array_values(array_unique(array_filter(array_map(
            static fn (array $line): int => (int) (
                $line['account_id'] ?? $line['compte_id'] ?? 0
            ),
            $lines
        ), static fn (int $id): bool => $id > 0)));
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
                throw new BillingException(
                    'Compte de ligne absent, inactif ou hors du dossier.'
                );
            }
        }
        $allowedVatNatures = $this->direction($type) === 'client'
            ? ['collectee', 'non_taxable', 'correction']
            : ['prealable', 'acquisition', 'non_taxable', 'correction'];
        $vatNature = $this->pdo->prepare(
            "SELECT nature FROM tva_codes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND date_debut <= ?
               AND COALESCE(date_fin, '9999-12-31') >= ?"
        );
        foreach (array_values($lines) as $index => $line) {
            $label = trim((string) (
                $line['label'] ?? $line['libelle'] ?? ''
            ));
            $quantity = (int) (
                $line['quantity_milli'] ?? $line['quantite_milli'] ?? 0
            );
            $unitPrice = (int) (
                $line['unit_price_cents']
                    ?? $line['prix_unitaire_centimes']
                    ?? -1
            );
            $mode = (string) (
                $line['input_mode'] ?? $line['mode_saisie'] ?? 'net'
            );
            $accountId = (int) (
                $line['account_id'] ?? $line['compte_id'] ?? 0
            );
            $codeId = (int) (
                $line['vat_code_id'] ?? $line['code_tva_id'] ?? 0
            );
            if (
                $label === '' || $quantity < 1 || $unitPrice < 0
                || !in_array($mode, ['net', 'brut'], true)
                || $accountId < 0
            ) {
                throw new BillingException('Ligne commerciale invalide.');
            }
            if (!($vatStatus === 'non_assujetti' && $codeId === 0)) {
                $vatNature->execute([
                    $codeId,
                    $organisationId,
                    $dossierId,
                    $date,
                    $date,
                ]);
                $nature = $vatNature->fetchColumn();
                if (
                    $nature === false
                    || !in_array((string) $nature, $allowedVatNatures, true)
                ) {
                    throw new BillingException(
                        'Code TVA absent, expiré ou incompatible avec le document.'
                    );
                }
            }
            $amount = VatCalculator::divideRounded($unitPrice * $quantity, 1000);
            $quote = $vatStatus === 'non_assujetti' && $codeId === 0
                ? [
                    'net_cents' => $amount,
                    'vat_cents' => 0,
                    'gross_cents' => $amount,
                    'rate_bp' => 0,
                    'code' => '',
                ]
                : $this->vat->quote(
                    $organisationId,
                    $dossierId,
                    $codeId,
                    $date,
                    $amount,
                    $mode
                );
            $insert->execute([
                $documentId,
                $index + 1,
                $label,
                $quantity,
                $unitPrice,
                $mode,
                $accountId > 0 ? $accountId : null,
                $codeId > 0 ? $codeId : null,
                $quote['net_cents'],
                $quote['vat_cents'],
                $quote['gross_cents'],
                $quote['rate_bp'],
                $quote['code'],
            ]);
            $net += (int) $quote['net_cents'];
            $vat += (int) $quote['vat_cents'];
            $gross += (int) $quote['gross_cents'];
        }
        $this->pdo->prepare(
            'UPDATE documents_commerciaux
             SET total_net_centimes = ?, total_tva_centimes = ?,
                 total_brut_centimes = ?
             WHERE id = ?'
        )->execute([$net, $vat, $gross, $documentId]);
    }

    /** @return list<array<string,mixed>> */
    private function lines(int $documentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM lignes_document_commercial
             WHERE document_id = ? ORDER BY ordre'
        );
        $stmt->execute([$documentId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    private function links(int $documentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, type_lien, document_cible_commercial_id,
                    document_cible_financier_id, cree_le
             FROM conversions_documents
             WHERE document_source_id = ?
             ORDER BY id'
        );
        $stmt->execute([$documentId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed> */
    private function document(
        int $organisationId,
        int $dossierId,
        int $documentId,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM documents_commerciaux
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$documentId, $organisationId, $dossierId]);
        $document = $stmt->fetch();
        if ($document === false) {
            throw new BillingException('Document commercial absent du dossier.');
        }
        return $document;
    }

    private function assertContact(
        int $organisationId,
        int $dossierId,
        int $contactId,
        string $direction,
    ): void {
        $role = $direction === 'client' ? 'client' : 'fournisseur';
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM contacts c
             JOIN contact_roles r ON r.contact_id = c.id AND r.role = ?
             WHERE c.id = ? AND c.organisation_id = ? AND c.dossier_id = ?
               AND c.actif = 1'
        );
        $stmt->execute([$role, $contactId, $organisationId, $dossierId]);
        if ($stmt->fetchColumn() === false) {
            throw new BillingException(
                'Contact absent, archivé ou sans le rôle requis.'
            );
        }
    }

    private function assertSource(
        int $organisationId,
        int $dossierId,
        int $sourceId,
        string $targetType,
    ): void {
        $source = $this->document($organisationId, $dossierId, $sourceId);
        $allowed = match ($targetType) {
            'reponse_offre_fournisseur' => [
                'demande_offre_fournisseur',
                'reponse_offre_fournisseur',
            ],
            'commande_client' => ['offre_client'],
            'commande_fournisseur' => ['reponse_offre_fournisseur'],
            default => [],
        };
        if (!in_array((string) $source['type'], $allowed, true)) {
            throw new BillingException('Document d’origine incompatible.');
        }
    }

    private function linkCommercial(
        int $organisationId,
        int $dossierId,
        int $sourceId,
        int $targetId,
        string $type,
        int $actorId,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO conversions_documents
             (organisation_id, dossier_id, document_source_id,
              document_cible_commercial_id, type_lien, cree_par)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId,
            $dossierId,
            $sourceId,
            $targetId,
            $type,
            $actorId,
        ]);
        $conversionId = (int) $this->pdo->lastInsertId();
        $this->linkCommercialLines($conversionId, $sourceId, $targetId);
        return $conversionId;
    }

    private function linkInvoice(
        int $organisationId,
        int $dossierId,
        int $sourceId,
        int $invoiceId,
        int $actorId,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO conversions_documents
             (organisation_id, dossier_id, document_source_id,
              document_cible_financier_id, type_lien, cree_par)
             VALUES (?, ?, ?, ?, \'facture\', ?)'
        );
        $stmt->execute([
            $organisationId,
            $dossierId,
            $sourceId,
            $invoiceId,
            $actorId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function linkCommercialLines(
        int $conversionId,
        int $sourceId,
        int $targetId,
    ): void {
        $source = $this->lines($sourceId);
        $target = $this->lines($targetId);
        $insert = $this->pdo->prepare(
            'INSERT INTO conversions_lignes_documents
             (conversion_id, ligne_source_id, ligne_cible_commercial_id,
              quantite_milli)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($source as $index => $line) {
            if (!isset($target[$index])) {
                continue;
            }
            $insert->execute([
                $conversionId,
                (int) $line['id'],
                (int) $target[$index]['id'],
                (int) $line['quantite_milli'],
            ]);
        }
    }

    private function linkInvoiceLines(
        int $conversionId,
        int $sourceId,
        int $invoiceId,
    ): void {
        $source = $this->lines($sourceId);
        $target = $this->billing->lines($invoiceId);
        $insert = $this->pdo->prepare(
            'INSERT INTO conversions_lignes_documents
             (conversion_id, ligne_source_id, ligne_cible_financiere_id,
              quantite_milli)
             VALUES (?, ?, ?, ?)'
        );
        foreach ($source as $index => $line) {
            if (!isset($target[$index])) {
                continue;
            }
            $insert->execute([
                $conversionId,
                (int) $line['id'],
                (int) $target[$index]['id'],
                (int) $line['quantite_milli'],
            ]);
        }
    }

    private function nextNumber(int $dossierId, string $type, string $date): string
    {
        $prefix = match ($type) {
            'offre_client' => 'OF',
            'demande_offre_fournisseur' => 'DOF',
            'reponse_offre_fournisseur' => 'ROF',
            'commande_client' => 'CC',
            'commande_fournisseur' => 'CF',
        };
        $year = (int) substr($date, 0, 4);
        $this->pdo->prepare(
            'INSERT OR IGNORE INTO sequences_documents
             (dossier_id, annee, prefixe, dernier_numero)
             VALUES (?, ?, ?, 0)'
        )->execute([$dossierId, $year, $prefix]);
        $this->pdo->prepare(
            'UPDATE sequences_documents
             SET dernier_numero = dernier_numero + 1
             WHERE dossier_id = ? AND annee = ? AND prefixe = ?'
        )->execute([$dossierId, $year, $prefix]);
        $stmt = $this->pdo->prepare(
            'SELECT dernier_numero FROM sequences_documents
             WHERE dossier_id = ? AND annee = ? AND prefixe = ?'
        );
        $stmt->execute([$dossierId, $year, $prefix]);
        return sprintf(
            '%s-%04d-%03d',
            $prefix,
            $year,
            (int) $stmt->fetchColumn()
        );
    }

    /** @return list<string> */
    private function allowedTransitions(string $type, string $current): array
    {
        if ($current === 'brouillon') {
            return $type === 'reponse_offre_fournisseur'
                ? ['recu', 'annule']
                : ['envoye', 'annule'];
        }
        if ($current === 'envoye') {
            return match ($type) {
                'offre_client', 'commande_client', 'commande_fournisseur' =>
                    ['accepte', 'refuse', 'annule'],
                'demande_offre_fournisseur' => ['annule'],
                default => [],
            };
        }
        if ($current === 'recu' && $type === 'reponse_offre_fournisseur') {
            return ['accepte', 'refuse', 'annule'];
        }
        if (
            $current === 'accepte'
            && in_array($type, ['commande_client', 'commande_fournisseur'], true)
        ) {
            return ['annule', 'archive'];
        }
        if (in_array($current, ['accepte', 'refuse', 'annule'], true)) {
            return ['archive'];
        }
        return [];
    }

    private function assertConversionState(
        string $sourceType,
        string $sourceStatus,
        string $targetType,
    ): void {
        $allowed = match ($sourceType) {
            'demande_offre_fournisseur' =>
                $sourceStatus === 'envoye'
                    && $targetType === 'reponse_offre_fournisseur',
            'offre_client' =>
                $sourceStatus === 'accepte'
                    && in_array($targetType, [
                        'commande_client',
                        'facture_client',
                    ], true),
            'reponse_offre_fournisseur' =>
                (
                    in_array($sourceStatus, ['recu', 'accepte'], true)
                    && $targetType === 'reponse_offre_fournisseur'
                )
                || (
                    $sourceStatus === 'accepte'
                    && in_array($targetType, [
                        'commande_fournisseur',
                        'facture_fournisseur',
                    ], true)
                ),
            'commande_client' =>
                in_array($sourceStatus, ['brouillon', 'envoye', 'accepte'], true)
                    && $targetType === 'facture_client',
            'commande_fournisseur' =>
                in_array($sourceStatus, ['brouillon', 'envoye', 'accepte'], true)
                    && $targetType === 'facture_fournisseur',
            default => false,
        };
        if (!$allowed) {
            throw new BillingException(
                'Le statut actuel ne permet pas cette conversion.'
            );
        }
    }

    private function direction(string $type): string
    {
        return in_array($type, ['offre_client', 'commande_client'], true)
            ? 'client'
            : 'fournisseur';
    }

    private function assertType(string $type): void
    {
        if (!in_array($type, [
            'offre_client',
            'demande_offre_fournisseur',
            'reponse_offre_fournisseur',
            'commande_client',
            'commande_fournisseur',
        ], true)) {
            throw new BillingException('Type de document commercial invalide.');
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
                'Aucun régime TVA ne couvre la date du document commercial.'
            );
        }
        return (string) $status;
    }

    private function assertDate(string $date): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new BillingException('Date commerciale invalide.');
        }
    }

    private function transaction(callable $callback): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($owns) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
