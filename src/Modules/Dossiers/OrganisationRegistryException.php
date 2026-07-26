<?php
declare(strict_types=1);

namespace Compta\Modules\Dossiers;

use RuntimeException;

final class OrganisationRegistryException extends RuntimeException
{
    /** @param array<string,int> $dependencies */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'ORGANISATION_INVALID',
        public readonly array $dependencies = [],
    ) {
        parent::__construct($message);
    }
}
