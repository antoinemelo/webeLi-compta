<?php
declare(strict_types=1);

namespace Compta\Modules\Dossiers\Http;

use Compta\Core\Auth\AccessControl;
use Compta\Core\Auth\AuthService;
use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Api\ApiResponse;
use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Compta\Core\Security\SessionStore;
use Compta\Modules\Dossiers\DossierRegistryException;
use Compta\Modules\Dossiers\DossierRegistryService;
use PDOException;

final class DossierApiController
{
    public function __construct(
        private readonly SessionStore $session,
        private readonly AuthService $auth,
        private readonly AccessControl $access,
        private readonly DossierRegistryService $registry,
        private readonly DossierInputValidator $validator,
    ) {
    }

    public function list(Request $request): Response
    {
        $userId = $this->userId();
        $data = $this->validator->listing($request);
        $this->assertOrganisationManage($userId, $data['organisation_id']);
        return $this->execute(
            $request,
            fn (): array => [
                'items' => $this->registry->listForOrganisation(
                    $data['organisation_id'],
                    $data['status']
                ),
            ]
        );
    }

    public function detail(Request $request): Response
    {
        $userId = $this->userId();
        $id = $this->validator->detail($request);
        $detail = $this->authorisedDetail($userId, $id);
        return ApiResponse::success($request, $detail);
    }

    public function create(Request $request): Response
    {
        $userId = $this->userId();
        $data = $this->validator->create($request);
        $this->assertOrganisationManage($userId, $data['organisation_id']);
        return $this->execute($request, fn (): array => $this->registry
            ->createInitialized(
                $data['organisation_id'],
                $data['name'],
                $data['slug'],
                $data['type'],
                $data['currency'],
                $data['modules'],
                $data['plan_variant'],
                $data['association']['enabled'],
                [
                    'projets' => $data['association']['projects'],
                    'fonds_affectes' => $data['association']['restricted_funds'],
                ],
                $data['exercise']['label'],
                $data['exercise']['start'],
                $data['exercise']['end'],
                $data['journal']['code'],
                $data['journal']['label'],
                $userId
            ), 201);
    }

    public function update(Request $request): Response
    {
        $userId = $this->userId();
        $data = $this->validator->update($request);
        $this->authorisedDetail($userId, $data['id']);
        return $this->execute($request, function () use ($data, $userId): array {
            $this->registry->update(
                $data['id'],
                $data['version'],
                $data['name'],
                $data['type'],
                $data['currency'],
                $userId
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
        $userId = $this->userId();
        $data = $this->validator->action($request);
        $detail = $this->registry->detail($data['id']);
        $this->assertOrganisationManage($userId, (int) $detail['organisation_id']);
        return $this->execute($request, function () use ($data, $userId): array {
            $this->registry->delete($data['id'], $data['version'], $userId);
            $this->clearCurrent($data['id']);
            return ['deleted' => true, 'id' => $data['id']];
        });
    }

    private function status(Request $request, bool $active): Response
    {
        $userId = $this->userId();
        $data = $this->validator->action($request);
        $this->authorisedDetail($userId, $data['id']);
        return $this->execute($request, function () use (
            $data, $userId, $active
        ): array {
            if ($active) {
                $this->registry->reactivate($data['id'], $data['version'], $userId);
            } else {
                $this->registry->archive($data['id'], $data['version'], $userId);
                $this->clearCurrent($data['id']);
            }
            return $this->registry->detail($data['id']);
        });
    }

    /** @return array<string,mixed> */
    private function authorisedDetail(int $userId, int $dossierId): array
    {
        try {
            $detail = $this->registry->detail($dossierId);
        } catch (DossierRegistryException $exception) {
            if ($exception->errorCode === 'DOSSIER_NOT_FOUND') {
                throw ApiException::notFound('Dossier introuvable.');
            }
            throw $exception;
        }
        $organisationId = (int) $detail['organisation_id'];
        if (
            !$this->access->hasInstallationPermission($userId, 'installation.admin')
            && !$this->access->hasOrganisationPermission(
                $userId, $organisationId, 'organisation.manage'
            )
            && !$this->access->hasDossierPermissionIncludingArchived(
                $userId, $organisationId, $dossierId, 'dossier.manage'
            )
        ) {
            throw ApiException::notFound('Dossier introuvable.');
        }
        return $detail;
    }

    private function assertOrganisationManage(int $userId, int $organisationId): void
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

    private function clearCurrent(int $dossierId): void
    {
        if ((int) $this->session->get('dossier_id', 0) === $dossierId) {
            $this->session->remove('dossier_id');
            $this->session->remove('organisation_id');
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

    /** @param callable():array<string,mixed> $callback */
    private function execute(
        Request $request,
        callable $callback,
        int $status = 200,
    ): Response {
        try {
            return ApiResponse::success($request, $callback(), [], $status);
        } catch (DossierRegistryException $exception) {
            if ($exception->errorCode === 'DOSSIER_NOT_FOUND') {
                throw ApiException::notFound($exception->getMessage());
            }
            if (
                $exception->errorCode === 'DOSSIER_VERSION_CONFLICT'
                || $exception->errorCode === 'DOSSIER_HAS_DEPENDENCIES'
                || $exception->errorCode === 'DOSSIER_HISTORICAL_FIELDS_LOCKED'
                || $exception->errorCode === 'DOSSIER_ORGANISATION_INACTIVE'
                || $exception->errorCode === 'DOSSIER_ARCHIVED'
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
                'dossier' => [$exception->getMessage()],
            ]);
        } catch (PDOException) {
            throw ApiException::validation([
                'dossier' => [
                    'Cette valeur existe déjà ou référence une donnée incompatible.',
                ],
            ]);
        }
    }
}
