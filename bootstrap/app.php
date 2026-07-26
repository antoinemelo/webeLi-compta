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
use Compta\Modules\Compta\EntryService;
use Compta\Modules\Compta\ReportingService;
use Compta\Modules\Configuration\Application\ConfigurationService;
use Compta\Modules\Configuration\Application\ManagedReferencesService;
use Compta\Modules\Configuration\Application\ModuleAccessService;
use Compta\Modules\Configuration\Http\ConfigurationApiController;
use Compta\Modules\Configuration\Http\ConfigurationInputValidator;
use Compta\Modules\Dashboard\Application\DashboardReadService;
use Compta\Modules\Dashboard\Http\DashboardApiController;
use Compta\Modules\Dashboard\Http\DashboardInputValidator;
use Compta\Modules\Facturation\BillingService;
use Compta\Modules\Facturation\ContactService;
use Compta\Modules\Facturation\AttachmentService;
use Compta\Modules\Facturation\InvoicePdfService;
use Compta\Modules\Facturation\PaymentService;
use Compta\Modules\Salaires\PayrollCertificateService;
use Compta\Modules\Salaires\PayrollConfigurationService;
use Compta\Modules\Salaires\PayrollImportService;
use Compta\Modules\Salaires\PayrollPaymentService;
use Compta\Modules\Salaires\PayrollService;
use Compta\Modules\Pedagogie\PedagogyService;
use Compta\Modules\Shell\Application\ShellReadService;
use Compta\Modules\Shell\Http\ShellApiController;
use Compta\Modules\Shell\Http\ShellInputValidator;
use Compta\Modules\Shell\Http\ShellPageController;
use Compta\Modules\Tva\VatConfigurationService;
use Compta\Modules\Tresorerie\TreasuryAccountService;

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
$payrollConfiguration = new PayrollConfigurationService($pdo, $audit);
$vatConfiguration = new VatConfigurationService($pdo, $audit);
$treasuryAccounts = new TreasuryAccountService($pdo, $audit);
$accountingSetup = new AccountingSetupService($pdo, $audit);
$moduleAccess = new ModuleAccessService($pdo);
$configuration = new ConfigurationService($pdo, $audit, $moduleAccess);
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
        new AccountingWorkspaceService($chart, $entries, $reports),
        new AccountingInputValidator()
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
        new BillingService($pdo, $audit, $entries),
        new PaymentService($pdo, $audit, $entries),
        new InvoicePdfService($pdo, $audit),
        new AttachmentService($pdo, $audit),
        $payrollConfiguration,
        $payrolls,
        new PayrollPaymentService($pdo, $audit, $entries),
        new PayrollCertificateService($pdo, $audit),
        new PayrollImportService($pdo, $audit, $payrolls),
        new PedagogyService($pdo, $audit, $entries),
        $apiRoutes,
        $shellPage,
        $moduleAccess
    ),
];
