<?php
declare(strict_types=1);

namespace Compta\Modules\Dossiers\Http;

use Compta\Core\Auth\AccessControl;
use Compta\Core\Auth\AuthService;
use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Api\ApiResponse;
use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Compta\Modules\Dossiers\OrganisationRegistryException;
use Compta\Modules\Dossiers\OrganisationRegistryService;
use PDOException;

final class OrganisationApiController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly AccessControl $access,
        private readonly OrganisationRegistryService $registry,
        private readonly OrganisationInputValidator $validator,
    ) {
    }

    public function list(Request $request): Response
    {
        $userId = $this->userId();
        $query = $this->validator->listing($request);
        $installationAdmin = $this->access->hasInstallationPermission(
            $userId, 'installation.admin'
        );
        $allowed = $installationAdmin ? null : $this->access
            ->organisationIdsForPermission($userId, 'organisation.manage');
        if (!$installationAdmin && $allowed === []) {
            throw ApiException::forbidden();
        }
        return $this->execute($request, fn (): array => $this->registry->list(
            $query['search'], $query['status'], $query['page'],
            $query['per_page'], $allowed
        ));
    }

    public function detail(Request $request): Response
    {
        $userId = $this->userId();
        $id = $this->validator->detail($request);
        $this->assertManage($userId, $id);
        return $this->execute($request, fn (): array => $this->registry->detail($id));
    }

    public function create(Request $request): Response
    {
        $userId = $this->installationAdmin();
        $data = $this->validator->create($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->registry->create(
                $data['name'], $data['nature'], $data['identity'], $userId
            ),
        ], 201);
    }

    public function update(Request $request): Response
    {
        $userId = $this->userId();
        $data = $this->validator->update($request);
        $this->assertManage($userId, $data['id']);
        return $this->execute($request, function () use ($data, $userId): array {
            $this->registry->updateName(
                $data['id'], $data['name'], $data['version'], $userId
            );
            return $this->registry->detail($data['id']);
        });
    }

    public function saveLegalIdentity(Request $request): Response
    {
        $userId = $this->userId();
        $data = $this->validator->legalIdentity($request);
        $this->assertManage($userId, $data['id']);
        return $this->execute($request, function () use ($data, $userId): array {
            $this->registry->saveLegalIdentity(
                $data['id'],
                $data['version'],
                $data['identity'],
                $userId,
                $data['expected_legal_identity_id']
            );
            return $this->registry->detail($data['id']);
        });
    }

    public function archive(Request $request): Response
    {
        return $this->status($request, false);
    }

    public function reactivate(Request $request): Response
    {
        return $this->status($request, true);
    }

    public function delete(Request $request): Response
    {
        $userId = $this->installationAdmin();
        $data = $this->validator->action($request);
        return $this->execute($request, function () use ($data, $userId): array {
            $this->registry->delete($data['id'], $data['version'], $userId);
            return ['deleted' => true, 'id' => $data['id']];
        });
    }

    private function status(Request $request, bool $active): Response
    {
        $userId = $this->userId();
        $data = $this->validator->action($request);
        $this->assertManage($userId, $data['id']);
        return $this->execute($request, function () use ($data, $userId, $active): array {
            if ($active) {
                $this->registry->reactivate($data['id'], $data['version'], $userId);
            } else {
                $this->registry->archive($data['id'], $data['version'], $userId);
            }
            return $this->registry->detail($data['id']);
        });
    }

    private function userId(): int
    {
        $userId = $this->auth->userId();
        if ($userId === null) {
            throw ApiException::authenticationRequired();
        }
        return $userId;
    }

    private function installationAdmin(): int
    {
        $userId = $this->userId();
        if (!$this->access->hasInstallationPermission($userId, 'installation.admin')) {
            throw ApiException::forbidden(
                'La permission d’administration de l’installation est requise.'
            );
        }
        return $userId;
    }

    private function assertManage(int $userId, int $organisationId): void
    {
        if (
            !$this->access->hasInstallationPermission($userId, 'installation.admin')
            && !$this->access->hasOrganisationPermission(
                $userId, $organisationId, 'organisation.manage'
            )
        ) {
            throw ApiException::notFound('Organisation introuvable.');
        }
    }

    /** @param callable():array<string,mixed> $callback */
    private function execute(
        Request $request,
        callable $callback,
        int $status = 200,
    ): Response {
        try {
            return ApiResponse::success($request, $callback(), [], $status);
        } catch (OrganisationRegistryException $exception) {
            if ($exception->errorCode === 'ORGANISATION_NOT_FOUND') {
                throw ApiException::notFound($exception->getMessage());
            }
            if (
                $exception->errorCode === 'ORGANISATION_VERSION_CONFLICT'
                || $exception->errorCode === 'ORGANISATION_HAS_ACTIVE_DOSSIERS'
                || $exception->errorCode === 'ORGANISATION_HAS_DEPENDENCIES'
            ) {
                throw new ApiException(
                    409,
                    $exception->errorCode,
                    $exception->getMessage(),
                    [],
                    $exception->dependencies
                );
            }
            throw ApiException::validation([
                'organisation' => [$exception->getMessage()],
            ]);
        } catch (PDOException) {
            throw ApiException::validation([
                'organisation' => [
                    'Cette valeur existe déjà ou référence une donnée incompatible.',
                ],
            ]);
        }
    }
}
