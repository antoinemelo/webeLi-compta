<?php
declare(strict_types=1);

namespace Compta\Modules\Consolidation;

use Compta\Core\Auth\AccessControl;
use Compta\Core\Auth\AuthService;
use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Api\ApiResponse;
use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Compta\Core\Security\SessionStore;
use PDOException;

final class ConsolidationApiController
{
    public function __construct(
        private readonly SessionStore $session,
        private readonly AuthService $auth,
        private readonly AccessControl $access,
        private readonly ConsolidationService $consolidation,
        private readonly ConsolidationInputValidator $validator,
    ) {
    }

    public function show(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.view');
        $query = $this->validator->query($request);
        return $this->execute($request, function () use (
            $userId, $organisationId, $dossierId, $query
        ): array {
            $visible = [];
            foreach (
                $this->consolidation->groupIdsForScope($organisationId, $dossierId)
                as $groupId
            ) {
                if ($this->hasGroupPermission($userId, $groupId, 'compta.view')) {
                    $visible[] = $groupId;
                }
            }
            if (
                $query['group_id'] !== null
                && !in_array($query['group_id'], $visible, true)
            ) {
                throw ApiException::forbidden(
                    'Les droits de lecture sont requis sur chaque entité du groupe.'
                );
            }
            $data = $this->consolidation->read(
                $visible,
                $query['group_id'],
                $query['period_id']
            );
            $selected = $data['selected_group']['id'] ?? null;
            $data['capabilities'] = [
                'setup' => is_int($selected)
                    && $this->hasGroupPermission($userId, $selected, 'compta.setup'),
                'validate' => is_int($selected)
                    && $this->hasGroupPermission($userId, $selected, 'compta.validate'),
                'export' => is_int($selected)
                    && $this->hasGroupPermission($userId, $selected, 'compta.export'),
                'create_group' => $this->has(
                    $userId, $organisationId, $dossierId, 'compta.setup'
                ),
            ];
            $data['available_members'] = array_values(array_map(
                static fn (array $dossier): array => [
                    'organisation_id' => (int) $dossier['organisation_id'],
                    'organisation' => (string) $dossier['organisation_nom'],
                    'dossier_id' => (int) $dossier['id'],
                    'dossier' => (string) $dossier['nom'],
                    'label' => (string) $dossier['organisation_nom']
                        . ' — ' . (string) $dossier['nom'],
                ],
                array_filter(
                    $this->access->dossiersForUser($userId),
                    fn (array $dossier): bool => $this->has(
                        $userId,
                        (int) $dossier['organisation_id'],
                        (int) $dossier['id'],
                        'compta.view'
                    )
                )
            ));
            return $data;
        });
    }

