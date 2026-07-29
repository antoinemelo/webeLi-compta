<?php
declare(strict_types=1);

namespace Compta\Core\Auth;

use RuntimeException;

final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    public static function assert(string $password): void
    {
        $violation = self::violation($password);
        if ($violation !== null) {
            throw new RuntimeException($violation);
        }
    }

    public static function violation(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'Le mot de passe doit contenir au moins 12 caractères.';
        }
        if (strlen($password) > 4096) {
            return 'Le mot de passe est trop long.';
        }
        if (!defined('PASSWORD_ARGON2ID') && strlen($password) > 72) {
            return 'Le mot de passe dépasse la limite sûre de ce serveur.';
        }
        $normalized = mb_strtolower(
            preg_replace('/[^a-z0-9]/i', '', $password) ?? ''
        );
        if (in_array($normalized, [
            'changeme123',
            'motdepasse123',
            'password123',
            'administrateur',
            'admin123456',
        ], true)) {
            return 'Ce mot de passe est trop prévisible. '
                . 'Utilisez une phrase secrète unique.';
        }
        return null;
    }
}
