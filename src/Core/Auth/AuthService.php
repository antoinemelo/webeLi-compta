<?php
declare(strict_types=1);

namespace Compta\Core\Auth;

use Compta\Core\Audit\AuditLogger;
use Compta\Core\Security\SessionStore;

final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginThrottle $throttle,
        private readonly AuditLogger $audit,
        private readonly SessionStore $session,
    ) {
    }

    public function attempt(string $email, string $password, string $ip): bool
    {
        if ($this->throttle->blocked($email, $ip)) {
            $this->audit->log('auth.bloque', ip: $ip);
            return false;
        }
        $user = $this->users->findByEmail($email);
        if (
            $user === null
            || (int) $user['actif'] !== 1
            || !password_verify($password, (string) $user['mot_de_passe'])
        ) {
            $this->throttle->failure($email, $ip);
            $this->audit->log('auth.echec', ip: $ip);
            return false;
        }
        $userId = (int) $user['id'];
        $this->throttle->success($email, $ip);
        $this->session->regenerate();
        $this->session->set('user_id', $userId);
        $this->users->markLogin($userId);
        $this->audit->log('auth.connexion', $userId, ip: $ip);
        return true;
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
        return $id > 0 ? $id : null;
    }
}