    public function createGroup(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        $data = $this->validator->group($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->consolidation->createGroup(
                $organisationId,
                $dossierId,
                $data['code'],
                $data['label'],
                $data['currency'],
                $data['valid_from'],
                $userId,
                $data['mode']
            ),
        ]);
    }

    public function updateGroup(Request $request): Response
    {
        [$userId] = $this->scope('compta.setup');
        $data = $this->validator->groupUpdate($request);
        $this->assertGroupPermission($userId, $data['group_id'], 'compta.setup');
        return $this->execute($request, function () use ($userId, $data): array {
            $this->consolidation->updateGroup(
                $data['group_id'],
                $data['label'],
                $data['currency'],
                $data['mode'],
                $data['version'],
                $userId
            );
            return ['updated' => true];
        });
    }

    public function activateGroup(Request $request): Response
    {
        return $this->groupLifecycle($request, 'activate');
    }

    public function archiveGroup(Request $request): Response
    {
        return $this->groupLifecycle($request, 'archive');
    }

    public function reactivateGroup(Request $request): Response
    {
        return $this->groupLifecycle($request, 'reactivate');
    }

    public function addMember(Request $request): Response
    {
        [$userId] = $this->scope('compta.setup');
        $data = $this->validator->member($request);
        $this->assertGroupPermission($userId, $data['group_id'], 'compta.setup');
        $this->assertScopePermission(
            $userId,
            $data['organisation_id'],
            $data['dossier_id'],
            'compta.setup'
        );
        return $this->execute($request, fn (): array => [
            'id' => $this->consolidation->addMember(
                $data['group_id'],
                $data['organisation_id'],
                $data['dossier_id'],
                $data['valid_from'],
                $data['valid_until'],
                $userId
            ),
        ]);
    }

    public function removeMember(Request $request): Response
    {
        [$userId] = $this->scope('compta.setup');
        $data = $this->validator->memberRemoval($request);
        $this->assertGroupPermission($userId, $data['group_id'], 'compta.setup');
        return $this->execute(
            $request,
            fn (): array => $this->consolidation->removeMember(
                $data['group_id'],
                $data['member_id'],
                $data['version'],
                $data['valid_until'],
                $userId
            )
        );
    }

    public function saveLegalAttributes(Request $request): Response
    {
        [$userId, $organisationId] = $this->scope('compta.setup');
        $data = $this->validator->legalAttributes($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->consolidation->saveLegalAttributes(
                $organisationId,
                $data['valid_from'],
                $data['legal_name'],
                $data['legal_form'],
                $data['uid'],
                $data['address'],
                $data['source'],
                $userId
            ),
        ]);
    }

    public function createPeriod(Request $request): Response
    {
        [$userId] = $this->scope('compta.setup');
        $data = $this->validator->period($request);
        $this->assertGroupPermission($userId, $data['group_id'], 'compta.setup');
        return $this->execute($request, fn (): array => [
            'id' => $this->consolidation->createPeriod(
                $data['group_id'],
                $data['label'],
                $data['start'],
                $data['end'],
                $data['conversions'],
                $userId
            ),
        ]);
    }

    public function saveMapping(Request $request): Response
    {
        [$userId] = $this->scope('compta.setup');
        $data = $this->validator->mapping($request);
        $this->assertGroupPermission($userId, $data['group_id'], 'compta.setup');
        return $this->execute($request, fn (): array => [
            'id' => $this->consolidation->saveMapping(
                $data['group_id'],
                $data['member_id'],
                $data['source_account_id'],
                $data['target_account'],
                $data['target_label'],
                $data['target_type'],
                $data['version'],
                $userId,
                $data['effective_from']
            ),
        ]);
    }

    public function disableMapping(Request $request): Response
    {
        [$userId] = $this->scope('compta.setup');
        $data = $this->validator->versionedDisable($request, 'mapping_id');
        $this->assertGroupPermission($userId, $data['group_id'], 'compta.setup');
        return $this->execute($request, function () use ($userId, $data): array {
            $this->consolidation->disableMapping(
                $data['group_id'],
                $data['resource_id'],
                $data['version'],
                $data['effective_from'],
                $userId
            );
            return ['disabled' => true];
        });
    }

    public function savePair(Request $request): Response
    {
        [$userId] = $this->scope('compta.setup');
        $data = $this->validator->pair($request);
        $this->assertGroupPermission($userId, $data['group_id'], 'compta.setup');
        return $this->execute($request, fn (): array => [
            'id' => $this->consolidation->saveIntercompanyPair(
                $data['group_id'],
                $data['label'],
                $data['left_member_id'],
                $data['left_account_id'],
                $data['right_member_id'],
                $data['right_account_id'],
                $userId,
                $data['effective_from']
            ),
        ]);
    }

    public function disablePair(Request $request): Response
    {
        [$userId] = $this->scope('compta.setup');
        $data = $this->validator->versionedDisable($request, 'pair_id');
        $this->assertGroupPermission($userId, $data['group_id'], 'compta.setup');
        return $this->execute($request, function () use ($userId, $data): array {
            $this->consolidation->disableIntercompanyPair(
                $data['group_id'],
                $data['resource_id'],
                $data['version'],
                $data['effective_from'],
                $userId
            );
            return ['disabled' => true];
        });
    }

    public function createElimination(Request $request): Response
    {
        [$userId] = $this->scope('compta.validate');
        $data = $this->validator->elimination($request);
        $this->assertGroupPermission($userId, $data['group_id'], 'compta.validate');
        return $this->execute($request, fn (): array => [
            'id' => $this->consolidation->createElimination(
                $data['group_id'],
                $data['period_id'],
                $data['reference'],
                $data['label'],
                $data['justification'],
                $data['lines'],
                $userId
            ),
        ]);
    }

    public function closePeriod(Request $request): Response
    {
        [$userId] = $this->scope('compta.validate');
        $data = $this->validator->periodAction($request);
        $this->assertGroupPermission($userId, $data['group_id'], 'compta.validate');
        return $this->execute($request, function () use ($userId, $data): array {
            $this->consolidation->closePeriod(
                $data['group_id'], $data['period_id'], $userId
            );
            return ['closed' => true];
        });
    }

    public function export(Request $request): Response
    {
        [$userId] = $this->scope('compta.export');
        $data = $this->validator->exportQuery($request);
        $this->assertGroupPermission($userId, $data['group_id'], 'compta.export');
        try {
            $export = $this->consolidation->export(
                $data['group_id'], $data['period_id']
            );
            return new Response($export['content'], 200, [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Content-Disposition' =>
                    'attachment; filename="' . $export['filename'] . '"',
                'X-Content-SHA256' => $export['hash'],
            ]);
        } catch (ConsolidationException $exception) {
            throw ApiException::validation([
                'consolidation' => [$exception->getMessage()],
            ]);
        }
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
        $this->assertScopePermission(
            $userId, $organisationId, $dossierId, $permission
        );
        return [$userId, $organisationId, $dossierId];
    }

    private function assertGroupPermission(
        int $userId,
        int $groupId,
        string $permission,
    ): void {
        if (!$this->hasGroupPermission($userId, $groupId, $permission)) {
            throw ApiException::forbidden(
                'La permission est requise sur chaque entité du groupe.'
            );
        }
    }

    private function groupLifecycle(Request $request, string $action): Response
    {
        [$userId] = $this->scope('compta.setup');
        $data = $this->validator->groupAction($request);
        $this->assertGroupPermission($userId, $data['group_id'], 'compta.setup');
        return $this->execute($request, function () use (
            $userId,
            $data,
            $action
        ): array {
            if ($action === 'activate') {
                $this->consolidation->activateGroup(
                    $data['group_id'],
                    $data['version'],
                    $userId
                );
            } elseif ($action === 'archive') {
                $this->consolidation->archiveGroup(
                    $data['group_id'],
                    $data['version'],
                    $userId
                );
            } else {
                $this->consolidation->reactivateGroup(
                    $data['group_id'],
                    $data['version'],
                    $userId
                );
            }
            return ['status' => $action];
        });
    }

    private function hasGroupPermission(
        int $userId,
        int $groupId,
        string $permission,
    ): bool {
        foreach ($this->consolidation->groupScopes($groupId) as $scope) {
            if (!$this->has(
                $userId,
                $scope['organisation_id'],
                $scope['dossier_id'],
                $permission
            )) {
                return false;
            }
        }
        return true;
    }

    private function assertScopePermission(
        int $userId,
        int $organisationId,
        int $dossierId,
        string $permission,
    ): void {
        if (
            !$this->access->canViewDossier(
                $userId, $organisationId, $dossierId
            )
            || !$this->has($userId, $organisationId, $dossierId, $permission)
        ) {
            throw ApiException::forbidden(
                'Permission comptable insuffisante sur cette entité.'
            );
        }
    }

    private function has(
        int $userId,
        int $organisationId,
        int $dossierId,
        string $permission,
    ): bool {
        return $this->access->hasDossierPermission(
            $userId, $organisationId, $dossierId, $permission
        );
    }

    /** @param callable():array<string,mixed> $callback */
    private function execute(Request $request, callable $callback): Response
    {
        try {
            return ApiResponse::success($request, $callback());
        } catch (ConsolidationException $exception) {
            $message = $exception->getMessage();
            if (
                str_contains($message, 'modifié par un autre')
                || str_contains($message, 'clôturée')
            ) {
                throw ApiException::conflict('CONSOLIDATION_CONFLICT', $message);
            }
            throw ApiException::validation([
                'consolidation' => [$message],
            ]);
        } catch (PDOException) {
            throw ApiException::validation([
                'consolidation' => [
                    'Référence déjà utilisée ou donnée hors de son périmètre.',
                ],
            ]);
        }
    }
}
