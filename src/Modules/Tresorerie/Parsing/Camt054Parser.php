<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie\Parsing;

final class Camt054Parser extends CamtParser
{
    protected function messageNumber(): string
    {
        return '054';
    }
}
