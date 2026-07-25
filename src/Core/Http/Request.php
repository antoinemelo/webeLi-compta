<?php
declare(strict_types=1);

namespace Compta\Core\Http;

final class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $post
     * @param array<string, string> $server
     * @param array<string, array{name:string,type:string,tmp_name:string,error:int,size:int}> $files
     * @param array<string, mixed> $json
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $server = [],
        public readonly array $files = [],
        public readonly array $json = [],
    ) {
    }

    public static function fromGlobals(string $basePath = ''): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        if (
            $basePath !== ''
            && ($path === $basePath || str_starts_with($path, $basePath . '/'))
        ) {
            $path = substr($path, strlen($basePath)) ?: '/';
        }
        $query = [];
        foreach ($_GET as $key => $value) {
            $query[(string) $key] = is_scalar($value) ? (string) $value : '';
        }
        $post = [];
        foreach ($_POST as $key => $value) {
            $post[(string) $key] = is_scalar($value) ? (string) $value : '';
        }
        $server = [];
        foreach ($_SERVER as $key => $value) {
            $server[(string) $key] = is_scalar($value) ? (string) $value : '';
        }
        $files = [];
        foreach ($_FILES as $key => $value) {
            if (!is_array($value) || is_array($value['name'] ?? null)) {
                continue;
            }
            $files[(string) $key] = [
                'name' => (string) ($value['name'] ?? ''),
                'type' => (string) ($value['type'] ?? ''),
                'tmp_name' => (string) ($value['tmp_name'] ?? ''),
                'error' => (int) ($value['error'] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($value['size'] ?? 0),
            ];
        }
        $json = [];
        $contentType = mb_strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_starts_with($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && trim($raw) !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $json = $decoded;
                }
            }
        }
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            '/' . ltrim($path, '/'),
            $query,
            $post,
            $server,
            $files,
            $json,
        );
    }

    public function ip(): string
    {
        return mb_substr($this->server['REMOTE_ADDR'] ?? '', 0, 64);
    }

    public function header(string $name): ?string
    {
        $normalized = strtoupper(str_replace('-', '_', trim($name)));
        $key = in_array($normalized, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)
            ? $normalized
            : 'HTTP_' . $normalized;
        $value = $this->server[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    public function input(): array
    {
        $data = $this->json['data'] ?? $this->json;
        return is_array($data) && $data !== [] ? $data : $this->post;
    }
}
