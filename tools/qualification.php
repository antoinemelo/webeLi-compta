<?php
declare(strict_types=1);

/**
 * Porte de qualification autonome pour l'hébergement mutualisé.
 *
 * @return int code de sortie
 */
function runQualification(string $root): int
{
    $steps = [
        'migrations 001–010 inchangées' => static fn (): bool =>
            qualificationMigrationHashes($root),
        'syntaxe PHP' => static fn (): bool => qualificationLint($root),
        'tests rapides' => static fn (): bool => qualificationCommand(
            [PHP_BINARY, $root . '/bin/console', 'test', '--suite=quick'],
            $root
        ),
        'tests d’intégration' => static fn (): bool => qualificationCommand(
            [PHP_BINARY, $root . '/bin/console', 'test', '--suite=integration'],
            $root
        ),
        'installation SQLite vierge' => static fn (): bool =>
            qualificationFreshDatabase($root),
        'contenu du paquet mutualisé' => static fn (): bool =>
            qualificationPackage($root),
    ];

    foreach ($steps as $label => $step) {
        echo "\n== {$label} ==\n";
        if (!$step()) {
            fwrite(STDERR, "\nÉCHEC QUALIFICATION : {$label}\n");
            return 1;
        }
        echo "[OK] {$label}\n";
    }

    echo "\nQUALIFICATION RÉUSSIE\n";
    return 0;
}

function qualificationMigrationHashes(string $root): bool
{
    $manifest = $root . '/docs/baseline/migrations.sha256';
    $lines = file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false || count($lines) !== 10) {
        fwrite(STDERR, "Manifeste de migrations absent ou incomplet.\n");
        return false;
    }
    foreach ($lines as $line) {
        if (preg_match('/^([a-f0-9]{64})  (database\/migrations\/.+\.sql)$/', $line, $match) !== 1) {
            fwrite(STDERR, "Ligne de manifeste invalide : {$line}\n");
            return false;
        }
        $path = $root . '/' . $match[2];
        $actual = is_file($path) ? hash_file('sha256', $path) : false;
        if (!is_string($actual) || !hash_equals($match[1], $actual)) {
            fwrite(STDERR, "Migration modifiée ou absente : {$match[2]}\n");
            return false;
        }
    }
    return true;
}

function qualificationLint(string $root): bool
{
    $files = [$root . '/bin/console'];
    foreach (['bootstrap', 'config', 'public', 'src', 'templates', 'tests', 'tools'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root . '/' . $directory,
                FilesystemIterator::SKIP_DOTS
            )
        );
        foreach ($iterator as $file) {
            if (
                $file->isFile()
                && $file->getExtension() === 'php'
                && $file->getFilename() !== 'local.php'
            ) {
                $files[] = $file->getPathname();
            }
        }
    }
    $files = array_values(array_unique($files));
    sort($files, SORT_STRING);
    foreach ($files as $file) {
        if (!qualificationCommand([PHP_BINARY, '-l', $file], $root, false)) {
            fwrite(STDERR, "Syntaxe invalide : {$file}\n");
            return false;
        }
    }
    echo count($files) . " fichiers PHP valides.\n";
    return true;
}

function qualificationFreshDatabase(string $root): bool
{
    $temporary = sys_get_temp_dir() . '/compta-qualification-' . bin2hex(random_bytes(6));
    if (!mkdir($temporary, 0770, true) && !is_dir($temporary)) {
        fwrite(STDERR, "Répertoire temporaire impossible.\n");
        return false;
    }
    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $environment['APP_ENV'] = 'qualification';
    $environment['APP_INSTANCE_ID'] = 'qualification';
    $environment['APP_STORAGE_PATH'] = $temporary;
    $environment['APP_DB_PATH'] = $temporary . '/app.sqlite';
    try {
        foreach ([
            ['db:migrate', '--apply', '--backup'],
            ['app:doctor'],
            ['db:integrity'],
        ] as $arguments) {
            if (!qualificationCommand(
                [PHP_BINARY, $root . '/bin/console', ...$arguments],
                $root,
                true,
                $environment
            )) {
                return false;
            }
        }
        return is_file($temporary . '/app.sqlite')
            && glob($temporary . '/backups/*.sqlite') !== [];
    } finally {
        qualificationRemoveTree($temporary);
    }
}

function qualificationPackage(string $root): bool
{
    foreach ([
        'VERSION',
        'bin/console',
        'bootstrap/autoload.php',
        'composer.json',
        'composer.lock',
        'config/app.php',
        'database/migrations',
        'public/index.php',
        'resources',
        'src',
        'templates',
        'vendor/autoload.php',
    ] as $required) {
        if (!file_exists($root . '/' . $required)) {
            fwrite(STDERR, "Élément de livraison absent : {$required}\n");
            return false;
        }
    }

    $tracked = [];
    exec(
        'git -C ' . escapeshellarg($root) . ' ls-files',
        $tracked,
        $gitStatus
    );
    if ($gitStatus !== 0 || $tracked === []) {
        fwrite(STDERR, "Aucun état Git versionné disponible pour le paquet.\n");
        return false;
    }
    foreach ($tracked as $path) {
        if (
            $path === 'config/local.php'
            || preg_match('/(?:^|\/)(?:\.env[^\/]*|.*\.sqlite(?:-(?:wal|shm))?|.*\.log)$/', $path) === 1
            || (str_starts_with($path, 'storage/')
                && !in_array($path, ['storage/.gitkeep', 'storage/.htaccess'], true))
        ) {
            fwrite(STDERR, "Fichier sensible suivi par Git : {$path}\n");
            return false;
        }
    }
    if (is_file($root . '/frontend/admin-vue/package.json')) {
        if (!qualificationCommand(
            ['npm', '--prefix', $root . '/frontend/admin-vue', 'run', 'build'],
            $root
        )) {
            return false;
        }
        $manifest = $root . '/public/app/.vite/manifest.json';
        $decoded = is_file($manifest)
            ? json_decode((string) file_get_contents($manifest), true)
            : null;
        if (!is_array($decoded) || !is_array($decoded['index.html'] ?? null)) {
            fwrite(STDERR, "Manifest Vue de livraison absent ou invalide.\n");
            return false;
        }
        echo "Build Vue versionné et manifest valide.\n";
        return true;
    }
    echo "Interface Vue absente : build non applicable.\n";
    return true;
}

/**
 * @param list<string> $command
 * @param array<string,string>|null $environment
 */
function qualificationCommand(
    array $command,
    string $workingDirectory,
    bool $display = true,
    ?array $environment = null,
): bool {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        $workingDirectory,
        $environment
    );
    if (!is_resource($process)) {
        return false;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($display && is_string($stdout) && $stdout !== '') {
        echo $stdout;
    }
    if (is_string($stderr) && $stderr !== '') {
        fwrite(STDERR, $stderr);
    }
    return $status === 0;
}

function qualificationRemoveTree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($directory);
}
