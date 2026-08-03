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
        $check = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $check->exec('PRAGMA foreign_keys = ON');
        $journalMode = mb_strtolower(
            (string) $check->query('PRAGMA journal_mode = DELETE')->fetchColumn()
        );
        if ($journalMode !== 'delete') {
            throw new RuntimeException(
                'La sauvegarde ne peut pas être normalisée en fichier SQLite autonome.'
            );
        }
        if (!IntegrityChecker::check($check)['ok']) {
            throw new RuntimeException('La sauvegarde créée est incohérente.');
        }
        $check = null;
        if (is_file($path . '-wal') || is_file($path . '-shm')) {
            throw new RuntimeException(
                'La sauvegarde autonome conserve des fichiers SQLite auxiliaires.'
            );
        }
        return $path;
    }
}
