<?php
declare(strict_types=1);

namespace Compta\Core\Maintenance;

use Closure;
use Compta\Core\Database\BackupService;
use Compta\Core\Database\IntegrityChecker;
use Compta\Core\Database\MigrationRunner;
use FilesystemIterator;
use PDO;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

final class UpdateService
{
    public const REPOSITORY = 'git@github.com:antoinemelo/webeLi-compta.git';
    public const BRANCH = 'main';
    public const MANIFEST_URL =
        'https://raw.githubusercontent.com/antoinemelo/webeLi-compta/main/RELEASE.json';
    public const ARCHIVE_URL =
        'https://codeload.github.com/antoinemelo/webeLi-compta/tar.gz/refs/heads/main';

    private const CACHE_SECONDS = 900;
    private const MAX_MANIFEST_BYTES = 2_000_000;
    private const MAX_ARCHIVE_BYTES = 120_000_000;
    private const REQUIRED_FILES = [
        '.htaccess',
        'index.php',
        'VERSION',
        'composer.json',
        'composer.lock',
        'bootstrap/app.php',
        'config/app.php',
        'database/migrations/001_initial.sql',
        'public/.htaccess',
        'public/index.php',
        'public/app/index.html',
        'public/app/.vite/manifest.json',
    ];
    private const ROOT_FILES = [
        '.htaccess',
        'index.php',
        'VERSION',
        'composer.json',
        'composer.lock',
    ];
    private const RUNTIME_PREFIXES = [
        'bin/',
        'bootstrap/',
        'config/',
        'database/migrations/',
        'database/seeds/',
        'public/',
        'resources/',
        'src/',
        'templates/',
    ];
    private const PRESERVE = ['storage/', 'config/local.php', 'vendor/'];

    private readonly Closure $httpGet;

    /**
     * @param null|Closure(string,int,int):string $httpGet
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $root,
        private readonly string $storage,
        private readonly string $instanceId,
        ?Closure $httpGet = null,
    ) {
        $this->httpGet = $httpGet ?? $this->download(...);
    }

    /** @return array<string,mixed> */
    public function status(bool $refresh = false): array
    {
        $installed = $this->installedVersion();
        $cache = null;
        $cachePath = $this->cachePath();
        if (!$refresh && is_file($cachePath)) {
            try {
                $candidate = json_decode(
                    (string) file_get_contents($cachePath),
                    true,
                    64,
                    JSON_THROW_ON_ERROR
                );
                if (
                    is_array($candidate)
                    && (int) ($candidate['checked_at'] ?? 0) >= time() - self::CACHE_SECONDS
                ) {
                    $cache = $candidate;
                }
            } catch (Throwable) {
                $cache = null;
            }
        }

        if ($cache === null) {
            try {
                $raw = ($this->httpGet)(
                    self::MANIFEST_URL,
                    self::MAX_MANIFEST_BYTES,
                    12
                );
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new UpdateException('Le manifeste Git est invalide.');
                }
                $cache = [
                    'checked_at' => time(),
                    'manifest' => $this->validateManifest($decoded),
                    'error' => null,
                ];
            } catch (Throwable $exception) {
                $cache = [
                    'checked_at' => time(),
                    'manifest' => null,
                    'error' => $exception->getMessage(),
                ];
            }
            $this->writeCache($cache);
        }

        $manifest = is_array($cache['manifest'] ?? null)
            ? $cache['manifest']
            : null;
        $latest = trim((string) ($manifest['version'] ?? ''));
        $available = $latest !== '' && $this->compareVersions($latest, $installed) > 0;
        $current = $latest !== '' && $this->compareVersions($latest, $installed) <= 0;

