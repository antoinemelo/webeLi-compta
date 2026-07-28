<?php
declare(strict_types=1);

$root = dirname(__DIR__);
foreach ([
    $root . '/vendor/autoload.php',
    dirname($root) . '/vendor/autoload.php',
] as $composerAutoload) {
    if (is_file($composerAutoload)) {
        require_once $composerAutoload;
        break;
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Compta\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = dirname(__DIR__) . '/src/' . $relative . '.php';
    if (is_file($path)) {
        require $path;
    }
});
