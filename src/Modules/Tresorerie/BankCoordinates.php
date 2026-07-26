<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie;

final class BankCoordinates
{
    public static function normalizeIban(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', trim($value)));
    }

    public static function normalizeBic(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', trim($value)));
    }

    public static function assertIban(string $value): string
    {
        $iban = self::normalizeIban($value);
        if (
            preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban) !== 1
            || !self::mod97IsOne(substr($iban, 4) . substr($iban, 0, 4))
        ) {
            throw new TreasuryException('IBAN invalide.');
        }
        return $iban;
    }

    public static function assertBic(string $value): string
    {
        $bic = self::normalizeBic($value);
        if (
            $bic !== ''
            && preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic) !== 1
        ) {
            throw new TreasuryException('BIC invalide.');
        }
        return $bic;
    }

    private static function mod97IsOne(string $value): bool
    {
        $remainder = 0;
        foreach (str_split($value) as $character) {
            $digits = ctype_alpha($character)
                ? (string) (ord($character) - 55)
                : $character;
            foreach (str_split($digits) as $digit) {
                $remainder = (($remainder * 10) + (int) $digit) % 97;
            }
        }
        return $remainder === 1;
    }
}
