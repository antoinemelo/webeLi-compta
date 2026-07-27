<?php
declare(strict_types=1);

/*
 * Front controller for shared hosting where the virtual host cannot point its
 * document root directly at public/. Apache protects the private directories
 * through the root .htaccess before this file is reached.
 */
if ((getenv('APP_BASE_URL') ?: '') === '') {
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $applicationRoot = realpath(__DIR__);
    $basePath = '';

    if (
        is_string($documentRoot)
        && $documentRoot !== ''
        && is_string($applicationRoot)
        && str_starts_with($applicationRoot, rtrim($documentRoot, '/') . '/')
    ) {
        $basePath = substr($applicationRoot, strlen(rtrim($documentRoot, '/')));
    } else {
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $basePath = rtrim(dirname($scriptName), '/.');
    }

    if ($basePath !== '') {
        putenv('APP_BASE_URL=' . $basePath);
        $_ENV['APP_BASE_URL'] = $basePath;
    }
}

if (getenv('APP_ENV') === false) {
    putenv('APP_ENV=prod');
    $_ENV['APP_ENV'] = 'prod';
}
if (getenv('APP_DEBUG') === false) {
    putenv('APP_DEBUG=0');
    $_ENV['APP_DEBUG'] = '0';
}

require __DIR__ . '/public/index.php';
