<?php
declare(strict_types=1);

namespace Compta\Core\Auth;

use Compta\Core\Audit\AuditLogger;
use Compta\Core\Config\AppConfig;
use Compta\Core\Mail\Mailer;
use Compta\Core\Security\SessionStore;
use Compta\Core\Security\TotpService;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use PDO;
use RuntimeException;

final class MfaService
{
    private const LOGIN_SESSION = '_mfa_login';
    private const SETUP_SESSION = '_mfa_setup';

    public function __construct(
        private readonly PDO $pdo,
        private readonly UserRepository $users,
        private readonly SessionStore $session,
        private readonly AuditLogger $audit,
        private readonly TotpService $totp,
        private readonly Mailer $mailer,
        private readonly AppConfig $config,
    ) {
    }

    /** @param array<string, mixed> $user @return array<string, string> */
    public function startLogin(array $user, string $ip, string $userAgent): array
    {
        $mode = (string) ($user['mode_connexion'] ?? 'password');
        if (!in_array($mode, ['email', 'totp'], true)) {
            throw new RuntimeException('Second facteur non configuré.');
        }
        if ($mode === 'totp' && trim((string) ($user['secret_totp_protege'] ?? '')) === '') {
            throw new RuntimeException('Configuration TOTP incomplète.');
        }
        $pending = [
            'user_id' => (int) $user['id'],
            'mode' => $mode,
            'email' => (string) $user['email'],
            'ip_hash' => hash('sha256', $ip),
            'agent_hash' => hash('sha256', $userAgent),
            'expires_at' => time() + $this->challengeSeconds(),
            'attempts' => 0,
        ];
        if ($mode === 'email') {
            $pending['selector'] = $this->sendEmailChallenge(
                (int) $user['id'],
                (string) $user['email'],
                'connexion',
                $ip,
                $userAgent
            );
        }
        $this->session->set(self::LOGIN_SESSION, $pending);
        $this->audit->log(
            'auth.mfa_demande',
            (int) $user['id'],
            targetType: 'utilisateur',
            targetId: (string) $user['id'],
            summary: ['mode' => $mode],
            ip: $ip
        );
        return $this->challengeView($pending);
    }

    /** @return array<string, string>|null */
    public function pendingLogin(): ?array
    {
        $pending = $this->session->get(self::LOGIN_SESSION);
        if (!is_array($pending) || (int) ($pending['expires_at'] ?? 0) < time()) {
            $this->session->remove(self::LOGIN_SESSION);
            return null;
        }
        return $this->challengeView($pending);
    }

    public function cancelLogin(): void
    {
        $this->session->remove(self::LOGIN_SESSION);
    }

    public function verifyLogin(string $code, string $ip, string $userAgent): ?int
    {
        $pending = $this->session->get(self::LOGIN_SESSION);
        if (
            !is_array($pending)
            || (int) ($pending['expires_at'] ?? 0) < time()
            || !hash_equals((string) ($pending['ip_hash'] ?? ''), hash('sha256', $ip))
            || !hash_equals(
                (string) ($pending['agent_hash'] ?? ''),
                hash('sha256', $userAgent)
            )
        ) {
            $this->session->remove(self::LOGIN_SESSION);
            return null;
        }
        $attempts = (int) ($pending['attempts'] ?? 0) + 1;
        $pending['attempts'] = $attempts;
        if ($attempts > $this->maxAttempts()) {
            $this->session->remove(self::LOGIN_SESSION);
            return null;
        }
        $this->session->set(self::LOGIN_SESSION, $pending);
        $userId = (int) ($pending['user_id'] ?? 0);
        $valid = match ((string) ($pending['mode'] ?? '')) {
            'email' => $this->verifyEmailChallenge(
                (string) ($pending['selector'] ?? ''),
                $userId,
                'connexion',
                $code
            ),
            'totp' => $this->verifyTotpOrRecovery($userId, $code),
            default => false,
        };
        if (!$valid) {
            $this->audit->log('auth.mfa_echec', $userId, ip: $ip);
            return null;
        }
        $this->session->remove(self::LOGIN_SESSION);
        $this->audit->log('auth.mfa_valide', $userId, ip: $ip);
        return $userId;
    }

    /** @return array<string, mixed> */
    public function profile(int $userId): array
    {
        $user = $this->users->findById($userId);
        if ($user === null || (int) $user['actif'] !== 1) {
            throw new RuntimeException('Compte utilisateur introuvable.');
        }
        $recovery = json_decode((string) $user['codes_recuperation_json'], true);
        $mailTransport = mb_strtolower($this->config->string('mail_transport'));
        $emailAvailable = filter_var(
            $this->config->string('mail_from_address'),
            FILTER_VALIDATE_EMAIL
        ) !== false && (
            ($mailTransport === 'php' && function_exists('mail'))
            || (
                $mailTransport === 'smtp'
                && trim($this->config->string('smtp_host')) !== ''
            )
        );
        return [
            'email' => (string) $user['email'],
            'mode' => (string) $user['mode_connexion'],
            'mfa_active_at' => $user['mfa_active_le'],
            'recovery_codes_remaining' => is_array($recovery) ? count($recovery) : 0,
            'totp_available' => strlen($this->config->string('mfa_encryption_key')) >= 32,
            'email_available' => $emailAvailable,
        ];
    }

