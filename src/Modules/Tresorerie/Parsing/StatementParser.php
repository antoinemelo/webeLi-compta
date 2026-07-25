<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie\Parsing;

interface StatementParser
{
    public function supports(string $content, string $filename = ''): bool;

    public function parse(string $content, string $filename = ''): ParsedStatement;
}
