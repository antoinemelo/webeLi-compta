<?php
declare(strict_types=1);

namespace Compta\Modules\Dossiers\Http;

use Compta\Core\Auth\AccessControl;
use Compta\Core\Auth\AuthService;
use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Api\ApiResponse;
use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Compta\Modules\Dossiers\StructureAccessException;
use Compta\Modules\Dossiers\StructureAccessService;

final class StructureAccessApiController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly AccessControl $access,
        private readonly StructureAccessService $service,
        private readonly StructureAccessInputValidator $validator,
    ) {
    }

    public function matrix(Request $request): Response
    {
        $userId = $this->userId();
        $data = $this->validator->matrix($request);
        $installationAdmin = $this->authorise($userId, $data);
        return $this->execute(
            $request,
            fn (): array => $this->service->matrix(
                $userId,
                $data['scope'],
                $data['organisation_id'],
                $data['dossier_id'],
                $installationAdmin
            )
        );
    }

    public function preview(Request $request): Response
    {
        $userId = $this->userId();
        $data = $this->validator->preview($request);
        $installationAdmin = $this->authorise($userId, $data);
        return $this->execute(
            $request,
            fn (): array => $this->service->preview(
                $userId,
                $data['scope'],
                $data['organisation_id'],
                $data['dossier_id'],
                $data['user_id'],
                $data['role_ids'],
                $data['expected_version'],
                $installationAdmin,
                $data['successor_user_id']
            )
        );
    }

    public function apply(Request $request): Response
    {
        $userId = $this->userId();
        $data = $this->validator->apply($request);
        $installationAdmin = $this->authorise($userId, $data);
        return $this->execute(
            $request,
            fn (): array => $this->service->apply(
                $userId,
                $data['scope'],
                $data['organisation_id'],
                $data['dossier_id'],
                $data['user_id'],
                $data['role_ids'],
                $data['expected_version'],
                $data['confirmation_token'],
                $installationAdmin,
                $data['successor_user_id']
            )
        );
    }

    public function copyPreview(Request $request): Response
    {
        $userId = $this->userId();
        $data = $this->validator->copyPreview($request);
        $this->authorise($userId, [
            'scope' => 'organisation',
            'organisation_id' => $data['organisation_id'],
            'dossier_id' => null,
        ]);
        return $this->execute(
            $request,
            fn (): array => $this->service->previewDossierCopy(
                $data['organisation_id'],
                $data['source_dossier_id']
            )
        );
    }

    public function exportUsers(Request $request): Response
    {
        $this->installationAdministrator();
        return new Response(
            $this->service->exportUsersCsv(),
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="utilisateurs.csv"',
                'Cache-Control' => 'no-store',
            ]
        );
    }

    public function exportAccess(Request $request): Response
    {
        $this->installationAdministrator();
        return new Response(
            $this->service->exportAccessCsv(),
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="roles_acces.csv"',
                'Cache-Control' => 'no-store',
            ]
        );
    }

    public function csvPreview(Request $request): Response
    {
        $this->installationAdministrator();
        $data = $this->validator->csvPreview($request);
        return $this->execute(
            $request,
            fn (): array => $this->service->previewCsv(
                $data['users_csv'],
                $data['access_csv']
            )
        );
    }

    public function csvImport(Request $request): Response
    {
        $userId = $this->installationAdministrator();
        $data = $this->validator->csvImport($request);
        return $this->execute(
            $request,
            fn (): array => $this->service->importCsv(
                $data['users_csv'],
                $data['access_csv'],
                $data['confirmation_token'],
                $userId
            )
        );
    }

    /** @param array<string,mixed> $scope */
    private function authorise(int $userId, array $scope): bool
    {
        $installationAdmin = $this->access->hasInstallationPermission(
            $userId,
            'installation.admin'
        );
        if ($scope['scope'] === 'installation') {
            if (!$installationAdmin) {
                throw ApiException::notFound('Périmètre introuvable.');
            }
            return true;
        }
        $organisationId = (int) $scope['organisation_id'];
        if (
            !$installationAdmin
            && !$this->access->hasOrganisationPermission(
                $userId,
                $organisationId,
                'organisation.manage'
            )
        ) {
            throw ApiException::notFound('Structure introuvable.');
        }
        return $installationAdmin;
    }

    private function userId(): int
    {
        $userId = $this->auth->userId();
        if ($userId === null) {
            throw ApiException::authenticationRequired();
        }
        return $userId;
    }

    private function installationAdministrator(): int
    {
        $userId = $this->userId();
        if (!$this->access->hasInstallationPermission(
            $userId,
            'installation.admin'
        )) {
            throw ApiException::notFound('Périmètre introuvable.');
        }
        return $userId;
    }

    /** @param callable():array<string,mixed> $callback */
    private function execute(Request $request, callable $callback): Response
    {
        try {
            return ApiResponse::success($request, $callback());
        } catch (StructureAccessException $exception) {
            if (
                $exception->errorCode === 'STRUCTURE_ACCESS_NOT_FOUND'
                || $exception->errorCode === 'STRUCTURE_ACCESS_USER_NOT_FOUND'
                || $exception->errorCode === 'STRUCTURE_ACCESS_FORBIDDEN'
            ) {
                throw ApiException::notFound('Structure introuvable.');
            }
            if (in_array($exception->errorCode, [
                'STRUCTURE_ACCESS_VERSION_CONFLICT',
                'STRUCTURE_ACCESS_PREVIEW_CONFLICT',
                'STRUCTURE_ACCESS_COPY_CONFLICT',
                'STRUCTURE_ACCESS_LAST_ADMIN',
            ], true)) {
                throw ApiException::conflict(
                    $exception->errorCode,
                    $exception->getMessage()
                );
            }
            throw ApiException::validation([
                'access' => [$exception->getMessage()],
            ]);
        }
    }
}
