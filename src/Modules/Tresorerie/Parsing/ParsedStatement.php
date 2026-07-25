<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie\Parsing;

final class ParsedStatement
{
    /**
     * @param list<array<string,mixed>> $transactions
     * @param list<array<string,mixed>> $balances
     * @param list<string> $errors
     */
    public function __construct(
        public readonly string $format,
        public readonly string $namespace,
        public readonly string $iban,
        public readonly string $currency,
        public readonly string $dateStart,
        public readonly string $dateEnd,
        public readonly array $transactions,
        public readonly array $balances = [],
        public readonly array $errors = [],
    ) {
    }
}
