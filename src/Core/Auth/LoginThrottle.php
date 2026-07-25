<?php
declare(strict_types=1);

namespace Compta\Core\Auth;

use PDO;

final class LoginThrottle
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxAttempts,
        private readonly int $windowSeconds,
    ) {
    }

    public function blocked(string $email, string $ip): bool
    {
        $since = time() - $this->windowSeconds;
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM tentatives_connexion
             WHERE email = :email AND ip = :ip AND tente_le >= :since'
        );
        $stmt->execute([
            'email' => mb_strtolower(trim($email)),
            'ip' => $ip,
            'since' => $since,
        ]);
        return (int) $stmt->fetchColumn() >= $this->maxAttempts;
    }

    public function failure(string $email, string $ip): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tentatives_connexion (email, ip, tente_le) VALUES (:email, :ip, :time)'
        );
        $stmt->execute([
            'email' => mb_strtolower(trim($email)),
            'ip' => $ip,
            'time' => time(),
        ]);
        $this->pdo->prepare('DELETE FROM tentatives_connexion WHERE tente_le < :expiry')
            ->execute(['expiry' => time() - ($this->windowSeconds * 2)]);
    }

    public function success(string $email, string $ip): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM tentatives_connexion WHERE email = :email AND ip = :ip'
        );
        $stmt->execute(['email' => mb_strtolower(trim($email)), 'ip' => $ip]);
    }
}
