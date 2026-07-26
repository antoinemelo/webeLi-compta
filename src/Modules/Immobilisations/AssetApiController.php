<?php
declare(strict_types=1);

namespace Compta\Modules\Immobilisations;

use Compta\Core\Auth\AccessControl;
use Compta\Core\Auth\AuthService;
use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Api\ApiResponse;
use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Compta\Core\Security\SessionStore;
use PDOException;

final class AssetApiController
{
    public function __construct(
        private readonly SessionStore $session,
        private readonly AuthService $auth,
        private readonly AccessControl $access,
        private readonly AssetService $assets,
        private readonly AssetInputValidator $validator,
    ) {
    }

    public function show(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.view');
        $query = $this->validator->query($request);
        return $this->execute($request, function () use (
            $userId,
            $organisationId,
            $dossierId,
            $query
        ): array {
            $data = $this->assets->read(
                $organisationId,
                $dossierId,
                $query['exercise_id'],
                $query['asset_id'],
                $query['page'],
                $query['per_page']
            );
            $data['capabilities'] = [
                'setup' => $this->has(
                    $userId,
                    $organisationId,
                    $dossierId,
                    'compta.setup'
                ),
                'post' => $this->has(
                    $userId,
                    $organisationId,
                    $dossierId,
                    'compta.validate'
                ),
                'reverse' => $this->has(
                    $userId,
                    $organisationId,
                    $dossierId,
                    'compta.validate'
                ),
            ];
            return $data;
        });
    }

    public function saveCategory(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        $data = $this->validator->category($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->assets->saveCategory(
                $organisationId,
                $dossierId,
                $data,
                $userId
            ),
        ]);
    }

    public function saveAsset(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        $data = $this->validator->asset($request);
        return $this->execute($request, function () use (
            $userId,
            $organisationId,
            $dossierId,
            $data
        ): array {
            if ($data['id'] > 0) {
                $this->assets->updateAsset(
                    $organisationId,
                    $dossierId,
                    $data['id'],
                    $data['version'],
                    $data,
                    $userId
                );
                return ['id' => $data['id']];
            }
            return [
                'id' => $this->assets->createAsset(
                    $organisationId,
                    $dossierId,
                    $data,
                    $userId
                ),
            ];
        });
    }

    public function postDepreciation(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] =
            $this->scope('compta.validate');
        $data = $this->validator->posting($request);
        return $this->execute($request, fn (): array => [
            'entry_id' => $this->assets->postDepreciation(
                $organisationId,
                $dossierId,
                $data['schedule_id'],
                $data['exercise_id'],
                $data['journal_id'],
                $userId
            ),
        ]);
    }

    public function reverseDepreciation(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] =
            $this->scope('compta.validate');
        $data = $this->validator->reversal($request);
        return $this->execute($request, fn (): array => [
            'entry_id' => $this->assets->reverseDepreciation(
                $organisationId,
                $dossierId,
                $data['schedule_id'],
                $data['date'],
                $userId
            ),
        ]);
    }

    public function dispose(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] =
            $this->scope('compta.validate');
        $data = $this->validator->disposal($request);
        return $this->execute($request, fn (): array => [
            'entry_id' => $this->assets->dispose(
                $organisationId,
                $dossierId,
                $data['asset_id'],
                $data['type'],
                $data['date'],
                $data['proceeds_cents'],
                $data['proceeds_account_id'],
                $data['exercise_id'],
                $data['journal_id'],
                $userId
            ),
        ]);
    }

    public function reverseDisposal(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] =
            $this->scope('compta.validate');
        $data = $this->validator->disposalReversal($request);
        return $this->execute($request, fn (): array => [
            'entry_id' => $this->assets->reverseDisposal(
                $organisationId,
                $dossierId,
                $data['asset_id'],
                $data['date'],
                $userId
            ),
        ]);
    }

    /** @return array{int,int,int} */
    private function scope(string $permission): array
    {
        $userId = $this->auth->userId();
        if ($userId === null) {
            throw ApiException::authenticationRequired();
        }
        $organisationId = (int) $this->session->get('organisation_id', 0);
        $dossierId = (int) $this->session->get('dossier_id', 0);
        if ($organisationId < 1 || $dossierId < 1) {
            throw ApiException::conflict(
                'CONTEXT_REQUIRED',
                'Sélectionnez un dossier avant cette opération.'
            );
        }
        if (
            !$this->access->canViewDossier(
                $userId,
                $organisationId,
                $dossierId
            )
        ) {
            throw ApiException::forbidden('Accès aux immobilisations refusé.');
        }
        if (!$this->has(
            $userId,
            $organisationId,
            $dossierId,
            $permission
        )) {
            throw ApiException::forbidden(
                'Permission insuffisante sur les immobilisations.'
            );
        }
        return [$userId, $organisationId, $dossierId];
    }

    private function has(
        int $userId,
        int $organisationId,
        int $dossierId,
        string $permission,
    ): bool {
        return $this->access->hasDossierPermission(
            $userId,
            $organisationId,
            $dossierId,
            $permission
        );
    }

    /** @param callable():array<string,mixed> $callback */
    private function execute(Request $request, callable $callback): Response
    {
        try {
            return ApiResponse::success($request, $callback());
        } catch (AssetException $exception) {
            $message = $exception->getMessage();
            if (
                str_contains($message, 'modifiée')
                || str_contains($message, 'Conflit')
            ) {
                throw ApiException::conflict('ASSET_CONFLICT', $message);
            }
            throw ApiException::validation([
                'asset' => [$message],
            ]);
        } catch (PDOException) {
            throw ApiException::validation([
                'asset' => [
                    'Code déjà utilisé ou donnée encore référencée.',
                ],
            ]);
        }
    }
}
