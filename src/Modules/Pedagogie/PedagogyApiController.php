<?php
declare(strict_types=1);

namespace Compta\Modules\Pedagogie;

use Compta\Core\Auth\AccessControl;
use Compta\Core\Auth\AuthService;
use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Api\ApiResponse;
use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Compta\Core\Security\SessionStore;
use Compta\Modules\Compta\AccountingException;
use PDOException;

final class PedagogyApiController
{
    public function __construct(
        private readonly SessionStore $session,
        private readonly AuthService $auth,
        private readonly AccessControl $access,
        private readonly PedagogyService $pedagogy,
        private readonly PedagogyInputValidator $validator,
    ) {
    }

    public function show(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('pedagogie.view');
        $manage = $this->has(
            $userId,
            $organisationId,
            $dossierId,
            'pedagogie.manage'
        );
        return $this->execute($request, function () use (
            $userId,
            $organisationId,
            $dossierId,
            $manage
        ): array {
            $data = $this->pedagogy->workspace(
                $organisationId,
                $dossierId,
                $userId,
                $manage
            );
            $data['capabilities'] = [
                'work' => $this->has(
                    $userId,
                    $organisationId,
                    $dossierId,
                    'pedagogie.work'
                ),
                'manage' => $manage,
                'correct' => $this->has(
                    $userId,
                    $organisationId,
                    $dossierId,
                    'pedagogie.correct'
                ),
                'reset' => $this->has(
                    $userId,
                    $organisationId,
                    $dossierId,
                    'pedagogie.reset'
                ),
                'export' => $this->has(
                    $userId,
                    $organisationId,
                    $dossierId,
                    'pedagogie.export'
                ),
            ];
            return $data;
        });
    }

    public function installCatalog(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('pedagogie.manage');
        return $this->execute($request, fn (): array =>
            $this->pedagogy->installTargetedCatalog(
                $organisationId,
                $dossierId,
                $userId
            )
        );
    }

    public function createModel(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('pedagogie.manage');
        $data = $this->validator->model($request);
        return $this->execute($request, function () use (
            $userId,
            $organisationId,
            $dossierId,
            $data
        ): array {
            return $this->pedagogy->createPublishedModel(
                $organisationId,
                $dossierId,
                $data['title'],
                $data['description'],
                $data['competence'],
                $data['level'],
                $data['duration_minutes'],
                $data['instructions'],
                $data['steps'],
                $data['opening'],
                $data['initial'],
                $data['solution'],
                $data['correction_rule'],
                $data['correction_value'],
                $userId
            );
        });
    }

    public function attempt(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('pedagogie.work');
        $data = $this->validator->attempt($request);
        return $this->execute($request, fn (): array =>
            $this->pedagogy->attempt(
                $organisationId,
                $dossierId,
                $userId,
                $data['step_id'],
                $data['entry_id']
            )
        );
    }

    public function hint(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('pedagogie.work');
        $stepId = $this->validator->identifier($request, 'step_id');
        return $this->execute($request, fn (): array =>
            $this->pedagogy->nextHint(
                $organisationId,
                $dossierId,
                $userId,
                $stepId
            )
        );
    }

    public function correction(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('pedagogie.work');
        return $this->execute($request, fn (): array => [
            'solution' => $this->pedagogy->correction(
                $organisationId,
                $dossierId,
                $userId
            ),
        ]);
    }

    public function authorize(Request $request): Response
    {
        [$userId, $organisationId] = $this->scope('pedagogie.correct');
        $id = $this->validator->identifier($request, 'assignment_id');
        return $this->execute($request, function () use (
            $organisationId,
            $id,
            $userId
        ): array {
            $this->pedagogy->authorizeCorrection(
                $organisationId,
                $id,
                $userId
            );
            return ['authorized' => true];
        });
    }

    public function reset(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('pedagogie.reset');
        $id = $this->validator->identifier($request, 'assignment_id');
        return $this->execute($request, function () use (
            $organisationId,
            $dossierId,
            $id,
            $userId
        ): array {
            $this->pedagogy->assertPedagogicalContext(
                $organisationId,
                $dossierId
            );
            $newDossier = $this->pedagogy->reset(
                $organisationId,
                $id,
                $userId
            );
            $this->session->set('dossier_id', $newDossier);
            return ['dossier_id' => $newDossier];
        });
    }

    public function createGroup(Request $request): Response
    {
        [$userId, $organisationId] = $this->scope('pedagogie.manage');
        $name = $this->validator->group($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->pedagogy->createGroup(
                $organisationId,
                $name,
                $userId
            ),
        ]);
    }

    public function addMember(Request $request): Response
    {
        [$userId, $organisationId] = $this->scope('pedagogie.manage');
        $data = $this->validator->member($request);
        return $this->execute($request, function () use (
            $organisationId,
            $data,
            $userId
        ): array {
            $this->pedagogy->addMember(
                $organisationId,
                $data['group_id'],
                $data['user_id'],
                'membre',
                $userId
            );
            return ['saved' => true];
        });
    }

    public function assign(Request $request): Response
    {
        [$userId, $organisationId] = $this->scope('pedagogie.manage');
        $data = $this->validator->assignment($request);
        return $this->execute($request, function () use (
            $organisationId,
            $data,
            $userId
        ): array {
            $id = $data['target_type'] === 'group'
                ? $this->pedagogy->assignGroup(
                    $organisationId,
                    $data['version_id'],
                    $data['target_id'],
                    $data['name'],
                    $userId
                )
                : $this->pedagogy->assignIndividual(
                    $organisationId,
                    $data['version_id'],
                    $data['target_id'],
                    $data['name'],
                    $userId
                );
            return ['id' => $id];
        });
    }

    public function export(Request $request): Response
    {
        [, $organisationId] = $this->scope('pedagogie.export');
        try {
            return new Response(
                $this->pedagogy->exportResults($organisationId),
                200,
                [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' =>
                        'attachment; filename="resultats-pedagogiques.csv"',
                ]
            );
        } catch (PedagogyException $exception) {
            throw ApiException::validation([
                'pedagogy' => [$exception->getMessage()],
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
            throw ApiException::conflict('CONTEXT_REQUIRED', 'Sélectionnez un dossier.');
        }
        if (
            !$this->access->canViewDossier(
                $userId,
                $organisationId,
                $dossierId
            )
            || !$this->has(
                $userId,
                $organisationId,
                $dossierId,
                $permission
            )
        ) {
            throw ApiException::forbidden('Accès pédagogique refusé.');
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
        } catch (PedagogyConflictException $exception) {
            throw ApiException::conflict(
                'PEDAGOGY_CONFLICT',
                $exception->getMessage()
            );
        } catch (PedagogyException|AccountingException $exception) {
            throw ApiException::validation([
                'pedagogy' => [$exception->getMessage()],
            ]);
        } catch (PDOException) {
            throw ApiException::validation([
                'pedagogy' => ['Les données pédagogiques ne peuvent pas être enregistrées.'],
            ]);
        }
    }
}
