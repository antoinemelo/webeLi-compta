<?php
declare(strict_types=1);

use Compta\Core\Http\Request;

header_remove('X-Powered-By');

try {
    $container = require dirname(__DIR__) . '/bootstrap/app.php';
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
