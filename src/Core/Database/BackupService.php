<?php
declare(strict_types=1);

namespace Compta\Core\Database;

use PDO;
use RuntimeException;

final class BackupService
{
    public static function create(PDO $pdo, string $directory, string $instanceId): string
    {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException("Impossible de créer {$directory}");
        }
        $safeInstance = preg_replace('/[^a-zA-Z0-9._-]/', '-', $instanceId) ?: 'instance';
        $path = sprintf(
            '%s/%s-before-migrate-%s-%s.sqlite',
            rtrim($directory, '/'),
            $safeInstance,
            gmdate('Ymd-His'),
            bin2hex(random_bytes(3))
        );
        $quoted = str_replace("'", "''", $path);
        $pdo->exec("VACUUM INTO '{$quoted}'");
        $check = ConnectionFactory::sqlite($path);
        if (!IntegrityChecker::check($check)['ok']) {
            throw new RuntimeException('La sauvegarde créée est incohérente.');
        }
        return $path;
    }
}
