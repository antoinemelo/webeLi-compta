<?php
declare(strict_types=1);

namespace Compta\Core\Http;

final class Router
{
    /** @var array<string, callable(Request):Response> */
    private array $routes = [];

    /** @param callable(Request):Response $handler */
    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[strtoupper($method) . ' ' . $this->normalize($path)] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $key = strtoupper($request->method) . ' ' . $this->normalize($request->path);
        if (!isset($this->routes[$key])) {
            return new Response('Route introuvable', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        return ($this->routes[$key])($request);
    }

    private function normalize(string $path): string
    {
        $normalized = '/' . trim($path, '/');
        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }
}
