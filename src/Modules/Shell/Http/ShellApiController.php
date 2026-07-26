<?php
declare(strict_types=1);

namespace Compta\Modules\Shell\Http;

use Compta\Core\Audit\AuditLogger;
use Compta\Core\Auth\AccessControl;
use Compta\Core\Auth\AuthService;
use Compta\Core\Config\AppConfig;
use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Api\ApiResponse;
use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Compta\Core\Security\Csrf;
use Compta\Core\Security\SessionStore;
use Compta\Modules\Shell\Application\ShellReadService;

final class ShellApiController
{
    public function __construct(
        private readonly AppConfig $config,
        private readonly SessionStore $session,
        private readonly Csrf $csrf,
        private readonly AuthService $auth,
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
        private readonly ShellReadService $reads,
        private readonly ShellInputValidator $validator,
    ) {
    }

    public function context(Request $request): Response
    {
        return ApiResponse::success(
            $request,
            $this->contextData($this->requireUser())
        );
    }

    public function dossiers(Request $request): Response
    {
        $userId = $this->requireUser();
        $query = $this->validator->listQuery(
            $request,
            ['id', 'name', 'organization_name', 'type'],
            'organization_name',
            [
                'type' => ['reel', 'demo', 'exercice'],
                'search' => null,
            ]
        );
        $result = $this->reads->dossiers(
            $this->access->dossiersForUser($userId),
            $query
        );
        return ApiResponse::success($request, $result['items'], [
            'pagination' => $result['pagination'],
            'sort' => ['field' => $query->sort, 'order' => $query->order],
            'filters' => $query->filters,
        ]);
    }

    public function navigation(Request $request): Response
    {
        $userId = $this->requireUser();
        $scope = $this->optionalScope($userId);
        $permissions = $scope === null
            ? []
            : $this->reads->permissions($userId, $scope[0], $scope[1]);
        $enabledModules = $scope === null
            ? []
            : $this->reads->enabledModules($scope[0], $scope[1]);
        return ApiResponse::success(
            $request,
            $this->reads->navigation($permissions, $scope !== null, $enabledModules)
        );
    }

    public function permissions(Request $request): Response
    {
        $userId = $this->requireUser();
        [$organisationId, $dossierId] = $this->requireScope($userId);
        return ApiResponse::success(
            $request,
            $this->reads->permissions($userId, $organisationId, $dossierId)
        );
    }

    public function exercises(Request $request): Response
    {
        $userId = $this->requireUser();
        [$organisationId, $dossierId] = $this->requireScope(
            $userId,
            'exercice.view'
        );
        $query = $this->validator->listQuery(
            $request,
            ['id', 'label', 'start_date', 'end_date', 'status'],
            'start_date',
            [
                'status' => ['ouvert', 'ferme'],
                'search' => null,
            ]
        );
        $result = $this->reads->exercises($organisationId, $dossierId, $query);
        return ApiResponse::success($request, $result['items'], [
            'pagination' => $result['pagination'],
            'sort' => ['field' => $query->sort, 'order' => $query->order],
            'filters' => $query->filters,
        ]);
    }

    public function references(Request $request): Response
    {
        $userId = $this->requireUser();
        [$organisationId, $dossierId] = $this->requireScope($userId);
        return ApiResponse::success(
            $request,
            $this->reads->references(
                $this->reads->dossierCurrency($organisationId, $dossierId)
            )
        );
    }

    public function selectDossier(Request $request): Response
    {
        $userId = $this->requireUser();
        $selection = $this->validator->dossierSelection($request);
        $organisationId = $selection['organisation_id'];
        $dossierId = $selection['dossier_id'];
        if (!$this->access->canViewDossier($userId, $organisationId, $dossierId)) {
            throw ApiException::forbidden('Accès au dossier refusé.');
        }
        $this->session->set('organisation_id', $organisationId);
        $this->session->set('dossier_id', $dossierId);
        $this->audit->log(
            'contexte.dossier_selectionne_api',
            $userId,
            $organisationId,
            $dossierId,
            'dossier',
            (string) $dossierId,
            ['correlation_id' => ApiResponse::correlationId($request)],
            $request->ip()
        );
        return ApiResponse::success(
            $request,
            $this->contextData($userId),
            status: 200
        );
    }

