<?php
declare(strict_types=1);

namespace Compta\Core\Maintenance;

use Compta\Core\Audit\AuditLogger;
use Compta\Core\Auth\AccessControl;
use Compta\Core\Auth\AuthService;
use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Api\ApiResponse;
use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Throwable;

final class MaintenanceApiController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly AccessControl $access,
        private readonly UpdateService $updates,
        private readonly AuditLogger $audit,
    ) {
    }

    public function status(Request $request): Response
    {
        $this->installationAdmin();
        $refresh = in_array(
            mb_strtolower(trim((string) ($request->query['refresh'] ?? ''))),
            ['1', 'true', 'yes'],
            true
        );
        $status = $this->updates->status($refresh);
        unset($status['manifest']);
        return ApiResponse::success($request, $status);
    }

    public function apply(Request $request): Response
    {
        $userId = $this->installationAdmin();
        $input = $request->input();
        $expectedVersion = trim((string) ($input['expected_version'] ?? ''));
        $expectedFingerprint = trim((string) ($input['release_fingerprint'] ?? ''));
        if (
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $expectedVersion) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $expectedFingerprint) !== 1
        ) {
            throw ApiException::validation([
                'release' => ['La publication attendue doit être vérifiée à nouveau.'],
            ]);
        }
        try {
            $status = $this->updates->status(true);
            $manifest = $status['manifest'] ?? null;
            if (!is_array($manifest) || ($status['error'] ?? null) !== null) {
                throw new UpdateException(
                    (string) ($status['error'] ?? 'Aucune publication Git n’est disponible.')
                );
            }
            if (
                !($status['available'] ?? false)
                || !hash_equals($expectedVersion, (string) ($manifest['version'] ?? ''))
                || !hash_equals(
                    $expectedFingerprint,
                    $this->updates->manifestFingerprint($manifest)
                )
            ) {
                throw new UpdateException(
                    'La publication disponible a changé. Vérifiez à nouveau la version.'
                );
            }
            @set_time_limit(180);
            ignore_user_abort(true);
            $result = $this->updates->apply($manifest);
            $this->audit->log(
                'maintenance.update_applied',
                $userId,
                null,
                null,
                'application_release',
                $result['version'],
                [
                    'version' => $result['version'],
                    'file_count' => $result['file_count'],
                    'migration_count' => count($result['migrations']),
                    'repository' => UpdateService::REPOSITORY,
                ],
                $request->ip()
            );
            return ApiResponse::success($request, $result + ['reload_required' => true]);
        } catch (UpdateException $exception) {
            throw ApiException::conflict('UPDATE_FAILED', $exception->getMessage());
        } catch (Throwable $exception) {
            throw new ApiException(
                500,
                'UPDATE_FAILED',
                'La mise à jour a échoué. Consultez les journaux du serveur.'
            );
        }
    }

    private function installationAdmin(): int
    {
        $userId = $this->auth->userId();
        if ($userId === null) {
            throw ApiException::authenticationRequired();
        }
        if (!$this->access->hasInstallationPermission($userId, 'installation.admin')) {
            throw ApiException::forbidden(
                'La permission d’administration de l’installation est requise.'
            );
        }
        return $userId;
    }
}
