<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie\Parsing;

use Compta\Modules\Tresorerie\TreasuryException;

final class Money
{
    public static function toCents(string $value): int
    {
        $normalized = str_replace(
            ["'", ' ', "\u{202F}", "\u{00A0}"],
            '',
            trim($value)
        );
        $normalized = str_replace(',', '.', $normalized);
        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d+))?$/', $normalized, $matches)) {
            throw new TreasuryException("Montant bancaire invalide : {$value}");
        }
        $negative = $matches[1] === '-';
        $fraction = str_pad($matches[3] ?? '', 3, '0');
        $cents = ((int) $matches[2] * 100) + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $cents++;
        }
        return $negative ? -$cents : $cents;
    }
}
