<?php
declare(strict_types=1);

namespace Compta\Core\Auth;

use PDO;
use RuntimeException;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM utilisateurs WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => mb_strtolower(trim($email))]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM utilisateurs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function create(string $email, string $password, string $firstName = '', string $lastName = ''): int
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Adresse e-mail invalide.');
        }
        PasswordPolicy::assert($password);
        $stmt = $this->pdo->prepare(
            'INSERT INTO utilisateurs (email, mot_de_passe, prenom, nom)
             VALUES (:email, :password, :first_name, :last_name)'
        );
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $stmt->execute([
            'email' => $email,
            'password' => password_hash($password, $algorithm),
            'first_name' => trim($firstName),
            'last_name' => trim($lastName),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function markLogin(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE utilisateurs SET derniere_connexion_le = datetime('now') WHERE id = :id"
        );
        $stmt->execute(['id' => $userId]);
    }

    public function rehashPasswordIfNeeded(
        int $userId,
        string $password,
        string $currentHash,
    ): void {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        if (!password_needs_rehash($currentHash, $algorithm)) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE utilisateurs SET mot_de_passe = :password WHERE id = :id'
        );
        $stmt->execute([
            'password' => password_hash($password, $algorithm),
            'id' => $userId,
        ]);
    }

    public function verifyPassword(int $userId, string $password): bool
    {
        $user = $this->findById($userId);
        return $user !== null
            && (int) $user['actif'] === 1
            && password_verify($password, (string) $user['mot_de_passe']);
    }

    public function changePassword(int $userId, string $password): void
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $stmt = $this->pdo->prepare(
            'UPDATE utilisateurs
             SET mot_de_passe = :password,
                 version_securite = version_securite + 1
             WHERE id = :id AND actif = 1'
        );
        $stmt->execute([
            'password' => password_hash($password, $algorithm),
            'id' => $userId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Compte utilisateur introuvable.');
        }
    }

    public function securityVersion(int $userId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT version_securite FROM utilisateurs WHERE id = :id AND actif = 1'
        );
        $stmt->execute(['id' => $userId]);
        $version = $stmt->fetchColumn();
        return $version === false ? null : (int) $version;
    }

    /** @param list<string> $recoveryHashes */
    public function enableTotp(
        int $userId,
        string $protectedSecret,
        array $recoveryHashes,
    ): void {
        $stmt = $this->pdo->prepare(
            "UPDATE utilisateurs
             SET mode_connexion = 'totp',
                 secret_totp_protege = :secret,
                 codes_recuperation_json = :codes,
                 mfa_active_le = datetime('now'),
                 version_securite = version_securite + 1
             WHERE id = :id AND actif = 1"
        );
        $stmt->execute([
            'secret' => $protectedSecret,
            'codes' => json_encode($recoveryHashes, JSON_THROW_ON_ERROR),
            'id' => $userId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Compte utilisateur introuvable.');
        }
    }

    public function setLoginMode(int $userId, string $mode): void
    {
        if (!in_array($mode, ['password', 'email'], true)) {
            throw new RuntimeException('Mode de connexion invalide.');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE utilisateurs
             SET mode_connexion = :mode,
                 secret_totp_protege = NULL,
                 codes_recuperation_json = :codes,
                 mfa_active_le = CASE WHEN :mode = :password THEN NULL ELSE datetime(\'now\') END,
                 version_securite = version_securite + 1
             WHERE id = :id AND actif = 1'
        );
        $stmt->execute([
            'mode' => $mode,
            'codes' => '[]',
            'password' => 'password',
            'id' => $userId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Compte utilisateur introuvable.');
        }
    }

    /** @param list<string> $hashes */
    public function replaceRecoveryHashes(
        int $userId,
        array $hashes,
        string $expectedJson,
    ): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE utilisateurs
             SET codes_recuperation_json = :codes
             WHERE id = :id
               AND mode_connexion = :mode
               AND codes_recuperation_json = :expected'
        );
        $stmt->execute([
            'codes' => json_encode($hashes, JSON_THROW_ON_ERROR),
            'id' => $userId,
            'mode' => 'totp',
            'expected' => $expectedJson,
        ]);
        return $stmt->rowCount() === 1;
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn();
    }
}
