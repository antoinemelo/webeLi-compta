<?php
declare(strict_types=1);

namespace Compta\Modules\Facturation;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

final class SwissQrService
{
    /** @param array<string,mixed> $creditor @param array<string,mixed> $debtor */
    public function payload(
        string $iban,
        array $creditor,
        array $debtor,
        int $amountCents,
        string $currency,
        string $scor,
        string $message = '',
    ): string {
        $iban = strtoupper((string) preg_replace('/\s+/', '', $iban));
        if (
            !preg_match('/^(CH|LI)[0-9A-Z]{19}$/', $iban)
            || !in_array($currency, ['CHF', 'EUR'], true)
            || $amountCents <= 0
            || !ScorReference::valid($scor)
        ) {
            throw new BillingException('Données Swiss QR invalides.');
        }
        $creditorFields = $this->address($creditor);
        $debtorFields = $this->address($debtor);
        $fields = [
            'SPC', '0200', '1', $iban,
            ...$creditorFields,
            '', '', '', '', '', '', '',
            number_format($amountCents / 100, 2, '.', ''),
            $currency,
            ...$debtorFields,
            'SCOR', strtoupper($scor),
            mb_substr(trim($message), 0, 140),
            'EPD', '', '', '',
        ];
        return implode("\r\n", $fields);
    }

    public function png(string $payload, int $size = 360): string
    {
        $qr = new QrCode(
            data: $payload,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 12,
            roundBlockSizeMode: RoundBlockSizeMode::Margin
        );
        $raw = (new PngWriter())->write($qr)->getString();
        $image = imagecreatefromstring($raw);
        if ($image === false) {
            throw new BillingException('Impossible de générer le Swiss QR.');
        }
        $center = intdiv(imagesx($image), 2);
        $outer = max(22, intdiv($size, 11));
        $inner = intdiv($outer, 5);
        $black = imagecolorallocate($image, 0, 0, 0);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle(
            $image,
            $center - intdiv($outer, 2),
            $center - intdiv($outer, 2),
            $center + intdiv($outer, 2),
            $center + intdiv($outer, 2),
            $black
        );
        imagefilledrectangle(
            $image,
            $center - $inner,
            $center - intdiv($outer, 3),
            $center + $inner,
            $center + intdiv($outer, 3),
            $white
        );
        imagefilledrectangle(
            $image,
            $center - intdiv($outer, 3),
            $center - $inner,
            $center + intdiv($outer, 3),
            $center + $inner,
            $white
        );
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        if (!is_string($png)) {
            throw new BillingException('Impossible d’encoder le Swiss QR.');
        }
        return $png;
    }

    /** @param array<string,mixed> $address @return list<string> */
    private function address(array $address): array
    {
        $name = trim((string) ($address['nom'] ?? $address['raison_sociale'] ?? ''));
        $line1 = trim((string) ($address['ligne1'] ?? ''));
        $postal = trim((string) ($address['code_postal'] ?? ''));
        $city = trim((string) ($address['localite'] ?? ''));
        $country = strtoupper(trim((string) ($address['pays'] ?? 'CH')));
        if ($name === '' || $line1 === '' || $postal === '' || $city === '') {
            throw new BillingException('Adresse Swiss QR incomplète.');
        }
        return ['K', $name, $line1, trim((string) ($address['ligne2'] ?? '')), $postal, $city, $country];
    }
}
