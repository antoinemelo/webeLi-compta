<?php
declare(strict_types=1);

namespace Compta\Modules\Facturation;

final class ScorReference
{
    public static function create(string $source): string
    {
        $body = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $source));
        if ($body === '' || strlen($body) > 21) {
            throw new BillingException('Source SCOR invalide.');
        }
        $rearranged = self::expand($body . 'RF00');
        $check = 98 - self::mod97($rearranged);
        return 'RF' . str_pad((string) $check, 2, '0', STR_PAD_LEFT) . $body;
    }

    public static function valid(string $reference): bool
    {
        $value = strtoupper((string) preg_replace('/\s+/', '', $reference));
        if (!preg_match('/^RF[0-9]{2}[A-Z0-9]{1,21}$/', $value)) {
            return false;
        }
        return self::mod97(self::expand(substr($value, 4) . substr($value, 0, 4))) === 1;
    }

    public static function formatted(string $reference): string
    {
        return trim(chunk_split(strtoupper($reference), 4, ' '));
    }

    private static function expand(string $value): string
    {
        $result = '';
        foreach (str_split($value) as $character) {
            $result .= ctype_alpha($character)
                ? (string) (ord($character) - 55)
                : $character;
        }
        return $result;
    }

    private static function mod97(string $digits): int
    {
        $remainder = 0;
        foreach (str_split($digits) as $digit) {
            $remainder = (($remainder * 10) + (int) $digit) % 97;
        }
        return $remainder;
    }
}
