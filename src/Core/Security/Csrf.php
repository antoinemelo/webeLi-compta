<?php
declare(strict_types=1);

namespace Compta\Core\Security;

final class Csrf
{
    private const KEY = '_csrf_token';

    public function __construct(private readonly SessionStore $session)
    {
    }

    public function token(): string
    {
        $token = (string) $this->session->get(self::KEY, '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::KEY, $token);
        }
        return $token;
    }

    public function validate(?string $token): bool
    {
        $expected = (string) $this->session->get(self::KEY, '');
        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    public function rotate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set(self::KEY, $token);
        return $token;
    }
}
