<?php
declare(strict_types=1);

namespace Compta\Core\Http;

final class Router
{
    /** @var array<string, callable(Request):Response> */
    private array $routes = [];
    /** @var list<array{method:string,prefix:string,handler:callable(Request):Response}> */
    private array $prefixRoutes = [];

    /** @param callable(Request):Response $handler */
    public function add(string $method, string $path, callable $handler): void
    {
        $this->routes[strtoupper($method) . ' ' . $this->normalize($path)] = $handler;
    }

    /** @param callable(Request):Response $handler */
    public function addPrefix(string $method, string $prefix, callable $handler): void
    {
        $this->prefixRoutes[] = [
            'method' => strtoupper($method),
            'prefix' => $this->normalize($prefix),
            'handler' => $handler,
        ];
        usort(
            $this->prefixRoutes,
            static fn (array $left, array $right): int =>
                strlen($right['prefix']) <=> strlen($left['prefix'])
        );
    }

    public function dispatch(Request $request): Response
    {
        $key = strtoupper($request->method) . ' ' . $this->normalize($request->path);
        if (isset($this->routes[$key])) {
            return ($this->routes[$key])($request);
        }
        foreach ($this->prefixRoutes as $route) {
            if (
                $route['method'] === strtoupper($request->method)
                && $this->matchesPrefix($request->path, $route['prefix'])
            ) {
                return ($route['handler'])($request);
            }
        }
        return new Response(
            'Route introuvable',
            404,
            ['Content-Type' => 'text/plain; charset=UTF-8']
        );
    }

    public function has(string $method, string $path): bool
    {
        $method = strtoupper($method);
        if (isset($this->routes[$method . ' ' . $this->normalize($path)])) {
            return true;
        }
        foreach ($this->prefixRoutes as $route) {
            if ($route['method'] === $method && $this->matchesPrefix($path, $route['prefix'])) {
                return true;
            }
        }
        return false;
    }

    private function normalize(string $path): string
    {
        $normalized = '/' . trim($path, '/');
        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }

    private function matchesPrefix(string $path, string $prefix): bool
    {
        $normalized = $this->normalize($path);
        return $normalized === $prefix || str_starts_with($normalized, $prefix . '/');
    }
}
