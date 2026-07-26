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
use Compta\Modules\Compta\AccountingException;
use Compta\Modules\Configuration\Application\ConfigurationException;
use Compta\Modules\Configuration\Application\ConfigurationService;
use Compta\Modules\Configuration\Application\ManagedReferencesService;
use Compta\Modules\Devises\ExchangeRateException;
use Compta\Modules\Facturation\BillingException;
use Compta\Modules\Salaires\PayrollException;
use Compta\Modules\Tva\VatException;
use Compta\Modules\Tresorerie\TreasuryException;
use PDOException;

final class ConfigurationApiController
{
    public function __construct(
        private readonly SessionStore $session,
        private readonly AuthService $auth,
        private readonly AccessControl $access,
        private readonly ConfigurationService $configuration,
        private readonly ConfigurationInputValidator $validator,
        private readonly ManagedReferencesService $managedReferences,
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

    public function references(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope();
        $result = $this->managedReferences->read($organisationId, $dossierId);
        $result['capabilities'] = [
            'contacts' => $this->hasPermission(
                $userId,
                $organisationId,
                $dossierId,
                'facturation.manage'
            ),
            'vat' => $this->hasPermission(
                $userId,
                $organisationId,
                $dossierId,
                'tva.setup'
            ),
            'payroll' => $this->hasPermission(
                $userId,
                $organisationId,
                $dossierId,
                'salaires.manage'
            ),
            'treasury' => $this->hasPermission(
                $userId,
                $organisationId,
                $dossierId,
                'tresorerie.setup'
            ),
            'accounting_setup' => $this->hasPermission(
                $userId,
                $organisationId,
                $dossierId,
                'compta.setup'
            ),
            'currencies' => $this->hasPermission(
                $userId,
                $organisationId,
                $dossierId,
                'compta.setup'
            ),
            'access' => true,
        ];
        return ApiResponse::success($request, $result);
    }

    public function saveCurrency(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        return $this->referenceMutation($request, function () use (
            $request, $userId, $organisationId, $dossierId
        ): array {
            $data = $this->validator->currency($request);
            $this->managedReferences->saveCurrency(
                $organisationId,
                $dossierId,
                $data['currency'],
                $data['active'],
                $userId
            );
            return ['saved' => true];
        });
    }

    public function saveExchangeRate(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        return $this->referenceMutation($request, fn (): array => [
            'id' => $this->managedReferences->saveExchangeRate(
                $organisationId,
                $dossierId,
                $this->validator->exchangeRate($request),
                $userId
            ),
        ]);
    }

    public function saveExchangeMapping(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        return $this->referenceMutation($request, function () use (
            $request, $userId, $organisationId, $dossierId
        ): array {
            $this->managedReferences->saveExchangeMapping(
                $organisationId,
                $dossierId,
                $this->validator->exchangeMapping($request),
                $userId
            );
            return ['saved' => true];
        });
    }

    public function createContact(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope(
            'facturation.manage'
        );
        return $this->referenceMutation($request, function () use (
            $request,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $id = $this->managedReferences->createContact(
                $organisationId,
                $dossierId,
                $this->validator->contact($request),
                $userId
            );
            return ['id' => $id];
        });
    }

    public function saveVatCode(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('tva.setup');
        return $this->referenceMutation($request, function () use (
            $request,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $id = $this->managedReferences->saveVatCode(
                $organisationId,
                $dossierId,
                $this->validator->vatCode($request),
                $userId
            );
            return ['id' => $id];
        });
    }

    public function deleteVatCode(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('tva.setup');
        return $this->referenceMutation($request, function () use (
            $request,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $id = $this->validator->referenceIdentifier($request);
            $this->managedReferences->deleteVatCode(
                $organisationId,
                $dossierId,
                $id,
                $userId
            );
            return ['id' => $id];
        });
    }

    public function savePayrollRates(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope(
            'salaires.manage'
        );
        return $this->referenceMutation($request, function () use (
            $request,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $id = $this->managedReferences->savePayrollRates(
                $organisationId,
                $dossierId,
                $this->validator->payrollRates($request),
                $userId
            );
            return ['id' => $id];
        });
    }

    public function savePayrollEmployerSettings(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope(
            'salaires.manage'
        );
        return $this->referenceMutation($request, function () use (
            $request,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $id = $this->managedReferences->savePayrollEmployerSettings(
                $organisationId,
                $dossierId,
                $this->validator->payrollEmployerSettings($request),
                $userId
            );
            return ['id' => $id];
        });
    }

    public function savePayrollMappingSettings(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope(
            'salaires.manage'
        );
        return $this->referenceMutation($request, function () use (
            $request,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $this->managedReferences->savePayrollMappingSettings(
                $organisationId,
                $dossierId,
                $this->validator->payrollMappingSettings($request),
                $userId
            );
            return ['saved' => true];
        });
    }

    public function saveTreasuryAccount(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope(
            'tresorerie.setup'
        );
        return $this->referenceMutation($request, function () use (
            $request,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $id = $this->managedReferences->saveTreasuryAccount(
                $organisationId,
                $dossierId,
                $this->validator->treasuryAccount($request),
                $userId
            );
            return ['id' => $id];
        });
    }

    public function saveJournal(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        return $this->referenceMutation($request, function () use (
            $request,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $id = $this->managedReferences->saveJournal(
                $organisationId,
                $dossierId,
                $this->validator->journal($request),
                $userId
            );
            return ['id' => $id];
        });
    }

    public function saveExercise(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        return $this->referenceMutation($request, function () use (
            $request,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $id = $this->managedReferences->saveExercise(
                $organisationId,
                $dossierId,
                $this->validator->exercise($request),
                $userId
            );
            return ['id' => $id];
        });
    }

    public function savePeriod(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        return $this->referenceMutation($request, function () use (
            $request,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $id = $this->managedReferences->savePeriod(
                $organisationId,
                $dossierId,
                $this->validator->period($request),
                $userId
            );
            return ['id' => $id];
        });
    }

    public function saveDossierAccess(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope();
        return $this->referenceMutation($request, function () use (
            $request,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $id = $this->managedReferences->saveDossierAccess(
                $organisationId,
                $dossierId,
                $this->validator->dossierAccess($request),
                $userId
            );
            return ['id' => $id];
        });
    }

    /** @return array{int,int,int} */
    private function scope(?string $permission = null): array
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
        if (
            $permission !== null
            && !$this->hasPermission(
                $userId,
                $organisationId,
                $dossierId,
                $permission
            )
        ) {
            throw ApiException::forbidden('Permission métier insuffisante.');
        }
        return [$userId, $organisationId, $dossierId];
    }

    private function hasPermission(
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

    /** @param callable():array<string,mixed> $callback */
    private function referenceMutation(Request $request, callable $callback): Response
    {
        try {
            return ApiResponse::success($request, $callback());
        } catch (
            AccountingException
            |BillingException
            |ConfigurationException
            |ExchangeRateException
            |PayrollException
            |TreasuryException
            |VatException $exception
        ) {
            throw ApiException::validation([
                'reference' => [$exception->getMessage()],
            ]);
        } catch (PDOException) {
            throw ApiException::validation([
                'reference' => [
                    'Cette valeur existe déjà ou référence un élément invalide.',
                ],
            ]);
        }
    }
}