    private function requireUser(): int
    {
        $userId = $this->auth->userId();
        if ($userId === null || $this->reads->user($userId) === null) {
            throw ApiException::authenticationRequired();
        }
        return $userId;
    }

    /** @return array{int,int} */
    private function requireScope(int $userId, ?string $permission = null): array
    {
        $organisationId = (int) $this->session->get('organisation_id', 0);
        $dossierId = (int) $this->session->get('dossier_id', 0);
        if ($organisationId < 1 || $dossierId < 1) {
            throw ApiException::conflict(
                'CONTEXT_REQUIRED',
                'Sélectionnez un dossier avant cette opération.'
            );
        }
        if (!$this->access->canViewDossier($userId, $organisationId, $dossierId)) {
            throw ApiException::forbidden('Accès au dossier refusé.');
        }
        if (
            $permission !== null
            && !$this->access->hasDossierPermission(
                $userId,
                $organisationId,
                $dossierId,
                $permission
            )
        ) {
            throw ApiException::forbidden('Permission insuffisante.');
        }
        return [$organisationId, $dossierId];
    }

    /** @return array{int,int}|null */
    private function optionalScope(int $userId): ?array
    {
        $organisationId = (int) $this->session->get('organisation_id', 0);
        $dossierId = (int) $this->session->get('dossier_id', 0);
        if (
            $organisationId < 1
            || $dossierId < 1
            || !$this->access->canViewDossier($userId, $organisationId, $dossierId)
        ) {
            return null;
        }
        return [$organisationId, $dossierId];
    }

    /** @return array<string, mixed> */
    private function contextData(int $userId): array
    {
        $user = $this->reads->user($userId);
        if ($user === null) {
            throw ApiException::authenticationRequired();
        }
        $scope = $this->optionalScope($userId);
        $selection = null;
        $permissions = [];
        $enabledModules = [];
        if ($scope !== null) {
            [$organisationId, $dossierId] = $scope;
            $visible = $this->access->visibleDossier(
                $userId,
                $organisationId,
                $dossierId
            );
            if ($visible !== null) {
                $permissions = $this->reads->permissions(
                    $userId,
                    $organisationId,
                    $dossierId
                );
                $enabledModules = $this->reads->enabledModules(
                    $organisationId,
                    $dossierId
                );
                $selection = [
                    'organization' => [
                        'id' => $organisationId,
                        'name' => (string) $visible['organisation_nom'],
                        'nature' => (string) $visible['nature'],
                    ],
                    'dossier' => [
                        'id' => $dossierId,
                        'name' => (string) $visible['nom'],
                        'type' => (string) $visible['type'],
                        'currency' => $this->reads->dossierCurrency(
                            $organisationId,
                            $dossierId
                        ),
                    ],
                    'exercise' => $this->reads->currentExercise(
                        $organisationId,
                        $dossierId
                    ),
                ];
            }
        }
        return [
            'api' => [
                'version' => ApiResponse::CONTRACT_VERSION,
                'base_path' => $this->config->url('/api/v1'),
                'csrf_header' => 'X-CSRF-Token',
                'endpoints' => [
                    ['key' => 'context', 'method' => 'GET', 'path' => '/context'],
                    ['key' => 'select_dossier', 'method' => 'POST', 'path' => '/context/dossier'],
                    ['key' => 'dossiers', 'method' => 'GET', 'path' => '/dossiers'],
                    ['key' => 'navigation', 'method' => 'GET', 'path' => '/navigation'],
                    ['key' => 'permissions', 'method' => 'GET', 'path' => '/permissions'],
                    ['key' => 'exercises', 'method' => 'GET', 'path' => '/exercises'],
                    ['key' => 'references', 'method' => 'GET', 'path' => '/references'],
                    ['key' => 'dashboard', 'method' => 'GET', 'path' => '/dashboard'],
                    ['key' => 'configuration', 'method' => 'GET', 'path' => '/configuration'],
                    ['key' => 'liquidities', 'method' => 'GET', 'path' => '/liquidites'],
                ],
            ],
            'instance' => $this->config->string('instance_id'),
            'user' => $user,
            'selection' => $selection,
            'permissions' => $permissions,
            'enabled_modules' => $enabledModules,
            'navigation' => $this->reads->navigation(
                $permissions,
                $selection !== null,
                $enabledModules
            ),
            'csrf_token' => $this->csrf->token(),
        ];
    }
}
