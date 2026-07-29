<?php
declare(strict_types=1);

namespace Compta\Core\Config;

use RuntimeException;

final class AppConfig
{
    /** @param array<string, mixed> $values */
    private function __construct(private readonly string $root, private array $values)
    {
    }

    /** @param array<string, mixed> $overrides */
    public static function load(string $root, array $overrides = []): self
    {
        $root = rtrim($root, '/');
        $defaults = require $root . '/config/app.php';
        $localPath = $root . '/config/local.php';
        $local = is_file($localPath) ? require $localPath : [];
        if (!is_array($defaults) || !is_array($local)) {
            throw new RuntimeException('La configuration doit retourner un tableau.');
        }
        $values = array_replace($defaults, $local, $overrides);

        $instance = trim((string) ($values['instance_id'] ?? ''));
        if ($instance === '') {
            $instance = basename($root) . '-' . substr(hash('sha256', $root), 0, 10);
        }
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $instance)) {
            throw new RuntimeException('APP_INSTANCE_ID contient des caractères invalides.');
        }
        $values['instance_id'] = $instance;

        $baseUrl = trim((string) ($values['base_url'] ?? ''));
        if ($baseUrl !== '') {
            $path = parse_url($baseUrl, PHP_URL_PATH);
            if (!is_string($path)) {
                throw new RuntimeException('APP_BASE_URL est invalide.');
            }
            $baseUrl = '/' . trim($path, '/');
            if ($baseUrl === '/') {
                $baseUrl = '';
            }
        }
        $values['base_url'] = $baseUrl;

        $publicUrl = rtrim(trim((string) ($values['public_url'] ?? '')), '/');
        if ($publicUrl !== '') {
            $parts = parse_url($publicUrl);
            if (!is_array($parts)) {
                throw new RuntimeException(
                    'APP_PUBLIC_URL doit être une URL HTTPS complète en production '
                    . 'et reprendre exactement APP_BASE_URL.'
                );
            }
            $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
            $publicPath = rtrim((string) ($parts['path'] ?? ''), '/');
            if ($publicPath === '/') {
                $publicPath = '';
            }
            if (
                !in_array($scheme, ['http', 'https'], true)
                || trim((string) ($parts['host'] ?? '')) === ''
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['query'])
                || isset($parts['fragment'])
                || $publicPath !== $baseUrl
                || (
                    ($values['env'] ?? 'dev') === 'prod'
                    && $scheme !== 'https'
                )
            ) {
                throw new RuntimeException(
                    'APP_PUBLIC_URL doit être une URL HTTPS complète en production '
                    . 'et reprendre exactement APP_BASE_URL.'
                );
            }
        }
        $values['public_url'] = $publicUrl;

        $storage = trim((string) ($values['storage_path'] ?? ''));
        $values['storage_path'] = $storage !== '' ? $storage : $root . '/storage';
        $database = trim((string) ($values['database_path'] ?? ''));
        $values['database_path'] = $database !== ''
            ? $database
            : $values['storage_path'] . '/database/app.sqlite';

        return new self($root, $values);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function string(string $key): string
    {
        return (string) ($this->values[$key] ?? '');
    }

    public function int(string $key): int
    {
        return (int) ($this->values[$key] ?? 0);
    }

    public function bool(string $key): bool
    {
        return (bool) ($this->values[$key] ?? false);
    }

    public function sessionName(): string
    {
        return 'COMPTA_' . strtoupper(substr(hash('sha256', $this->string('instance_id')), 0, 12));
    }

    public function sessionPath(): string
    {
        $base = $this->string('base_url');
        return $base === '' ? '/' : $base . '/';
    }

    public function url(string $path = ''): string
    {
        $path = '/' . ltrim($path, '/');
        return $this->string('base_url') . ($path === '/' ? '/' : $path);
    }

    public function publicUrl(string $path = ''): string
    {
        $base = $this->string('public_url');
        if ($base === '') {
            return '';
        }
        $path = '/' . ltrim($path, '/');
        return $base . ($path === '/' ? '/' : $path);
    }
}