    /** @return array<string, string> */
    public function prepareTotp(int $userId, string $currentPassword): array
    {
        $user = $this->requirePassword($userId, $currentPassword);
        $secret = $this->totp->generateSecret();
        $issuer = 'Compta ' . $this->config->string('instance_id');
        $uri = $this->totp->uri($secret, (string) $user['email'], $issuer);
        $qr = new QrCode(
            data: $uri,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 260,
            margin: 12,
            roundBlockSizeMode: RoundBlockSizeMode::Margin
        );
        $png = (new PngWriter())->write($qr)->getString();
        $this->session->set(self::SETUP_SESSION, [
            'user_id' => $userId,
            'mode' => 'totp',
            'protected_secret' => $this->totp->encrypt($secret),
            'expires_at' => time() + $this->challengeSeconds(),
        ]);
        return [
            'secret' => $secret,
            'otpauth_uri' => $uri,
            'qr_data_uri' => 'data:image/png;base64,' . base64_encode($png),
        ];
    }

    /** @return list<string> */
    public function confirmTotp(int $userId, string $code, string $ip): array
    {
        $setup = $this->session->get(self::SETUP_SESSION);
        if (
            !is_array($setup)
            || (int) ($setup['user_id'] ?? 0) !== $userId
            || ($setup['mode'] ?? '') !== 'totp'
            || (int) ($setup['expires_at'] ?? 0) < time()
        ) {
            throw new RuntimeException('Code TOTP invalide ou préparation expirée.');
        }
        $secret = $this->totp->decrypt((string) ($setup['protected_secret'] ?? ''));
        if (!$this->totp->verify($secret, $code)) {
            throw new RuntimeException('Code TOTP invalide ou préparation expirée.');
        }
        $codes = $this->totp->recoveryCodes();
        $hashes = array_map($this->totp->recoveryHash(...), $codes);
        $this->users->enableTotp(
            $userId,
            $this->totp->encrypt($secret),
            $hashes
        );
        $this->session->remove(self::SETUP_SESSION);
        $this->audit->log('auth.mfa_totp_active', $userId, ip: $ip);
        return $codes;
    }

    public function prepareEmail(
        int $userId,
        string $currentPassword,
        string $ip,
        string $userAgent,
    ): void {
        $user = $this->requirePassword($userId, $currentPassword);
        $selector = $this->sendEmailChallenge(
            $userId,
            (string) $user['email'],
            'activation',
            $ip,
            $userAgent
        );
        $this->session->set(self::SETUP_SESSION, [
            'user_id' => $userId,
            'mode' => 'email',
            'selector' => $selector,
            'expires_at' => time() + $this->challengeSeconds(),
        ]);
    }

    public function confirmEmail(int $userId, string $code, string $ip): void
    {
        $setup = $this->session->get(self::SETUP_SESSION);
        if (
            !is_array($setup)
            || (int) ($setup['user_id'] ?? 0) !== $userId
            || ($setup['mode'] ?? '') !== 'email'
            || (int) ($setup['expires_at'] ?? 0) < time()
            || !$this->verifyEmailChallenge(
                (string) ($setup['selector'] ?? ''),
                $userId,
                'activation',
                $code
            )
        ) {
            throw new RuntimeException('Code e-mail invalide ou préparation expirée.');
        }
        $this->users->setLoginMode($userId, 'email');
        $this->session->remove(self::SETUP_SESSION);
        $this->audit->log('auth.mfa_email_active', $userId, ip: $ip);
    }

    public function disable(int $userId, string $currentPassword, string $ip): void
    {
        $this->requirePassword($userId, $currentPassword);
        $this->users->setLoginMode($userId, 'password');
        $this->session->remove(self::SETUP_SESSION);
        $this->audit->log('auth.mfa_desactive', $userId, ip: $ip);
    }

    public function changePassword(
        int $userId,
        string $currentPassword,
        string $newPassword,
        string $ip,
    ): void {
        $user = $this->requirePassword($userId, $currentPassword);
        if (password_verify($newPassword, (string) $user['mot_de_passe'])) {
            throw new RuntimeException('Le nouveau mot de passe doit être différent.');
        }
        PasswordPolicy::assert($newPassword);
        $this->users->changePassword($userId, $newPassword);
        $this->audit->log('auth.mot_de_passe_modifie', $userId, ip: $ip);
    }

    /** @param array<string, mixed> $pending @return array<string, string> */
    private function challengeView(array $pending): array
    {
        return [
            'mode' => (string) $pending['mode'],
            'email_hint' => $this->maskEmail((string) $pending['email']),
        ];
    }

