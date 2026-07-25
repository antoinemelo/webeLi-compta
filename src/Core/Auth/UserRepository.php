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

    public function create(string $email, string $password, string $firstName = '', string $lastName = ''): int
    {
        $email = mb_strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Adresse e-mail invalide.');
        }
        if (strlen($password) < 12) {
            throw new RuntimeException('Le mot de passe doit contenir au moins 12 caractères.');
        }
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

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn();
    }
}
