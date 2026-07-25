<?php
declare(strict_types=1);

namespace Compta\Modules\Dashboard\Application;

use RuntimeException;

final class DashboardQueryException extends RuntimeException
{
    public function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }
}
