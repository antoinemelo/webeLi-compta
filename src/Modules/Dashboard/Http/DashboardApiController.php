<?php
declare(strict_types=1);

namespace Compta\Modules\Dashboard\Http;

use Compta\Core\Auth\AccessControl;
use Compta\Core\Auth\AuthService;
use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Api\ApiResponse;
use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Compta\Core\Security\SessionStore;
use Compta\Modules\Dashboard\Application\DashboardQueryException;
use Compta\Modules\Dashboard\Application\DashboardReadService;

final class DashboardApiController
{
    public function __construct(
        private readonly SessionStore $session,
        private readonly AuthService $auth,
        private readonly AccessControl $access,
        private readonly DashboardReadService $reads,
        private readonly DashboardInputValidator $validator,
    ) {
    }

    public function show(Request $request): Response
    {
        [$organisationId, $dossierId] = $this->requireScope();
        $query = $this->validator->query($request);
        try {
            $projection = $this->reads->projection(
                $organisationId,
                $dossierId,
                $query['exercise_id'],
                $query['as_of_date']
            );
        } catch (DashboardQueryException $exception) {
            throw ApiException::validation([
                $exception->field => [$exception->getMessage()],
            ]);
        }
        return ApiResponse::success($request, $projection);
    }

    /** @return array{int,int} */
    private function requireScope(): array
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
            !$this->access->canViewDossier($userId, $organisationId, $dossierId)
            || !$this->access->hasDossierPermission(
                $userId,
                $organisationId,
                $dossierId,
                'compta.view'
            )
        ) {
            throw ApiException::forbidden('Accès au tableau de bord refusé.');
        }
        return [$organisationId, $dossierId];
    }
}
