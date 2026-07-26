<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie;

use Closure;

final class PublicMarketHttpClient
{
    /** @param null|Closure(string):string $transport */
    public function __construct(
        private readonly ?Closure $transport = null,
    ) {
    }

    public function get(string $url): string
    {
        $this->assertAllowedUrl($url);
        if ($this->transport !== null) {
            return $this->validateBody(($this->transport)($url));
        }
        if (function_exists('curl_init')) {
            return $this->withCurl($url);
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 12,
                'ignore_errors' => true,
                'header' => "Accept: application/json, application/xml;q=0.9\r\n"
                    . "User-Agent: WebeLi-Compta/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (!is_string($body)) {
            throw new TreasuryException('La source publique de marché est momentanément indisponible.');
        }
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }
        if ($status < 200 || $status >= 300) {
            throw new TreasuryException("La source publique a répondu avec le statut HTTP {$status}.");
        }
        return $this->validateBody($body);
    }

    private function withCurl(string $url): string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TreasuryException('Initialisation de la source publique impossible.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json, application/xml;q=0.9',
                'User-Agent: WebeLi-Compta/1.0',
            ],
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            $suffix = $status > 0 ? " (HTTP {$status})" : '';
            throw new TreasuryException(
                'La source publique de marché est momentanément indisponible'
                . $suffix . ($error !== '' ? '.' : '.')
            );
        }
        return $this->validateBody($body);
    }

    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $allowed = (
            $host === 'data.snb.ch'
            && in_array($path, [
                '/api/cube/devkum/data/json/fr',
                '/api/cube/zimoma/data/json/fr',
            ], true)
        ) || (
            $host === 'www.backend-rates.bazg.admin.ch'
            && $path === '/api/xmldaily'
        );
        if (($parts['scheme'] ?? '') !== 'https' || !$allowed) {
            throw new TreasuryException('Source publique non autorisée.');
        }
    }

    private function validateBody(string $body): string
    {
        $length = strlen($body);
        if ($length < 2 || $length > 2 * 1024 * 1024) {
            throw new TreasuryException('Réponse publique vide ou trop volumineuse.');
        }
        return $body;
    }
}
