<?php
declare(strict_types=1);

namespace Compta\Core\Http;

use Compta\Core\Config\AppConfig;
use RuntimeException;

final class VueShellRenderer
{
    public function __construct(
        private readonly string $root,
        private readonly AppConfig $config,
    ) {
    }

    public function response(): Response
    {
        $manifest = $this->manifest();
        $entry = $manifest['index.html'] ?? null;
        if (!is_array($entry) || !is_string($entry['file'] ?? null)) {
            throw new RuntimeException('Manifest Vue incomplet : entrée index.html absente.');
        }
        $scriptUrl = $this->config->url('/app/' . ltrim($entry['file'], '/'));
        $styleUrls = [];
        foreach (($entry['css'] ?? []) as $css) {
            if (is_string($css) && $css !== '') {
                $styleUrls[] = $this->config->url('/app/' . ltrim($css, '/'));
            }
        }
        $config = $this->config;
        ob_start();
        require $this->root . '/templates/vue-shell.php';
        return new Response(
            (string) ob_get_clean(),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $path = $this->root . '/public/app/.vite/manifest.json';
        if (!is_file($path)) {
            throw new RuntimeException(
                'Assets Vue absents. Exécutez npm --prefix frontend/admin-vue run build.'
            );
        }
        $decoded = json_decode(
            (string) file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($decoded)) {
            throw new RuntimeException('Manifest Vue invalide.');
        }
        return $decoded;
    }
}
