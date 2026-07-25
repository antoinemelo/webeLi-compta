<?php
declare(strict_types=1);

namespace Compta\Core\Database;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $directory,
    ) {
    }

    /** @return list<array{version:string,file:string,status:string,checksum:string}> */
    public function plan(): array
    {
        $this->ensureJournal();
        $applied = [];
        foreach ($this->pdo->query('SELECT version, checksum FROM schema_migrations') as $row) {
            $applied[(string) $row['version']] = (string) $row['checksum'];
        }

        $plan = [];
        foreach ($this->files() as $version => $path) {
            $checksum = hash_file('sha256', $path);
            if ($checksum === false) {
                throw new RuntimeException("Checksum impossible : {$path}");
            }
            $status = 'pending';
            if (isset($applied[$version])) {
                $status = hash_equals($applied[$version], $checksum) ? 'applied' : 'mismatch';
                unset($applied[$version]);
            }
            $plan[] = [
                'version' => $version,
                'file' => basename($path),
                'status' => $status,
                'checksum' => $checksum,
            ];
        }
        foreach ($applied as $version => $checksum) {
            $plan[] = [
                'version' => $version,
                'file' => '(fichier absent)',
                'status' => 'missing',
                'checksum' => $checksum,
            ];
        }
        return $plan;
    }

    /** @return list<string> versions appliquées */
    public function apply(): array
    {
        $plan = $this->plan();
        foreach ($plan as $item) {
            if (in_array($item['status'], ['mismatch', 'missing'], true)) {
                throw new RuntimeException(
                    "Migration {$item['version']} incohérente ({$item['status']})."
                );
            }
        }

        $applied = [];
        foreach ($plan as $item) {
            if ($item['status'] !== 'pending') {
                continue;
            }
            $path = $this->directory . '/' . $item['file'];
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new RuntimeException("Lecture impossible : {$path}");
            }
            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $stmt = $this->pdo->prepare(
                    "INSERT INTO schema_migrations (version, checksum, applied_at)
                     VALUES (:version, :checksum, datetime('now'))"
                );
                $stmt->execute([
                    'version' => $item['version'],
                    'checksum' => $item['checksum'],
                ]);
                $this->pdo->commit();
                $applied[] = $item['version'];
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }
        return $applied;
    }

    private function ensureJournal(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                checksum TEXT NOT NULL,
                applied_at TEXT NOT NULL
            )"
        );
    }

    /** @return array<string, string> */
    private function files(): array
    {
        $paths = glob(rtrim($this->directory, '/') . '/*.sql') ?: [];
        sort($paths, SORT_STRING);
        $files = [];
        foreach ($paths as $path) {
            if (!preg_match('/^([0-9]{3,})_[a-z0-9_-]+\.sql$/i', basename($path), $match)) {
                throw new RuntimeException('Nom de migration invalide : ' . basename($path));
            }
            $version = $match[1];
            if (isset($files[$version])) {
                throw new RuntimeException("Migration dupliquée : {$version}");
            }
            $files[$version] = $path;
        }
        return $files;
    }
}