    /** @return array<string, mixed> */
    private function requirePassword(int $userId, string $password): array
    {
        if (!$this->users->verifyPassword($userId, $password)) {
            throw new RuntimeException('Mot de passe actuel incorrect.');
        }
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new RuntimeException('Compte utilisateur introuvable.');
        }
        return $user;
    }

    private function sendEmailChallenge(
        int $userId,
        string $email,
        string $purpose,
        string $ip,
        string $userAgent,
    ): string {
        $rate = $this->pdo->prepare(
            'SELECT COUNT(*) FROM defis_mfa_email
             WHERE utilisateur_id = :user AND but = :purpose AND cree_le >= :since'
        );
        $rate->execute([
            'user' => $userId,
            'purpose' => $purpose,
            'since' => time() - 600,
        ]);
        if ((int) $rate->fetchColumn() >= 5) {
            throw new RuntimeException(
                'Trop de codes ont été demandés. Réessayez dans quelques minutes.'
            );
        }
        $selector = bin2hex(random_bytes(24));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $now = time();
        $stmt = $this->pdo->prepare(
            'INSERT INTO defis_mfa_email
             (selecteur, utilisateur_id, but, code_hash, ip, agent_utilisateur,
              expire_le, cree_le)
             VALUES
             (:selector, :user, :purpose, :hash, :ip, :agent, :expires, :created)'
        );
        $stmt->execute([
            'selector' => $selector,
            'user' => $userId,
            'purpose' => $purpose,
            'hash' => password_hash($code, PASSWORD_DEFAULT),
            'ip' => mb_substr($ip, 0, 64),
            'agent' => mb_substr($userAgent, 0, 255),
            'expires' => $now + $this->challengeSeconds(),
            'created' => $now,
        ]);
        try {
            $this->mailer->send(
                $email,
                'Votre code de sécurité Compta',
                "Votre code de sécurité est : {$code}\n\n"
                . "Il expire dans 10 minutes et ne peut être utilisé qu’une fois.\n"
                . "Si vous n’êtes pas à l’origine de cette demande, ignorez ce message."
            );
        } catch (\Throwable $exception) {
            $this->pdo->prepare(
                'UPDATE defis_mfa_email SET consomme_le = :now WHERE selecteur = :selector'
            )->execute(['now' => time(), 'selector' => $selector]);
            throw $exception;
        }
        return $selector;
    }

    private function verifyEmailChallenge(
        string $selector,
        int $userId,
        string $purpose,
        string $code,
    ): bool {
        $stmt = $this->pdo->prepare(
            'SELECT id, code_hash, tentatives, expire_le, consomme_le
             FROM defis_mfa_email
             WHERE selecteur = :selector AND utilisateur_id = :user AND but = :purpose
             LIMIT 1'
        );
        $stmt->execute([
            'selector' => $selector,
            'user' => $userId,
            'purpose' => $purpose,
        ]);
        $challenge = $stmt->fetch();
        if (
            $challenge === false
            || $challenge['consomme_le'] !== null
            || (int) $challenge['expire_le'] < time()
            || (int) $challenge['tentatives'] >= $this->maxAttempts()
        ) {
            return false;
        }
        $valid = preg_match('/^\d{6}$/', trim($code)) === 1
            && password_verify(trim($code), (string) $challenge['code_hash']);
        $update = $this->pdo->prepare(
            'UPDATE defis_mfa_email
             SET tentatives = tentatives + 1,
                 consomme_le = CASE WHEN :valid = 1 THEN :now ELSE consomme_le END
             WHERE id = :id AND consomme_le IS NULL'
        );
        $update->execute([
            'valid' => $valid ? 1 : 0,
            'now' => time(),
            'id' => (int) $challenge['id'],
        ]);
        return $valid && $update->rowCount() === 1;
    }

    private function verifyTotpOrRecovery(int $userId, string $code): bool
    {
        $user = $this->users->findById($userId);
        if ($user === null || (string) $user['mode_connexion'] !== 'totp') {
            return false;
        }
        try {
            if ($this->totp->verify(
                $this->totp->decrypt((string) $user['secret_totp_protege']),
                $code
            )) {
                return true;
            }
        } catch (RuntimeException) {
            return false;
        }
        $hash = $this->totp->recoveryHash($code);
        $hashes = json_decode((string) $user['codes_recuperation_json'], true);
        if (!is_array($hashes)) {
            return false;
        }
        foreach ($hashes as $index => $candidate) {
            if (is_string($candidate) && hash_equals($candidate, $hash)) {
                unset($hashes[$index]);
                if (!$this->users->replaceRecoveryHashes(
                    $userId,
                    array_values($hashes),
                    (string) $user['codes_recuperation_json']
                )) {
                    return false;
                }
                $this->audit->log('auth.code_recuperation_utilise', $userId);
                return true;
            }
        }
        return false;
    }

    private function challengeSeconds(): int
    {
        return max(120, min(1800, $this->config->int('mfa_challenge_seconds')));
    }

    private function maxAttempts(): int
    {
        return max(3, min(5, $this->config->int('mfa_max_attempts')));
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, 1)
            . str_repeat('•', max(2, mb_strlen($local) - 1))
            . ($domain !== '' ? '@' . $domain : '');
    }
}
