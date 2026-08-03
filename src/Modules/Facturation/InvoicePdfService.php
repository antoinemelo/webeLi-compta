<?php
declare(strict_types=1);

namespace Compta\Modules\Facturation;

use Compta\Core\Audit\AuditLogger;
use PDO;

final class InvoicePdfService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly SwissQrService $qr = new SwissQrService(),
    ) {
    }

    /**
     * @param array{
     *   nom:string,dossier?:string,ligne1:string,ligne2?:string,code_postal:string,
     *   localite:string,pays?:string,iban?:string
     * } $creditor
     */
    public function archive(
        int $organisationId,
        int $dossierId,
        int $documentId,
        array $creditor,
        ?int $actorId = null,
    ): string {
        $document = $this->document($organisationId, $dossierId, $documentId);
        if (!in_array($document['statut'], ['emis', 'comptabilise', 'annule'], true)) {
            throw new BillingException('Le PDF ne peut être figé qu’après émission.');
        }
        [$pdf, $payload] = $this->render($document, $this->lines($documentId), $creditor);
        $hash = hash('sha256', $pdf);
        $stmt = $this->pdo->prepare(
            'UPDATE documents_financiers
             SET pdf_archive = ?, pdf_empreinte_sha256 = ?, qr_payload = ?
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->bindValue(1, $pdf, PDO::PARAM_LOB);
        $stmt->bindValue(2, $hash);
        $stmt->bindValue(3, $payload);
        $stmt->bindValue(4, $documentId, PDO::PARAM_INT);
        $stmt->bindValue(5, $organisationId, PDO::PARAM_INT);
        $stmt->bindValue(6, $dossierId, PDO::PARAM_INT);
        $stmt->execute();
        $this->audit->log(
            'facturation.pdf_archive',
            $actorId,
            $organisationId,
            $dossierId,
            'document_financier',
            (string) $documentId,
            ['empreinte_sha256' => $hash, 'qr' => $payload !== '']
        );
        return $pdf;
    }

    /**
     * Génère une représentation imprimable d’une offre, demande ou commande.
     *
     * Le document commercial reste indépendant de la comptabilité et son
     * statut n’est pas modifié par le téléchargement.
     *
     * @param array<string,mixed> $issuer
     */
    public function commercial(
        int $organisationId,
        int $dossierId,
        int $documentId,
        array $issuer,
        ?int $actorId = null,
    ): string {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM documents_commerciaux
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$documentId, $organisationId, $dossierId]);
        $document = $stmt->fetch();
        if ($document === false) {
            throw new BillingException('Document commercial absent du dossier.');
        }
        $lines = $this->pdo->prepare(
            'SELECT * FROM lignes_document_commercial
             WHERE document_id = ? ORDER BY ordre'
        );
        $lines->execute([$documentId]);
        $contact = json_decode(
            (string) $document['contact_snapshot_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $address = json_decode(
            (string) $document['adresse_snapshot_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $recipient = $address + [
            'nom' => $this->contactName($contact),
            'pays' => 'CH',
        ];
        $number = trim((string) $document['numero']);
        if ($number === '') {
            $number = 'Brouillon #' . $documentId;
        }
        $type = $this->commercialTypeLabel((string) $document['type']);
        $metadata = [
            'Date' => $this->formatDate((string) $document['date_document']),
            'Valable jusqu’au' => $document['date_validite'] === null
                ? '—'
                : $this->formatDate((string) $document['date_validite']),
            'Devise' => (string) $document['monnaie'],
            'Statut' => $this->commercialStatusLabel(
                (string) $document['statut'],
                (string) $document['type']
            ),
        ];
        $body = $this->commercialLinesHtml(
            $lines->fetchAll(),
            $document
        );
        $bytes = $this->renderSimpleDocument(
            $issuer,
            $recipient,
            $type,
            $number,
            $metadata,
            $body,
            (string) $document['texte_entete'],
            (string) $document['texte_pied']
        );
        $this->audit->log(
            'facturation.document_commercial_pdf',
            $actorId,
            $organisationId,
            $dossierId,
            'document_commercial',
            (string) $documentId,
            ['numero' => $number]
        );
        return $bytes;
    }

    /**
     * Génère un justificatif de paiement lisible depuis la fiche contact.
     *
     * @param array<string,mixed> $issuer
     */
    public function payment(
        int $organisationId,
        int $dossierId,
        int $paymentId,
        array $issuer,
        ?int $actorId = null,
    ): string {
        $stmt = $this->pdo->prepare(
            "SELECT p.*, COALESCE(NULLIF(c.raison_sociale, ''),
                    trim(c.prenom || ' ' || c.nom)) AS contact
             FROM paiements p
             JOIN contacts c ON c.id = p.contact_id
             WHERE p.id = ? AND p.organisation_id = ? AND p.dossier_id = ?"
        );
        $stmt->execute([$paymentId, $organisationId, $dossierId]);
        $payment = $stmt->fetch();
        if ($payment === false) {
            throw new BillingException('Paiement absent du dossier.');
        }
        $addressStmt = $this->pdo->prepare(
            "SELECT ligne1, ligne2, code_postal, localite, pays
             FROM adresses_contacts
             WHERE contact_id = ? AND actif = 1
             ORDER BY CASE type WHEN 'facturation' THEN 0 ELSE 1 END, id
             LIMIT 1"
        );
        $addressStmt->execute([(int) $payment['contact_id']]);
        $address = $addressStmt->fetch() ?: [];
        $recipient = $address + [
            'nom' => (string) $payment['contact'],
            'pays' => 'CH',
        ];
        $allocations = $this->pdo->prepare(
            "SELECT d.numero, a.montant_centimes, d.monnaie
             FROM allocations a
             JOIN documents_financiers d ON d.id = a.document_id
             WHERE a.paiement_id = ? AND a.organisation_id = ?
               AND a.dossier_id = ? AND a.statut = 'valide'
             ORDER BY a.id"
        );
        $allocations->execute([$paymentId, $organisationId, $dossierId]);
        $rows = $allocations->fetchAll();
        $reference = trim((string) $payment['reference']);
        $number = $reference !== '' ? $reference : 'Paiement #' . $paymentId;
        $metadata = [
            'Date' => $this->formatDate((string) $payment['date_paiement']),
            'Sens' => (string) $payment['sens'] === 'encaissement'
                ? 'Encaissement'
                : 'Décaissement',
            'Devise' => (string) $payment['monnaie'],
            'Statut' => (string) $payment['statut'] === 'valide'
                ? 'Validé'
                : 'Annulé',
        ];
        $bytes = $this->renderSimpleDocument(
            $issuer,
            $recipient,
            'Confirmation de paiement',
            $number,
            $metadata,
            $this->paymentLinesHtml($rows, $payment),
            '',
            'Ce justificatif récapitule le paiement et ses allocations au moment du téléchargement.'
        );
        $this->audit->log(
            'facturation.paiement_pdf',
            $actorId,
            $organisationId,
            $dossierId,
            'paiement',
            (string) $paymentId,
            ['reference' => $number]
        );
        return $bytes;
    }

    /**
     * @param array<string,mixed> $document
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed> $creditor
     * @return array{string,string}
     */
    public function render(array $document, array $lines, array $creditor): array
    {
        $debtor = json_decode(
            (string) $document['contact_snapshot_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $address = json_decode(
            (string) $document['adresse_snapshot_json'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $debtorAddress = $address + [
            'nom' => $this->contactName($debtor),
            'pays' => 'CH',
        ];
        $payload = '';
        $qrPng = '';
        if (
            $document['type'] === 'facture_client'
            && (int) $document['total_brut_centimes'] > 0
            && $this->qrProfileComplete($creditor, $debtorAddress)
        ) {
            $payload = $this->qr->payload(
                (string) ($creditor['iban'] ?? ''),
                $creditor,
                $debtorAddress,
                (int) $document['total_brut_centimes'],
                (string) $document['monnaie'],
                (string) $document['reference_scor'],
                (string) $document['numero']
            );
            $qrPng = $this->qr->png($payload);
        }

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('webe.li COMPTA');
        $pdf->SetAuthor((string) ($creditor['nom'] ?? ''));
        $pdf->SetTitle($this->typeLabel((string) $document['type']) . ' ' . $document['numero']);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(18, 14, 18);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 9.5);
        $pdf->writeHTML(
            $this->headerHtml($creditor, $debtorAddress, $document),
            true,
            false,
            true
        );
        $pdf->Ln(3);
        $pdf->writeHTML($this->linesHtml($lines, $document), true, false, true);
        if ($qrPng !== '') {
            if ($pdf->GetY() > 188.0) {
                $pdf->AddPage();
                $pdf->SetY(18);
            } else {
                $pdf->SetY(max(157.0, $pdf->GetY() + 8));
            }
            $sectionY = $pdf->GetY();
            $pdf->SetDrawColor(23, 50, 77);
            $pdf->SetLineWidth(0.35);
            $pdf->Line(18, $sectionY, 192, $sectionY);
            $pdf->SetY($sectionY + 5);
            $pdf->SetTextColor(23, 50, 77);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 6, 'PAIEMENT', 0, 1);
            $qrY = $pdf->GetY() + 1;
            $pdf->Image('@' . $qrPng, 18, $qrY, 45, 45, 'PNG');
            $pdf->SetXY(70, $qrY + 1);
            $pdf->SetTextColor(45, 55, 65);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->MultiCell(
                122,
                4.6,
                "Compte / payable à\n"
                . $this->formatIban((string) $creditor['iban'])
                . "\n" . (string) ($creditor['nom'] ?? '')
                . "\n\nRéférence\n"
                . ScorReference::formatted((string) $document['reference_scor'])
                . "\n\nMontant dû\n"
                . $this->money((int) $document['total_brut_centimes'])
                . ' ' . $document['monnaie'],
                0,
                'L'
            );
        }
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetTextColor(95, 105, 115);
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetXY(18, 286);
        $pdf->Cell(
            174,
            4,
            $this->footerText($creditor),
            0,
            0,
            'C'
        );
        $bytes = $pdf->Output('', 'S');
        if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-')) {
            throw new BillingException('La génération du PDF a échoué.');
        }
        return [$bytes, $payload];
    }

    /**
     * @param array<string,mixed> $issuer
     * @param array<string,mixed> $recipient
     * @param array<string,string> $metadata
     */
    private function renderSimpleDocument(
        array $issuer,
        array $recipient,
        string $type,
        string $number,
        array $metadata,
        string $body,
        string $introduction,
        string $conclusion,
    ): string {
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('webe.li COMPTA');
        $pdf->SetAuthor((string) ($issuer['nom'] ?? ''));
        $pdf->SetTitle($type . ' ' . $number);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(18, 14, 18);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 9.5);
        $metadataHtml = '';
        foreach ($metadata as $label => $value) {
            $metadataHtml .= $this->metadataRow($label, $value);
        }
        $html = '<table cellpadding="0" cellspacing="0"><tr>'
            . '<td width="58%"><span style="color:#17324d;font-size:20pt"><strong>'
            . $this->e((string) ($issuer['nom'] ?? ''))
            . '</strong></span><br>'
            . (trim((string) ($issuer['dossier'] ?? '')) !== ''
                ? '<span style="color:#17324d;font-size:9pt"><strong>'
                    . $this->e((string) $issuer['dossier'])
                    . '</strong></span><br>'
                : '')
            . '<span style="color:#6b7680;font-size:8.5pt">'
            . $this->addressHtml($issuer, ' &nbsp;·&nbsp; ')
            . '</span></td><td width="42%" align="right">'
            . '<span style="color:#17324d;font-size:9pt"><strong>'
            . mb_strtoupper($this->e($type))
            . '</strong></span><br><span style="color:#17324d;font-size:18pt"><strong>'
            . $this->e($number)
            . '</strong></span></td></tr></table>'
            . '<div style="height:18px"></div>'
            . '<table cellpadding="7" cellspacing="0"><tr>'
            . '<td width="54%" style="background-color:#f3f6f8">'
            . '<span style="color:#6b7680;font-size:8pt">ADRESSÉ À</span><br>'
            . '<span style="color:#17324d;font-size:11pt"><strong>'
            . $this->e((string) ($recipient['nom'] ?? ''))
            . '</strong></span><br>' . $this->addressHtml($recipient)
            . '</td><td width="4%"></td><td width="42%">'
            . '<table cellpadding="2">' . $metadataHtml . '</table>'
            . '</td></tr></table><div style="height:15px"></div>';
        if (trim($introduction) !== '') {
            $html .= '<p style="color:#2d3741;font-size:10pt">'
                . nl2br($this->e($introduction)) . '</p>';
        }
        $pdf->writeHTML($html . $body, true, false, true);
        if (trim($conclusion) !== '') {
            $pdf->Ln(2);
            $pdf->writeHTML(
                '<p style="color:#59636d;font-size:9pt">'
                . nl2br($this->e($conclusion)) . '</p>',
                true,
                false,
                true
            );
        }
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetTextColor(95, 105, 115);
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetXY(18, 286);
        $pdf->Cell(174, 4, $this->footerText($issuer), 0, 0, 'C');
        $bytes = $pdf->Output('', 'S');
        if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-')) {
            throw new BillingException('La génération du PDF a échoué.');
        }
        return $bytes;
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed> $document
     */
    private function commercialLinesHtml(array $lines, array $document): string
    {
        $showVat = (int) $document['total_tva_centimes'] !== 0;
        $html = '<table cellpadding="6" cellspacing="0"><thead><tr '
            . 'style="background-color:#17324d;color:#ffffff">'
            . '<th width="' . ($showVat ? '42%' : '50%') . '"><strong>RÉFÉRENCE</strong></th>'
            . '<th width="12%" align="right"><strong>QTÉ</strong></th>'
            . '<th width="17%" align="right"><strong>PRIX</strong></th>'
            . ($showVat ? '<th width="12%" align="right"><strong>TVA</strong></th>' : '')
            . '<th width="' . ($showVat ? '17%' : '21%')
            . '" align="right"><strong>TOTAL</strong></th></tr></thead><tbody>';
        foreach ($lines as $line) {
            $html .= '<tr style="border-bottom:1px solid #dfe5e9"><td>'
                . '<span style="color:#17324d"><strong>'
                . $this->e((string) $line['libelle'])
                . '</strong></span></td><td align="right">'
                . $this->quantity((int) $line['quantite_milli'])
                . '</td><td align="right">'
                . $this->money((int) $line['prix_unitaire_centimes'])
                . '</td>'
                . ($showVat
                    ? '<td align="right">'
                        . number_format((int) $line['taux_tva_snapshot_bp'] / 100, 2, '.', '')
                        . '%</td>'
                    : '')
                . '<td align="right"><strong>'
                . $this->money((int) $line['total_brut_centimes'])
                . '</strong></td></tr>';
        }
        return $html . '</tbody></table><br><table cellpadding="4">'
            . '<tr><td width="61%"></td><td width="20%" style="color:#6b7680">Sous-total</td>'
            . '<td width="19%" align="right">'
            . $this->money((int) $document['total_net_centimes']) . '</td></tr>'
            . ($showVat
                ? '<tr><td></td><td style="color:#6b7680">TVA</td><td align="right">'
                    . $this->money((int) $document['total_tva_centimes']) . '</td></tr>'
                : '')
            . '<tr style="background-color:#edf2f5;color:#17324d">'
            . '<td></td><td><strong>TOTAL</strong></td><td align="right">'
            . '<span style="font-size:12pt"><strong>'
            . $this->money((int) $document['total_brut_centimes']) . ' '
            . $this->e((string) $document['monnaie'])
            . '</strong></span></td></tr></table>';
    }

    /**
     * @param list<array<string,mixed>> $allocations
     * @param array<string,mixed> $payment
     */
    private function paymentLinesHtml(array $allocations, array $payment): string
    {
        $allocated = 0;
        $html = '<table cellpadding="6" cellspacing="0"><thead><tr '
            . 'style="background-color:#17324d;color:#ffffff">'
            . '<th width="70%"><strong>RÉFÉRENCE</strong></th>'
            . '<th width="30%" align="right"><strong>MONTANT</strong></th>'
            . '</tr></thead><tbody>';
        foreach ($allocations as $allocation) {
            $amount = (int) $allocation['montant_centimes'];
            $allocated += $amount;
            $html .= '<tr style="border-bottom:1px solid #dfe5e9"><td>'
                . $this->e((string) $allocation['numero'])
                . '</td><td align="right">' . $this->money($amount) . ' '
                . $this->e((string) $allocation['monnaie']) . '</td></tr>';
        }
        if ($allocations === []) {
            $html .= '<tr><td>Paiement non encore alloué</td><td align="right">—</td></tr>';
        }
        $unallocated = max(0, (int) $payment['montant_centimes'] - $allocated);
        return $html . '</tbody></table><br><table cellpadding="4">'
            . '<tr><td width="61%"></td><td width="20%" style="color:#6b7680">'
            . 'Non alloué</td><td width="19%" align="right">'
            . $this->money($unallocated) . '</td></tr>'
            . '<tr style="background-color:#edf2f5;color:#17324d">'
            . '<td></td><td><strong>TOTAL</strong></td><td align="right">'
            . '<span style="font-size:12pt"><strong>'
            . $this->money((int) $payment['montant_centimes']) . ' '
            . $this->e((string) $payment['monnaie'])
            . '</strong></span></td></tr></table>';
    }

    /** @param array<string,mixed> $creditor @param array<string,mixed> $debtor */
    private function qrProfileComplete(array $creditor, array $debtor): bool
    {
        $iban = strtoupper((string) preg_replace(
            '/\s+/',
            '',
            (string) ($creditor['iban'] ?? '')
        ));
        if (preg_match('/^(CH|LI)[0-9A-Z]{19}$/', $iban) !== 1) {
            return false;
        }
        foreach ([$creditor, $debtor] as $address) {
            foreach (['nom', 'ligne1', 'code_postal', 'localite'] as $field) {
                if (trim((string) ($address[$field] ?? '')) === '') {
                    return false;
                }
            }
            if (preg_match('/^[A-Z]{2}$/', strtoupper(
                trim((string) ($address['pays'] ?? ''))
            )) !== 1) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function document(int $organisationId, int $dossierId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM documents_financiers
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$id, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new BillingException('Document absent du dossier.');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function lines(int $documentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM lignes_document WHERE document_id = ? ORDER BY ordre'
        );
        $stmt->execute([$documentId]);
        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $creditor @param array<string,mixed> $debtor */
    private function headerHtml(array $creditor, array $debtor, array $document): string
    {
        $type = $this->e($this->typeLabel((string) $document['type']));
        return '<table cellpadding="0" cellspacing="0"><tr>'
            . '<td width="58%"><span style="color:#17324d;font-size:20pt"><strong>'
            . $this->e((string) ($creditor['nom'] ?? ''))
            . '</strong></span><br>'
            . (trim((string) ($creditor['dossier'] ?? '')) !== ''
                ? '<span style="color:#17324d;font-size:9pt"><strong>'
                    . $this->e((string) $creditor['dossier'])
                    . '</strong></span><br>'
                : '')
            . '<span style="color:#6b7680;font-size:8.5pt">'
            . $this->addressHtml($creditor, ' &nbsp;·&nbsp; ')
            . '</span></td>'
            . '<td width="42%" align="right">'
            . '<span style="color:#17324d;font-size:9pt;letter-spacing:1px"><strong>'
            . mb_strtoupper($type)
            . '</strong></span><br><span style="color:#17324d;font-size:18pt"><strong>'
            . $this->e((string) $document['numero'])
            . '</strong></span></td></tr></table>'
            . '<div style="height:18px"></div>'
            . '<table cellpadding="7" cellspacing="0"><tr>'
            . '<td width="54%" style="background-color:#f3f6f8">'
            . '<span style="color:#6b7680;font-size:8pt">ADRESSÉ À</span><br>'
            . '<span style="color:#17324d;font-size:11pt"><strong>'
            . $this->e((string) ($debtor['nom'] ?? ''))
            . '</strong></span><br>'
            . $this->addressHtml($debtor)
            . '</td><td width="4%"></td>'
            . '<td width="42%"><table cellpadding="2">'
            . $this->metadataRow(
                'Date',
                $this->formatDate((string) $document['date_document'])
            )
            . $this->metadataRow(
                'Échéance',
                $this->formatDate((string) $document['date_echeance'])
            )
            . $this->metadataRow('Devise', (string) $document['monnaie'])
            . '</table></td></tr></table>'
            . '<div style="height:15px"></div>'
            . '<p style="color:#2d3741;font-size:10pt">'
            . 'Nous vous remercions de votre confiance. Vous trouverez ci-dessous '
            . 'le détail de ' . mb_strtolower($type) . '.</p>';
    }

    /** @param list<array<string,mixed>> $lines @param array<string,mixed> $document */
    private function linesHtml(array $lines, array $document): string
    {
        $showVat = (int) $document['total_tva_centimes'] !== 0;
        $descriptionWidth = $showVat ? '31%' : '39%';
        $html = '<table cellpadding="6" cellspacing="0">'
            . '<thead><tr style="background-color:#17324d;color:#ffffff">'
            . '<th width="' . $descriptionWidth . '"><strong>DÉSIGNATION</strong></th>'
            . '<th width="10%" align="right"><strong>QTÉ</strong></th>'
            . '<th width="16%" align="right"><strong>PRIX UNITAIRE</strong></th>'
            . '<th width="' . ($showVat ? '14%' : '15%')
            . '"><strong>MODE DU PRIX</strong></th>'
            . ($showVat
                ? '<th width="12%" align="right"><strong>TVA</strong></th>'
                : '')
            . '<th width="' . ($showVat ? '17%' : '20%')
            . '" align="right"><strong>TOTAL</strong></th></tr></thead><tbody>';
        foreach ($lines as $line) {
            $html .= '<tr style="border-bottom:1px solid #dfe5e9">'
                . '<td><span style="color:#17324d"><strong>'
                . $this->e((string) $line['libelle'])
                . '</strong></span><br><span style="color:#7a848d;font-size:8pt">'
                . 'Prestation du '
                . $this->formatDate((string) $line['date_prestation'])
                . '</span></td><td align="right">'
                . $this->quantity((int) $line['quantite_milli'])
                . '</td><td align="right">' . $this->money((int) $line['prix_unitaire_centimes'])
                . '</td><td>' . $this->priceModeHtml($line, $document) . '</td>'
                . ($showVat
                    ? '<td align="right">' . number_format(
                        (int) $line['taux_tva_snapshot_bp'] / 100,
                        2,
                        '.',
                        ''
                    ) . '%</td>'
                    : '')
                . '<td align="right"><strong>'
                . $this->money((int) $line['total_brut_centimes'])
                . '</strong></td></tr>';
        }
        return $html . '</tbody></table><br><table cellpadding="4">'
            . '<tr><td width="61%"></td><td width="20%" style="color:#6b7680">Sous-total</td>'
            . '<td width="19%" align="right">'
            . $this->money((int) $document['total_net_centimes']) . '</td></tr>'
            . ($showVat
                ? '<tr><td></td><td style="color:#6b7680">TVA</td><td align="right">'
                    . $this->money((int) $document['total_tva_centimes'])
                    . '</td></tr>'
                : '')
            . '<tr style="background-color:#edf2f5;color:#17324d">'
            . '<td></td><td><strong>TOTAL</strong></td><td align="right">'
            . '<span style="font-size:12pt"><strong>'
            . $this->money((int) $document['total_brut_centimes']) . ' '
            . $this->e((string) $document['monnaie'])
            . '</strong></span></td></tr></table>'
            . '<p style="color:#59636d;font-size:8.5pt">'
            . 'Merci d’effectuer le règlement au plus tard le '
            . $this->formatDate((string) $document['date_echeance'])
            . ' en indiquant la référence du document.</p>';
    }

    /** @param array<string,mixed> $line @param array<string,mixed> $document */
    private function priceModeHtml(array $line, array $document): string
    {
        if ((string) $line['mode_saisie'] !== 'brut') {
            return 'Hors TVA';
        }
        if (
            str_contains((string) $document['type'], 'fournisseur')
            && (
                $line['code_tva_id'] === null
                || (
                    (int) $line['tva_centimes'] > 0
                    && (int) $line['tva_deductible_centimes'] === 0
                )
            )
        ) {
            return 'TVA comprise<br><span style="font-size:7pt;color:#6b7680">'
                . 'non récupérable</span>';
        }
        return 'TVA comprise';
    }

    /** @param array<string,mixed> $contact */
    private function contactName(array $contact): string
    {
        $company = trim((string) ($contact['raison_sociale'] ?? ''));
        return $company !== ''
            ? $company
            : trim((string) ($contact['prenom'] ?? '') . ' ' . (string) ($contact['nom'] ?? ''));
    }

    /** @param array<string,mixed> $address */
    private function addressHtml(array $address, string $separator = '<br>'): string
    {
        $parts = [
            (string) ($address['ligne1'] ?? ''),
            (string) ($address['ligne2'] ?? ''),
            trim((string) ($address['code_postal'] ?? '') . ' ' . (string) ($address['localite'] ?? '')),
            (string) ($address['pays'] ?? ''),
        ];
        return implode($separator, array_map(
            fn (string $part): string => $this->e($part),
            array_values(array_filter($parts, static fn (string $part): bool => trim($part) !== ''))
        ));
    }

    private function metadataRow(string $label, string $value): string
    {
        return '<tr><td width="43%" style="color:#6b7680">'
            . $this->e($label)
            . '</td><td width="57%" align="right" style="color:#17324d"><strong>'
            . $this->e($value)
            . '</strong></td></tr>';
    }

    /** @param array<string,mixed> $creditor */
    private function footerText(array $creditor): string
    {
        $parts = [
            (string) ($creditor['nom'] ?? ''),
            trim((string) ($creditor['ligne1'] ?? '') . ' '
                . (string) ($creditor['code_postal'] ?? '') . ' '
                . (string) ($creditor['localite'] ?? '')),
        ];
        if (trim((string) ($creditor['iban'] ?? '')) !== '') {
            $parts[] = $this->formatIban((string) $creditor['iban']);
        }
        return implode('  ·  ', array_filter(
            $parts,
            static fn (string $part): bool => trim($part) !== ''
        ));
    }

    private function formatDate(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed === false ? $date : $parsed->format('d.m.Y');
    }

    private function quantity(int $quantityMilli): string
    {
        return rtrim(rtrim(
            number_format($quantityMilli / 1000, 3, '.', ' '),
            '0'
        ), '.');
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'facture_client' => 'Facture',
            'avoir_client' => 'Avoir',
            'facture_fournisseur' => 'Facture fournisseur',
            'avoir_fournisseur' => 'Avoir fournisseur',
            default => 'Document',
        };
    }

    private function commercialTypeLabel(string $type): string
    {
        return match ($type) {
            'offre_client' => 'Offre client',
            'demande_offre_fournisseur' => 'Demande d’offre fournisseur',
            'reponse_offre_fournisseur' => 'Offre fournisseur',
            'commande_client' => 'Commande client',
            'commande_fournisseur' => 'Commande fournisseur',
            default => 'Document commercial',
        };
    }

    private function commercialStatusLabel(string $status, string $type): string
    {
        if (str_starts_with($type, 'commande_')) {
            return match ($status) {
                'envoye' => 'Envoyée',
                'livre' => 'Livrée',
                'facture' => 'Facturée',
                'annule' => 'Annulée',
                'archive' => 'Archivée',
                default => $status === 'brouillon'
                    ? 'Brouillon'
                    : $status,
            };
        }
        return match ($status) {
            'brouillon' => 'Brouillon',
            'envoye' => 'Envoyé',
            'recu' => 'Reçu',
            'accepte' => 'Accepté',
            'refuse' => 'Refusé',
            'remplace' => 'Remplacé',
            'commande' => 'Commandé',
            'facture' => 'Facturé',
            'annule' => 'Annulé',
            'archive' => 'Archivé',
            default => $status,
        };
    }

    private function formatIban(string $iban): string
    {
        return trim(chunk_split(
            strtoupper((string) preg_replace('/\s+/', '', $iban)),
            4,
            ' '
        ));
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', ' ');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
