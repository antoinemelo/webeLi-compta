<?php
declare(strict_types=1);

namespace Compta\Modules\Compta;

use Compta\Core\Auth\AccessControl;
use Compta\Core\Auth\AuthService;
use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Api\ApiResponse;
use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Compta\Core\Security\SessionStore;
use PDOException;

final class AccountingApiController
{
    public function __construct(
        private readonly SessionStore $session,
        private readonly AuthService $auth,
        private readonly AccessControl $access,
        private readonly AccountingWorkspaceService $workspace,
        private readonly AccountingInputValidator $validator,
    ) {
    }

    public function show(Request $request): Response
    {
        [, $organisationId, $dossierId] = $this->scope('compta.view');
        $query = $this->validator->query($request);
        return $this->execute($request, fn (): array => $this->workspace->read(
            $organisationId,
            $dossierId,
            $query['exercise_id'],
            $query['account_id']
        ));
    }

    public function createEntry(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.edit');
        $data = $this->validator->entry($request);
        if ($data['validate']) {
            $this->assertPermission(
                $userId,
                $organisationId,
                $dossierId,
                'compta.validate'
            );
        }
        return $this->execute($request, fn (): array => $this->workspace->createEntry(
            $organisationId,
            $dossierId,
            $data,
            $userId
        ));
    }

    public function saveTypes(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        return $this->execute($request, function () use (
            $request, $userId, $organisationId, $dossierId
        ): array {
            $this->workspace->saveTypes(
                $organisationId,
                $dossierId,
                $this->validator->types($request),
                $userId
            );
            return ['saved' => true];
        });
    }

    public function saveSenseRules(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        return $this->execute($request, function () use (
            $request, $userId, $organisationId, $dossierId
        ): array {
            $this->workspace->saveSenseRules(
                $organisationId,
                $dossierId,
                $this->validator->senseRules($request),
                $userId
            );
            return ['saved' => true];
        });
    }

    public function mutateRubric(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        return $this->execute($request, function () use (
            $request, $userId, $organisationId, $dossierId
        ): array {
            $this->workspace->mutateRubric(
                $organisationId,
                $dossierId,
                $this->validator->rubric($request),
                $userId
            );
            return ['saved' => true];
        });
    }

    public function mutateAccount(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        return $this->execute($request, function () use (
            $request, $userId, $organisationId, $dossierId
        ): array {
            $this->workspace->mutateAccount(
                $organisationId,
                $dossierId,
                $this->validator->account($request),
                $userId
            );
            return ['saved' => true];
        });
    }

    public function saveOpening(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        $data = $this->validator->opening($request);
        if ($data['validate']) {
            $this->assertPermission(
                $userId,
                $organisationId,
                $dossierId,
                'compta.validate'
            );
        }
        return $this->execute($request, fn (): array => $this->workspace->saveOpening(
            $organisationId,
            $dossierId,
            $data['exercise_id'],
            $data['balances'],
            $data['validate'],
            $userId
        ));
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
            !$this->access->canViewDossier($userId, $organisationId, $dossierId)
        ) {
            throw ApiException::forbidden('Accès comptable refusé.');
        }
        $this->assertPermission(
            $userId,
            $organisationId,
            $dossierId,
            $permission
        );
        return [$userId, $organisationId, $dossierId];
    }

    private function assertPermission(
        int $userId,
        int $organisationId,
        int $dossierId,
        string $permission,
    ): void {
        if (!$this->access->hasDossierPermission(
            $userId,
            $organisationId,
            $dossierId,
            $permission
        )) {
            throw ApiException::forbidden('Permission comptable insuffisante.');
        }
    }

    /** @param callable():array<string,mixed> $callback */
    private function execute(Request $request, callable $callback): Response
    {
        try {
            return ApiResponse::success($request, $callback());
        } catch (AccountingException $exception) {
            $message = $exception->getMessage();
            if (
                str_contains($message, 'modifié')
                || str_contains($message, 'déjà validé')
            ) {
                throw ApiException::conflict('ACCOUNTING_CONFLICT', $message);
            }
            throw ApiException::validation(['accounting' => [$message]]);
        } catch (PDOException) {
            throw ApiException::validation([
                'accounting' => [
                    'Ce numéro est déjà utilisé ou la donnée reste référencée.',
                ],
            ]);
        }
    }
}
