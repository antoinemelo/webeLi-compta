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
     *   nom:string,ligne1:string,ligne2?:string,code_postal:string,
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
        $pdf->SetMargins(16, 15, 16);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 10);
        $pdf->writeHTML($this->headerHtml($creditor, $debtorAddress, $document), true, false, true);
        $pdf->Ln(5);
        $pdf->writeHTML($this->linesHtml($lines, $document), true, false, true);
        if ($qrPng !== '') {
            $pdf->SetY(max(150.0, $pdf->GetY() + 8));
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(0, 7, 'Section paiement — QR-facture suisse', 0, 1);
            $pdf->Image('@' . $qrPng, 20, $pdf->GetY(), 48, 48, 'PNG');
            $pdf->SetXY(74, $pdf->GetY() + 4);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->MultiCell(
                115,
                5,
                "Compte\n" . $this->formatIban((string) $creditor['iban'])
                . "\n\nRéférence SCOR\n"
                . ScorReference::formatted((string) $document['reference_scor'])
                . "\n\nMontant\n"
                . $this->money((int) $document['total_brut_centimes'])
                . ' ' . $document['monnaie'],
                0,
                'L'
            );
        }
        $bytes = $pdf->Output('', 'S');
        if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-')) {
            throw new BillingException('La génération du PDF a échoué.');
        }
        return [$bytes, $payload];
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
        return '<table cellpadding="2"><tr><td width="55%"><strong>'
            . $this->e((string) ($creditor['nom'] ?? ''))
            . '</strong><br>' . $this->addressHtml($creditor)
            . '</td><td width="45%"><strong>' . $this->e($this->typeLabel((string) $document['type']))
            . '</strong><br><span style="font-size:16pt"><strong>'
            . $this->e((string) $document['numero'])
            . '</strong></span><br>Date : ' . $this->e((string) $document['date_document'])
            . '<br>Échéance : ' . $this->e((string) $document['date_echeance'])
            . '</td></tr><tr><td></td><td><br><strong>'
            . $this->e((string) ($debtor['nom'] ?? ''))
            . '</strong><br>' . $this->addressHtml($debtor)
            . '</td></tr></table>';
    }

    /** @param list<array<string,mixed>> $lines @param array<string,mixed> $document */
    private function linesHtml(array $lines, array $document): string
    {
        $html = '<table border="1" cellpadding="4">'
            . '<thead><tr style="background-color:#eeeeee">'
            . '<th width="42%">Désignation</th><th width="12%" align="right">Qté</th>'
            . '<th width="16%" align="right">Prix</th><th width="12%" align="right">TVA</th>'
            . '<th width="18%" align="right">Total</th></tr></thead><tbody>';
        foreach ($lines as $line) {
            $html .= '<tr><td>' . $this->e((string) $line['libelle'])
                . '<br><small>Prestation : ' . $this->e((string) $line['date_prestation'])
                . '</small></td><td align="right">'
                . number_format((int) $line['quantite_milli'] / 1000, 3, '.', ' ')
                . '</td><td align="right">' . $this->money((int) $line['prix_unitaire_centimes'])
                . '</td><td align="right">' . number_format(
                    (int) $line['taux_tva_snapshot_bp'] / 100,
                    2,
                    '.',
                    ''
                ) . '%</td><td align="right">'
                . $this->money((int) $line['total_brut_centimes'])
                . '</td></tr>';
        }
        return $html . '</tbody></table><br><table cellpadding="3">'
            . '<tr><td width="70%"></td><td width="15%">Net</td><td width="15%" align="right">'
            . $this->money((int) $document['total_net_centimes']) . '</td></tr>'
            . '<tr><td></td><td>TVA</td><td align="right">'
            . $this->money((int) $document['total_tva_centimes']) . '</td></tr>'
            . '<tr><td></td><td><strong>Total</strong></td><td align="right"><strong>'
            . $this->money((int) $document['total_brut_centimes']) . ' '
            . $this->e((string) $document['monnaie'])
            . '</strong></td></tr></table>';
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
    private function addressHtml(array $address): string
    {
        $parts = [
            (string) ($address['ligne1'] ?? ''),
            (string) ($address['ligne2'] ?? ''),
            trim((string) ($address['code_postal'] ?? '') . ' ' . (string) ($address['localite'] ?? '')),
            (string) ($address['pays'] ?? ''),
        ];
        return implode('<br>', array_map(
            fn (string $part): string => $this->e($part),
            array_values(array_filter($parts, static fn (string $part): bool => trim($part) !== ''))
        ));
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
