<?php
declare(strict_types=1);

namespace Compta\Core\Security;

use RuntimeException;

final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(private readonly string $encryptionKey)
    {
    }

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function uri(string $secret, string $email, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $email);
        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    public function verify(string $secret, string $code, ?int $time = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }
        $counter = intdiv($time ?? time(), 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            if (hash_equals($this->code($secret, $counter + $offset), $code)) {
                return true;
            }
        }
        return false;
    }

    public function encrypt(string $secret): string
    {
        $key = $this->key();
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 's1.' . base64_encode(
                $nonce . sodium_crypto_secretbox($secret, $nonce, $key)
            );
        }
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt(
            $secret,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if (!is_string($ciphertext)) {
            throw new RuntimeException('Protection du secret TOTP impossible.');
        }
        return 'o1.' . base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $protected): string
    {
        [$version, $encoded] = array_pad(explode('.', $protected, 2), 2, '');
        $payload = base64_decode($encoded, true);
        if (!is_string($payload)) {
            throw new RuntimeException('Secret TOTP illisible.');
        }
        $key = $this->key();
        if ($version === 's1' && function_exists('sodium_crypto_secretbox_open')) {
            $nonceLength = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $plain = sodium_crypto_secretbox_open(
                substr($payload, $nonceLength),
                substr($payload, 0, $nonceLength),
                $key
            );
        } elseif ($version === 'o1') {
            $plain = openssl_decrypt(
                substr($payload, 32),
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                substr($payload, 0, 16),
                substr($payload, 16, 16)
            );
        } else {
            $plain = false;
        }
        if (!is_string($plain) || $plain === '') {
            throw new RuntimeException('Secret TOTP illisible.');
        }
        return $plain;
    }

    /** @return list<string> */
    public function recoveryCodes(): array
    {
        $codes = [];
        for ($index = 0; $index < 8; $index++) {
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }
        return $codes;
    }

    public function recoveryHash(string $code): string
    {
        return hash_hmac(
            'sha256',
            strtoupper(str_replace(['-', ' '], '', trim($code))),
            $this->key()
        );
    }

    private function code(string $secret, int $counter): string
    {
        $binary = $this->base32Decode($secret);
        $high = intdiv($counter, 4294967296);
        $low = $counter % 4294967296;
        $digest = hash_hmac('sha1', pack('N2', $high, $low), $binary, true);
        $offset = ord($digest[19]) & 0x0f;
        $value = (
            ((ord($digest[$offset]) & 0x7f) << 24)
            | ((ord($digest[$offset + 1]) & 0xff) << 16)
            | ((ord($digest[$offset + 2]) & 0xff) << 8)
            | (ord($digest[$offset + 3]) & 0xff)
        ) % 1000000;
        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    private function key(): string
    {
        if (strlen($this->encryptionKey) < 32) {
            throw new RuntimeException(
                'APP_MFA_KEY doit contenir au moins 32 caractères avant d’activer TOTP.'
            );
        }
        return hash('sha256', $this->encryptionKey, true);
    }

    private function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $encoded;
    }

    private function base32Decode(string $encoded): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($encoded, '='))) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) {
                throw new RuntimeException('Secret TOTP invalide.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }
        return $decoded;
    }
}
