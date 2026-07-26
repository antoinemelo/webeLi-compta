<?php
declare(strict_types=1);

use Compta\Core\Audit\AuditLogger;
use Compta\Core\Auth\AccessControl;
use Compta\Core\Auth\AuthService;
use Compta\Core\Auth\LoginThrottle;
use Compta\Core\Auth\UserRepository;
use Compta\Core\Config\AppConfig;
use Compta\Core\Database\ConnectionFactory;
use Compta\Core\Http\Api\ApiRouteRegistry;
use Compta\Core\Http\View;
use Compta\Core\Http\WebApplication;
use Compta\Core\Http\VueShellRenderer;
use Compta\Core\Security\Csrf;
use Compta\Core\Security\NativeSessionStore;
use Compta\Modules\Compta\ChartOfAccountsService;
use Compta\Modules\Compta\AccountingApiController;
use Compta\Modules\Compta\AccountingInputValidator;
use Compta\Modules\Compta\AccountingSetupService;
use Compta\Modules\Compta\AccountingWorkspaceService;
use Compta\Modules\Compta\ClosingAndTaxService;
use Compta\Modules\Compta\EntryService;
use Compta\Modules\Compta\FinancialReportingService;
use Compta\Modules\Compta\ReportingService;
use Compta\Modules\Consolidation\ConsolidationApiController;
use Compta\Modules\Consolidation\ConsolidationInputValidator;
use Compta\Modules\Consolidation\ConsolidationService;
use Compta\Modules\Configuration\Application\ConfigurationService;
use Compta\Modules\Configuration\Application\ManagedReferencesService;
use Compta\Modules\Configuration\Application\ModuleAccessService;
use Compta\Modules\Configuration\Http\ConfigurationApiController;
use Compta\Modules\Configuration\Http\ConfigurationInputValidator;
use Compta\Modules\Dashboard\Application\DashboardReadService;
use Compta\Modules\Dashboard\Http\DashboardApiController;
use Compta\Modules\Dashboard\Http\DashboardInputValidator;
use Compta\Modules\Facturation\BillingService;
use Compta\Modules\Facturation\BillingWorkspaceService;
use Compta\Modules\Facturation\ContactService;
use Compta\Modules\Facturation\AttachmentService;
use Compta\Modules\Facturation\InvoicePdfService;
use Compta\Modules\Facturation\PaymentService;
use Compta\Modules\Facturation\RecurringBillingService;
use Compta\Modules\Facturation\Http\BillingApiController;
use Compta\Modules\Devises\ExchangeRevaluationService;
use Compta\Modules\Facturation\Http\BillingInputValidator;
use Compta\Modules\Immobilisations\AssetApiController;
use Compta\Modules\Immobilisations\AssetInputValidator;
use Compta\Modules\Immobilisations\AssetService;
use Compta\Modules\Salaires\PayrollCertificateService;
use Compta\Modules\Salaires\PayrollConfigurationService;
use Compta\Modules\Salaires\OcasRateImportService;
use Compta\Modules\Salaires\PayrollApiController;
use Compta\Modules\Salaires\PayrollInputValidator;
use Compta\Modules\Salaires\PayrollImportService;
use Compta\Modules\Salaires\PayrollPaymentService;
use Compta\Modules\Salaires\PayrollService;
use Compta\Modules\Salaires\PayrollWorkspaceService;
use Compta\Modules\Pedagogie\PedagogyService;
use Compta\Modules\Pedagogie\PedagogyApiController;
use Compta\Modules\Pedagogie\PedagogyInputValidator;
use Compta\Modules\Shell\Application\ShellReadService;
use Compta\Modules\Shell\Http\ShellApiController;
use Compta\Modules\Shell\Http\ShellInputValidator;
use Compta\Modules\Shell\Http\ShellPageController;
use Compta\Modules\Tva\VatConfigurationService;
use Compta\Modules\Tva\Ech0217ExportService;
use Compta\Modules\Tva\Ech0217Validator;
use Compta\Modules\Tva\VatStatementService;
use Compta\Modules\Tva\VatWorkspaceService;
use Compta\Modules\Tresorerie\TreasuryAccountService;
use Compta\Modules\Tresorerie\BankImportService;
use Compta\Modules\Tresorerie\OutgoingPaymentService;
use Compta\Modules\Tresorerie\Pain001Generator;
use Compta\Modules\Tresorerie\PublicMarketDataService;
use Compta\Modules\Tresorerie\PublicMarketHttpClient;
use Compta\Modules\Tresorerie\ReconciliationService;
use Compta\Modules\Tresorerie\SuggestionService;
use Compta\Modules\Tresorerie\TreasuryWorkspaceService;
use Compta\Modules\Tresorerie\ExpenseService;
use Compta\Modules\Tresorerie\Http\ExpenseApiController;
use Compta\Modules\Tresorerie\Http\ExpenseInputValidator;
use Compta\Modules\Tresorerie\Http\TreasuryApiController;
use Compta\Modules\Tresorerie\Http\TreasuryInputValidator;

require __DIR__ . '/autoload.php';

