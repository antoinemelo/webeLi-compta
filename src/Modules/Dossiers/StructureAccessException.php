<?php
declare(strict_types=1);

namespace Compta\Modules\Dossiers;

use RuntimeException;

final class StructureAccessException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'STRUCTURE_ACCESS_INVALID',
    ) {
        parent::__construct($message);
    }
}
