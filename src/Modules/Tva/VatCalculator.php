<?php
declare(strict_types=1);

namespace Compta\Modules\Tva;

final class VatCalculator
{
    /** @return array{net_cents:int,vat_cents:int,gross_cents:int,rate_bp:int,input_mode:string} */
    public function calculate(int $amountCents, int $rateBp, string $inputMode): array
    {
        if ($rateBp < 0 || $rateBp > 10000 || !in_array($inputMode, ['net', 'brut'], true)) {
            throw new VatException('Taux ou mode de saisie TVA invalide.');
        }
        if ($inputMode === 'net') {
            $net = $amountCents;
            $vat = self::divideRounded($net * $rateBp, 10000);
            $gross = $net + $vat;
        } else {
            $gross = $amountCents;
            $net = self::divideRounded($gross * 10000, 10000 + $rateBp);
            $vat = $gross - $net;
        }
        return [
            'net_cents' => $net,
            'vat_cents' => $vat,
            'gross_cents' => $gross,
            'rate_bp' => $rateBp,
            'input_mode' => $inputMode,
        ];
    }

    public static function divideRounded(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new VatException('Diviseur invalide.');
        }
        $sign = $numerator < 0 ? -1 : 1;
        $absolute = abs($numerator);
        $quotient = intdiv($absolute, $denominator);
        $remainder = $absolute % $denominator;
        if ($remainder * 2 >= $denominator) {
            $quotient++;
        }
        return $sign * $quotient;
    }
}
