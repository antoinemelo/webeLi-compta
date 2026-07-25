<?php
declare(strict_types=1);

namespace Compta\Core\Database;

use PDO;

final class IntegrityChecker
{
    /** @return array{integrity:list<string>,foreign_keys:list<array<string,mixed>>,ok:bool} */
    public static function check(PDO $pdo): array
    {
        $integrity = array_map(
            static fn (array $row): string => (string) array_values($row)[0],
            $pdo->query('PRAGMA integrity_check')->fetchAll()
        );
        $foreignKeys = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
        return [
            'integrity' => $integrity,
            'foreign_keys' => $foreignKeys,
            'ok' => $integrity === ['ok'] && $foreignKeys === [],
        ];
    }
}
