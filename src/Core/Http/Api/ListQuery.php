<?php
declare(strict_types=1);

namespace Compta\Core\Http\Api;

final class ListQuery
{
    /** @param array<string, string> $filters */
    public function __construct(
        public readonly int $page,
        public readonly int $perPage,
        public readonly string $sort,
        public readonly string $order,
        public readonly array $filters = [],
    ) {
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
