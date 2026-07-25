<?php
declare(strict_types=1);

namespace Compta\Core\Http\Api;

use Compta\Core\Http\Request;
use Compta\Core\Http\Response;

final class ApiResponse
{
    public const CONTRACT_VERSION = 'compta-api-v1';

    /** @var \WeakMap<Request, string>|null */
    private static ?\WeakMap $correlationIds = null;

    /** @param array<string, mixed> $meta */
    public static function success(
        Request $request,
        mixed $data,
        array $meta = [],
        int $status = 200,
    ): Response {
        $correlationId = self::correlationId($request);
        return self::json([
            'data' => $data,
            'meta' => ['contract_version' => self::CONTRACT_VERSION]
                + ['correlation_id' => $correlationId]
                + $meta,
            'errors' => [],
        ], $status, $correlationId);
    }

    public static function failure(Request $request, ApiException $exception): Response
    {
        $correlationId = self::correlationId($request);
        $error = [
            'code' => $exception->errorCode,
            'message' => $exception->getMessage(),
            'correlation_id' => $correlationId,
        ];
        if ($exception->fields !== []) {
            $error['fields'] = $exception->fields;
        }
        if ($exception->details !== []) {
            $error['details'] = $exception->details;
        }
        return self::json([
            'data' => null,
            'meta' => [
                'contract_version' => self::CONTRACT_VERSION,
                'correlation_id' => $correlationId,
            ],
            'errors' => [$error],
        ], $exception->status, $correlationId);
    }

    public static function correlationId(Request $request): string
    {
        self::$correlationIds ??= new \WeakMap();
        if (isset(self::$correlationIds[$request])) {
            return self::$correlationIds[$request];
        }
        $provided = trim((string) ($request->header('X-Correlation-ID') ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,63}$/', $provided) === 1) {
            self::$correlationIds[$request] = $provided;
            return self::$correlationIds[$request];
        }
        self::$correlationIds[$request] = bin2hex(random_bytes(16));
        return self::$correlationIds[$request];
    }

    /** @param array<string, mixed> $payload */
    private static function json(array $payload, int $status, string $correlationId): Response
    {
        return new Response(
            json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            $status,
            [
                'Content-Type' => 'application/json; charset=UTF-8',
                'X-Contract-Version' => self::CONTRACT_VERSION,
                'X-Correlation-ID' => $correlationId,
            ]
        );
    }
}
