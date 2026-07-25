<?php
declare(strict_types=1);

$public = dirname(__DIR__) . '/public';
$requestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$basePath = (string) parse_url((string) (getenv('APP_BASE_URL') ?: ''), PHP_URL_PATH);
if (
    $basePath !== ''
    && ($requestPath === $basePath || str_starts_with($requestPath, rtrim($basePath, '/') . '/'))
) {
    $requestPath = substr($requestPath, strlen(rtrim($basePath, '/'))) ?: '/';
}
$relative = ltrim($requestPath, '/');
$candidate = realpath($public . '/' . $relative);
if (
    $relative !== ''
    && $candidate !== false
    && is_file($candidate)
    && str_starts_with($candidate, $public . '/')
) {
    $extension = mb_strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    $mime = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'ttf' => 'font/ttf',
        'woff2' => 'font/woff2',
    ][$extension] ?? mime_content_type($candidate);
    if (is_string($mime) && $mime !== '') {
        header('Content-Type: ' . $mime);
    }
    readfile($candidate);
    return true;
}

require $public . '/index.php';
return true;
