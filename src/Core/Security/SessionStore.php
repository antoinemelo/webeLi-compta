<?php
declare(strict_types=1);

namespace Compta\Core\Security;

interface SessionStore
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
    public function remove(string $key): void;
    public function regenerate(): void;
    public function destroy(): void;
}