        return [
            'installed' => $installed,
            'latest' => $latest !== '' ? $latest : null,
            'checked_at' => (int) ($cache['checked_at'] ?? 0),
            'error' => $cache['error'] ?? null,
            'available' => $available,
            'current' => $current,
            'writable' => is_writable($this->root) && $this->storageWritable(),
            'repository' => self::REPOSITORY,
            'branch' => self::BRANCH,
            'release_fingerprint' => $manifest === null
                ? null
                : $this->manifestFingerprint($manifest),
            'file_count' => is_array($manifest['files'] ?? null)
                ? count($manifest['files'])
                : 0,
            'manifest' => $manifest,
        ];
    }

    /**
     * @param array<string,mixed> $expectedManifest
     * @return array{version:string,migrations:list<string>,backup_created:bool,file_count:int}
     */
    public function apply(array $expectedManifest): array
    {
        $expectedManifest = $this->validateManifest($expectedManifest);
        $root = rtrim($this->root, '/');
        $storage = rtrim($this->storage, '/');
        if (!is_dir($root) || !is_writable($root) || !$this->storageWritable()) {
            throw new UpdateException(
                'Les dossiers de l’application et de stockage doivent être inscriptibles.'
            );
        }

        $updates = $storage . '/updates';
        $this->ensureDirectory($updates);
        if (is_link($updates)) {
            throw new UpdateException('Le dossier des mises à jour ne peut pas être un lien.');
        }
        $lock = fopen($updates . '/update.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new UpdateException('Une autre mise à jour est déjà en cours.');
        }

        $token = bin2hex(random_bytes(6));
        $work = $updates . '/work-' . $token;
        $backup = $updates . '/backup-' . gmdate('Ymd-His') . '-'
            . $expectedManifest['version'] . '-' . $token;
        $archive = $work . '/release.tar.gz';
        $extract = $work . '/extract';
        $maintenance = $root . '/.maintenance';
        /** @var list<string> $touched */
        $touched = [];

        try {
            $this->ensureDirectory($work);
            $this->ensureDirectory($extract);
            $this->ensureDirectory($backup . '/code');

            $manifestRaw = ($this->httpGet)(
                self::MANIFEST_URL,
                self::MAX_MANIFEST_BYTES,
                12
            );
            $fresh = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($fresh)) {
                throw new UpdateException('Le manifeste Git actualisé est invalide.');
            }
            $fresh = $this->validateManifest($fresh);
            if (!hash_equals(
                $this->manifestFingerprint($expectedManifest),
                $this->manifestFingerprint($fresh)
            )) {
                throw new UpdateException(
                    'Une nouvelle publication est apparue. Vérifiez à nouveau la version.'
                );
            }

            $archiveBytes = ($this->httpGet)(
                self::ARCHIVE_URL,
                self::MAX_ARCHIVE_BYTES,
                60
            );
            if (file_put_contents($archive, $archiveBytes, LOCK_EX) === false) {
                throw new UpdateException('L’archive Git ne peut pas être enregistrée.');
            }
            $releaseRoot = $this->extractAndVerify(
                $archive,
                $extract,
                $fresh,
                $manifestRaw
            );

            $plan = (new MigrationRunner(
                $this->pdo,
                $releaseRoot . '/database/migrations'
            ))->plan();
            foreach ($plan as $item) {
                if (in_array($item['status'], ['mismatch', 'missing'], true)) {
                    throw new UpdateException(
                        "Migration {$item['version']} incohérente ({$item['status']})."
                    );
                }
            }

            if (file_put_contents($maintenance, (string) time(), LOCK_EX) === false) {
                throw new UpdateException('Le mode maintenance ne peut pas être activé.');
            }
            BackupService::create($this->pdo, $backup, $this->instanceId);
            $oldManifest = $this->installedManifest();
            $oldFiles = is_array($oldManifest['files'] ?? null)
                ? array_keys($oldManifest['files'])
                : [];
            $newFiles = array_keys($fresh['files']);
            $managed = array_values(array_unique(array_merge(
                $oldFiles,
                $newFiles,
                ['RELEASE.json']
            )));
            foreach ($managed as $relative) {
                if ($relative !== 'RELEASE.json' && !$this->safeReleasePath($relative)) {
                    continue;
                }
                $target = $root . '/' . $relative;
                if (!is_file($target)) {
                    continue;
                }
                $saved = $backup . '/code/' . $relative;
                $this->ensureDirectory(dirname($saved));
                if (!copy($target, $saved)) {
                    throw new UpdateException('La sauvegarde du code a échoué.');
                }
            }
            if (file_put_contents(
                $backup . '/previous-files.json',
                json_encode($oldFiles, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                LOCK_EX
            ) === false) {
                throw new UpdateException('L’inventaire du code précédent ne peut pas être sauvegardé.');
            }
            foreach ($newFiles as $relative) {
                $touched[] = $relative;
                $this->copyAtomic(
                    $releaseRoot . '/' . $relative,
                    $root . '/' . $relative
                );
            }
            $touched[] = 'RELEASE.json';
            $this->copyAtomic($releaseRoot . '/RELEASE.json', $root . '/RELEASE.json');
            foreach (array_diff($oldFiles, $newFiles) as $relative) {
                if (!$this->safeReleasePath($relative)) {
                    continue;
                }
                $target = $root . '/' . $relative;
                if (is_file($target)) {
                    $touched[] = $relative;
                    if (!unlink($target)) {
                        throw new UpdateException(
                            "L’ancien fichier {$relative} ne peut pas être retiré."
                        );
                    }
                }
            }

            $migrations = $this->applyMigrationsAtomically($plan, $releaseRoot);
            @unlink($this->cachePath());
            @unlink($maintenance);
            $this->removeTree($work);
            flock($lock, LOCK_UN);
            fclose($lock);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            return [
                'version' => (string) $fresh['version'],
                'migrations' => $migrations,
                'backup_created' => true,
                'file_count' => count($newFiles),
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            foreach (array_reverse(array_values(array_unique($touched))) as $relative) {
                $saved = $backup . '/code/' . $relative;
                $target = $root . '/' . $relative;
                if (is_file($saved)) {
                    try {
                        $this->copyAtomic($saved, $target);
                    } catch (Throwable) {
                        // La sauvegarde reste disponible pour une restauration manuelle.
                    }
                } elseif (is_file($target)) {
                    @unlink($target);
                }
            }
            @unlink($maintenance);
            $this->removeTree($work);
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            throw $exception instanceof UpdateException
                ? $exception
                : new UpdateException(
                    'La mise à jour a échoué : ' . $exception->getMessage(),
                    0,
                    $exception
                );
        }
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    public function validateManifest(array $manifest): array
    {
        if (($manifest['format'] ?? null) !== 1) {
            throw new UpdateException('Le format du manifeste Git est incompatible.');
        }
        if (($manifest['application'] ?? '') !== 'webeli-compta') {
            throw new UpdateException('Le manifeste ne concerne pas WebeLi Compta.');
        }
        if (
            ($manifest['repository'] ?? '') !== self::REPOSITORY
            || ($manifest['branch'] ?? '') !== self::BRANCH
            || ($manifest['manifest_url'] ?? '') !== self::MANIFEST_URL
            || ($manifest['archive_url'] ?? '') !== self::ARCHIVE_URL
        ) {
            throw new UpdateException('La source Git de la mise à jour n’est pas autorisée.');
        }
        $version = trim((string) ($manifest['version'] ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $version) !== 1) {
            throw new UpdateException('La version publiée est invalide.');
        }
        $preserve = $manifest['preserve_on_update'] ?? null;
        if (!is_array($preserve) || array_values($preserve) !== self::PRESERVE) {
            throw new UpdateException('La politique de préservation est invalide.');
        }
        $files = $manifest['files'] ?? null;
        if (!is_array($files) || count($files) < count(self::REQUIRED_FILES)) {
            throw new UpdateException('L’inventaire de la publication est incomplet.');
        }
        foreach ($files as $path => $hash) {
            if (
                !is_string($path)
                || !$this->safeReleasePath($path)
                || !is_string($hash)
                || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1
            ) {
                throw new UpdateException(
                    'Le manifeste contient un chemin ou une empreinte invalide.'
                );
            }
        }
        foreach (self::REQUIRED_FILES as $required) {
            if (!isset($files[$required])) {
                throw new UpdateException(
                    "Le fichier indispensable {$required} manque dans la publication."
                );
            }
        }
        $manifest['version'] = $version;
        $manifest['files'] = $files;
        $manifest['preserve_on_update'] = array_values($preserve);
        return $manifest;
    }

    public function manifestFingerprint(array $manifest): string
    {
        $manifest = $this->validateManifest($manifest);
        return hash('sha256', json_encode([
            'application' => $manifest['application'],
            'version' => $manifest['version'],
            'repository' => $manifest['repository'],
            'branch' => $manifest['branch'],
            'preserve_on_update' => $manifest['preserve_on_update'],
            'files' => $manifest['files'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function safeReleasePath(string $path): bool
    {
        if (
            $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, "\0")
            || str_contains($path, '\\')
        ) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }
        if ($path === 'config/local.php' || str_starts_with($path, 'storage/')) {
            return false;
        }
        if (in_array($path, self::ROOT_FILES, true)) {
            return true;
        }
        foreach (self::RUNTIME_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function installedVersion(): string
    {
        $path = rtrim($this->root, '/') . '/VERSION';
        $version = is_file($path) ? trim((string) file_get_contents($path)) : '';
        return $version !== '' ? $version : '0.0.0';
    }

    /** @return array<string,mixed>|null */
    private function installedManifest(): ?array
    {
        $path = rtrim($this->root, '/') . '/RELEASE.json';
        if (!is_file($path)) {
            return null;
        }
        try {
            $decoded = json_decode(
                (string) file_get_contents($path),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            return is_array($decoded) ? $this->validateManifest($decoded) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function compareVersions(string $left, string $right): int
    {
        $quarter = '/^(\d{4})Q([1-4])\.(\d+)$/';
        if (
            preg_match($quarter, $left, $leftParts) === 1
            && preg_match($quarter, $right, $rightParts) === 1
        ) {
            foreach ([1, 2, 3] as $index) {
                $comparison = (int) $leftParts[$index] <=> (int) $rightParts[$index];
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            return 0;
        }
        return version_compare($left, $right);
    }

    private function cachePath(): string
    {
        return rtrim($this->storage, '/') . '/updates/release-status.json';
    }

    /** @param array<string,mixed> $cache */
    private function writeCache(array $cache): void
    {
        try {
            $this->ensureDirectory(dirname($this->cachePath()));
            @file_put_contents(
                $this->cachePath(),
                json_encode(
                    $cache,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ),
                LOCK_EX
            );
        } catch (Throwable) {
            // Un cache non inscriptible ne doit pas masquer le résultat de la vérification.
        }
    }

    private function storageWritable(): bool
    {
        if (is_dir($this->storage)) {
            return is_writable($this->storage);
        }
        $parent = dirname($this->storage);
        return is_dir($parent) && is_writable($parent);
    }

    private function download(string $url, int $maxBytes, int $timeout): string
    {
        $parts = parse_url($url);
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        if (
            ($parts['scheme'] ?? '') !== 'https'
            || !in_array($host, ['raw.githubusercontent.com', 'codeload.github.com'], true)
        ) {
            throw new UpdateException('L’adresse de mise à jour n’est pas autorisée.');
        }
        if (!function_exists('curl_init')) {
            throw new UpdateException('L’extension PHP cURL est requise.');
        }
        $contents = '';
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'webeli-compta-update-client/1',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FAILONERROR => true,
            CURLOPT_WRITEFUNCTION => static function (
                mixed $curl,
                string $chunk
            ) use (&$contents, $maxBytes): int {
                $length = strlen($chunk);
                if (strlen($contents) + $length > $maxBytes) {
                    return 0;
                }
                $contents .= $chunk;
                return $length;
            },
        ]);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($ok !== true || $status !== 200) {
            throw new UpdateException(
                'GitHub est momentanément inaccessible'
                . ($error !== '' ? ' : ' . $error : '.')
            );
        }
        return $contents;
    }

    /** @param array<string,mixed> $manifest */
    private function extractAndVerify(
        string $archive,
        string $destination,
        array $manifest,
        string $manifestRaw,
    ): string {
        if (!class_exists(PharData::class)) {
            throw new UpdateException('L’extension PHP Phar est requise.');
        }
        $tar = substr($archive, 0, -3);
        try {
            $compressed = new PharData($archive);
            if (!is_file($tar)) {
                $compressed->decompress();
            }
            $phar = new PharData($tar);
            foreach (new RecursiveIteratorIterator($phar) as $entry) {
                $name = str_replace('\\', '/', (string) $entry->getPathname());
                if (
                    $entry->isLink()
                    || str_contains($name, "\0")
                    || preg_match('~(?:^|/)\.\.(?:/|$)~', $name) === 1
                ) {
                    throw new UpdateException('L’archive Git contient une entrée interdite.');
                }
            }
            $phar->extractTo($destination, null, true);
        } catch (UpdateException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new UpdateException(
                'L’archive Git ne peut pas être extraite : ' . $exception->getMessage(),
                0,
                $exception
            );
        }
        $entries = array_values(array_filter(
            scandir($destination) ?: [],
            static fn (string $name): bool => !in_array($name, ['.', '..'], true)
        ));
        if (count($entries) !== 1 || !is_dir($destination . '/' . $entries[0])) {
            throw new UpdateException('La structure de l’archive Git est inattendue.');
        }
        $releaseRoot = $destination . '/' . $entries[0];
        $archiveManifest = $releaseRoot . '/RELEASE.json';
        if (!is_file($archiveManifest)) {
            throw new UpdateException('Le manifeste manque dans l’archive Git.');
        }
        $archiveManifestRaw = (string) file_get_contents($archiveManifest);
        $archiveDecoded = json_decode(
            $archiveManifestRaw,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        if (
            !is_array($archiveDecoded)
            || !hash_equals(
                $this->manifestFingerprint($manifest),
                $this->manifestFingerprint($archiveDecoded)
            )
            || !hash_equals(hash('sha256', $manifestRaw), hash('sha256', $archiveManifestRaw))
        ) {
            throw new UpdateException(
                'Le manifeste distant et l’archive Git ne désignent pas la même publication.'
            );
        }
        foreach ($manifest['files'] as $relative => $hash) {
            $path = $releaseRoot . '/' . $relative;
            $real = realpath($path);
            $realRoot = realpath($releaseRoot);
            if (
                !is_file($path)
                || is_link($path)
                || $real === false
                || $realRoot === false
                || !str_starts_with($real, rtrim($realRoot, '/') . '/')
                || !hash_equals($hash, hash_file('sha256', $path) ?: '')
            ) {
                throw new UpdateException(
                    "L’empreinte du fichier {$relative} est invalide."
                );
            }
        }
        return $releaseRoot;
    }

    /**
     * @param list<array{version:string,file:string,status:string,checksum:string}> $plan
     * @return list<string>
     */
    private function applyMigrationsAtomically(array $plan, string $releaseRoot): array
    {
        $pending = array_values(array_filter(
            $plan,
            static fn (array $item): bool => $item['status'] === 'pending'
        ));
        if ($pending === []) {
            return [];
        }
        $applied = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($pending as $item) {
                $path = $releaseRoot . '/database/migrations/' . $item['file'];
                $sql = file_get_contents($path);
                if ($sql === false) {
                    throw new UpdateException("Lecture impossible : {$item['file']}");
                }
                $this->pdo->exec($sql);
                $statement = $this->pdo->prepare(
                    "INSERT INTO schema_migrations (version, checksum, applied_at)
                     VALUES (:version, :checksum, datetime('now'))"
                );
                $statement->execute([
                    'version' => $item['version'],
                    'checksum' => $item['checksum'],
                ]);
                $applied[] = $item['version'];
            }
            if (!IntegrityChecker::check($this->pdo)['ok']) {
                throw new UpdateException('La base migrée ne passe pas le contrôle d’intégrité.');
            }
            $this->pdo->commit();
            return $applied;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function copyAtomic(string $source, string $destination): void
    {
        if (!is_file($source) || is_link($source)) {
            throw new UpdateException('Un fichier de la publication est introuvable.');
        }
        $this->ensureDirectory(dirname($destination));
        $temporary = $destination . '.update-' . bin2hex(random_bytes(4));
        if (!copy($source, $temporary)) {
            @unlink($temporary);
            throw new UpdateException('Un fichier de la mise à jour ne peut pas être préparé.');
        }
        @chmod($temporary, fileperms($source) & 0777);
        if (!rename($temporary, $destination)) {
            @unlink($temporary);
            throw new UpdateException('Un fichier de l’application ne peut pas être remplacé.');
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (
            !is_dir($directory)
            && !mkdir($directory, 0770, true)
            && !is_dir($directory)
        ) {
            throw new UpdateException("Le dossier {$directory} ne peut pas être créé.");
        }
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
            $this->removeTree($item->getPathname());
        }
        @rmdir($path);
    }
}
