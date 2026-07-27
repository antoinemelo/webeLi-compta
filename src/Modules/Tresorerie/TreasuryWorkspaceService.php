<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie;

use Compta\Modules\Compta\EntryService;
use Compta\Modules\Facturation\PaymentService;
use PDO;

final class TreasuryWorkspaceService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PaymentService $payments,
        private readonly EntryService $entries,
    ) {
    }

    /** @return array<string,mixed> */
    public function read(int $organisationId, int $dossierId): array
    {
        $accounts = $this->query(
            'SELECT t.id, t.libelle AS label, t.type, t.iban, t.bic, t.monnaie AS currency,
                    t.compte_comptable_id AS ledger_account_id,
                    c.numero AS ledger_number, c.libelle AS ledger_label
             FROM comptes_tresorerie t
             JOIN comptes c ON c.id = t.compte_comptable_id
             WHERE t.organisation_id = ? AND t.dossier_id = ?
               AND t.actif = 1 AND c.actif = 1 AND c.imputable = 1
             ORDER BY t.libelle',
            [$organisationId, $dossierId]
        );
        $imports = $this->query(
            'SELECT id, compte_tresorerie_id AS treasury_account_id,
                    format, nom_fichier AS filename, empreinte_source AS source_hash,
                    date_debut AS date_start, date_fin AS date_end, statut AS status,
                    nb_total AS total_count, nb_importees AS imported_count,
                    nb_doublons AS duplicate_count, cree_le AS created_at,
                    confirme_le AS confirmed_at
             FROM imports_bancaires
             WHERE organisation_id = ? AND dossier_id = ?
             ORDER BY id DESC LIMIT 100',
            [$organisationId, $dossierId]
        );
        $bankLines = $this->query(
            "SELECT l.id, l.compte_tresorerie_id AS treasury_account_id,
                    l.import_id, l.date_comptabilisation AS booking_date,
                    l.date_valeur AS value_date, l.libelle AS label, l.tiers AS counterparty,
                    l.communication, l.reference, l.montant_centimes AS amount_cents,
                    l.frais_centimes AS fee_cents, l.monnaie AS currency,
                    r.rapprochement_id AS reconciliation_id
             FROM lignes_bancaires l
             LEFT JOIN rapprochement_lignes_bancaires r
               ON r.ligne_bancaire_id = l.id AND r.actif = 1
             WHERE l.organisation_id = ? AND l.dossier_id = ?
             ORDER BY l.date_comptabilisation DESC, l.id DESC LIMIT 500",
            [$organisationId, $dossierId]
        );
        $accountingLines = $this->query(
            "SELECT l.id, t.id AS treasury_account_id, e.id AS entry_id,
                    e.numero AS entry_number, e.date_comptable AS accounting_date,
                    e.libelle AS label,
                    (l.debit_centimes - l.credit_centimes) AS amount_cents,
                    r.rapprochement_id AS reconciliation_id
             FROM lignes_ecriture l
             JOIN ecritures e ON e.id = l.ecriture_id
             JOIN comptes_tresorerie t ON t.compte_comptable_id = l.compte_id
             LEFT JOIN rapprochement_lignes_comptables r
               ON r.ligne_ecriture_id = l.id AND r.actif = 1
             WHERE e.organisation_id = ? AND e.dossier_id = ?
               AND e.statut IN ('validee', 'contre_passee')
             ORDER BY e.date_comptable DESC, l.id DESC LIMIT 500",
            [$organisationId, $dossierId]
        );
        $reconciliations = $this->query(
            "SELECT r.id, r.compte_tresorerie_id AS treasury_account_id,
                    r.libelle AS label, r.total_banque_centimes AS bank_total_cents,
                    r.total_comptable_centimes AS accounting_total_cents,
                    r.difference_centimes AS difference_cents,
                    r.tolerance_centimes AS tolerance_cents, r.statut AS status,
                    r.cree_le AS created_at, r.annule_le AS cancelled_at,
                    r.version,
                    (SELECT COUNT(*) FROM rapprochement_lignes_bancaires b
                     WHERE b.rapprochement_id = r.id) AS bank_line_count,
                    (SELECT COUNT(*) FROM rapprochement_lignes_comptables c
                     WHERE c.rapprochement_id = r.id) AS accounting_line_count
             FROM rapprochements_bancaires r
             WHERE r.organisation_id = ? AND r.dossier_id = ?
             ORDER BY r.id DESC LIMIT 200",
            [$organisationId, $dossierId]
        );
        $suggestions = $this->query(
            'SELECT s.id, s.ligne_bancaire_id AS bank_line_id,
                    s.compte_contrepartie_id AS counterpart_account_id,
                    s.libelle AS label, s.confiance AS confidence,
                    s.raison AS reason, s.statut AS status,
                    s.ecriture_id AS entry_id
             FROM suggestions_comptabilisation s
             WHERE s.organisation_id = ? AND s.dossier_id = ?
             ORDER BY s.id DESC LIMIT 200',
            [$organisationId, $dossierId]
        );
        $documents = $this->query(
            "SELECT d.id, d.numero AS number, d.type, d.workflow,
                    d.contact_id, d.date_echeance AS due_date,
                    d.monnaie AS currency, abs(d.total_brut_centimes) AS gross_cents,
                    COALESCE((
                        SELECT SUM(a.montant_centimes) FROM allocations a
                        WHERE a.document_id = d.id AND a.statut = 'valide'
                    ), 0) AS allocated_cents,
                    COALESCE(NULLIF(c.raison_sociale, ''),
                             trim(c.prenom || ' ' || c.nom)) AS contact
             FROM documents_financiers d
             JOIN contacts c ON c.id = d.contact_id
             WHERE d.organisation_id = ? AND d.dossier_id = ?
               AND d.type IN ('facture_client', 'facture_fournisseur')
               AND d.statut IN ('emis', 'comptabilise')
             ORDER BY d.date_echeance, d.id",
            [$organisationId, $dossierId]
        );
        foreach ($documents as &$document) {
            $document['open_cents'] = max(
                0,
                (int) $document['gross_cents'] - (int) $document['allocated_cents']
            );
        }
        unset($document);
        $documents = array_values(array_filter(
            $documents,
            static fn (array $document): bool => $document['open_cents'] > 0
        ));
        $batches = $this->query(
            'SELECT l.id, l.compte_tresorerie_id AS treasury_account_id,
                    l.message_id, l.date_execution AS execution_date,
                    l.monnaie AS currency, l.nombre_ordres AS order_count,
                    l.total_centimes AS total_cents, l.statut AS status,
                    l.version_pain AS pain_version, l.empreinte_sha256 AS hash,
                    l.cree_le AS created_at, l.exporte_le AS exported_at,
                    l.confirme_le AS confirmed_at,
                    l.ligne_bancaire_id AS bank_line_id,
                    l.rapprochement_id AS reconciliation_id,
                    l.frais_centimes AS fee_cents, l.version
             FROM lots_paiements_sortants l
             WHERE l.organisation_id = ? AND l.dossier_id = ?
             ORDER BY l.id DESC LIMIT 200',
            [$organisationId, $dossierId]
        );
        foreach ($batches as &$batch) {
            $batch['orders'] = $this->query(
                'SELECT id, document_id, contact_id,
                        beneficiaire_snapshot AS beneficiary,
                        iban_snapshot AS iban, bic_snapshot AS bic,
                        reference, montant_centimes AS amount_cents,
                        monnaie AS currency, statut AS status,
                        paiement_id AS payment_id
                 FROM ordres_paiement_sortants
                 WHERE lot_id = ? ORDER BY id',
                [(int) $batch['id']]
            );
        }
        unset($batch);
        $payableDebts = $this->query(
            "SELECT d.id, d.numero AS number, d.numero_externe AS external_number,
                    d.contact_id, d.date_echeance AS due_date, d.monnaie AS currency,
                    abs(d.total_brut_centimes) - COALESCE((
                        SELECT SUM(a.montant_centimes) FROM allocations a
                        WHERE a.document_id = d.id AND a.statut = 'valide'
                    ), 0) AS open_cents,
                    COALESCE(NULLIF(c.raison_sociale, ''),
                             trim(c.prenom || ' ' || c.nom)) AS supplier,
                    c.iban_paiement AS iban, c.bic_paiement AS bic
             FROM documents_financiers d
             JOIN contacts c ON c.id = d.contact_id
             JOIN comptes collectif ON collectif.id = d.compte_collectif_id
             WHERE d.organisation_id = ? AND d.dossier_id = ?
               AND d.type = 'facture_fournisseur'
               AND d.statut = 'comptabilise'
               AND collectif.type = 'passif'
             ORDER BY d.date_echeance, d.id",
            [$organisationId, $dossierId]
        );
        $payableDebts = array_values(array_filter(
            $payableDebts,
            static fn (array $debt): bool => (int) $debt['open_cents'] > 0
        ));
        $catalog = $this->entries->entryCatalog($organisationId, $dossierId);
        $catalog['contacts'] = $this->query(
            "SELECT c.id, COALESCE(NULLIF(c.raison_sociale, ''),
                    trim(c.prenom || ' ' || c.nom)) AS label,
                    group_concat(cr.role, ',') AS roles
             FROM contacts c
             JOIN contact_roles cr ON cr.contact_id = c.id
             WHERE c.organisation_id = ? AND c.dossier_id = ? AND c.actif = 1
             GROUP BY c.id ORDER BY label",
            [$organisationId, $dossierId]
        );
        $catalog['treasury_accounts'] = $accounts;
        return [
            'treasury_accounts' => $accounts,
            'imports' => $imports,
            'bank_lines' => $bankLines,
            'accounting_lines' => $accountingLines,
            'reconciliations' => $reconciliations,
            'suggestions' => $suggestions,
            'payments' => $this->payments->payments($organisationId, $dossierId),
            'allocations' => $this->payments->allocations($organisationId, $dossierId),
            'open_documents' => $documents,
            'payable_debts' => $payableDebts,
            'outgoing_batches' => $batches,
            'catalog' => $catalog,
            'definitions' => [
                'banking' => 'Une ligne bancaire importée reste distincte du grand livre.',
                'matching' => 'Le lettrage répartit un paiement sur des documents ouverts.',
                'pain001' => 'Un fichier exporté est préparé pour téléchargement, jamais déclaré transmis.',
            ],
        ];
    }

    /** @param list<mixed> $parameters @return list<array<string,mixed>> */
    private function query(string $sql, array $parameters): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parameters);
        return $stmt->fetchAll();
    }
}
