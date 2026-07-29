<?php
declare(strict_types=1);

namespace Compta\Core\Security;

use Compta\Core\Config\AppConfig;

final class NativeSessionStore implements SessionStore
{
    public function __construct(private readonly AppConfig $config)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        session_name($this->config->sessionName());
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $this->config->sessionPath(),
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        $now = time();
        $created = (int) ($_SESSION['_created_at'] ?? $now);
        $last = (int) ($_SESSION['_last_seen_at'] ?? $now);
        if (
            $now - $last > $this->config->int('session_idle_seconds')
            || $now - $created > $this->config->int('session_absolute_seconds')
        ) {
            $this->destroy();
            session_start();
            $created = $now;
        }
        $_SESSION['_created_at'] = $created;
        $_SESSION['_last_seen_at'] = $now;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
