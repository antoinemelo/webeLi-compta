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
            'SELECT
                SUM(CASE WHEN email = :email AND ip = :ip THEN 1 ELSE 0 END) AS pair_count,
                SUM(CASE WHEN email = :email THEN 1 ELSE 0 END) AS email_count,
                SUM(CASE WHEN ip = :ip THEN 1 ELSE 0 END) AS ip_count
             FROM tentatives_connexion
             WHERE tente_le >= :since'
        );
        $stmt->execute([
            'email' => $this->email($email),
            'ip' => $this->ip($ip),
            'since' => $since,
        ]);
        $counts = $stmt->fetch() ?: [];
        return (int) ($counts['pair_count'] ?? 0) >= $this->maxAttempts
            || (int) ($counts['email_count'] ?? 0) >= $this->maxAttempts * 4
            || (int) ($counts['ip_count'] ?? 0) >= $this->maxAttempts * 10;
    }

    public function failure(string $email, string $ip): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tentatives_connexion (email, ip, tente_le) VALUES (:email, :ip, :time)'
        );
        $stmt->execute([
            'email' => $this->email($email),
            'ip' => $this->ip($ip),
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
        $stmt->execute(['email' => $this->email($email), 'ip' => $this->ip($ip)]);
    }

    private function email(string $email): string
    {
        return mb_substr(mb_strtolower(trim($email)), 0, 254);
    }

    private function ip(string $ip): string
    {
        return mb_substr(trim($ip), 0, 64);
    }
}
