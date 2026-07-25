<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie\Parsing;

use Compta\Modules\Tresorerie\TreasuryException;

final class ParserRegistry
{
    /** @var list<StatementParser> */
    private array $parsers;

    public function __construct(?array $parsers = null)
    {
        $this->parsers = $parsers ?? [
            new PostFinanceCsvParser(),
            new Camt053Parser(),
            new Camt054Parser(),
        ];
    }

    public function parse(string $content, string $filename = ''): ParsedStatement
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($content, $filename)) {
                return $parser->parse($content, $filename);
            }
        }
        throw new TreasuryException('Format bancaire non reconnu.');
    }
}