$root = dirname(__DIR__);
$config = AppConfig::load($root);
$pdo = ConnectionFactory::sqlite($config->string('database_path'));
$session = new NativeSessionStore($config);
$session->start();
$users = new UserRepository($pdo);
$audit = new AuditLogger($pdo);
$entries = new EntryService($pdo, $audit);
$payrolls = new PayrollService($pdo, $audit, $entries);
$csrf = new Csrf($session);
$auth = new AuthService(
    $users,
    new LoginThrottle(
        $pdo,
        $config->int('login_max_attempts'),
        $config->int('login_window_seconds')
    ),
    $audit,
    $session
);
$access = new AccessControl($pdo);
$reports = new ReportingService($pdo);
$chart = new ChartOfAccountsService($pdo, $audit);
$contacts = new ContactService($pdo, $audit);
$payments = new PaymentService($pdo, $audit, $entries);
$billing = new BillingService($pdo, $audit, $entries);
$recurringBilling = new RecurringBillingService($pdo, $audit, $billing);
$reconciliations = new ReconciliationService($pdo, $audit);
$payrollConfiguration = new PayrollConfigurationService($pdo, $audit);
$payrollPayments = new PayrollPaymentService($pdo, $audit, $entries);
$payrollCertificates = new PayrollCertificateService($pdo, $audit);
$vatConfiguration = new VatConfigurationService($pdo, $audit);
$treasuryAccounts = new TreasuryAccountService($pdo, $audit);
$accountingSetup = new AccountingSetupService($pdo, $audit);
$financialReports = new FinancialReportingService($pdo, $reports);
$vatWorkspace = new VatWorkspaceService(
    $pdo,
    new VatStatementService($pdo, $audit),
    new Ech0217ExportService(
        $pdo,
        $audit,
        new Ech0217Validator(
            $root . '/resources/xsd/ech-0217-2-0-0-current-profile.xsd'
        ),
        trim((string) file_get_contents($root . '/VERSION'))
    )
);
$closingAndTax = new ClosingAndTaxService(
    $pdo,
    $audit,
    $accountingSetup,
    $financialReports
);
$moduleAccess = new ModuleAccessService($pdo);
$configuration = new ConfigurationService($pdo, $audit, $moduleAccess);
$pedagogy = new PedagogyService($pdo, $audit, $entries);
$apiRoutes = new ApiRouteRegistry(
    new ShellApiController(
        $config,
        $session,
        $csrf,
        $auth,
        $access,
        $audit,
        new ShellReadService($pdo, $moduleAccess),
        new ShellInputValidator()
    ),
    new DashboardApiController(
        $session,
        $auth,
        $access,
        new DashboardReadService($pdo, $reports),
        new DashboardInputValidator()
    ),
    $csrf,
    new ConfigurationApiController(
        $session,
        $auth,
        $access,
        $configuration,
        new ConfigurationInputValidator(),
        new ManagedReferencesService(
            $pdo,
            $contacts,
            $vatConfiguration,
            $payrollConfiguration,
            $treasuryAccounts,
            $accountingSetup,
            $audit
        )
    ),
    new AccountingApiController(
        $session,
        $auth,
        $access,
        new AccountingWorkspaceService(
            $chart,
            $entries,
            $reports,
            $financialReports,
            $vatWorkspace,
            $closingAndTax,
            new ExchangeRevaluationService($pdo, $audit, $entries)
        ),
        new AccountingInputValidator(),
        $audit
    ),
    new ExpenseApiController(
        $session,
        $auth,
        $access,
        new ExpenseService($pdo, $audit, $entries),
        new AttachmentService($pdo, $audit),
        new ExpenseInputValidator()
    ),
    new TreasuryApiController(
        $session,
        $auth,
        $access,
        new TreasuryWorkspaceService($pdo, $payments, $entries),
        new BankImportService($pdo, $audit),
        $reconciliations,
        new SuggestionService($pdo, $audit, $entries),
        $payments,
        new OutgoingPaymentService(
            $pdo,
            $audit,
            $entries,
            $payments,
            $reconciliations,
            new Pain001Generator()
        ),
        new PublicMarketDataService($pdo, new PublicMarketHttpClient()),
        new TreasuryInputValidator()
    ),
    new BillingApiController(
        $session,
        $auth,
        $access,
        new BillingWorkspaceService($pdo, $billing, $payments, $contacts),
        $billing,
        $contacts,
        $payments,
        $recurringBilling,
        new InvoicePdfService($pdo, $audit),
        new AttachmentService($pdo, $audit),
        new BillingInputValidator()
    ),
    new AssetApiController(
        $session,
        $auth,
        $access,
        new AssetService($pdo, $audit, $entries),
        new AssetInputValidator()
    ),
    new PayrollApiController(
        $session,
        $auth,
        $access,
        new PayrollWorkspaceService(
            $payrollConfiguration,
            $payrolls,
            $payrollPayments,
            $payrollCertificates
        ),
        $payrollConfiguration,
        $payrolls,
        $payrollPayments,
        $payrollCertificates,
        new OcasRateImportService(
            $config->string('ocas_database_path'),
            $payrollConfiguration,
            $audit
        ),
        new PayrollInputValidator()
    ),
    new PedagogyApiController(
        $session,
        $auth,
        $access,
        $pedagogy,
        new PedagogyInputValidator()
    ),
    new ConsolidationApiController(
        $session,
        $auth,
        $access,
        new ConsolidationService($pdo, $audit),
        new ConsolidationInputValidator()
    )
);
$shellPage = new ShellPageController(
    $config,
    $auth,
    new VueShellRenderer($root, $config)
);

return [
    'config' => $config,
    'pdo' => $pdo,
    'session' => $session,
    'web' => new WebApplication(
        $config,
        new View($root . '/templates', $config),
        $session,
        $csrf,
        $auth,
        $access,
        $audit,
        $reports,
        $contacts,
        $billing,
        $payments,
        new InvoicePdfService($pdo, $audit),
        new AttachmentService($pdo, $audit),
        $payrollConfiguration,
        $payrolls,
        $payrollPayments,
        $payrollCertificates,
        new PayrollImportService($pdo, $audit, $payrolls),
        $pedagogy,
        $apiRoutes,
        $shellPage,
        $moduleAccess
    ),
];
