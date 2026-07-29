<?php
declare(strict_types=1);

namespace Compta\Core\Auth;

use Compta\Core\Audit\AuditLogger;
use Compta\Core\Security\Csrf;
use Compta\Core\Security\SessionStore;

final class AuthService
{
    private const IDENTIFICATION_SESSION = '_login_identification';
    private const IDENTIFICATION_SECONDS = 600;
    private const IDENTIFICATION_MAX_ATTEMPTS = 10;
    private const DUMMY_PASSWORD_HASH =
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    private const DUMMY_ARGON2ID_HASH =
        '$argon2id$v=19$m=65536,t=4,p=1$RXFLL3c2MUVKWFV1eVhJOA$dA8D33qWXIK+0KBbiBPN2MoZBugQZYCMreZ1cst+KPc';

    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginThrottle $throttle,
        private readonly AuditLogger $audit,
        private readonly SessionStore $session,
        private readonly ?Csrf $csrf = null,
        private readonly ?MfaService $mfa = null,
    ) {
    }

    public function attempt(string $email, string $password, string $ip): bool
    {
        return $this->begin($email, $password, $ip)['status'] === 'authenticated';
    }

    /** @return array{email:string} */
    public function identify(
        string $email,
        string $ip,
        string $userAgent = '',
    ): array {
        $this->mfa?->cancelLogin();
        $normalizedEmail = mb_substr(mb_strtolower(trim($email)), 0, 254);
        $this->session->regenerate();
        $this->csrf?->rotate();
        $this->session->set(self::IDENTIFICATION_SESSION, [
            'email' => $normalizedEmail,
            'ip_hash' => hash('sha256', $ip),
            'agent_hash' => hash('sha256', $userAgent),
            'expires_at' => time() + self::IDENTIFICATION_SECONDS,
            'attempts' => 0,
        ]);
        return ['email' => $normalizedEmail];
    }

    /** @return array{email:string}|null */
    public function pendingIdentification(string $ip, string $userAgent = ''): ?array
    {
        $pending = $this->session->get(self::IDENTIFICATION_SESSION);
        if (
            !is_array($pending)
            || (int) ($pending['expires_at'] ?? 0) <= time()
            || !hash_equals(
                (string) ($pending['ip_hash'] ?? ''),
                hash('sha256', $ip)
            )
            || !hash_equals(
                (string) ($pending['agent_hash'] ?? ''),
                hash('sha256', $userAgent)
            )
        ) {
            $this->session->remove(self::IDENTIFICATION_SESSION);
            return null;
        }
        return ['email' => (string) ($pending['email'] ?? '')];
    }

    /** @return array{status:string,challenge?:array<string,string>} */
    public function continueWithPassword(
        string $password,
        string $ip,
        string $userAgent = '',
    ): array {
        $identification = $this->pendingIdentification($ip, $userAgent);
        if ($identification === null) {
            return ['status' => 'identification_expired'];
        }
        $pending = $this->session->get(self::IDENTIFICATION_SESSION);
        $attempts = (int) ($pending['attempts'] ?? 0) + 1;
        if ($attempts > self::IDENTIFICATION_MAX_ATTEMPTS) {
            $this->session->remove(self::IDENTIFICATION_SESSION);
            return ['status' => 'identification_expired'];
        }
        $pending['attempts'] = $attempts;
        $this->session->set(self::IDENTIFICATION_SESSION, $pending);

        $result = $this->begin(
            $identification['email'],
            $password,
            $ip,
            $userAgent
        );
        if ($result['status'] !== 'invalid') {
            $this->session->remove(self::IDENTIFICATION_SESSION);
        }
        return $result;
    }

    public function cancelIdentification(): void
    {
        $this->session->remove(self::IDENTIFICATION_SESSION);
    }

    /** @return array{status:string,challenge?:array<string,string>} */
    public function begin(
        string $email,
        string $password,
        string $ip,
        string $userAgent = '',
    ): array {
        $this->mfa?->cancelLogin();
        $normalizedEmail = mb_strtolower(trim($email));
        $throttleEmail = mb_substr($normalizedEmail, 0, 254);
        if ($this->throttle->blocked($throttleEmail, $ip)) {
            $this->audit->log('auth.bloque', ip: $ip);
            return ['status' => 'invalid'];
        }
        $user = mb_strlen($normalizedEmail) <= 254
            ? $this->users->findByEmail($normalizedEmail)
            : null;
        $storedHash = $user === null
            ? (
                defined('PASSWORD_ARGON2ID')
                    ? self::DUMMY_ARGON2ID_HASH
                    : self::DUMMY_PASSWORD_HASH
            )
            : (string) $user['mot_de_passe'];
        $passwordWithinLimit = strlen($password) <= 4096;
        $passwordValid = password_verify(
            $passwordWithinLimit ? $password : '__overlong_password_rejected__',
            $storedHash
        ) && $passwordWithinLimit;
        $userActive = $user !== null && (int) $user['actif'] === 1;
        if (!$passwordValid || !$userActive) {
            $this->throttle->failure($throttleEmail, $ip);
            $this->audit->log('auth.echec', ip: $ip);
            return ['status' => 'invalid'];
        }
        $userId = (int) $user['id'];
        $this->throttle->success($throttleEmail, $ip);
        $this->users->rehashPasswordIfNeeded($userId, $password, $storedHash);
        $mode = (string) ($user['mode_connexion'] ?? 'password');
        if ($mode !== 'password') {
            if ($this->mfa === null) {
                $this->audit->log('auth.mfa_indisponible', $userId, ip: $ip);
                return ['status' => 'invalid'];
            }
            try {
                // The anonymous identifier must not survive the password boundary,
                // even though the user is not authenticated until MFA succeeds.
                $this->session->regenerate();
                $this->csrf?->rotate();
                return [
                    'status' => 'mfa_required',
                    'challenge' => $this->mfa->startLogin($user, $ip, $userAgent),
                ];
            } catch (\Throwable) {
                $this->audit->log('auth.mfa_indisponible', $userId, ip: $ip);
                return ['status' => 'delivery_failed'];
            }
        }
        $this->authenticate($userId, $ip);
        return ['status' => 'authenticated'];
    }

    /** @return array{status:string} */
    public function completeMfa(
        string $code,
        string $ip,
        string $userAgent = '',
    ): array
    {
        $userId = $this->mfa?->verifyLogin($code, $ip, $userAgent);
        if ($userId === null) {
            return ['status' => 'invalid'];
        }
        $user = $this->users->findById($userId);
        if ($user === null || (int) $user['actif'] !== 1) {
            return ['status' => 'invalid'];
        }
        $this->authenticate($userId, $ip);
        return ['status' => 'authenticated'];
    }

    /** @return array<string,string>|null */
    public function pendingMfa(): ?array
    {
        return $this->mfa?->pendingLogin();
    }

    public function cancelMfa(): void
    {
        $this->mfa?->cancelLogin();
    }

    private function authenticate(int $userId, string $ip): void
    {
        $this->session->regenerate();
        $this->csrf?->rotate();
        $this->session->remove(self::IDENTIFICATION_SESSION);
        $this->session->set('user_id', $userId);
        $this->session->set(
            'auth_security_version',
            $this->users->securityVersion($userId) ?? 1
        );
        $this->users->markLogin($userId);
        $this->audit->log('auth.connexion', $userId, ip: $ip);
    }

    public function logout(string $ip = ''): void
    {
        $userId = $this->userId();
        if ($userId !== null) {
            $this->audit->log('auth.deconnexion', $userId, ip: $ip);
        }
        $this->session->destroy();
    }

    public function userId(): ?int
    {
        $id = (int) $this->session->get('user_id', 0);
        if ($id < 1) {
            return null;
        }
        $sessionVersion = (int) $this->session->get('auth_security_version', 0);
        if ($sessionVersion > 0) {
            $currentVersion = $this->users->securityVersion($id);
            if ($currentVersion === null || $currentVersion !== $sessionVersion) {
                $this->session->remove('user_id');
                $this->session->remove('auth_security_version');
                $this->session->remove('organisation_id');
                $this->session->remove('dossier_id');
                $this->mfa?->cancelLogin();
                $this->session->regenerate();
                $this->csrf?->rotate();
                return null;
            }
        }
        return $id;
    }
}
