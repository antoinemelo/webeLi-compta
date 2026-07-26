<?php
declare(strict_types=1);

namespace Compta\Modules\Configuration\Http;

use Compta\Core\Auth\AccessControl;
use Compta\Core\Auth\AuthService;
use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Api\ApiResponse;
use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Compta\Core\Security\SessionStore;
use Compta\Modules\Configuration\Application\ConfigurationException;
use Compta\Modules\Configuration\Application\ConfigurationService;

final class ConfigurationApiController
{
    public function __construct(
        private readonly SessionStore $session,
        private readonly AuthService $auth,
        private readonly AccessControl $access,
        private readonly ConfigurationService $configuration,
        private readonly ConfigurationInputValidator $validator,
    ) {
    }

    public function show(Request $request): Response
    {
        [, $organisationId, $dossierId] = $this->scope();
        return ApiResponse::success(
            $request,
            $this->configuration->read($organisationId, $dossierId)
        );
    }

    public function updateIdentity(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope();
        return $this->mutation($request, fn (): array => [
            'identity' => $this->configuration->updateIdentity(
                $organisationId,
                $dossierId,
                $this->validator->identity($request),
                $userId
            ),
        ]);
    }

    public function updateModule(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope();
        return $this->mutation($request, function () use (
            $request,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $data = $this->validator->module($request);
            $this->configuration->setModule(
                $organisationId,
                $dossierId,
                $data['code'],
                $data['enabled'],
                $data['version'],
                $userId
            );
            return $this->configuration->read($organisationId, $dossierId);
        });
    }

    public function createPaymentTerm(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope();
        return $this->mutation($request, function () use (
            $request,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $id = $this->configuration->createPaymentTerm(
                $organisationId,
                $dossierId,
                $this->validator->paymentTerm($request),
                $userId
            );
            return [
                'id' => $id,
                'configuration' => $this->configuration->read(
                    $organisationId,
                    $dossierId
                ),
            ];
        });
    }

    public function updatePaymentDefault(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope();
        return $this->mutation($request, function () use (
            $request,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $data = $this->validator->paymentDefault($request);
            $id = $this->configuration->setPaymentDefault(
                $organisationId,
                $dossierId,
                $data['direction'],
                $data['condition_id'],
                $data['valid_from'],
                $userId
            );
            return [
                'id' => $id,
                'configuration' => $this->configuration->read(
                    $organisationId,
                    $dossierId
                ),
            ];
        });
    }

    /** @return array{int,int,int} */
    private function scope(): array
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
                'dossier.manage'
            )
        ) {
            throw ApiException::forbidden('Configuration du dossier refusée.');
        }
        return [$userId, $organisationId, $dossierId];
    }

    /** @param callable():array<string,mixed> $callback */
    private function mutation(Request $request, callable $callback): Response
    {
        try {
            return ApiResponse::success($request, $callback());
        } catch (ConfigurationException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'modifié par un autre') || str_contains($message, 'Conflit')) {
                throw ApiException::conflict('CONFIGURATION_CONFLICT', $message);
            }
            throw ApiException::validation(['configuration' => [$message]]);
        }
    }
}
