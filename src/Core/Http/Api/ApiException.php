<?php
declare(strict_types=1);

namespace Compta\Core\Http\Api;

use RuntimeException;

final class ApiException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $fields
     * @param array<string, scalar|null> $details
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function authenticationRequired(): self
    {
        return new self(401, 'AUTHENTICATION_REQUIRED', 'Authentification requise.');
    }

    public static function forbidden(string $message = 'Accès refusé.'): self
    {
        return new self(403, 'ACCESS_FORBIDDEN', $message);
    }

    public static function notFound(string $message = 'Ressource introuvable.'): self
    {
        return new self(404, 'RESOURCE_NOT_FOUND', $message);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }

    /** @param array<string, list<string>> $fields */
    public static function validation(array $fields): self
    {
        return new self(
            422,
            'VALIDATION_FAILED',
            'Les données transmises sont invalides.',
            $fields
        );
    }
}
