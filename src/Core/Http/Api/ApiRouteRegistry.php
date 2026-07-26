<?php
declare(strict_types=1);

namespace Compta\Core\Http\Api;

use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Compta\Core\Http\Router;
use Compta\Core\Security\Csrf;
use Compta\Modules\Compta\AccountingApiController;
use Compta\Modules\Configuration\Http\ConfigurationApiController;
use Compta\Modules\Dashboard\Http\DashboardApiController;
use Compta\Modules\Facturation\Http\BillingApiController;
use Compta\Modules\Shell\Http\ShellApiController;
use Compta\Modules\Tresorerie\Http\ExpenseApiController;
use Compta\Modules\Tresorerie\Http\TreasuryApiController;
use Throwable;

final class ApiRouteRegistry
{
    public function __construct(
        private readonly ShellApiController $shell,
        private readonly DashboardApiController $dashboard,
        private readonly Csrf $csrf,
        private readonly ?ConfigurationApiController $configuration = null,
        private readonly ?AccountingApiController $accounting = null,
        private readonly ?ExpenseApiController $expenses = null,
        private readonly ?TreasuryApiController $treasury = null,
        private readonly ?BillingApiController $billing = null,
    ) {
    }

    public function register(Router $router): void
    {
        $this->add($router, 'GET', '/api/v1/context', $this->shell->context(...));
        $this->add($router, 'POST', '/api/v1/context/dossier', $this->shell->selectDossier(...));
        $this->add($router, 'GET', '/api/v1/dossiers', $this->shell->dossiers(...));
        $this->add($router, 'GET', '/api/v1/navigation', $this->shell->navigation(...));
        $this->add($router, 'GET', '/api/v1/permissions', $this->shell->permissions(...));
        $this->add($router, 'GET', '/api/v1/exercises', $this->shell->exercises(...));
        $this->add($router, 'GET', '/api/v1/references', $this->shell->references(...));
        $this->add($router, 'GET', '/api/v1/dashboard', $this->dashboard->show(...));
        if ($this->configuration !== null) {
            $this->add(
                $router,
                'GET',
                '/api/v1/configuration',
                $this->configuration->show(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/configuration/identity',
                $this->configuration->updateIdentity(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/configuration/modules',
                $this->configuration->updateModule(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/configuration/payment-terms',
                $this->configuration->createPaymentTerm(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/configuration/payment-defaults',
                $this->configuration->updatePaymentDefault(...)
            );
            $this->add(
                $router,
                'GET',
                '/api/v1/configuration/references',
                $this->configuration->references(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/configuration/references/contacts',
                $this->configuration->createContact(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/configuration/references/vat-codes',
                $this->configuration->createVatCode(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/configuration/references/payroll-rates',
                $this->configuration->savePayrollRates(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/configuration/references/treasury-accounts',
                $this->configuration->saveTreasuryAccount(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/configuration/references/journals',
                $this->configuration->saveJournal(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/configuration/references/exercises',
                $this->configuration->saveExercise(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/configuration/references/periods',
                $this->configuration->savePeriod(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/configuration/access',
                $this->configuration->saveDossierAccess(...)
            );
        }
        if ($this->accounting !== null) {
            $this->add(
                $router,
                'GET',
                '/api/v1/accounting',
                $this->accounting->show(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/accounting/entries',
                $this->accounting->createEntry(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/accounting/chart/types',
                $this->accounting->saveTypes(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/accounting/chart/sense-rules',
                $this->accounting->saveSenseRules(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/accounting/chart/rubrics',
                $this->accounting->mutateRubric(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/accounting/chart/accounts',
                $this->accounting->mutateAccount(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/accounting/opening',
                $this->accounting->saveOpening(...)
            );
        }
        if ($this->expenses !== null) {
            $this->add($router, 'GET', '/api/v1/liquidites', $this->expenses->show(...));
            $this->add(
                $router,
                'POST',
                '/api/v1/liquidites/depenses',
                $this->expenses->create(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/liquidites/depenses/soumettre',
                $this->expenses->submit(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/liquidites/depenses/approuver',
                $this->expenses->approve(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/liquidites/depenses/comptabiliser',
                $this->expenses->post(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/liquidites/depenses/annuler',
                $this->expenses->cancel(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/liquidites/recurrences',
                $this->expenses->createRecurrence(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/liquidites/recurrences/pause',
                $this->expenses->pauseRecurrence(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/liquidites/recurrences/generer',
                $this->expenses->generateRecurrences(...)
            );
        }
        if ($this->treasury !== null) {
            $this->add($router, 'GET', '/api/v1/liquidites/banque', $this->treasury->show(...));
            $this->add($router, 'POST', '/api/v1/liquidites/banque/imports/previsualiser', $this->treasury->previewImport(...));
            $this->add($router, 'POST', '/api/v1/liquidites/banque/imports/confirmer', $this->treasury->confirmImport(...));
            $this->add($router, 'POST', '/api/v1/liquidites/banque/rapprochements', $this->treasury->reconcile(...));
            $this->add($router, 'POST', '/api/v1/liquidites/banque/rapprochements/annuler', $this->treasury->cancelReconciliation(...));
            $this->add($router, 'POST', '/api/v1/liquidites/banque/suggestions', $this->treasury->proposeSuggestion(...));
            $this->add($router, 'POST', '/api/v1/liquidites/banque/suggestions/accepter', $this->treasury->acceptSuggestion(...));
            $this->add($router, 'POST', '/api/v1/liquidites/lettrage/paiements', $this->treasury->createPayment(...));
            $this->add($router, 'POST', '/api/v1/liquidites/lettrage/allocations', $this->treasury->allocate(...));
            $this->add($router, 'POST', '/api/v1/liquidites/lettrage/allocations/annuler', $this->treasury->unallocate(...));
            $this->add($router, 'POST', '/api/v1/liquidites/paiements/lots', $this->treasury->prepareBatch(...));
            $this->add($router, 'POST', '/api/v1/liquidites/paiements/lots/exporter', $this->treasury->exportBatch(...));
            $this->add($router, 'POST', '/api/v1/liquidites/paiements/lots/confirmer', $this->treasury->confirmBatch(...));
        }
        if ($this->billing !== null) {
            $this->add($router, 'GET', '/api/v1/facturation', $this->billing->show(...));
            $this->add($router, 'GET', '/api/v1/facturation/export', $this->billing->export(...));
            $this->add($router, 'POST', '/api/v1/facturation/documents', $this->billing->createDocument(...));
            $this->add($router, 'POST', '/api/v1/facturation/documents/emettre', $this->billing->issueDocument(...));
            $this->add($router, 'POST', '/api/v1/facturation/documents/comptabiliser', $this->billing->postDocument(...));
            $this->add($router, 'POST', '/api/v1/facturation/documents/avoirs', $this->billing->createCredit(...));
            $this->add($router, 'POST', '/api/v1/facturation/documents/pdf', $this->billing->archivePdf(...));
            $this->add($router, 'POST', '/api/v1/facturation/contacts', $this->billing->createContact(...));
            $this->add($router, 'POST', '/api/v1/facturation/contacts/modifier', $this->billing->updateContact(...));
            $this->add($router, 'POST', '/api/v1/facturation/recurrences', $this->billing->createRecurrence(...));
            $this->add($router, 'POST', '/api/v1/facturation/recurrences/pause', $this->billing->pauseRecurrence(...));
            $this->add($router, 'POST', '/api/v1/facturation/recurrences/generer', $this->billing->generateRecurrences(...));
            $this->add($router, 'POST', '/api/v1/facturation/rappels', $this->billing->createReminder(...));
            $this->add($router, 'POST', '/api/v1/facturation/paiements', $this->billing->createPayment(...));
            $this->add($router, 'POST', '/api/v1/facturation/allocations', $this->billing->allocatePayment(...));
            $this->add($router, 'POST', '/api/v1/facturation/allocations/avoirs', $this->billing->allocateCredit(...));
            $this->add($router, 'POST', '/api/v1/facturation/allocations/annuler', $this->billing->unallocate(...));
        }
    }

    /** @param callable(Request):Response $handler */
    private function add(
        Router $router,
        string $method,
        string $path,
        callable $handler,
    ): void {
        $router->add($method, $path, function (Request $request) use ($method, $handler): Response {
            try {
                $contract = $request->header('X-Contract-Version');
                if ($contract !== null && $contract !== ApiResponse::CONTRACT_VERSION) {
                    throw ApiException::conflict(
                        'CONTRACT_VERSION_UNSUPPORTED',
                        'Version de contrat API non prise en charge.'
                    );
                }
                if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                    $token = $request->header('X-CSRF-Token')
                        ?? (is_string($request->input()['_csrf'] ?? null)
                            ? $request->input()['_csrf']
                            : null);
                    if (!$this->csrf->validate($token)) {
                        throw new ApiException(
                            403,
                            'CSRF_INVALID',
                            'Jeton CSRF invalide.'
                        );
                    }
                }
                return $handler($request);
            } catch (ApiException $exception) {
                return ApiResponse::failure($request, $exception);
            } catch (Throwable $exception) {
                $response = ApiResponse::failure(
                    $request,
                    new ApiException(500, 'INTERNAL_ERROR', 'Erreur interne.')
                );
                error_log(sprintf(
                    '[COMPTA API %s] %s: %s',
                    $response->headers['X-Correlation-ID'],
                    $exception::class,
                    $exception->getMessage()
                ));
                return $response;
            }
        });
    }
}
