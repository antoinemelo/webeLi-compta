<?php
declare(strict_types=1);

namespace Compta\Core\Http\Api;

use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Compta\Core\Http\Router;
use Compta\Core\Security\Csrf;
use Compta\Modules\Compta\AccountingApiController;
use Compta\Modules\Consolidation\ConsolidationApiController;
use Compta\Modules\Configuration\Http\ConfigurationApiController;
use Compta\Modules\Dashboard\Http\DashboardApiController;
use Compta\Modules\Dossiers\Http\OrganisationApiController;
use Compta\Modules\Dossiers\Http\DossierApiController;
use Compta\Modules\Dossiers\Http\StructureAccessApiController;
use Compta\Modules\Facturation\Http\BillingApiController;
use Compta\Modules\Immobilisations\AssetApiController;
use Compta\Modules\Pedagogie\PedagogyApiController;
use Compta\Modules\Salaires\PayrollApiController;
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
        private readonly ?AssetApiController $assets = null,
        private readonly ?PayrollApiController $payroll = null,
        private readonly ?PedagogyApiController $pedagogy = null,
        private readonly ?ConsolidationApiController $consolidation = null,
        private readonly ?OrganisationApiController $organisations = null,
        private readonly ?DossierApiController $dossiers = null,
        private readonly ?StructureAccessApiController $structureAccess = null,
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
        if ($this->organisations !== null) {
            $this->add(
                $router, 'GET', '/api/v1/structures/organisations',
                $this->organisations->list(...)
            );
            $this->add(
                $router, 'GET', '/api/v1/structures/organisations/detail',
                $this->organisations->detail(...)
            );
            $this->add(
                $router, 'POST', '/api/v1/structures/organisations',
                $this->organisations->create(...)
            );
            $this->add(
                $router, 'POST', '/api/v1/structures/organisations/update',
                $this->organisations->update(...)
            );
            $this->add(
                $router, 'POST', '/api/v1/structures/organisations/legal-identities',
                $this->organisations->saveLegalIdentity(...)
            );
            $this->add(
                $router, 'POST', '/api/v1/structures/organisations/archive',
                $this->organisations->archive(...)
            );
            $this->add(
                $router, 'POST', '/api/v1/structures/organisations/reactivate',
                $this->organisations->reactivate(...)
            );
            $this->add(
                $router, 'POST', '/api/v1/structures/organisations/delete',
                $this->organisations->delete(...)
            );
        }
        if ($this->dossiers !== null) {
            $this->add($router, 'GET', '/api/v1/structures/dossiers', $this->dossiers->list(...));
            $this->add($router, 'GET', '/api/v1/structures/dossiers/detail', $this->dossiers->detail(...));
            $this->add($router, 'POST', '/api/v1/structures/dossiers', $this->dossiers->create(...));
            $this->add($router, 'POST', '/api/v1/structures/dossiers/update', $this->dossiers->update(...));
            $this->add($router, 'POST', '/api/v1/structures/dossiers/archive', $this->dossiers->archive(...));
            $this->add($router, 'POST', '/api/v1/structures/dossiers/reactivate', $this->dossiers->reactivate(...));
            $this->add($router, 'POST', '/api/v1/structures/dossiers/delete', $this->dossiers->delete(...));
        }
        if ($this->structureAccess !== null) {
            $this->add($router, 'GET', '/api/v1/structures/access', $this->structureAccess->matrix(...));
            $this->add($router, 'POST', '/api/v1/structures/access/preview', $this->structureAccess->preview(...));
            $this->add($router, 'POST', '/api/v1/structures/access/apply', $this->structureAccess->apply(...));
            $this->add($router, 'POST', '/api/v1/structures/access/copy-preview', $this->structureAccess->copyPreview(...));
        }
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
                '/api/v1/configuration/references/contacts/delete',
                $this->configuration->deleteContact(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/configuration/references/currencies',
                $this->configuration->saveCurrency(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/configuration/references/exchange-rates',
                $this->configuration->saveExchangeRate(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/configuration/references/exchange-mapping',
                $this->configuration->saveExchangeMapping(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/configuration/references/vat-codes',
                $this->configuration->saveVatCode(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/configuration/references/vat-codes/delete',
                $this->configuration->deleteVatCode(...)
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
                '/api/v1/configuration/payroll/employer',
                $this->configuration->savePayrollEmployerSettings(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/configuration/payroll/mapping',
                $this->configuration->savePayrollMappingSettings(...)
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
                '/api/v1/accounting/exchange-revaluations',
                $this->accounting->postExchangeRevaluation(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/accounting/exchange-revaluations/reverse',
                $this->accounting->reverseExchangeRevaluation(...)
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
                'GET',
                '/api/v1/accounting/chart/export',
                $this->accounting->exportChart(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/accounting/chart/import/preview',
                $this->accounting->previewChartImport(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/accounting/chart/import',
                $this->accounting->importChart(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/accounting/chart/reset/preview',
                $this->accounting->previewChartReset(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/accounting/chart/reset',
                $this->accounting->resetChart(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/accounting/opening',
                $this->accounting->saveOpening(...)
            );
            $this->add($router, 'GET', '/api/v1/accounting/opening/export', $this->accounting->exportOpening(...));
            $this->add($router, 'POST', '/api/v1/accounting/opening/import/preview', $this->accounting->previewOpeningImport(...));
            $this->add($router, 'POST', '/api/v1/accounting/opening/import', $this->accounting->importOpening(...));
            $this->add($router, 'GET', '/api/v1/accounting/journal/details', $this->accounting->journalDetails(...));
            $this->add($router, 'GET', '/api/v1/accounting/journal/export', $this->accounting->exportJournal(...));
            $this->add($router, 'POST', '/api/v1/accounting/journal/import/preview', $this->accounting->previewJournalImport(...));
            $this->add($router, 'POST', '/api/v1/accounting/journal/import', $this->accounting->importJournal(...));
            $this->add($router, 'GET', '/api/v1/accounting/reports/export', $this->accounting->exportReport(...));
            $this->add($router, 'POST', '/api/v1/accounting/vat/periods', $this->accounting->createVatPeriod(...));
            $this->add($router, 'POST', '/api/v1/accounting/vat/statements/prepare', $this->accounting->prepareVatStatement(...));
            $this->add($router, 'POST', '/api/v1/accounting/vat/statements/control', $this->accounting->controlVatStatement(...));
            $this->add($router, 'POST', '/api/v1/accounting/vat/statements/export', $this->accounting->exportVatStatement(...));
            $this->add($router, 'POST', '/api/v1/accounting/vat/statements/declare', $this->accounting->declareVatStatement(...));
            $this->add($router, 'GET', '/api/v1/accounting/vat/exports/download', $this->accounting->downloadVatExport(...));
            $this->add($router, 'POST', '/api/v1/accounting/closing/controls', $this->accounting->saveClosingControl(...));
            $this->add($router, 'POST', '/api/v1/accounting/closing/periods', $this->accounting->setPeriodStatus(...));
            $this->add($router, 'POST', '/api/v1/accounting/tax-file/adjustments', $this->accounting->createTaxAdjustment(...));
            $this->add($router, 'POST', '/api/v1/accounting/tax-file/adjustments/status', $this->accounting->setTaxAdjustmentStatus(...));
            $this->add($router, 'POST', '/api/v1/accounting/archives', $this->accounting->archive(...));
            $this->add($router, 'GET', '/api/v1/accounting/archives/download', $this->accounting->downloadArchive(...));
        }
        if ($this->consolidation !== null) {
            $this->add($router, 'GET', '/api/v1/consolidation', $this->consolidation->show(...));
            $this->add($router, 'GET', '/api/v1/consolidation/export', $this->consolidation->export(...));
            $this->add($router, 'POST', '/api/v1/consolidation/groups', $this->consolidation->createGroup(...));
            $this->add($router, 'POST', '/api/v1/consolidation/groups/update', $this->consolidation->updateGroup(...));
            $this->add($router, 'POST', '/api/v1/consolidation/groups/activate', $this->consolidation->activateGroup(...));
            $this->add($router, 'POST', '/api/v1/consolidation/groups/archive', $this->consolidation->archiveGroup(...));
            $this->add($router, 'POST', '/api/v1/consolidation/groups/reactivate', $this->consolidation->reactivateGroup(...));
            $this->add($router, 'POST', '/api/v1/consolidation/groups/members', $this->consolidation->addMember(...));
            $this->add($router, 'POST', '/api/v1/consolidation/groups/members/remove', $this->consolidation->removeMember(...));
            $this->add($router, 'POST', '/api/v1/consolidation/legal-attributes', $this->consolidation->saveLegalAttributes(...));
            $this->add($router, 'POST', '/api/v1/consolidation/periods', $this->consolidation->createPeriod(...));
            $this->add($router, 'POST', '/api/v1/consolidation/periods/close', $this->consolidation->closePeriod(...));
            $this->add($router, 'POST', '/api/v1/consolidation/mappings', $this->consolidation->saveMapping(...));
            $this->add($router, 'POST', '/api/v1/consolidation/mappings/disable', $this->consolidation->disableMapping(...));
            $this->add($router, 'POST', '/api/v1/consolidation/intercompany-pairs', $this->consolidation->savePair(...));
            $this->add($router, 'POST', '/api/v1/consolidation/intercompany-pairs/disable', $this->consolidation->disablePair(...));
            $this->add($router, 'POST', '/api/v1/consolidation/eliminations', $this->consolidation->createElimination(...));
        }
        if ($this->assets !== null) {
            $this->add(
                $router,
                'GET',
                '/api/v1/accounting/assets',
                $this->assets->show(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/accounting/assets/categories',
                $this->assets->saveCategory(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/accounting/assets/records',
                $this->assets->saveAsset(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/accounting/assets/depreciations',
                $this->assets->postDepreciation(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/accounting/assets/depreciations/reverse',
                $this->assets->reverseDepreciation(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/accounting/assets/disposals',
                $this->assets->dispose(...)
            );
            $this->add(
                $router,
                'POST',
                '/api/v1/accounting/assets/disposals/reverse',
                $this->assets->reverseDisposal(...)
            );
        }
        if ($this->payroll !== null) {
            $this->add($router, 'GET', '/api/v1/salaires', $this->payroll->show(...));
            $this->add($router, 'POST', '/api/v1/salaires/employeur', $this->payroll->saveEmployer(...));
            $this->add($router, 'POST', '/api/v1/salaires/mapping', $this->payroll->saveMapping(...));
            $this->add($router, 'POST', '/api/v1/salaires/employes', $this->payroll->createEmployee(...));
            $this->add($router, 'POST', '/api/v1/salaires/employes/supprimer', $this->payroll->deleteEmployee(...));
            $this->add($router, 'POST', '/api/v1/salaires/contrats', $this->payroll->saveContract(...));
            $this->add($router, 'POST', '/api/v1/salaires/contrats/supprimer', $this->payroll->deleteContract(...));
            $this->add($router, 'POST', '/api/v1/salaires/fiches', $this->payroll->createDraft(...));
            $this->add($router, 'POST', '/api/v1/salaires/fiches/brouillon/supprimer', $this->payroll->deleteDraft(...));
            $this->add($router, 'POST', '/api/v1/salaires/fiches/valider', $this->payroll->validate(...));
            $this->add($router, 'POST', '/api/v1/salaires/fiches/comptabiliser', $this->payroll->post(...));
            $this->add($router, 'POST', '/api/v1/salaires/fiches/annuler', $this->payroll->cancel(...));
            $this->add($router, 'POST', '/api/v1/salaires/paiements', $this->payroll->createPayment(...));
            $this->add($router, 'POST', '/api/v1/salaires/allocations', $this->payroll->allocate(...));
            $this->add($router, 'POST', '/api/v1/salaires/paiements/comptabiliser', $this->payroll->postPayment(...));
            $this->add($router, 'POST', '/api/v1/salaires/taux-ocas/previsualiser', $this->payroll->previewOcas(...));
            $this->add($router, 'POST', '/api/v1/salaires/taux-ocas/confirmer', $this->payroll->confirmOcas(...));
            $this->add($router, 'POST', '/api/v1/salaires/certificats/preparer', $this->payroll->prepareCertificate(...));
            $this->add($router, 'POST', '/api/v1/salaires/certificats/controler', $this->payroll->controlCertificate(...));
            $this->add($router, 'GET', '/api/v1/salaires/certificats/exporter', $this->payroll->exportCertificate(...));
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
            $this->add($router, 'GET', '/api/v1/liquidites/taux-change', $this->treasury->exchangeRates(...));
            $this->add($router, 'GET', '/api/v1/liquidites/taux-interet', $this->treasury->interestRates(...));
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
        if ($this->pedagogy !== null) {
            $this->add($router, 'GET', '/api/v1/pedagogie', $this->pedagogy->show(...));
            $this->add($router, 'POST', '/api/v1/pedagogie/catalogue/installer', $this->pedagogy->installCatalog(...));
            $this->add($router, 'POST', '/api/v1/pedagogie/modeles', $this->pedagogy->createModel(...));
            $this->add($router, 'POST', '/api/v1/pedagogie/tentatives', $this->pedagogy->attempt(...));
            $this->add($router, 'POST', '/api/v1/pedagogie/indices', $this->pedagogy->hint(...));
            $this->add($router, 'GET', '/api/v1/pedagogie/correction', $this->pedagogy->correction(...));
            $this->add($router, 'POST', '/api/v1/pedagogie/correction/autoriser', $this->pedagogy->authorize(...));
            $this->add($router, 'POST', '/api/v1/pedagogie/reinitialiser', $this->pedagogy->reset(...));
            $this->add($router, 'POST', '/api/v1/pedagogie/groupes', $this->pedagogy->createGroup(...));
            $this->add($router, 'POST', '/api/v1/pedagogie/groupes/membres', $this->pedagogy->addMember(...));
            $this->add($router, 'POST', '/api/v1/pedagogie/assignations', $this->pedagogy->assign(...));
            $this->add($router, 'GET', '/api/v1/pedagogie/export', $this->pedagogy->export(...));
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
