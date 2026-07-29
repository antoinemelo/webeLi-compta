<?php
declare(strict_types=1);

namespace Compta\Core\Auth;

use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Api\ApiResponse;
use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use RuntimeException;

final class SecurityApiController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly MfaService $mfa,
    ) {
    }

    public function profile(Request $request): Response
    {
        return ApiResponse::success($request, $this->mfa->profile($this->userId()));
    }

    public function prepareTotp(Request $request): Response
    {
        return $this->run($request, fn (): array => $this->mfa->prepareTotp(
            $this->userId(),
            $this->required($request, 'current_password')
        ));
    }

    public function confirmTotp(Request $request): Response
    {
        return $this->run($request, function () use ($request): array {
            $codes = $this->mfa->confirmTotp(
                $this->userId(),
                $this->required($request, 'code'),
                $request->ip()
            );
            return [
                'mode' => 'totp',
                'recovery_codes' => $codes,
                'reauthenticate' => true,
            ];
        });
    }

    public function prepareEmail(Request $request): Response
    {
        return $this->run($request, function () use ($request): array {
            $this->mfa->prepareEmail(
                $this->userId(),
                $this->required($request, 'current_password'),
                $request->ip(),
                (string) ($request->header('User-Agent') ?? '')
            );
            return ['challenge' => 'email'];
        });
    }

    public function confirmEmail(Request $request): Response
    {
        return $this->run($request, function () use ($request): array {
            $this->mfa->confirmEmail(
                $this->userId(),
                $this->required($request, 'code'),
                $request->ip()
            );
            return ['mode' => 'email', 'reauthenticate' => true];
        });
    }

    public function disable(Request $request): Response
    {
        return $this->run($request, function () use ($request): array {
            $this->mfa->disable(
                $this->userId(),
                $this->required($request, 'current_password'),
                $request->ip()
            );
            return ['mode' => 'password', 'reauthenticate' => true];
        });
    }

    public function changePassword(Request $request): Response
    {
        return $this->run($request, function () use ($request): array {
            $newPassword = $this->required($request, 'new_password');
            $confirmation = $this->required($request, 'new_password_confirmation');
            if (!hash_equals($newPassword, $confirmation)) {
                throw ApiException::validation([
                    'new_password_confirmation' => ['La confirmation ne correspond pas.'],
                ]);
            }
            $this->mfa->changePassword(
                $this->userId(),
                $this->required($request, 'current_password'),
                $newPassword,
                $request->ip()
            );
            return ['changed' => true, 'reauthenticate' => true];
        });
    }

    /** @param callable():array<string,mixed> $operation */
    private function run(Request $request, callable $operation): Response
    {
        try {
            return ApiResponse::success($request, $operation());
        } catch (ApiException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            throw ApiException::validation([
                'security' => [$exception->getMessage()],
            ]);
        }
    }

    private function userId(): int
    {
        $userId = $this->auth->userId();
        if ($userId === null) {
            throw ApiException::authenticationRequired();
        }
        return $userId;
    }

    private function required(Request $request, string $key): string
    {
        $raw = (string) ($request->input()[$key] ?? '');
        $isPassword = str_contains($key, 'password');
        $value = $isPassword ? $raw : trim($raw);
        if ($value === '') {
            throw ApiException::validation([$key => ['Champ obligatoire.']]);
        }
        return mb_substr($value, 0, $isPassword ? 4096 : 32);
    }
}
