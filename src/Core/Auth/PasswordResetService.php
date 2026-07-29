<?php
declare(strict_types=1);

namespace Compta\Core\Auth;

use Compta\Core\Audit\AuditLogger;
use Compta\Core\Config\AppConfig;
use Compta\Core\Mail\Mailer;
use PDO;
use RuntimeException;
use Throwable;

final class PasswordResetService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly UserRepository $users,
        private readonly AuditLogger $audit,
        private readonly Mailer $mailer,
        private readonly AppConfig $config,
    ) {
    }

    public function request(string $email, string $ip): void
    {
        $email = mb_substr(mb_strtolower(trim($email)), 0, 254);
        $emailHash = hash('sha256', $email);
        $ipHash = hash('sha256', mb_substr($ip, 0, 64));
        $now = time();
        $since = $now - 900;
        $selector = null;
        $validator = null;
        $user = null;

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'DELETE FROM demandes_reinitialisation_mot_de_passe
                 WHERE cree_le < ?'
            )->execute([$now - 604800]);
            $emailRequests = $this->countRecent('email_hash', $emailHash, $since);
            $ipRequests = $this->countRecent('ip_hash', $ipHash, $since);
            $user = filter_var($email, FILTER_VALIDATE_EMAIL)
                ? $this->users->findByEmail($email)
                : null;
            $eligible = $user !== null
                && (int) $user['actif'] === 1
                && $emailRequests < $this->emailLimit()
                && $ipRequests < $this->ipLimit()
                && $this->config->publicUrl() !== '';

            if ($eligible) {
                $selector = bin2hex(random_bytes(16));
                $validator = bin2hex(random_bytes(32));
                $this->pdo->prepare(
                    'UPDATE demandes_reinitialisation_mot_de_passe
                     SET consomme_le = ?
                     WHERE utilisateur_id = ?
                       AND consomme_le IS NULL'
                )->execute([$now, (int) $user['id']]);
                $this->pdo->prepare(
                    'INSERT INTO demandes_reinitialisation_mot_de_passe
                     (
                         utilisateur_id, email_hash, ip_hash, selecteur,
                         jeton_hash, expire_le, cree_le
                     )
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    (int) $user['id'],
                    $emailHash,
                    $ipHash,
                    $selector,
                    hash('sha256', $validator),
                    $now + $this->validitySeconds(),
                    $now,
                ]);
            } else {
                $this->pdo->prepare(
                    'INSERT INTO demandes_reinitialisation_mot_de_passe
                     (email_hash, ip_hash, cree_le)
                     VALUES (?, ?, ?)'
                )->execute([$emailHash, $ipHash, $now]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        if ($selector === null || $validator === null || $user === null) {
            $this->audit->log(
                'auth.reinitialisation_demandee',
                targetType: 'adresse',
                targetId: substr($emailHash, 0, 16),
                summary: ['envoi' => 'non'],
                ip: $ip
            );
            return;
        }

        $url = $this->config->publicUrl('/reinitialiser-mot-de-passe')
            . '?' . http_build_query([
                'selector' => $selector,
                'token' => $validator,
            ], '', '&', PHP_QUERY_RFC3986);
        try {
            $this->mailer->send(
                (string) $user['email'],
                'Réinitialisation de votre mot de passe Compta',
                implode("\n", [
                    'Une réinitialisation du mot de passe de votre compte Compta a été demandée.',
                    '',
                    'Ouvrez ce lien à usage unique :',
                    $url,
                    '',
                    'Ce lien expire dans '
                        . (string) (int) ceil($this->validitySeconds() / 60)
                        . ' minutes.',
                    'Si vous n’êtes pas à l’origine de cette demande, ignorez ce message.',
                    'Votre mot de passe actuel reste inchangé tant que le lien n’est pas utilisé.',
                ])
            );
            $this->audit->log(
                'auth.reinitialisation_demandee',
                (int) $user['id'],
                targetType: 'utilisateur',
                targetId: (string) $user['id'],
                summary: ['envoi' => 'oui'],
                ip: $ip
            );
        } catch (Throwable) {
            $this->pdo->prepare(
                'UPDATE demandes_reinitialisation_mot_de_passe
                 SET consomme_le = ?
                 WHERE selecteur = ? AND consomme_le IS NULL'
            )->execute([time(), $selector]);
            $this->audit->log(
                'auth.reinitialisation_envoi_echec',
                (int) $user['id'],
                targetType: 'utilisateur',
                targetId: (string) $user['id'],
                ip: $ip
            );
        }
    }

    public function tokenIsValid(string $selector, string $validator): bool
    {
        $row = $this->tokenRow($selector);
        return $row !== null
            && $this->validatorMatches($row, $validator)
            && (int) $row['actif'] === 1
            && (int) $row['expire_le'] >= time()
            && $row['consomme_le'] === null;
    }

    public function reset(
        string $selector,
        string $validator,
        string $password,
        string $confirmation,
        string $ip,
    ): void {
        if (!hash_equals($password, $confirmation)) {
            throw new RuntimeException('La confirmation du mot de passe ne correspond pas.');
        }
        PasswordPolicy::assert($password);
        $row = $this->tokenRow($selector);
        if (
            $row === null
            || !$this->validatorMatches($row, $validator)
            || (int) $row['actif'] !== 1
            || (int) $row['expire_le'] < time()
            || $row['consomme_le'] !== null
        ) {
            throw new RuntimeException(
                'Le lien de réinitialisation est invalide, expiré ou déjà utilisé.'
            );
        }
        if (password_verify($password, (string) $row['mot_de_passe'])) {
            throw new RuntimeException('Le nouveau mot de passe doit être différent.');
        }

        $now = time();
        $userId = (int) $row['utilisateur_id'];
        $this->pdo->beginTransaction();
        try {
            $consume = $this->pdo->prepare(
                'UPDATE demandes_reinitialisation_mot_de_passe
                 SET consomme_le = ?
                 WHERE id = ?
                   AND consomme_le IS NULL
                   AND expire_le >= ?'
            );
            $consume->execute([$now, (int) $row['id'], $now]);
            if ($consume->rowCount() !== 1) {
                throw new RuntimeException(
                    'Le lien de réinitialisation est invalide, expiré ou déjà utilisé.'
                );
            }
            $this->users->changePassword($userId, $password);
            $this->invalidateChallenges($userId, $now);
            $this->pdo->prepare(
                'DELETE FROM tentatives_connexion WHERE email = ?'
            )->execute([(string) $row['email']]);
            $this->audit->log(
                'auth.mot_de_passe_reinitialise',
                $userId,
                targetType: 'utilisateur',
                targetId: (string) $userId,
                summary: ['origine' => 'lien_email'],
                ip: $ip
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array{user_id:int,mfa_mode:string} */
    public function administrativeReset(string $email, string $password): array
    {
        PasswordPolicy::assert($password);
        $user = $this->users->findByEmail($email);
        if ($user === null || (int) $user['actif'] !== 1) {
            throw new RuntimeException('Compte utilisateur actif introuvable.');
        }
        if (password_verify($password, (string) $user['mot_de_passe'])) {
            throw new RuntimeException('Le nouveau mot de passe doit être différent.');
        }
        $userId = (int) $user['id'];
        $now = time();
        $this->pdo->beginTransaction();
        try {
            $this->users->changePassword($userId, $password);
            $this->invalidateChallenges($userId, $now);
            $this->pdo->prepare(
                'DELETE FROM tentatives_connexion WHERE email = ?'
            )->execute([(string) $user['email']]);
            $this->audit->log(
                'auth.mot_de_passe_reinitialise',
                $userId,
                targetType: 'utilisateur',
                targetId: (string) $userId,
                summary: ['origine' => 'console']
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return [
            'user_id' => $userId,
            'mfa_mode' => (string) $user['mode_connexion'],
        ];
    }

    private function invalidateChallenges(int $userId, int $now): void
    {
        $this->pdo->prepare(
            'UPDATE demandes_reinitialisation_mot_de_passe
             SET consomme_le = ?
             WHERE utilisateur_id = ? AND consomme_le IS NULL'
        )->execute([$now, $userId]);
        $this->pdo->prepare(
            'UPDATE defis_mfa_email
             SET consomme_le = ?
             WHERE utilisateur_id = ? AND consomme_le IS NULL'
        )->execute([$now, $userId]);
    }

    /** @return array<string,mixed>|null */
    private function tokenRow(string $selector): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $selector)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT r.*, u.email, u.mot_de_passe, u.actif
             FROM demandes_reinitialisation_mot_de_passe r
             JOIN utilisateurs u ON u.id = r.utilisateur_id
             WHERE r.selecteur = ?
             LIMIT 1'
        );
        $stmt->execute([$selector]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $row */
    private function validatorMatches(array $row, string $validator): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $validator) === 1
            && hash_equals(
                (string) $row['jeton_hash'],
                hash('sha256', $validator)
            );
    }

    private function countRecent(string $column, string $hash, int $since): int
    {
        if (!in_array($column, ['email_hash', 'ip_hash'], true)) {
            throw new RuntimeException('Limite de récupération invalide.');
        }
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM demandes_reinitialisation_mot_de_passe
             WHERE {$column} = ? AND cree_le >= ?"
        );
        $stmt->execute([$hash, $since]);
        return (int) $stmt->fetchColumn();
    }

    private function validitySeconds(): int
    {
        return max(300, min(3600, $this->config->int('password_reset_seconds')));
    }

    private function emailLimit(): int
    {
        return max(1, min(5, $this->config->int('password_reset_email_limit')));
    }

    private function ipLimit(): int
    {
        return max(3, min(20, $this->config->int('password_reset_ip_limit')));
    }
}
