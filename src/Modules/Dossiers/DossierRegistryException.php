<?php
declare(strict_types=1);

namespace Compta\Modules\Dossiers;

use RuntimeException;

final class DossierRegistryException extends RuntimeException
{
    /** @param array<string,int> $dependencies */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'DOSSIER_INVALID',
        public readonly array $dependencies = [],
    ) {
        parent::__construct($message);
    }
}
