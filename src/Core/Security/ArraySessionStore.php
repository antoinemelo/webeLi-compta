<?php
declare(strict_types=1);

namespace Compta\Core\Security;

final class ArraySessionStore implements SessionStore
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
    }

    public function destroy(): void
    {
        $this->data = [];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }
}
