<?php
declare(strict_types=1);

namespace Compta\Core\Diagnostics;

use Compta\Core\Config\AppConfig;
use Compta\Core\Database\IntegrityChecker;
use Compta\Core\Database\MigrationRunner;
use PDO;
use Throwable;

final class Doctor
{
    public function __construct(
        private readonly AppConfig $config,
        private readonly PDO $pdo,
        private readonly MigrationRunner $migrations,
    ) {
    }

    /** @return list<array{level:string,label:string,message:string}> */
    public function run(): array
    {
        $results = [];
        $results[] = $this->item(
            version_compare(PHP_VERSION, '8.2.0', '>=') ? 'ok' : 'error',
            'PHP',
            PHP_VERSION
        );
        $drivers = PDO::getAvailableDrivers();
        $results[] = $this->item(
            in_array('sqlite', $drivers, true) ? 'ok' : 'error',
            'PDO SQLite',
            implode(', ', $drivers) ?: 'aucun pilote'
        );
        foreach (['mbstring', 'openssl', 'session'] as $extension) {
            $results[] = $this->item(
                extension_loaded($extension) ? 'ok' : 'error',
                "Extension {$extension}",
                extension_loaded($extension) ? 'chargée' : 'manquante'
            );
        }
        foreach (['intl', 'gd', 'dom', 'xmlreader', 'xmlwriter', 'simplexml', 'zip'] as $extension) {
            $results[] = $this->item(
                extension_loaded($extension) ? 'ok' : 'warning',
                "Extension {$extension}",
                extension_loaded($extension) ? 'chargée' : 'à installer avant les modules concernés'
            );
        }
        $storage = $this->config->string('storage_path');
        $writable = (is_dir($storage) || @mkdir($storage, 0770, true)) && is_writable($storage);
        $results[] = $this->item(
            $writable ? 'ok' : 'error',
            'Stockage',
            $writable ? $storage : "non inscriptible : {$storage}"
        );
        $results[] = $this->item(
            $this->config->string('env') !== 'prod' || $this->config->string('instance_id') !== ''
                ? 'ok'
                : 'error',
            'Instance',
            $this->config->string('instance_id')
        );

        try {
            $statuses = array_column($this->migrations->plan(), 'status');
            $bad = array_intersect($statuses, ['mismatch', 'missing']);
            $pending = count(array_filter($statuses, static fn (string $s): bool => $s === 'pending'));
            $results[] = $this->item(
                $bad === [] ? ($pending > 0 ? 'warning' : 'ok') : 'error',
                'Migrations',
                $bad !== [] ? 'checksum/fichier incohérent' : "{$pending} en attente"
            );
        } catch (Throwable $e) {
            $results[] = $this->item('error', 'Migrations', $e->getMessage());
        }

        try {
            $integrity = IntegrityChecker::check($this->pdo);
            $results[] = $this->item(
                $integrity['ok'] ? 'ok' : 'error',
                'Intégrité SQLite',
                $integrity['ok'] ? 'ok' : 'échec'
            );
        } catch (Throwable $e) {
            $results[] = $this->item('error', 'Intégrité SQLite', $e->getMessage());
        }

        return $results;
    }

    /** @return array{level:string,label:string,message:string} */
    private function item(string $level, string $label, string $message): array
    {
        return compact('level', 'label', 'message');
    }
}
