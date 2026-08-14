<?php
declare(strict_types=1);

use Compta\Core\Http\Request;

header_remove('X-Powered-By');

$applicationRoot = dirname(__DIR__);
if (is_file($applicationRoot . '/.maintenance')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('Retry-After: 30');
    echo 'Mise à jour en cours. Réessayez dans quelques instants.';
    exit;
}

try {
    $container = require $applicationRoot . '/bootstrap/app.php';
    $response = $container['web']->handle(
        Request::fromGlobals($container['config']->string('base_url'))
    );
    $response->send();
} catch (Throwable $e) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    $debug = (getenv('APP_ENV') ?: 'dev') !== 'prod';
    echo $debug ? 'Application indisponible : ' . $e->getMessage() : 'Application indisponible.';
}
