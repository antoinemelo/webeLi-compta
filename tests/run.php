<?php
declare(strict_types=1);

use Compta\Core\Audit\AuditLogger;
use Compta\Core\Auth\AccessControl;
use Compta\Core\Auth\AuthService;
use Compta\Core\Auth\LoginThrottle;
use Compta\Core\Auth\UserRepository;
use Compta\Core\Config\AppConfig;
use Compta\Core\Database\BackupService;
use Compta\Core\Database\ConnectionFactory;
use Compta\Core\Database\IntegrityChecker;
use Compta\Core\Database\MigrationRunner;
use Compta\Core\Diagnostics\Doctor;
use Compta\Core\Http\Api\ApiRouteRegistry;
use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Compta\Core\Http\View;
use Compta\Core\Http\WebApplication;
use Compta\Core\Http\VueShellRenderer;
use Compta\Core\Security\ArraySessionStore;
use Compta\Core\Security\Csrf;
use Compta\Core\Support\Html;
use Compta\Modules\Compta\AccountingSetupService;
use Compta\Modules\Compta\AccountingCsvService;
use Compta\Modules\Compta\AccountingApiController;
use Compta\Modules\Compta\AccountingInputValidator;
use Compta\Modules\Compta\AccountingWorkspaceService;
use Compta\Modules\Compta\ChartOfAccountsService;
use Compta\Modules\Compta\ClosingAndTaxService;
use Compta\Modules\Compta\EntryService;
use Compta\Modules\Compta\FinancialReportingService;
use Compta\Modules\Compta\PlanSeeder;
use Compta\Modules\Compta\ReportingService;
use Compta\Modules\Consolidation\ConsolidationApiController;
use Compta\Modules\Consolidation\ConsolidationInputValidator;
use Compta\Modules\Consolidation\ConsolidationService;
use Compta\Modules\Configuration\Application\ConfigurationService;
use Compta\Modules\Configuration\Application\ManagedReferencesService;
use Compta\Modules\Configuration\Application\ModuleAccessService;
use Compta\Modules\Configuration\Application\PaymentTermsService;
use Compta\Modules\Configuration\Http\ConfigurationApiController;
use Compta\Modules\Configuration\Http\ConfigurationInputValidator;
use Compta\Modules\Dashboard\Application\DashboardReadService;
use Compta\Modules\Dashboard\Http\DashboardApiController;
use Compta\Modules\Dashboard\Http\DashboardInputValidator;
use Compta\Modules\Dossiers\ScopeManager;
use Compta\Modules\Dossiers\DossierRegistryException;
use Compta\Modules\Dossiers\DossierRegistryService;
use Compta\Modules\Dossiers\OrganisationRegistryException;
use Compta\Modules\Dossiers\OrganisationRegistryService;
use Compta\Modules\Dossiers\StructureAccessService;
use Compta\Modules\Dossiers\Http\OrganisationApiController;
use Compta\Modules\Dossiers\Http\OrganisationInputValidator;
use Compta\Modules\Dossiers\Http\DossierApiController;
use Compta\Modules\Dossiers\Http\DossierInputValidator;
use Compta\Modules\Dossiers\Http\StructureAccessApiController;
use Compta\Modules\Dossiers\Http\StructureAccessInputValidator;
use Compta\Modules\Facturation\BillingService;
use Compta\Modules\Facturation\BillingWorkspaceService;
use Compta\Modules\Facturation\ContactService;
use Compta\Modules\Facturation\AttachmentService;
use Compta\Modules\Facturation\InvoicePdfService;
use Compta\Modules\Facturation\PaymentService;
use Compta\Modules\Facturation\RecurringBillingService;
use Compta\Modules\Facturation\Http\BillingApiController;
use Compta\Modules\Facturation\Http\BillingInputValidator;
use Compta\Modules\Facturation\ScorReference;
use Compta\Modules\Facturation\SwissQrService;
use Compta\Modules\Devises\ExchangeRateService;
use Compta\Modules\Devises\ExchangeRevaluationService;
use Compta\Modules\Immobilisations\AssetApiController;
use Compta\Modules\Immobilisations\AssetInputValidator;
use Compta\Modules\Immobilisations\AssetService;
use Compta\Modules\Tresorerie\BankImportService;
use Compta\Modules\Tresorerie\BankCoordinates;
use Compta\Modules\Tresorerie\InternalTransferService;
use Compta\Modules\Tresorerie\Parsing\Camt053Parser;
use Compta\Modules\Tresorerie\Parsing\Camt054Parser;
use Compta\Modules\Tresorerie\ReconciliationService;
use Compta\Modules\Tresorerie\SuggestionService;
use Compta\Modules\Tresorerie\OutgoingPaymentService;
use Compta\Modules\Tresorerie\Pain001Generator;
use Compta\Modules\Tresorerie\PublicMarketDataService;
use Compta\Modules\Tresorerie\PublicMarketHttpClient;
use Compta\Modules\Tresorerie\TreasuryAccountService;
use Compta\Modules\Tresorerie\TreasuryStateService;
use Compta\Modules\Tresorerie\TreasuryWorkspaceService;
use Compta\Modules\Tresorerie\ExpenseService;
use Compta\Modules\Tresorerie\ExpenseException;
use Compta\Modules\Tresorerie\Http\ExpenseApiController;
use Compta\Modules\Tresorerie\Http\ExpenseInputValidator;
use Compta\Modules\Tresorerie\Http\TreasuryApiController;
use Compta\Modules\Tresorerie\Http\TreasuryInputValidator;
use Compta\Modules\Tva\Ech0217ExportService;
use Compta\Modules\Tva\Ech0217Validator;
use Compta\Modules\Tva\DefaultVatCodeInstaller;
use Compta\Modules\Tva\VatCalculator;
use Compta\Modules\Tva\VatConfigurationService;
use Compta\Modules\Tva\VatLineService;
use Compta\Modules\Tva\VatPostingService;
use Compta\Modules\Tva\VatSettlementService;
use Compta\Modules\Tva\VatStatementService;
use Compta\Modules\Tva\VatWorkspaceService;
use Compta\Modules\Salaires\PayrollCalculator;
use Compta\Modules\Salaires\PayrollCertificateService;
use Compta\Modules\Salaires\PayrollConfigurationService;
use Compta\Modules\Salaires\OcasRateImportService;
use Compta\Modules\Salaires\PayrollApiController;
use Compta\Modules\Salaires\PayrollInputValidator;
use Compta\Modules\Salaires\PayrollImportService;
use Compta\Modules\Salaires\PayrollPaymentService;
use Compta\Modules\Salaires\PayrollService;
use Compta\Modules\Salaires\PayrollWorkspaceService;
use Compta\Modules\Pedagogie\PedagogyConflictException;
use Compta\Modules\Pedagogie\PedagogyApiController;
use Compta\Modules\Pedagogie\PedagogyInputValidator;
use Compta\Modules\Pedagogie\PedagogyService;
use Compta\Modules\Shell\Application\ShellReadService;
use Compta\Modules\Shell\Http\ShellApiController;
use Compta\Modules\Shell\Http\ShellInputValidator;
use Compta\Modules\Shell\Http\ShellPageController;

require dirname(__DIR__) . '/bootstrap/autoload.php';

final class Tests
{
    private int $assertions = 0;
    private int $failures = 0;
    /** @var list<string> */
    private array $temporaryDirectories = [];

    public function run(string $suite = 'all', string $caseFilter = ''): int
    {
        if (!in_array($suite, ['quick', 'integration', 'all'], true)) {
            fwrite(STDERR, "Suite inconnue : {$suite}\n");
            return 2;
        }
        $cases = [
            'unités configuration et sécurité' => ['quick', fn () => $this->unitTests()],
            'parité des 32 calculs salaires OCAS' => [
                'quick',
                fn () => $this->payrollCalculatorParityTests(),
            ],
            'référence stable des rapports' => [
                'integration',
                fn () => $this->baselineReportTests(),
            ],
            'salaires genevois, écritures et paiements' => [
                'integration',
                fn () => $this->payrollTests(),
            ],
            'enseignement, collaboration et isolation' => [
                'integration',
                fn () => $this->pedagogyTests(),
            ],
            'intégration migrations et SQLite' => [
                'integration',
                fn () => $this->databaseTests(),
            ],
            'authentification et isolation des scopes' => [
                'integration',
                fn () => $this->authAndScopeTests(),
            ],
            'registre des organisations et cycle de vie' => [
                'integration',
                fn () => $this->organisationRegistryTests(),
            ],
            'dossiers multiples et initialisation atomique' => [
                'integration',
                fn () => $this->dossierRegistryTests(),
            ],
            'gouvernance des accès aux structures' => [
                'integration',
                fn () => $this->structureAccessTests(),
            ],
            'comptabilité générale et rapports' => [
                'integration',
                fn () => $this->accountingTests(),
            ],
            'immobilisations et amortissements' => [
                'integration',
                fn () => $this->assetTests(),
            ],
            'trésorerie, CAMT et rapprochements' => [
                'integration',
                fn () => $this->treasuryTests(),
            ],
            'référentiel public de change et de taux d’intérêt' => [
                'integration',
                fn () => $this->publicMarketDataTests(),
            ],
            'lettrage et paiements sortants pain.001' => [
                'integration',
                fn () => $this->treasuryPaymentTests(),
            ],
            'TVA suisse effective, TDFN et eCH-0217' => [
                'integration',
                fn () => $this->vatTests(),
            ],
            'débiteurs, créanciers, paiements et QR-facture' => [
                'integration',
                fn () => $this->billingTests(),
            ],
            'multidevise, écarts de change et réévaluation' => [
                'integration',
                fn () => $this->multiCurrencyTests(),
            ],
            'multi-entités, consolidation et isolation' => [
                'integration',
                fn () => $this->consolidationTests(),
            ],
            'dépenses, approbation et récurrences' => [
                'integration',
                fn () => $this->expenseTests(),
            ],
            'projection du tableau de bord' => [
                'integration',
                fn () => $this->dashboardTests(),
            ],
            'configuration, modules et conditions de paiement' => [
                'integration',
                fn () => $this->configurationTests(),
            ],
            'HTTP et CSRF' => ['integration', fn () => $this->httpTests()],
            'diagnostic, sauvegarde et multi-instance' => [
                'integration',
                fn () => $this->operationsTests(),
            ],
        ];
        foreach ($cases as $name => [$caseSuite, $case]) {
            if ($caseFilter !== '' && !str_contains($name, $caseFilter)) {
                continue;
            }
            if ($suite !== 'all' && $suite !== $caseSuite) {
                continue;
            }
            echo "\n{$name}\n";
            try {
                $case();
            } catch (Throwable $e) {
                $this->failures++;
                echo "  ECHEC non capturé: {$e->getMessage()}\n";
            }
        }
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeTree($directory);
        }
        printf(
            "\n%s [%s] — %d assertions, %d échec(s)\n",
            $this->failures === 0 ? 'SUCCÈS' : 'ÉCHEC',
            $suite,
            $this->assertions,
            $this->failures
        );
        return $this->failures === 0 ? 0 : 1;
    }

    private function baselineReportTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $audit = new AuditLogger($pdo);
        $scope = new ScopeManager($pdo, $audit);
        $organisationId = $scope->createOrganisation('Référence', 'reelle');
        $dossierId = $scope->createDossier(
            $organisationId,
            'Référence rapports',
            'reference-rapports',
            'reel'
        );
        $exerciseId = $scope->createExercise(
            $dossierId,
            'Exercice 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $setup = new AccountingSetupService($pdo, $audit);
        $setup->createPeriod(
            $organisationId,
            $dossierId,
            $exerciseId,
            'Année 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $journalId = $setup->createJournal(
            $organisationId,
            $dossierId,
            'OD',
            'Opérations diverses'
        );
        $openingJournalId = $setup->createJournal(
            $organisationId,
            $dossierId,
            'OUV',
            'Ouverture',
            'ouverture'
        );
        (new PlanSeeder(
            $pdo,
            dirname(__DIR__) . '/database/seeds'
        ))->installForDossier($organisationId, $dossierId, 'personne_morale');

        $bankId = $this->accountId($pdo, $dossierId, '1020');
        $capitalId = $this->accountId($pdo, $dossierId, '2800');
        $salesId = $this->accountId($pdo, $dossierId, '3400');
        $expenseId = $this->accountId($pdo, $dossierId, '6500');
        $entries = new EntryService($pdo, $audit);
        $entries->postOpeningBalances(
            $organisationId,
            $dossierId,
            $exerciseId,
            $openingJournalId,
            [
                ['compte_id' => $bankId, 'debit_centimes' => 100000],
                ['compte_id' => $capitalId, 'credit_centimes' => 100000],
            ],
            'BASELINE-OUV-2026'
        );
        foreach ([
            [
                'key' => 'sale',
                'date' => '2026-03-15',
                'label' => 'Vente de référence',
                'lines' => [
                    ['compte_id' => $bankId, 'debit_centimes' => 20000],
                    ['compte_id' => $salesId, 'credit_centimes' => 20000],
                ],
            ],
            [
                'key' => 'expense',
                'date' => '2026-04-10',
                'label' => 'Charge de référence',
                'lines' => [
                    ['compte_id' => $expenseId, 'debit_centimes' => 8000],
                    ['compte_id' => $bankId, 'credit_centimes' => 8000],
                ],
            ],
        ] as $entry) {
            $entries->postGenerated([
                'organisation_id' => $organisationId,
                'dossier_id' => $dossierId,
                'exercice_id' => $exerciseId,
                'journal_id' => $journalId,
                'date_comptable' => $entry['date'],
                'libelle' => $entry['label'],
                'source_type' => 'baseline',
                'source_id' => $entry['key'],
                'source_action' => 'reference',
                'lignes' => $entry['lines'],
            ], 'baseline:' . $entry['key']);
        }

        $reports = new ReportingService($pdo);
        $balance = $reports->trialBalance(
            $organisationId,
            $dossierId,
            $exerciseId
        );
        $income = $reports->incomeStatement(
            $organisationId,
            $dossierId,
            $exerciseId
        );
        $sheet = $reports->balanceSheet(
            $organisationId,
            $dossierId,
            $exerciseId
        );
        $generalLedger = $reports->generalLedger(
            $organisationId,
            $dossierId,
            $exerciseId
        );
        $bankLedger = array_values(array_filter(
            $generalLedger['items'],
            static fn (array $row): bool => $row['numero'] === '1020'
        ))[0];
        $actual = [
            'journal_total' => $reports->journal(
                $organisationId,
                $dossierId,
                ['statut' => 'comptabilisee']
            )['total'],
            'balance' => [
                'total_debit_centimes' => $balance['total_debit_centimes'],
                'total_credit_centimes' => $balance['total_credit_centimes'],
                'equilibree' => $balance['equilibree'],
                'comptes' => [
                    '1020' => $this->balanceFor($balance['items'], '1020'),
                    '2800' => $this->balanceFor($balance['items'], '2800'),
                    '3400' => $this->balanceFor($balance['items'], '3400'),
                    '6500' => $this->balanceFor($balance['items'], '6500'),
                ],
            ],
            'resultat' => [
                'produits_centimes' => $income['produits_centimes'],
                'charges_centimes' => $income['charges_centimes'],
                'resultat_centimes' => $income['resultat_centimes'],
            ],
            'bilan' => [
                'total_actif_centimes' => $sheet['total_actif_centimes'],
                'total_passif_centimes' => $sheet['total_passif_centimes'],
                'equilibre' => $sheet['equilibre'],
            ],
            'grand_livre_1020' => [
                'initial_centimes' => (int) $bankLedger['initial_centimes'],
                'debit_centimes' => (int) $bankLedger['debit_centimes'],
                'credit_centimes' => (int) $bankLedger['credit_centimes'],
                'solde_centimes' => (int) $bankLedger['solde_centimes'],
            ],
        ];
        $expected = json_decode(
            (string) file_get_contents(
                dirname(__DIR__) . '/tests/fixtures/baseline-reports.json'
            ),
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        $this->same(
            $expected,
            $actual,
            'rapports conformes au snapshot de référence'
        );
    }

    private function unitTests(): void
    {
        $root = dirname(__DIR__);
        $a = AppConfig::load($root, [
            'instance_id' => 'edu',
            'base_url' => '/edu/',
            'storage_path' => '/tmp/compta-test-edu',
            'database_path' => '/tmp/compta-test-edu/db.sqlite',
        ]);
        $b = AppConfig::load($root, [
            'instance_id' => 'entreprise-1',
            'base_url' => '/entreprise-1',
            'storage_path' => '/tmp/compta-test-e1',
            'database_path' => '/tmp/compta-test-e1/db.sqlite',
        ]);
        $this->same('/edu', $a->string('base_url'), 'base path normalisé');
        $this->same('/edu/test', $a->url('/test'), 'URL sous-répertoire');
        $this->true($a->sessionName() !== $b->sessionName(), 'cookies propres aux instances');
        $this->same('/edu/', $a->sessionPath(), 'path cookie propre');

        $defaults = require $root . '/config/app.php';
        $this->false(
            array_key_exists('vue_shell_enabled', $defaults),
            'interface Vue unique sans bascule vers une interface classique'
        );

        $session = new ArraySessionStore();
        $csrf = new Csrf($session);
        $token = $csrf->token();
        $this->true(strlen($token) === 64, 'jeton CSRF aléatoire');
        $this->true($csrf->validate($token), 'jeton CSRF accepté');
        $this->false($csrf->validate('incorrect'), 'jeton CSRF incorrect refusé');
        $this->same(
            '&lt;script&gt;window.compromised=true&lt;/script&gt;',
            Html::escape('<script>window.compromised=true</script>'),
            'contenu HTML utilisateur échappé contre XSS'
        );
        $_SERVER['REQUEST_URI'] = '/education/login';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = Request::fromGlobals('/edu');
        $this->same('/education/login', $request->path, 'base path ne coupe pas un préfixe voisin');
    }

    private function databaseTests(): void
    {
        [$pdo, $runner, $databasePath] = $this->database();
        $applied = $runner->apply();
        $this->same(
            ['001', '002', '003'],
            $applied,
            'base initiale, gouvernance et lien contact-employé appliqués'
        );
        $this->same([], $runner->apply(), 'rejeu idempotent');
        $this->true(IntegrityChecker::check($pdo)['ok'], 'intégrité SQLite');
        $this->same('1', (string) $pdo->query('PRAGMA foreign_keys')->fetchColumn(), 'clés étrangères actives');
        $this->same('5000', (string) $pdo->query('PRAGMA busy_timeout')->fetchColumn(), 'busy timeout');
        $this->same('wal', mb_strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()), 'WAL actif');
        $contender = ConnectionFactory::sqlite($databasePath);
        $contender->exec('PRAGMA busy_timeout = 50');
        $pdo->exec('BEGIN IMMEDIATE');
        $locked = false;
        try {
            $contender->exec('BEGIN IMMEDIATE');
        } catch (\PDOException $exception) {
            $locked = str_contains(mb_strtolower($exception->getMessage()), 'locked');
        } finally {
            $pdo->exec('ROLLBACK');
        }
        $this->true($locked, 'second écrivain refusé pendant un verrou SQLite');
        $contender->exec('BEGIN IMMEDIATE');
        $contender->exec('ROLLBACK');
        $this->true(true, 'écriture concurrente possible après libération du verrou');

        $this->same(
            3,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM schema_migrations"
            )->fetchColumn(),
            'base initiale canonique et migrations additives présentes'
        );
        $this->same(
            5,
            (int) $pdo->query('SELECT COUNT(*) FROM modules_application')
                ->fetchColumn(),
            'référentiel de modules inclus dans la base initiale'
        );
        $migrationDirectory = $this->tempDir() . '/migrations';
        mkdir($migrationDirectory, 0770, true);
        copy(dirname(__DIR__) . '/database/migrations/001_initial.sql', $migrationDirectory . '/001_initial.sql');
        $dbPath = $this->tempDir() . '/checksum.sqlite';
        $checksumPdo = ConnectionFactory::sqlite($dbPath);
        $checksumRunner = new MigrationRunner($checksumPdo, $migrationDirectory);
        $checksumRunner->apply();
        file_put_contents($migrationDirectory . '/001_initial.sql', "\n-- mutation interdite\n", FILE_APPEND);
        $statuses = array_column($checksumRunner->plan(), 'status');
        $this->true(in_array('mismatch', $statuses, true), 'checksum modifié détecté');
        $this->throws(fn () => $checksumRunner->apply(), 'migration modifiée bloquée');

        $ids = $this->seedScopes($pdo);
        (new PlanSeeder($pdo, dirname(__DIR__) . '/database/seeds'))->installForDossier(
            $ids['organisation_a'],
            $ids['dossier_a'],
            'personne_morale'
        );
        $vatCodes = new DefaultVatCodeInstaller($pdo, new AuditLogger($pdo));
        $this->same(
            10,
            $vatCodes->install($ids['organisation_a'], $ids['dossier_a']),
            'référentiel TVA suisse installé'
        );
        $this->same(
            0,
            $vatCodes->install($ids['organisation_a'], $ids['dossier_a']),
            'installation des codes TVA idempotente'
        );
        $this->same(
            10,
            (int) $pdo->query('SELECT COUNT(*) FROM tva_codes')->fetchColumn(),
            'codes TVA complets et sans doublon'
        );
        $outsideId = (int) $pdo->query(
            "SELECT id FROM tva_codes WHERE code = 'HCH'"
        )->fetchColumn();
        $vatConfiguration = new VatConfigurationService($pdo, new AuditLogger($pdo));
        $vatConfiguration->updateCode(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $outsideId,
            [
                'code' => 'HCH',
                'libelle' => 'Hors champ modifié',
                'traitement' => 'hors_champ',
                'nature' => 'non_taxable',
                'taux_legal_id' => null,
                'droit_deduction' => false,
                'deduction_defaut_bp' => 0,
                'chiffre_afc' => '221',
                'compte_tva_id' => null,
                'date_debut' => '2024-01-01',
                'date_fin' => null,
                'actif' => false,
            ]
        );
        $this->same(
            'Hors champ modifié|0',
            (string) $pdo->query(
                "SELECT libelle || '|' || actif FROM tva_codes WHERE id = {$outsideId}"
            )->fetchColumn(),
            'code TVA modifiable et désactivable'
        );
        $vatConfiguration->deleteCode(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $outsideId
        );
        $this->same(
            9,
            (int) $pdo->query('SELECT COUNT(*) FROM tva_codes')->fetchColumn(),
            'code TVA inutilisé supprimable'
        );
    }

    private function payrollCalculatorParityTests(): void
    {
        $calculator = new PayrollCalculator();
        $rates = [
            'avs_ppm' => 53000,
            'ac_ppm' => 10600,
            'amat_ppm' => 290,
            'laa_ppm' => 10600,
            'lpp_ppm' => 70000,
            'emp_avs_ppm' => 53000,
            'emp_ac_ppm' => 11000,
            'emp_amat_ppm' => 290,
            'emp_af_ppm' => 23400,
            'emp_laa_ppm' => 0,
            'emp_frais_ppm' => 0,
            'emp_lpp_ppm' => 70000,
        ];
        $employee = [
            'supplement_vacances_ppm' => 83300,
            'procedure' => 'ordinaire',
            'impot_source_ppm' => 0,
        ];
        $result = $calculator->calculate($employee, 192000, $rates);
        $this->same(192000, $result['salaire_travail_centimes'], 'OCAS salaire_travail');
        $this->same(15994, $result['supplement_centimes'], 'OCAS supplément vacances');
        $this->same(207994, $result['brut_centimes'], 'OCAS salaire brut');
        $this->same(11024, $result['ded_avs_centimes'], 'OCAS AVS employé');
        $this->same(2205, $result['ded_ac_centimes'], 'OCAS AC employé');
        $this->same(60, $result['ded_amat_centimes'], 'OCAS A.mat employé');
        $this->same(2205, $result['ded_laa_centimes'], 'OCAS LAA employé');
        $this->same(14560, $result['ded_lpp_centimes'], 'OCAS LPP employé');
        $this->same(0, $result['ded_impot_source_centimes'], 'OCAS sans impôt source');
        $this->same(0, $result['ded_caf_centimes'], 'OCAS CAF supprimée');
        $this->same(30054, $result['total_deductions_centimes'], 'OCAS total déductions');
        $this->same(177940, $result['net_centimes'], 'OCAS salaire net');

        $this->same(11024, $result['emp_avs_centimes'], 'OCAS AVS employeur');
        $this->same(4867, $result['emp_af_centimes'], 'OCAS AF employeur');
        $this->same(14560, $result['emp_lpp_centimes'], 'OCAS LPP employeur');
        $this->same(32799, $result['total_charges_employeur_centimes'], 'OCAS charges employeur');
        $this->same(240793, $result['cout_total_centimes'], 'OCAS coût employeur');

        $source = $calculator->calculate([
            'supplement_vacances_ppm' => 0,
            'procedure' => 'ordinaire_impot_source',
            'impot_source_ppm' => 100000,
        ], 100000, $rates);
        $this->same(100000, $source['brut_centimes'], 'OCAS brut sans supplément');
        $this->same(10000, $source['ded_impot_source_centimes'], 'OCAS impôt source 10 %');

        $withoutVacation = $calculator->calculate([
            'supplement_vacances_ppm' => 0,
            'procedure' => 'ordinaire',
            'impot_source_ppm' => 0,
        ], 50000, $rates);
        $this->same(0, $withoutVacation['supplement_centimes'], 'OCAS supplément nul');
        $this->same(50000, $withoutVacation['brut_centimes'], 'OCAS brut égal au travail');
        $this->same(0, $withoutVacation['ded_caf_centimes'], 'OCAS aucune CAF cantonale');

        $withCantonalEmployerRates = $calculator->calculate([
            'supplement_vacances_ppm' => 0,
            'procedure' => 'ordinaire',
            'impot_source_ppm' => 0,
        ], 100000, $rates + [
            'emp_cpe_ppm' => 700,
            'emp_lfp_ppm' => 820,
        ]);
        $this->same(70, $withCantonalEmployerRates['emp_cpe_centimes'], 'OCAS CPE employeur');
        $this->same(82, $withCantonalEmployerRates['emp_lfp_centimes'], 'OCAS LFP employeur');
        $this->same(
            15921,
            $withCantonalEmployerRates['total_charges_employeur_centimes'],
            'OCAS total employeur avec CPE/LFP'
        );

        $this->same(35.43, round($calculator->monthlyHourThreshold(2026, 1), 2), 'OCAS seuil janvier');
        $this->same(32.0, round($calculator->monthlyHourThreshold(2026, 2), 2), 'OCAS seuil février');
        $accidentRates = [
            'laa_reduit_ppm' => 5300,
            'laa_plein_ppm' => 9600,
            'emp_laa_reduit_ppm' => 5300,
            'emp_laa_plein_ppm' => 9600,
        ];
        $reduced = $calculator->effectiveAccidentRates($accidentRates, 30.0, 2026, 1);
        $this->same(5300, $reduced['laa_ppm'], 'OCAS 30 h LAA employé réduit');
        $this->same(5300, $reduced['emp_laa_ppm'], 'OCAS 30 h LAA employeur réduit');
        $full = $calculator->effectiveAccidentRates($accidentRates, 40.0, 2026, 1);
        $this->same(9600, $full['laa_ppm'], 'OCAS 40 h LAA plein');
        $threshold = $calculator->effectiveAccidentRates($accidentRates, 35.43, 2026, 1);
        $this->same(5300, $threshold['laa_ppm'], 'OCAS seuil arrondi LAA réduit');

        $lppExample = $calculator->calculate([
            'supplement_vacances_ppm' => 0,
            'procedure' => 'ordinaire',
            'impot_source_ppm' => 0,
        ], 100000, $rates);
        $this->same(7000, $lppExample['ded_lpp_centimes'], 'OCAS LPP 7 %');
    }

    private function authAndScopeTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $ids = $this->seedScopes($pdo);
        $users = new UserRepository($pdo);
        $userId = $users->create('eleve@example.test', 'mot-de-passe-tres-long');
        $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code = 'apprenant'")->fetchColumn();
        $stmt = $pdo->prepare(
            'INSERT INTO utilisateur_roles_dossier (utilisateur_id, dossier_id, role_id)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $ids['dossier_a'], $roleId]);

        $access = new AccessControl($pdo);
        $this->true(
            $access->canViewDossier($userId, $ids['organisation_a'], $ids['dossier_a']),
            'accès au dossier attribué'
        );
        $this->false(
            $access->canViewDossier($userId, $ids['organisation_b'], $ids['dossier_a']),
            'couple organisation/dossier incohérent refusé'
        );
        $this->false(
            $access->canViewDossier($userId, $ids['organisation_b'], $ids['dossier_b']),
            'autre organisation refusée'
        );
        $this->same(1, count($access->dossiersForUser($userId)), 'liste limitée au scope autorisé');
        $manager = new ScopeManager($pdo, new AuditLogger($pdo));
        $this->throws(
            fn () => $manager->grantRole(
                $userId,
                'apprenant',
                'dossier',
                $ids['dossier_b']
            ),
            'rôle apprenant refusé sur dossier réel'
        );
        $newOrganisation = $manager->createOrganisation('École C', 'pedagogique');
        $newDossier = $manager->createDossier(
            $newOrganisation,
            'Cours 2026',
            'cours-2026',
            'exercice'
        );
        $newExercise = $manager->createExercise(
            $newDossier,
            'Année 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $this->true($newExercise > 0, 'organisation/dossier/exercice créés atomiquement');
        $manager->grantRole($userId, 'apprenant', 'dossier', $newDossier);
        $this->true(
            $access->canViewDossier($userId, $newOrganisation, $newDossier),
            'rôle dossier attribué par service'
        );

        $session = new ArraySessionStore();
        $auth = new AuthService(
            $users,
            new LoginThrottle($pdo, 2, 900),
            new AuditLogger($pdo),
            $session
        );
        $this->false($auth->attempt('eleve@example.test', 'faux', '127.0.0.1'), 'mauvais mot de passe');
        $this->true($auth->attempt('eleve@example.test', 'mot-de-passe-tres-long', '127.0.0.1'), 'connexion valide');
        $this->same($userId, $auth->userId(), 'utilisateur en session');
        $auditCount = (int) $pdo->query('SELECT COUNT(*) FROM audit_events')->fetchColumn();
        $this->true($auditCount >= 2, 'connexions auditées');
        $storedIp = (string) $pdo->query(
            "SELECT ip FROM audit_events WHERE action = 'auth.connexion' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        $this->same('127.0.0.0', $storedIp, 'IP d’audit anonymisée');

        $blockedSession = new ArraySessionStore();
        $blockedAuth = new AuthService(
            $users,
            new LoginThrottle($pdo, 2, 900),
            new AuditLogger($pdo),
            $blockedSession
        );
        $blockedAuth->attempt('eleve@example.test', 'faux-1', '127.0.0.2');
        $blockedAuth->attempt('eleve@example.test', 'faux-2', '127.0.0.2');
        $this->false(
            $blockedAuth->attempt(
                'eleve@example.test',
                'mot-de-passe-tres-long',
                '127.0.0.2'
            ),
            'anti-force-brute bloque après le seuil'
        );
    }

    private function organisationRegistryTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $audit = new AuditLogger($pdo);
        $users = new UserRepository($pdo);
        $actorId = $users->create(
            'registre-admin@example.test',
            'mot-de-passe-registre'
        );
        $managerId = $users->create(
            'registre-manager@example.test',
            'mot-de-passe-registre'
        );
        $administratorRole = (int) $pdo->query(
            "SELECT id FROM roles WHERE code = 'administrateur'"
        )->fetchColumn();
        $pdo->prepare(
            'INSERT INTO utilisateur_roles_installation
             (utilisateur_id, role_id) VALUES (?, ?)'
        )->execute([$actorId, $administratorRole]);
        $registry = new OrganisationRegistryService($pdo, $audit);
        $rolesBefore = (int) $pdo->query(
            'SELECT COUNT(*) FROM utilisateur_roles_organisation'
        )->fetchColumn();
        $organisationId = $registry->create(
            'Atelier Registre',
            'reelle',
            [
                'valid_from' => '2025-01-01',
                'legal_name' => 'Atelier Registre SA',
                'legal_form' => 'SA',
                'uid' => 'CHE-999.888.777',
                'source' => 'Extrait RC du 01.01.2025',
                'address' => [
                    'line1' => 'Rue du Test 1',
                    'postal_code' => '1200',
                    'city' => 'Genève',
                    'country' => 'CH',
                ],
            ],
            $actorId
        );
        $this->same(
            $rolesBefore,
            (int) $pdo->query(
                'SELECT COUNT(*) FROM utilisateur_roles_organisation'
            )->fetchColumn(),
            'création sans attribution implicite de rôle'
        );
        $detail = $registry->detail($organisationId);
        $this->same(
            'Atelier Registre SA',
            $detail['legal_history'][0]['raison_sociale'] ?? '',
            'identité juridique initiale historisée'
        );
        $this->same(
            'Extrait RC du 01.01.2025',
            $detail['legal_history'][0]['source'] ?? '',
            'source juridique obligatoire conservée'
        );
        $listed = $registry->list('CHE-999', 'all', 1, 10);
        $this->same(1, $listed['pagination']['total'], 'recherche serveur sur le numéro IDE');

        $registry->updateName(
            $organisationId,
            'Atelier Registre Groupe',
            (int) $detail['version'],
            $actorId
        );
        $this->throws(
            fn () => $registry->updateName(
                $organisationId,
                'Écrasement interdit',
                (int) $detail['version'],
                $actorId
            ),
            'conflit de version empêche un écrasement concurrent'
        );
        $afterName = $registry->detail($organisationId);
        $registry->saveLegalIdentity(
            $organisationId,
            (int) $afterName['version'],
            [
                'valid_from' => '2026-01-01',
                'legal_name' => 'Atelier Registre Groupe SA',
                'legal_form' => 'SA',
                'uid' => 'CHE-999.888.777',
                'source' => 'Extrait RC du 01.01.2026',
                'address' => [
                    'line1' => 'Rue du Test 2',
                    'postal_code' => '1201',
                    'city' => 'Genève',
                    'country' => 'CH',
                ],
            ],
            $actorId
        );
        $history = $registry->detail($organisationId)['legal_history'];
        $this->same(2, count($history), 'identités juridiques successives conservées');
        $this->same(
            '2025-12-31',
            $history[1]['date_fin'] ?? '',
            'ancienne identité juridique fermée à la veille'
        );

        $scope = new ScopeManager($pdo, $audit);
        $dossierId = $scope->createDossier(
            $organisationId,
            'Dossier actif',
            'dossier-actif',
            'reel'
        );
        $withDossier = $registry->detail($organisationId);
        try {
            $registry->archive(
                $organisationId,
                (int) $withDossier['version'],
                $actorId
            );
            $this->true(false, 'organisation avec dossier actif non archivable');
        } catch (OrganisationRegistryException $exception) {
            $this->same(
                'ORGANISATION_HAS_ACTIVE_DOSSIERS',
                $exception->errorCode,
                'organisation avec dossier actif non archivable'
            );
        }
        $this->throws(
            fn () => $registry->delete(
                $organisationId,
                (int) $withDossier['version'],
                $actorId
            ),
            'suppression refusée tant qu’un dossier subsiste'
        );
        $this->true(
            isset($registry->deletionDependencies($organisationId)['dossiers']),
            'refus de suppression énumère les dossiers dépendants'
        );
        $this->true(
            isset(
                $registry->deletionDependencies($organisationId)[
                    'attributs_juridiques_organisation'
                ]
            ),
            'historique juridique immuable bloque la suppression physique'
        );
        $pdo->prepare('UPDATE dossiers SET actif = 0 WHERE id = ?')
            ->execute([$dossierId]);
        $registry->archive(
            $organisationId,
            (int) $registry->detail($organisationId)['version'],
            $actorId
        );
        $archived = $registry->detail($organisationId);
        $this->false((bool) $archived['active'], 'organisation archivée après ses dossiers');
        $registry->reactivate(
            $organisationId,
            (int) $archived['version'],
            $actorId
        );
        $this->true(
            (bool) $registry->detail($organisationId)['active'],
            'organisation réactivée sans effet sur les rôles'
        );

        $emptyId = $registry->create(
            'Bac à sable vide',
            'pedagogique',
            null,
            $actorId
        );
        $pdo->prepare(
            'INSERT INTO utilisateur_roles_organisation
             (utilisateur_id, organisation_id, role_id) VALUES (?, ?, ?)'
        )->execute([$managerId, $emptyId, $administratorRole]);
        $access = new AccessControl($pdo);
        $this->true(
            $access->hasOrganisationPermission(
                $managerId, $emptyId, 'organisation.manage'
            ),
            'gestionnaire limité autorisé sur son organisation'
        );
        $this->false(
            $access->hasOrganisationPermission(
                $managerId, $organisationId, 'organisation.manage'
            ),
            'gestionnaire limité sans découverte d’une autre organisation'
        );
        $this->false(
            $access->hasInstallationPermission($managerId, 'installation.admin'),
            'rôle organisation ne devient pas administrateur installation'
        );
        $this->same(
            ['organisation.manage'],
            (new ShellReadService($pdo))->installationPermissions($managerId),
            'shell n’élève pas un rôle organisation au niveau installation'
        );
        $listedForManager = $registry->list(
            '', 'all', 1, 20,
            $access->organisationIdsForPermission(
                $managerId, 'organisation.manage'
            )
        );
        $this->same(
            [$emptyId],
            array_column($listedForManager['items'], 'id'),
            'liste du gestionnaire strictement limitée à son organisation'
        );
        $pdo->prepare(
            'DELETE FROM utilisateur_roles_organisation
             WHERE utilisateur_id = ? AND organisation_id = ?'
        )->execute([$managerId, $emptyId]);
        $emptyVersion = (int) $registry->detail($emptyId)['version'];
        $registry->delete($emptyId, $emptyVersion, $actorId);
        $this->same(
            0,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM organisations WHERE id = {$emptyId}"
            )->fetchColumn(),
            'organisation réellement vide supprimée'
        );
        $auditDelete = $pdo->query(
            "SELECT organisation_id, cible_id, resume_json
             FROM audit_events
             WHERE action = 'organisation.supprimee'
             ORDER BY id DESC LIMIT 1"
        )->fetch();
        $this->same(null, $auditDelete['organisation_id'], 'audit conservé après suppression');
        $this->same((string) $emptyId, (string) $auditDelete['cible_id'], 'cible supprimée traçable');
        $this->true(
            IntegrityChecker::check($pdo)['ok'],
            'intégrité après cycle de vie des organisations'
        );
    }

    private function dossierRegistryTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $audit = new AuditLogger($pdo);
        $actorId = (new UserRepository($pdo))->create(
            'dossiers-admin@example.test',
            'mot-de-passe-dossiers'
        );
        $organisationId = (new ScopeManager($pdo, $audit))->createOrganisation(
            'Groupe multi-dossiers',
            'reelle',
            $actorId
        );
        $factory = static function (
            ?callable $checkpoint = null
        ) use ($pdo, $audit): DossierRegistryService {
            return new DossierRegistryService(
                $pdo,
                $audit,
                new ScopeManager($pdo, $audit),
                new PlanSeeder($pdo, dirname(__DIR__) . '/database/seeds'),
                new AccountingSetupService($pdo, $audit),
                new DefaultVatCodeInstaller($pdo, $audit),
                new ModuleAccessService($pdo),
                $checkpoint
            );
        };
        $registry = $factory();
        $rolesBefore = (int) $pdo->query(
            'SELECT COUNT(*) FROM utilisateur_roles_dossier'
        )->fetchColumn();
        $first = $registry->createInitialized(
            $organisationId,
            'Association principale',
            'association-principale',
            'reel',
            'CHF',
            ['comptabilite', 'facturation'],
            'personne_morale',
            true,
            ['projets' => true, 'fonds_affectes' => true],
            'Exercice 2026',
            '2026-01-01',
            '2026-12-31',
            'OD',
            'Opérations diverses',
            $actorId
        );
        $firstId = (int) $first['id'];
        $this->true(
            (int) $first['summary']['account_count'] > 0,
            'plan comptable installé dans le premier dossier'
        );
        $this->same(1, $first['summary']['exercise_count'], 'premier exercice créé');
        $this->same(1, $first['summary']['period_count'], 'première période créée');
        $this->same(1, $first['summary']['journal_count'], 'journal général créé');
        $this->same(
            ['facturation', 'comptabilite'],
            $first['summary']['modules'],
            'sélection de modules appliquée'
        );
        $this->same('CHF', $first['summary']['currency'], 'devise de base appliquée');
        $this->same(
            $rolesBefore,
            (int) $pdo->query(
                'SELECT COUNT(*) FROM utilisateur_roles_dossier'
            )->fetchColumn(),
            'création sans attribution implicite de droits'
        );
        $readerRoleId = (int) $pdo->query(
            "SELECT id FROM roles WHERE code = 'lecteur'"
        )->fetchColumn();
        $pdo->prepare(
            'INSERT INTO utilisateur_roles_dossier
             (utilisateur_id, dossier_id, role_id) VALUES (?, ?, ?)'
        )->execute([$actorId, $firstId, $readerRoleId]);
        $copyPreview = (new StructureAccessService($pdo, $audit))
            ->previewDossierCopy($organisationId, $firstId);

        $second = $registry->createInitialized(
            $organisationId,
            'Succursale',
            'succursale',
            'reel',
            'EUR',
            ['comptabilite'],
            'raison_individuelle',
            false,
            [],
            'Exercice 2026',
            '2026-01-01',
            '2026-12-31',
            'GEN',
            'Journal général',
            $actorId,
            [
                'source_dossier_id' => $firstId,
                'preview_hash' => $copyPreview['preview_hash'],
            ]
        );
        $secondId = (int) $second['id'];
        $this->same(
            1,
            $second['summary']['copied_access_count'],
            'copie explicite intégrée à la transaction de création'
        );
        $this->same(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM utilisateur_roles_dossier
                 WHERE utilisateur_id = {$actorId}
                   AND dossier_id = {$secondId}
                   AND role_id = {$readerRoleId}"
            )->fetchColumn(),
            'matrice prévisualisée copiée exactement dans le nouveau dossier'
        );
        $this->same(2, count($registry->listForOrganisation($organisationId)), 'deux dossiers réels dans une organisation');
        $this->same('EUR', $registry->detail($secondId)['monnaie'], 'devise propre au second dossier');
        $this->same(
            0,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM comptes a
                 JOIN comptes b ON b.id = a.id
                 WHERE a.dossier_id = {$firstId} AND b.dossier_id = {$secondId}"
            )->fetchColumn(),
            'plans comptables isolés entre dossiers'
        );

        $beforeFailure = count($registry->listForOrganisation($organisationId));
        $failing = $factory(static function (string $step): void {
            if ($step === 'plan') {
                throw new RuntimeException('Panne simulée après le plan.');
            }
        });
        $this->throws(
            fn () => $failing->createInitialized(
                $organisationId,
                'Dossier incomplet',
                'dossier-incomplet',
                'reel',
                'CHF',
                ['comptabilite'],
                'personne_morale',
                false,
                [],
                'Exercice 2026',
                '2026-01-01',
                '2026-12-31',
                'OD',
                'Journal général',
                $actorId
            ),
            'échec simulé de l’assistant propagé'
        );
        $this->same(
            $beforeFailure,
            count($registry->listForOrganisation($organisationId)),
            'échec d’initialisation sans dossier partiel'
        );

        $secondDetail = $registry->detail($secondId);
        $registry->update(
            $secondId,
            (int) $secondDetail['version'],
            'Succursale romande',
            'demo',
            'USD',
            $actorId
        );
        $this->throws(
            fn () => $registry->update(
                $secondId,
                (int) $secondDetail['version'],
                'Écrasement',
                'demo',
                'USD',
                $actorId
            ),
            'conflit optimiste de dossier détecté'
        );
        $this->throws(
            fn () => $registry->createInitialized(
                $organisationId,
                'Slug dupliqué',
                'association-principale',
                'reel',
                'CHF',
                ['comptabilite'],
                'personne_morale',
                false,
                [],
                'Exercice 2026',
                '2026-01-01',
                '2026-12-31',
                'OD',
                'Journal général',
                $actorId
            ),
            'slug unique par organisation'
        );

        $this->pdoInsertBusinessContact(
            $pdo,
            $organisationId,
            $firstId,
            $actorId
        );
        $firstDetail = $registry->detail($firstId);
        try {
            $registry->update(
                $firstId,
                (int) $firstDetail['version'],
                'Association renommée',
                'demo',
                'EUR',
                $actorId
            );
            $this->true(false, 'champs historiques verrouillés');
        } catch (DossierRegistryException $exception) {
            $this->same(
                'DOSSIER_HISTORICAL_FIELDS_LOCKED',
                $exception->errorCode,
                'type et devise verrouillés après donnée métier'
            );
        }
        try {
            $registry->delete(
                $firstId,
                (int) $firstDetail['version'],
                $actorId
            );
            $this->true(false, 'suppression métier protégée');
        } catch (DossierRegistryException $exception) {
            $this->same(
                'DOSSIER_HAS_DEPENDENCIES',
                $exception->errorCode,
                'dossier utilisé uniquement archivable'
            );
        }
        $registry->archive($firstId, (int) $firstDetail['version'], $actorId);
        $this->false($registry->detail($firstId)['active'], 'dossier utilisé archivé');

        $secondAfterUpdate = $registry->detail($secondId);
        $registry->delete(
            $secondId,
            (int) $secondAfterUpdate['version'],
            $actorId
        );
        $this->throws(
            fn () => $registry->detail($secondId),
            'dossier initialisé mais vide supprimable'
        );
        $this->true(IntegrityChecker::check($pdo)['ok'], 'intégrité après cycle de vie des dossiers');
    }

    private function pdoInsertBusinessContact(
        PDO $pdo,
        int $organisationId,
        int $dossierId,
        int $actorId,
    ): void {
        $pdo->prepare(
            "INSERT INTO contacts
             (organisation_id, dossier_id, type_personne, raison_sociale, cree_par)
             VALUES (?, ?, 'entreprise', 'Client historique', ?)"
        )->execute([$organisationId, $dossierId, $actorId]);
    }

    private function structureAccessTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $audit = new AuditLogger($pdo);
        $users = new UserRepository($pdo);
        $adminId = $users->create('access-admin@example.test', 'mot-de-passe-access');
        $managerId = $users->create('access-manager@example.test', 'mot-de-passe-access');
        $accountantId = $users->create('access-accountant@example.test', 'mot-de-passe-access');
        $outsiderId = $users->create('access-outsider@example.test', 'mot-de-passe-access');
        $adminRoleId = (int) $pdo->query(
            "SELECT id FROM roles WHERE code = 'administrateur'"
        )->fetchColumn();
        $accountantRoleId = (int) $pdo->query(
            "SELECT id FROM roles WHERE code = 'comptable'"
        )->fetchColumn();
        $readerRoleId = (int) $pdo->query(
            "SELECT id FROM roles WHERE code = 'lecteur'"
        )->fetchColumn();
        $pdo->prepare(
            'INSERT INTO utilisateur_roles_installation
             (utilisateur_id, role_id) VALUES (?, ?)'
        )->execute([$adminId, $adminRoleId]);
        $scopes = new ScopeManager($pdo, $audit);
        $organisationA = $scopes->createOrganisation('Accès A', 'reelle', $adminId);
        $organisationB = $scopes->createOrganisation('Accès B', 'reelle', $adminId);
        $dossierA1 = $scopes->createDossier(
            $organisationA,
            'Dossier A1',
            'access-a1',
            'reel',
            $adminId
        );
        $dossierA2 = $scopes->createDossier(
            $organisationA,
            'Dossier A2',
            'access-a2',
            'reel',
            $adminId
        );
        $dossierA3 = $scopes->createDossier(
            $organisationA,
            'Dossier A3',
            'access-a3',
            'reel',
            $adminId
        );
        $pdo->prepare(
            'INSERT INTO utilisateur_roles_organisation
             (utilisateur_id, organisation_id, role_id) VALUES (?, ?, ?)'
        )->execute([$managerId, $organisationA, $adminRoleId]);
        $pdo->prepare(
            'INSERT INTO utilisateur_roles_organisation
             (utilisateur_id, organisation_id, role_id) VALUES (?, ?, ?)'
        )->execute([$outsiderId, $organisationB, $readerRoleId]);

        $service = new StructureAccessService($pdo, $audit);
        $matrixA1 = $service->matrix(
            $adminId,
            'dossier',
            $organisationA,
            $dossierA1,
            true
        );
        $previewA1 = $service->preview(
            $adminId,
            'dossier',
            $organisationA,
            $dossierA1,
            $accountantId,
            [$accountantRoleId],
            $matrixA1['version'],
            true
        );
        $this->true(
            in_array('dossier.view', $previewA1['added_permissions'], true),
            'aperçu expose les permissions effectives ajoutées'
        );
        $firstApply = $service->apply(
            $adminId,
            'dossier',
            $organisationA,
            $dossierA1,
            $accountantId,
            [$accountantRoleId],
            $matrixA1['version'],
            $previewA1['confirmation_token'],
            true
        );
        $this->true($firstApply['changed'], 'première attribution directe appliquée');
        $duplicate = $service->apply(
            $adminId,
            'dossier',
            $organisationA,
            $dossierA1,
            $accountantId,
            [$accountantRoleId],
            $matrixA1['version'],
            $previewA1['confirmation_token'],
            true
        );
        $this->false($duplicate['changed'], 'rejeu idempotent sans doublon');

        $matrixA2 = $service->matrix(
            $adminId,
            'dossier',
            $organisationA,
            $dossierA2,
            true
        );
        $previewA2 = $service->preview(
            $adminId,
            'dossier',
            $organisationA,
            $dossierA2,
            $accountantId,
            [$accountantRoleId],
            $matrixA2['version'],
            true
        );
        $service->apply(
            $adminId,
            'dossier',
            $organisationA,
            $dossierA2,
            $accountantId,
            [$accountantRoleId],
            $matrixA2['version'],
            $previewA2['confirmation_token'],
            true
        );
        $removeMatrix = $service->matrix(
            $adminId,
            'dossier',
            $organisationA,
            $dossierA1,
            true
        );
        $removePreview = $service->preview(
            $adminId,
            'dossier',
            $organisationA,
            $dossierA1,
            $accountantId,
            [],
            $removeMatrix['version'],
            true
        );
        $service->apply(
            $adminId,
            'dossier',
            $organisationA,
            $dossierA1,
            $accountantId,
            [],
            $removeMatrix['version'],
            $removePreview['confirmation_token'],
            true
        );
        $access = new AccessControl($pdo);
        $this->false(
            $access->canViewDossier($accountantId, $organisationA, $dossierA1),
            'retrait effectif sur le premier dossier'
        );
        $this->true(
            $access->canViewDossier($accountantId, $organisationA, $dossierA2),
            'accès au dossier frère conservé'
        );
        $conflictMatrix = $service->matrix(
            $adminId,
            'dossier',
            $organisationA,
            $dossierA1,
            true
        );
        $conflictPreview = $service->preview(
            $adminId,
            'dossier',
            $organisationA,
            $dossierA1,
            $accountantId,
            [$readerRoleId],
            $conflictMatrix['version'],
            true
        );
        $pdo->prepare(
            'INSERT INTO utilisateur_roles_dossier
             (utilisateur_id, dossier_id, role_id) VALUES (?, ?, ?)'
        )->execute([$managerId, $dossierA1, $readerRoleId]);
        $this->throws(
            fn () => $service->apply(
                $adminId,
                'dossier',
                $organisationA,
                $dossierA1,
                $accountantId,
                [$readerRoleId],
                $conflictMatrix['version'],
                $conflictPreview['confirmation_token'],
                true
            ),
            'conflit optimiste protège une matrice modifiée en parallèle'
        );
        $pdo->prepare(
            'DELETE FROM utilisateur_roles_dossier
             WHERE utilisateur_id = ? AND dossier_id = ?'
        )->execute([$managerId, $dossierA1]);

        $copy = $service->previewDossierCopy($organisationA, $dossierA2);
        $copiedCount = $service->copyDossierMatrix(
            $organisationA,
            $dossierA2,
            $dossierA3,
            $copy['preview_hash'],
            $adminId
        );
        $this->same(
            $copy['assignment_count'],
            $copiedCount,
            'copie confirmée produit exactement la matrice prévisualisée'
        );
        $this->same(
            $service->previewDossierCopy($organisationA, $dossierA2)['assignments'],
            $service->previewDossierCopy($organisationA, $dossierA3)['assignments'],
            'aucun rôle hérité matérialisé pendant la copie'
        );

        $managerMatrix = $service->matrix(
            $managerId,
            'organisation',
            $organisationA,
            null,
            false
        );
        $managerVisibleIds = array_column($managerMatrix['users'], 'id');
        $this->false(
            in_array($outsiderId, $managerVisibleIds, true),
            'gestionnaire ne découvre aucun utilisateur d’une autre organisation'
        );
        $managerRow = current(array_filter(
            $managerMatrix['users'],
            static fn (array $user): bool => $user['id'] === $managerId
        ));
        $this->same(
            'administrateur',
            $managerRow['organisation_roles'][0]['code'] ?? '',
            'rôle organisation distingué de la source installation'
        );

        $installationMatrix = $service->matrix(
            $adminId,
            'installation',
            null,
            null,
            true
        );
        $this->throws(
            fn () => $service->preview(
                $adminId,
                'installation',
                null,
                null,
                $adminId,
                [],
                $installationMatrix['version'],
                true
            ),
            'dernier administrateur protégé sans successeur'
        );
        $transferPreview = $service->preview(
            $adminId,
            'installation',
            null,
            null,
            $adminId,
            [],
            $installationMatrix['version'],
            true,
            $managerId
        );
        $this->same(
            $managerId,
            $transferPreview['transfer']['user_id'] ?? 0,
            'transfert explicite du dernier administrateur prévisualisé'
        );

        $usersExport = $service->exportUsersCsv();
        $accessExport = $service->exportAccessCsv();
        $this->true(
            str_contains(
                $usersExport,
                'email;prenom;nom;actif;mot_de_passe'
            ),
            'export utilisateurs fournit le modèle CSV attendu'
        );
        $this->false(
            str_contains($usersExport, 'mot-de-passe-access'),
            'export utilisateurs ne divulgue aucun mot de passe'
        );
        $this->true(
            str_contains(
                $accessExport,
                'email;portee;organisation;dossier_slug;role'
            ),
            'export rôles et accès fournit un second CSV distinct'
        );
        $importUsers = implode("\n", [
            'email;prenom;nom;actif;mot_de_passe',
            'access-admin@example.test;Ada;Admin;1;',
            'csv-user@example.test;Camille;CSV;1;nouveau-mot-de-passe',
            '',
        ]);
        $importAccess = implode("\n", [
            'email;portee;organisation;dossier_slug;role',
            'access-admin@example.test;installation;;;administrateur',
            'csv-user@example.test;dossier;Accès A;access-a1;lecteur',
            '',
        ]);
        $csvPreview = $service->previewCsv($importUsers, $importAccess);
        $this->same(
            1,
            $csvPreview['users']['created'],
            'prévisualisation détecte le nouvel utilisateur'
        );
        $this->same(
            1,
            $csvPreview['access']['added'],
            'prévisualisation détecte la nouvelle affectation'
        );
        $csvApplied = $service->importCsv(
            $importUsers,
            $importAccess,
            $csvPreview['confirmation_token'],
            $adminId
        );
        $this->true($csvApplied['applied'], 'import des deux CSV confirmé');
        $csvUser = $users->findByEmail('csv-user@example.test');
        $this->same('Camille', $csvUser['prenom'] ?? '', 'prénom CSV importé');
        $this->true(
            password_verify(
                'nouveau-mot-de-passe',
                (string) ($csvUser['mot_de_passe'] ?? '')
            ),
            'mot de passe du nouvel utilisateur correctement haché'
        );
        $this->true(
            $access->canViewDossier(
                (int) ($csvUser['id'] ?? 0),
                $organisationA,
                $dossierA1
            ),
            'rôle de dossier du second CSV appliqué'
        );
        $this->throws(
            fn () => $service->previewCsv(
                $importUsers,
                str_replace(';lecteur', ';role-inconnu', $importAccess)
            ),
            'rôle CSV inconnu refusé avant toute mutation'
        );
        $this->true(
            (int) $pdo->query(
                "SELECT COUNT(*) FROM audit_events
                 WHERE action IN (
                    'structure.acces_modifie',
                    'structure.acces_dossier_copies',
                    'structure.utilisateurs_acces_importes'
                 )"
            )->fetchColumn() >= 4,
            'mutations et copie auditées'
        );
        $this->true(
            IntegrityChecker::check($pdo)['ok'],
            'intégrité après gouvernance des accès'
        );
    }

    private function dashboardTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $ids = $this->seedScopes($pdo);
        $organisationId = $ids['organisation_a'];
        $dossierId = $ids['dossier_a'];
        $audit = new AuditLogger($pdo);
        $scopes = new ScopeManager($pdo, $audit);
        $exerciseId = $scopes->createExercise(
            $dossierId,
            'Exercice tableau de bord 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $setup = new AccountingSetupService($pdo, $audit);
        $periodId = $setup->createPeriod(
            $organisationId,
            $dossierId,
            $exerciseId,
            'Année 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $journalId = $setup->createJournal(
            $organisationId,
            $dossierId,
            'TDB',
            'Tableau de bord'
        );
        (new PlanSeeder($pdo, dirname(__DIR__) . '/database/seeds'))
            ->installForDossier($organisationId, $dossierId, 'personne_morale');
        $account = fn (string $number): int =>
            $this->accountId($pdo, $dossierId, $number);
        $bankAccount = $account('1020');
        $receivable = $account('1100');
        $payable = $account('2000');
        $capital = $account('2800');
        $revenue = $account('3400');
        $expense = $account('6500');
        $entries = new EntryService($pdo, $audit);
        $post = function (
            string $key,
            string $date,
            string $label,
            array $lines,
        ) use (
            $entries,
            $organisationId,
            $dossierId,
            $exerciseId,
            $journalId
        ): int {
            return $entries->postGenerated([
                'organisation_id' => $organisationId,
                'dossier_id' => $dossierId,
                'exercice_id' => $exerciseId,
                'journal_id' => $journalId,
                'date_comptable' => $date,
                'libelle' => $label,
                'source_type' => 'test_tableau_bord',
                'source_id' => $key,
                'source_action' => 'projection',
                'lignes' => $lines,
            ], 'dashboard:' . $key);
        };
        $post('treasury', '2026-01-10', 'Apport bancaire', [
            ['compte_id' => $bankAccount, 'debit_centimes' => 50000],
            ['compte_id' => $capital, 'credit_centimes' => 50000],
        ]);
        $post('sale', '2026-02-10', 'Vente comptabilisée', [
            ['compte_id' => $receivable, 'debit_centimes' => 100000],
            ['compte_id' => $revenue, 'credit_centimes' => 100000],
        ]);
        $post('expense', '2026-03-10', 'Charge comptabilisée', [
            ['compte_id' => $expense, 'debit_centimes' => 40000],
            ['compte_id' => $payable, 'credit_centimes' => 40000],
        ]);
        $entries->createDraft([
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
            'exercice_id' => $exerciseId,
            'journal_id' => $journalId,
            'date_comptable' => '2026-04-01',
            'libelle' => 'Brouillon exclu',
            'lignes' => [
                ['compte_id' => $receivable, 'debit_centimes' => 900000],
                ['compte_id' => $revenue, 'credit_centimes' => 900000],
            ],
        ]);

        $treasuryAccountId = (new TreasuryAccountService($pdo, $audit))->create([
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
            'compte_comptable_id' => $bankAccount,
            'libelle' => 'Banque principale',
            'type' => 'banque',
            'monnaie' => 'CHF',
        ]);
        $import = $pdo->prepare(
            "INSERT INTO imports_bancaires
             (organisation_id, dossier_id, compte_tresorerie_id, format,
              nom_fichier, empreinte_source, contenu_source, statut, nb_total,
              nb_importees, confirme_le)
             VALUES (?, ?, ?, 'postfinance_csv', ?, ?, ?, 'confirme', 1, 1, datetime('now'))"
        );
        $import->execute([
            $organisationId,
            $dossierId,
            $treasuryAccountId,
            'dashboard.csv',
            hash('sha256', 'dashboard-bank-source'),
            'source-test',
        ]);
        $importId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO lignes_bancaires
             (organisation_id, dossier_id, compte_tresorerie_id, import_id,
              empreinte, date_comptabilisation, libelle, montant_centimes, monnaie)
             VALUES (?, ?, ?, ?, ?, '2026-04-10', 'Ligne non rapprochée', 15000, 'CHF')"
        )->execute([
            $organisationId,
            $dossierId,
            $treasuryAccountId,
            $importId,
            hash('sha256', 'dashboard-bank-line'),
        ]);
        $pdo->prepare(
            "INSERT INTO soldes_bancaires
             (organisation_id, dossier_id, compte_tresorerie_id, import_id,
              type, date_solde, montant_centimes, monnaie, empreinte)
             VALUES (?, ?, ?, ?, 'CLBD', '2026-04-15', 65000, 'CHF', ?)"
        )->execute([
            $organisationId,
            $dossierId,
            $treasuryAccountId,
            $importId,
            hash('sha256', 'dashboard-bank-balance'),
        ]);

        $contact = $pdo->prepare(
            "INSERT INTO contacts
             (organisation_id, dossier_id, type_personne, raison_sociale)
             VALUES (?, ?, 'entreprise', ?)"
        );
        $contact->execute([$organisationId, $dossierId, 'Client tableau']);
        $customerId = (int) $pdo->lastInsertId();
        $contact->execute([$organisationId, $dossierId, 'Fournisseur tableau']);
        $supplierId = (int) $pdo->lastInsertId();
        $document = $pdo->prepare(
            "INSERT INTO documents_financiers
             (organisation_id, dossier_id, contact_id, type, statut, numero,
              date_document, date_echeance, adresse_snapshot_json,
              contact_snapshot_json, total_net_centimes, total_tva_centimes,
              total_brut_centimes)
             VALUES (?, ?, ?, ?, 'emis', ?, ?, ?, '{}', '{}', ?, 0, ?)"
        );
        $document->execute([
            $organisationId, $dossierId, $customerId, 'facture_client',
            'FAC-TDB-1', '2026-02-01', '2026-03-01', 100000, 100000,
        ]);
        $customerInvoiceId = (int) $pdo->lastInsertId();
        $document->execute([
            $organisationId, $dossierId, $customerId, 'avoir_client',
            'NC-TDB-1', '2026-03-20', '2026-03-20', -10000, -10000,
        ]);
        $creditId = (int) $pdo->lastInsertId();
        $document->execute([
            $organisationId, $dossierId, $supplierId, 'facture_fournisseur',
            'ACH-TDB-1', '2026-04-01', '2026-04-30', 30000, 30000,
        ]);
        $payment = $pdo->prepare(
            "INSERT INTO paiements
             (organisation_id, dossier_id, contact_id, sens, date_paiement,
              montant_centimes, reference)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $payment->execute([
            $organisationId, $dossierId, $customerId, 'encaissement',
            '2026-03-15', 40000, 'Paiement partiel',
        ]);
        $partialPaymentId = (int) $pdo->lastInsertId();
        $payment->execute([
            $organisationId, $dossierId, $customerId, 'encaissement',
            '2026-04-10', 7000, 'À lettrer',
        ]);
        $allocation = $pdo->prepare(
            'INSERT INTO allocations
             (organisation_id, dossier_id, paiement_id, avoir_id,
              document_id, montant_centimes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $allocation->execute([
            $organisationId, $dossierId, $partialPaymentId, null,
            $customerInvoiceId, 40000,
        ]);
        $allocation->execute([
            $organisationId, $dossierId, null, $creditId,
            $customerInvoiceId, 5000,
        ]);

        $reports = new ReportingService($pdo);
        $dashboard = new DashboardReadService($pdo, $reports);
        $countsBefore = (string) $pdo->query(
            "SELECT
              (SELECT COUNT(*) FROM ecritures) || ':' ||
              (SELECT COUNT(*) FROM documents_financiers) || ':' ||
              (SELECT COUNT(*) FROM paiements) || ':' ||
              (SELECT COUNT(*) FROM allocations) || ':' ||
              (SELECT COUNT(*) FROM lignes_bancaires)"
        )->fetchColumn();
        $projection = $dashboard->projection(
            $organisationId,
            $dossierId,
            $exerciseId,
            '2026-04-15'
        );
        $countsAfter = (string) $pdo->query(
            "SELECT
              (SELECT COUNT(*) FROM ecritures) || ':' ||
              (SELECT COUNT(*) FROM documents_financiers) || ':' ||
              (SELECT COUNT(*) FROM paiements) || ':' ||
              (SELECT COUNT(*) FROM allocations) || ':' ||
              (SELECT COUNT(*) FROM lignes_bancaires)"
        )->fetchColumn();
        $income = $reports->incomeStatement(
            $organisationId,
            $dossierId,
            $exerciseId,
            '2026-04-15'
        );
        $this->same(
            (int) $income['produits_centimes'],
            (int) $projection['profit_and_loss']['revenue_cents'],
            'chiffre d’affaires égal au compte de résultat validé'
        );
        $this->same(
            (int) $income['charges_centimes'],
            (int) $projection['profit_and_loss']['expenses_cents'],
            'charges égales au compte de résultat validé'
        );
        $this->same(
            100000,
            (int) $projection['profit_and_loss']['revenue_cents'],
            'brouillon exclu du chiffre d’affaires'
        );
        $trialBalance = $reports->trialBalance(
            $organisationId,
            $dossierId,
            $exerciseId,
            '2026-04-15'
        );
        $this->same(
            $this->balanceFor($trialBalance['items'], '1020'),
            (int) $projection['treasury']['accounting_balance_cents'],
            'trésorerie égale à la balance du compte bancaire'
        );
        $treasuryState = (new TreasuryStateService($pdo))->state(
            $organisationId,
            $dossierId,
            $treasuryAccountId,
            '2026-04-15'
        );
        $this->same(
            (int) $treasuryState['bank_balance_cents'],
            (int) $projection['treasury']['bank_balance_cents'],
            'dernier solde bancaire égal à l’état de trésorerie'
        );
        $this->same(
            (int) $treasuryState['difference_cents'],
            (int) $projection['treasury']['difference_cents'],
            'écart banque/comptabilité égal à l’état de trésorerie'
        );
        $this->same(
            50000,
            (int) $projection['open_items']['receivables']['open_cents'],
            'paiement partiel et avoir partiel sans double comptage'
        );
        $this->same(
            30000,
            (int) $projection['open_items']['payables']['open_cents'],
            'dette fournisseur ouverte issue des documents et allocations'
        );
        $this->same(
            55000,
            (int) $projection['open_items']['receivables']['aging']['days_31_60'],
            'facture partiellement réglée dans sa tranche aging exacte'
        );
        $this->same(
            -5000,
            (int) $projection['open_items']['receivables']['aging']['days_1_30'],
            'avoir résiduel négatif dans sa propre tranche aging'
        );
        $this->same(
            1,
            (int) $projection['operations']['unreconciled_bank_lines']['count'],
            'ligne bancaire confirmée non rapprochée comptée'
        );
        $this->same(
            7000,
            (int) $projection['operations']['payments_to_process']['amount_cents'],
            'paiement non alloué signalé à traiter'
        );
        $this->same(
            $countsBefore,
            $countsAfter,
            'projection strictement sans mutation'
        );

        $emptyDossier = $scopes->createDossier(
            $organisationId,
            'Dossier vide',
            'dashboard-vide',
            'exercice'
        );
        $emptyExercise = $scopes->createExercise(
            $emptyDossier,
            'Exercice vide',
            '2026-01-01',
            '2026-12-31'
        );
        $empty = $dashboard->projection(
            $organisationId,
            $emptyDossier,
            $emptyExercise,
            '2026-04-15'
        );
        $this->true(
            (bool) $empty['empty_state']['is_empty'],
            'exercice sans données retourne un état vide explicite'
        );

        $pdo->beginTransaction();
        $entryInsert = $pdo->prepare(
            "INSERT INTO ecritures
             (organisation_id, dossier_id, exercice_id, journal_id,
              date_comptable, libelle, statut, source_type, source_id)
             VALUES (?, ?, ?, ?, '2026-04-14', ?, 'brouillon', 'benchmark', ?)"
        );
        $lineInsert = $pdo->prepare(
            'INSERT INTO lignes_ecriture
             (ecriture_id, compte_id, debit_centimes, credit_centimes, ordre)
             VALUES (?, ?, ?, ?, ?)'
        );
        $validateEntry = $pdo->prepare(
            "UPDATE ecritures SET statut = 'validee', validee_le = datetime('now')
             WHERE id = ?"
        );
        for ($index = 1; $index <= 500; $index++) {
            $entryInsert->execute([
                $organisationId, $dossierId, $exerciseId, $journalId,
                'Écriture représentative ' . $index, (string) $index,
            ]);
            $entryId = (int) $pdo->lastInsertId();
            $lineInsert->execute([$entryId, $receivable, 1, 0, 1]);
            $lineInsert->execute([$entryId, $revenue, 0, 1, 2]);
            $validateEntry->execute([$entryId]);
        }
        for ($index = 1; $index <= 200; $index++) {
            $document->execute([
                $organisationId, $dossierId, $customerId, 'facture_client',
                'FAC-BENCH-' . $index, '2026-04-01', '2026-04-30', 100, 100,
            ]);
        }
        $bankLineInsert = $pdo->prepare(
            "INSERT INTO lignes_bancaires
             (organisation_id, dossier_id, compte_tresorerie_id, import_id,
              empreinte, rang_occurrence, date_comptabilisation, libelle,
              montant_centimes, monnaie)
             VALUES (?, ?, ?, ?, ?, ?, '2026-04-14', 'Benchmark', 1, 'CHF')"
        );
        for ($index = 1; $index <= 100; $index++) {
            $bankLineInsert->execute([
                $organisationId, $dossierId, $treasuryAccountId, $importId,
                hash('sha256', 'dashboard-benchmark-line-' . $index), $index,
            ]);
        }
        $pdo->commit();
        $startedAt = hrtime(true);
        $representative = $dashboard->projection(
            $organisationId,
            $dossierId,
            $exerciseId,
            '2026-04-15'
        );
        $elapsedMilliseconds = intdiv(hrtime(true) - $startedAt, 1_000_000);
        $this->true(
            $elapsedMilliseconds < 500,
            "projection bornée sur 500 écritures, 200 factures et 100 lignes bancaires ({$elapsedMilliseconds} ms)"
        );
        $this->same(
            10,
            count($representative['recent_entries']),
            'activité récente strictement bornée à dix écritures'
        );

        $entryPlan = implode(' ', array_map(
            static fn (array $row): string => (string) $row['detail'],
            $pdo->query(
                "EXPLAIN QUERY PLAN
                 SELECT id FROM ecritures
                 WHERE dossier_id = {$dossierId}
                   AND exercice_id = {$exerciseId}
                   AND date_comptable <= '2026-04-15'
                 ORDER BY date_comptable DESC LIMIT 10"
            )->fetchAll()
        ));
        $documentPlan = implode(' ', array_map(
            static fn (array $row): string => (string) $row['detail'],
            $pdo->query(
                "EXPLAIN QUERY PLAN
                 SELECT id FROM documents_financiers
                 WHERE dossier_id = {$dossierId}
                   AND type = 'facture_client' AND statut = 'emis'
                   AND date_echeance <= '2026-04-15'"
            )->fetchAll()
        ));
        $bankPlan = implode(' ', array_map(
            static fn (array $row): string => (string) $row['detail'],
            $pdo->query(
                "EXPLAIN QUERY PLAN
                 SELECT id FROM lignes_bancaires
                 WHERE compte_tresorerie_id = {$treasuryAccountId}
                   AND date_comptabilisation <= '2026-04-15'"
            )->fetchAll()
        ));
        $this->true(
            str_contains($entryPlan, 'idx_ecritures_journal'),
            'plan SQLite indexé pour les écritures du tableau de bord'
        );
        $this->true(
            str_contains($documentPlan, 'idx_documents_scope_etat'),
            'plan SQLite indexé pour les échéances'
        );
        $this->true(
            str_contains($bankPlan, 'idx_lignes_bancaires_compte_date'),
            'plan SQLite indexé pour les lignes bancaires'
        );
        $setup->closePeriod($organisationId, $dossierId, $periodId);
        $closed = $dashboard->projection(
            $organisationId,
            $dossierId,
            $exerciseId,
            '2026-04-15'
        );
        $this->same(
            'fermee',
            $closed['scope']['period']['status'] ?? '',
            'période fermée consultable et explicitement signalée'
        );
    }

    private function configurationTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $audit = new AuditLogger($pdo);
        $manager = new ScopeManager($pdo, $audit);
        $organisationId = $manager->createOrganisation(
            'Atelier Configuration SA',
            'reelle'
        );
        $dossierId = $manager->createDossier(
            $organisationId,
            'Comptabilité configuration',
            'configuration',
            'reel'
        );
        $userId = (new UserRepository($pdo))->create(
            'configuration@example.test',
            'mot-de-passe-configuration'
        );
        $moduleAccess = new ModuleAccessService($pdo);
        $configuration = new ConfigurationService($pdo, $audit, $moduleAccess);

        $this->same(
            5,
            count($moduleAccess->modules($organisationId, $dossierId)),
            'nouveau dossier initialisé avec le registre de modules'
        );
        $pdo->prepare(
            'INSERT INTO parametres_dossier (dossier_id, cle, valeur)
             VALUES (?, ?, ?)'
        )->execute([$dossierId, 'preuve_apprentissage', 'conservee']);
        $configuration->setModule(
            $organisationId,
            $dossierId,
            'apprentissage',
            false,
            1,
            $userId
        );
        $this->false(
            $moduleAccess->isEnabled(
                $organisationId,
                $dossierId,
                'apprentissage'
            ),
            'module désactivé côté service'
        );
        $navigation = (new ShellReadService($pdo, $moduleAccess))->navigation(
            ['pedagogie.view'],
            true,
            $moduleAccess->enabledCodes($organisationId, $dossierId)
        );
        $this->false(
            in_array('learning', array_column($navigation, 'key'), true),
            'module désactivé absent de la navigation'
        );
        $configuration->setModule(
            $organisationId,
            $dossierId,
            'apprentissage',
            true,
            2,
            $userId
        );
        $this->true(
            $moduleAccess->isEnabled(
                $organisationId,
                $dossierId,
                'apprentissage'
            ),
            'module réactivé'
        );
        $this->same(
            'conservee',
            (string) $pdo->query(
                "SELECT valeur FROM parametres_dossier
                 WHERE dossier_id = {$dossierId}
                   AND cle = 'preuve_apprentissage'"
            )->fetchColumn(),
            'réactivation retrouve les données intactes'
        );

        $beforeLegalIdentity = $configuration->read($organisationId, $dossierId);
        (new OrganisationRegistryService($pdo, $audit))->saveLegalIdentity(
            $organisationId,
            (int) $beforeLegalIdentity['identity']['organization']['version'],
            [
                'valid_from' => '2026-01-01',
                'legal_name' => 'Atelier Configuration SA',
                'legal_form' => 'Société anonyme',
                'uid' => 'CHE-123.456.789',
                'source' => 'Extrait RC de test',
                'address' => [
                    'line1' => 'Rue des Tests 5',
                    'line2' => '',
                    'postal_code' => '1201',
                    'city' => 'Genève',
                    'canton' => 'GE',
                    'country' => 'CH',
                ],
            ],
            $userId
        );
        $initial = $configuration->read($organisationId, $dossierId);
        $updatedIdentity = $configuration->updateIdentity(
            $organisationId,
            $dossierId,
            [
                'organization_version' => $initial['identity']['organization']['version'],
                'dossier_version' => $initial['identity']['dossier']['version'],
                'name' => 'Atelier Configuration',
                'legal_name' => 'Atelier Configuration SA',
                'legal_form' => 'Société anonyme',
                'uid' => 'CHE-123.456.789',
                'address_line1' => 'Rue des Tests 5',
                'address_line2' => '',
                'postal_code' => '1201',
                'city' => 'Genève',
                'canton' => 'GE',
                'country' => 'CH',
                'phone' => '+41 22 000 00 00',
                'email' => 'compta@example.test',
                'website' => 'https://example.test',
                'billing_iban' => 'CH9300762011623852957',
                'base_currency' => 'EUR',
            ],
            $userId
        );
        $this->same(
            'Atelier Configuration SA|EUR',
            $updatedIdentity['organization']['legal_name']
                . '|' . $updatedIdentity['dossier']['base_currency'],
            'identité légale et devise de base enregistrées'
        );
        $this->same(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM attributs_juridiques_organisation
                 WHERE organisation_id = {$organisationId}"
            )->fetchColumn(),
            'identité légale modifiée uniquement par le registre historique'
        );
        $this->same(
            'CH9300762011623852957',
            $updatedIdentity['organization']['billing_iban'],
            'IBAN de facturation centralisé avec l’identité légale'
        );
        $this->throws(
            fn () => $configuration->updateIdentity(
                $organisationId,
                $dossierId,
                [
                    'organization_version' => 1,
                    'dossier_version' => 1,
                    'name' => 'Écrasement',
                    'base_currency' => 'CHF',
                ],
                $userId
            ),
            'conflit optimiste protège la configuration'
        );

        $net30 = $configuration->createPaymentTerm(
            $organisationId,
            $dossierId,
            [
                'code' => 'NET30',
                'label' => 'Net à 30 jours',
                'direction' => 'client',
                'days' => 30,
                'end_of_month' => false,
                'valid_from' => '2026-01-01',
                'valid_until' => '',
            ],
            $userId
        );
        $configuration->setPaymentDefault(
            $organisationId,
            $dossierId,
            'client',
            $net30,
            '2026-01-01',
            $userId
        );
        $terms = new PaymentTermsService($pdo);
        $oldResolution = $terms->resolveDefault(
            $organisationId,
            $dossierId,
            'client',
            '2026-02-01'
        );
        $this->same(
            '2026-03-03',
            $oldResolution['due_date'] ?? '',
            'échéance calculée sans flottant depuis le défaut daté'
        );
        $this->throws(
            fn () => $configuration->setPaymentDefault(
                $organisationId,
                $dossierId,
                'client',
                999999,
                '2026-06-01',
                $userId
            ),
            'condition par défaut étrangère ou absente refusée'
        );
        $this->same(
            $net30,
            $terms->resolveDefault(
                $organisationId,
                $dossierId,
                'client',
                '2026-06-15'
            )['condition_id'] ?? 0,
            'refus de condition atomique sans altérer le défaut courant'
        );

        $pdo->prepare(
            "INSERT INTO contacts
             (organisation_id, dossier_id, raison_sociale)
             VALUES (?, ?, 'Client historique')"
        )->execute([$organisationId, $dossierId]);
        $contactId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO documents_financiers
             (organisation_id, dossier_id, contact_id, type, statut, numero,
              date_document, date_echeance, adresse_snapshot_json,
              contact_snapshot_json, condition_paiement_id,
              condition_paiement_snapshot_json)
             VALUES (?, ?, ?, 'facture_client', 'emis', 'F-2026-001',
                     '2026-02-01', ?, '{}', '{}', ?, ?)"
        )->execute([
            $organisationId,
            $dossierId,
            $contactId,
            $oldResolution['due_date'],
            $oldResolution['condition_id'],
            json_encode($oldResolution['snapshot'], JSON_THROW_ON_ERROR),
        ]);
        $documentId = (int) $pdo->lastInsertId();

        $monthEnd = $configuration->createPaymentTerm(
            $organisationId,
            $dossierId,
            [
                'code' => 'M10',
                'label' => '10 jours fin de mois',
                'direction' => 'client',
                'days' => 10,
                'end_of_month' => true,
                'valid_from' => '2026-07-01',
                'valid_until' => '',
            ],
            $userId
        );
        $configuration->setPaymentDefault(
            $organisationId,
            $dossierId,
            'client',
            $monthEnd,
            '2026-07-01',
            $userId
        );
        $newResolution = $terms->resolveDefault(
            $organisationId,
            $dossierId,
            'client',
            '2026-08-10'
        );
        $this->same(
            '2026-08-31',
            $newResolution['due_date'] ?? '',
            'nouveau défaut appliqué seulement à sa période'
        );
        $historicalDocument = $pdo->query(
            "SELECT date_echeance,
                    json_extract(condition_paiement_snapshot_json, '$.code') AS code
             FROM documents_financiers WHERE id = {$documentId}"
        )->fetch();
        $this->same(
            '2026-03-03|NET30',
            $historicalDocument['date_echeance'] . '|' . $historicalDocument['code'],
            'changement de défaut sans effet rétroactif'
        );
        $this->throws(
            fn () => $pdo->exec(
                "UPDATE documents_financiers
                 SET condition_paiement_snapshot_json = '{}'
                 WHERE id = {$documentId}"
            ),
            'snapshot de paiement du document émis immuable'
        );
        $this->same(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM contacts WHERE dossier_id = {$dossierId}"
            )->fetchColumn(),
            'Configuration ne duplique pas le registre unique de contacts'
        );
        $this->true(
            (int) $pdo->query(
                "SELECT COUNT(*) FROM audit_events
                 WHERE dossier_id = {$dossierId}
                   AND action LIKE 'configuration.%'"
            )->fetchColumn() >= 7,
            'toutes les modifications sensibles sont auditées'
        );
        $this->true(
            IntegrityChecker::check($pdo)['ok'],
            'intégrité après configuration'
        );
    }

    private function httpTests(): void
    {
        [$pdo, $runner, $dbPath] = $this->database();
        $runner->apply();
        $ids = $this->seedScopes($pdo);
        $users = new UserRepository($pdo);
        $userId = $users->create('http@example.test', 'mot-de-passe-tres-long');
        $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code = 'comptable'")->fetchColumn();
        $pdo->prepare(
            'INSERT INTO utilisateur_roles_dossier (utilisateur_id, dossier_id, role_id) VALUES (?, ?, ?)'
        )->execute([$userId, $ids['dossier_a'], $roleId]);
        (new ScopeManager($pdo, new AuditLogger($pdo)))->grantRole(
            $userId, 'formateur', 'dossier', $ids['dossier_a']
        );
        $accessUserId = $users->create(
            'access-http@example.test',
            'mot-de-passe-tres-long'
        );
        $registryAdminId = $users->create(
            'registry-admin-http@example.test',
            'mot-de-passe-tres-long'
        );
        $registryManagerId = $users->create(
            'registry-manager-http@example.test',
            'mot-de-passe-tres-long'
        );
        $administratorRoleId = (int) $pdo->query(
            "SELECT id FROM roles WHERE code = 'administrateur'"
        )->fetchColumn();
        $pdo->prepare(
            'INSERT INTO utilisateur_roles_installation
             (utilisateur_id, role_id) VALUES (?, ?)'
        )->execute([$registryAdminId, $administratorRoleId]);
        $pdo->prepare(
            'INSERT INTO utilisateur_roles_organisation
             (utilisateur_id, organisation_id, role_id) VALUES (?, ?, ?)'
        )->execute([
            $registryManagerId,
            $ids['organisation_a'],
            $administratorRoleId,
        ]);
        $exerciseId = (new ScopeManager($pdo, new AuditLogger($pdo)))->createExercise(
            $ids['dossier_a'],
            'Exercice HTTP 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $forbiddenExerciseId = (new ScopeManager($pdo, new AuditLogger($pdo)))->createExercise(
            $ids['dossier_b'],
            'Exercice confidentiel 2026',
            '2026-01-01',
            '2026-12-31'
        );
        (new PlanSeeder($pdo, dirname(__DIR__) . '/database/seeds'))->installForDossier(
            $ids['organisation_a'],
            $ids['dossier_a']
        );
        $httpSetup = new AccountingSetupService($pdo, new AuditLogger($pdo));
        $httpSetup->createPeriod(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exerciseId,
            '2026',
            '2026-01-01',
            '2026-12-31'
        );
        $httpJournal = $httpSetup->createJournal(
            $ids['organisation_a'],
            $ids['dossier_a'],
            'OD',
            'Opérations diverses'
        );

        $config = AppConfig::load(dirname(__DIR__), [
            'instance_id' => 'http-test',
            'base_url' => '/edu',
            'database_path' => $dbPath,
            'storage_path' => dirname($dbPath),
            'debug' => true,
        ]);
        $session = new ArraySessionStore(['user_id' => $userId]);
        $csrf = new Csrf($session);
        $httpAudit = new AuditLogger($pdo);
        $httpEntries = new EntryService($pdo, $httpAudit);
        $httpPayrolls = new PayrollService($pdo, $httpAudit, $httpEntries);
        $httpPedagogy = new PedagogyService($pdo, $httpAudit, $httpEntries);
        $httpAuth = new AuthService(
            $users,
            new LoginThrottle($pdo, 5, 900),
            new AuditLogger($pdo),
            $session
        );
        $httpAccess = new AccessControl($pdo);
        $httpModuleAccess = new ModuleAccessService($pdo);
        $httpConfiguration = new ConfigurationService(
            $pdo,
            $httpAudit,
            $httpModuleAccess
        );
        $httpContacts = new ContactService($pdo, $httpAudit);
        $httpBilling = new BillingService($pdo, $httpAudit, $httpEntries);
        $httpPayments = new PaymentService($pdo, $httpAudit, $httpEntries);
        $httpPayrollConfiguration = new PayrollConfigurationService(
            $pdo,
            $httpAudit
        );
        $httpPayrollPayments = new PayrollPaymentService(
            $pdo,
            $httpAudit,
            $httpEntries
        );
        $httpPayrollCertificates = new PayrollCertificateService($pdo, $httpAudit);
        $httpVatConfiguration = new VatConfigurationService($pdo, $httpAudit);
        $httpReports = new ReportingService($pdo);
        $httpFinancial = new FinancialReportingService($pdo, $httpReports);
        $httpVatWorkspace = new VatWorkspaceService(
            $pdo,
            new VatStatementService($pdo, $httpAudit),
            new Ech0217ExportService(
                $pdo,
                $httpAudit,
                new Ech0217Validator(
                    dirname(__DIR__)
                    . '/resources/xsd/ech-0217-2-0-0-current-profile.xsd'
                ),
                trim((string) file_get_contents(dirname(__DIR__) . '/VERSION'))
            )
        );
        $httpAccountingSetup = new AccountingSetupService($pdo, $httpAudit);
        $httpClosing = new ClosingAndTaxService(
            $pdo,
            $httpAudit,
            $httpAccountingSetup,
            $httpFinancial
        );
        $apiRoutes = new ApiRouteRegistry(
            new ShellApiController(
                $config,
                $session,
                $csrf,
                $httpAuth,
                $httpAccess,
                $httpAudit,
                new ShellReadService($pdo, $httpModuleAccess),
                new ShellInputValidator()
            ),
            new DashboardApiController(
                $session,
                $httpAuth,
                $httpAccess,
                new DashboardReadService($pdo, new ReportingService($pdo)),
                new DashboardInputValidator()
            ),
            $csrf,
            new ConfigurationApiController(
                $session,
                $httpAuth,
                $httpAccess,
                $httpConfiguration,
                new ConfigurationInputValidator(),
                new ManagedReferencesService(
                    $pdo,
                    $httpContacts,
                    $httpVatConfiguration,
                    $httpPayrollConfiguration,
                    new TreasuryAccountService($pdo, $httpAudit),
                    new AccountingSetupService($pdo, $httpAudit),
                    $httpAudit
                )
            ),
            new AccountingApiController(
                $session,
                $httpAuth,
                $httpAccess,
                new AccountingWorkspaceService(
                    $httpChart = new ChartOfAccountsService($pdo, $httpAudit),
                    $httpEntries,
                    $httpReports,
                    $httpFinancial,
                    $httpVatWorkspace,
                    $httpClosing,
                    null,
                    new AccountingCsvService($pdo, $httpChart, $httpEntries)
                ),
                new AccountingInputValidator(),
                $httpAudit
            ),
            new ExpenseApiController(
                $session,
                $httpAuth,
                $httpAccess,
                new ExpenseService($pdo, $httpAudit, $httpEntries),
                new AttachmentService($pdo, $httpAudit),
                new ExpenseInputValidator()
            ),
            new TreasuryApiController(
                $session,
                $httpAuth,
                $httpAccess,
                new TreasuryWorkspaceService(
                    $pdo,
                    new PaymentService($pdo, $httpAudit, $httpEntries),
                    $httpEntries
                ),
                new BankImportService($pdo, $httpAudit),
                new ReconciliationService($pdo, $httpAudit),
                new SuggestionService($pdo, $httpAudit, $httpEntries),
                new PaymentService($pdo, $httpAudit, $httpEntries),
                new OutgoingPaymentService(
                    $pdo,
                    $httpAudit,
                    $httpEntries,
                    new PaymentService($pdo, $httpAudit, $httpEntries),
                    new ReconciliationService($pdo, $httpAudit),
                    new Pain001Generator()
                ),
                new PublicMarketDataService(
                    $pdo,
                    new PublicMarketHttpClient(
                        static fn (string $url): string => throw new RuntimeException(
                            "Réseau interdit pendant le test HTTP : {$url}"
                        )
                    ),
                    new DateTimeImmutable('2026-07-26')
                ),
                new TreasuryInputValidator()
            ),
            new BillingApiController(
                $session,
                $httpAuth,
                $httpAccess,
                new BillingWorkspaceService(
                    $pdo,
                    $httpBilling,
                    $httpPayments,
                    $httpContacts
                ),
                $httpBilling,
                $httpContacts,
                $httpPayments,
                new RecurringBillingService($pdo, $httpAudit, $httpBilling),
                new InvoicePdfService($pdo, $httpAudit),
                new AttachmentService($pdo, $httpAudit),
                new BillingInputValidator()
            ),
            new AssetApiController(
                $session,
                $httpAuth,
                $httpAccess,
                new AssetService($pdo, $httpAudit, $httpEntries),
                new AssetInputValidator()
            ),
            new PayrollApiController(
                $session,
                $httpAuth,
                $httpAccess,
                new PayrollWorkspaceService(
                    $httpPayrollConfiguration,
                    $httpPayrolls,
                    $httpPayrollPayments,
                    $httpPayrollCertificates
                ),
                $httpPayrollConfiguration,
                $httpPayrolls,
                $httpPayrollPayments,
                $httpPayrollCertificates,
                new OcasRateImportService(
                    '',
                    $httpPayrollConfiguration,
                    $httpAudit
                ),
                new PayrollInputValidator()
            ),
            new PedagogyApiController(
                $session,
                $httpAuth,
                $httpAccess,
                $httpPedagogy,
                new PedagogyInputValidator()
            ),
            new ConsolidationApiController(
                $session,
                $httpAuth,
                $httpAccess,
                new ConsolidationService($pdo, $httpAudit),
                new ConsolidationInputValidator()
            ),
            new OrganisationApiController(
                $httpAuth,
                $httpAccess,
                new OrganisationRegistryService($pdo, $httpAudit),
                new OrganisationInputValidator()
            ),
            new DossierApiController(
                $session,
                $httpAuth,
                $httpAccess,
                new DossierRegistryService(
                    $pdo,
                    $httpAudit,
                    new ScopeManager($pdo, $httpAudit),
                    new PlanSeeder($pdo, dirname(__DIR__) . '/database/seeds'),
                    $httpAccountingSetup,
                    new DefaultVatCodeInstaller($pdo, $httpAudit),
                    $httpModuleAccess
                ),
                new DossierInputValidator()
            ),
            new StructureAccessApiController(
                $httpAuth,
                $httpAccess,
                new StructureAccessService($pdo, $httpAudit),
                new StructureAccessInputValidator()
            )
        );
        $shellPage = new ShellPageController(
            $config,
            $httpAuth,
            new VueShellRenderer(dirname(__DIR__), $config)
        );
        $app = new WebApplication(
            $config,
            new View(dirname(__DIR__) . '/templates', $config),
            $session,
            $csrf,
            $httpAuth,
            $httpAccess,
            $httpAudit,
            new ReportingService($pdo),
            $httpContacts,
            new BillingService($pdo, $httpAudit, $httpEntries),
            new PaymentService($pdo, $httpAudit, $httpEntries),
            new InvoicePdfService($pdo, $httpAudit),
            new AttachmentService($pdo, $httpAudit),
            $httpPayrollConfiguration,
            $httpPayrolls,
            new PayrollPaymentService($pdo, $httpAudit, $httpEntries),
            new PayrollCertificateService($pdo, $httpAudit),
            new PayrollImportService($pdo, $httpAudit, $httpPayrolls),
            $httpPedagogy,
            $apiRoutes,
            $shellPage,
            $httpModuleAccess
        );

        $session->remove('user_id');
        $anonymousShell = $app->handle(new Request('GET', '/app/compta/etats'));
        $this->same(302, $anonymousShell->status, 'shell Vue profond exige une session');
        $this->same(
            '/edu/login',
            $anonymousShell->headers['Location'] ?? '',
            'shell Vue anonyme redirigé vers la connexion'
        );
        $apiAnonymous = $app->handle(new Request('GET', '/api/v1/context'));
        $this->same(401, $apiAnonymous->status, 'API refuse une session anonyme');
        $this->same(
            'AUTHENTICATION_REQUIRED',
            $this->responseJson($apiAnonymous)['errors'][0]['code'] ?? '',
            'API type explicitement l’erreur 401'
        );
        $session->set('user_id', $userId);

        $apiContext = $app->handle(new Request(
            'GET',
            '/api/v1/context',
            server: ['HTTP_X_CORRELATION_ID' => 'contract-test-0001']
        ));
        $apiContextJson = $this->responseJson($apiContext);
        $this->same(200, $apiContext->status, 'contexte API accessible');
        $this->same(
            ['data', 'meta', 'errors'],
            array_keys($apiContextJson),
            'enveloppe API uniforme data/meta/errors'
        );
        $this->same(
            'contract-test-0001',
            $apiContext->headers['X-Correlation-ID'] ?? '',
            'corrélation propagée dans l’en-tête'
        );
        $this->same(
            'contract-test-0001',
            $apiContextJson['meta']['correlation_id'] ?? '',
            'corrélation propagée dans le contrat'
        );
        $this->true(
            array_key_exists('selection', $apiContextJson['data'])
            && $apiContextJson['data']['selection'] === null,
            'contexte API explicite avant sélection'
        );

        $apiNoScope = $app->handle(new Request('GET', '/api/v1/permissions'));
        $this->same(409, $apiNoScope->status, 'API exige un contexte pour les permissions');
        $this->same(
            'CONTEXT_REQUIRED',
            $this->responseJson($apiNoScope)['errors'][0]['code'] ?? '',
            'conflit de contexte typé'
        );
        $dashboardNoScope = $app->handle(new Request(
            'GET',
            '/api/v1/dashboard',
            query: [
                'exercise_id' => (string) $exerciseId,
                'as_of_date' => '2026-06-30',
            ]
        ));
        $this->same(
            409,
            $dashboardNoScope->status,
            'tableau de bord API exige un contexte'
        );

        $apiUnknown = $app->handle(new Request('GET', '/api/v1/inconnue'));
        $this->same(404, $apiUnknown->status, 'route API inconnue en JSON 404');
        $this->same(
            'application/json; charset=UTF-8',
            $apiUnknown->headers['Content-Type'] ?? '',
            '404 API conserve le type JSON'
        );

        $apiNoCsrf = $app->handle(new Request(
            'POST',
            '/api/v1/context/dossier',
            json: ['data' => [
                'organisation_id' => $ids['organisation_a'],
                'dossier_id' => $ids['dossier_a'],
            ]]
        ));
        $this->same(403, $apiNoCsrf->status, 'mutation API sans CSRF refusée');
        $this->same(
            'CSRF_INVALID',
            $this->responseJson($apiNoCsrf)['errors'][0]['code'] ?? '',
            'erreur CSRF API typée'
        );

        $apiInvalid = $app->handle(new Request(
            'POST',
            '/api/v1/context/dossier',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => ['organisation_id' => 'abc', 'surprise' => true]]
        ));
        $apiInvalidJson = $this->responseJson($apiInvalid);
        $this->same(422, $apiInvalid->status, 'entrée API invalide refusée');
        $this->true(
            isset($apiInvalidJson['errors'][0]['fields']['organisation_id'])
            && isset($apiInvalidJson['errors'][0]['fields']['dossier_id'])
            && isset($apiInvalidJson['errors'][0]['fields']['surprise']),
            'validation API expose seulement des erreurs de champs'
        );

        $apiWrongVersion = $app->handle(new Request(
            'POST',
            '/api/v1/context/dossier',
            server: [
                'HTTP_X_CSRF_TOKEN' => $csrf->token(),
                'HTTP_X_CONTRACT_VERSION' => 'compta-api-v0',
            ],
            json: ['data' => [
                'organisation_id' => $ids['organisation_a'],
                'dossier_id' => $ids['dossier_a'],
            ]]
        ));
        $this->same(409, $apiWrongVersion->status, 'version de contrat incompatible refusée');

        $apiForbidden = $app->handle(new Request(
            'POST',
            '/api/v1/context/dossier',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'organisation_id' => $ids['organisation_b'],
                'dossier_id' => $ids['dossier_b'],
            ]]
        ));
        $this->same(403, $apiForbidden->status, 'API isole les organisations et dossiers');
        $this->false(
            str_contains($apiForbidden->body, 'Organisation B')
            || str_contains($apiForbidden->body, 'Comptabilité B'),
            'refus API sans fuite de données'
        );

        $apiSelected = $app->handle(new Request(
            'POST',
            '/api/v1/context/dossier',
            server: [
                'HTTP_X_CSRF_TOKEN' => $csrf->token(),
                'HTTP_X_CONTRACT_VERSION' => 'compta-api-v1',
                'HTTP_X_CORRELATION_ID' => 'selection-test-0001',
            ],
            json: ['data' => [
                'organisation_id' => $ids['organisation_a'],
                'dossier_id' => $ids['dossier_a'],
            ]]
        ));
        $apiSelectedJson = $this->responseJson($apiSelected);
        $this->same(200, $apiSelected->status, 'sélection de contexte API acceptée');
        $this->same(
            $ids['dossier_a'],
            $apiSelectedJson['data']['selection']['dossier']['id'] ?? 0,
            'sélection API strictement scopée'
        );
        $this->same(
            'selection-test-0001',
            (string) $pdo->query(
                "SELECT json_extract(resume_json, '$.correlation_id')
                 FROM audit_events
                 WHERE action = 'contexte.dossier_selectionne_api'
                 ORDER BY id DESC LIMIT 1"
            )->fetchColumn(),
            'corrélation conservée dans l’audit de mutation'
        );
        $apiExpenses = $app->handle(new Request('GET', '/api/v1/liquidites'));
        $apiExpensesJson = $this->responseJson($apiExpenses);
        $this->same(200, $apiExpenses->status, 'espace dépenses exposé en API');
        $this->same(
            ['expenses', 'recurrences', 'catalog', 'capabilities'],
            array_keys($apiExpensesJson['data'] ?? []),
            'contrat dépenses complet et stable'
        );
        $this->true(
            ($apiExpensesJson['data']['capabilities']['approve'] ?? false)
            && ($apiExpensesJson['data']['capabilities']['post'] ?? false),
            'capacités approbateur et comptabilisateur exposées séparément'
        );
        $apiTreasury = $app->handle(new Request(
            'GET',
            '/api/v1/liquidites/banque'
        ));
        $apiTreasuryJson = $this->responseJson($apiTreasury);
        $this->same(
            200,
            $apiTreasury->status,
            'banque, lettrage et paiements sortants exposés en API'
        );
        $this->same(
            [
                'treasury_accounts',
                'imports',
                'bank_lines',
                'accounting_lines',
                'reconciliations',
                'suggestions',
                'payments',
                'allocations',
                'open_documents',
                'payable_debts',
                'outgoing_batches',
                'catalog',
                'definitions',
                'capabilities',
            ],
            array_keys($apiTreasuryJson['data'] ?? []),
            'contrat banque et lettrage complet et stable'
        );
        $this->true(
            ($apiTreasuryJson['data']['capabilities']['prepare_payments'] ?? false)
            && ($apiTreasuryJson['data']['capabilities']['export_payments'] ?? false)
            && ($apiTreasuryJson['data']['capabilities']['confirm_payments'] ?? false),
            'séparation des capacités de paiements sortants exposée en API'
        );
        $apiExchangeHistory = $app->handle(new Request(
            'GET',
            '/api/v1/liquidites/taux-change',
            query: ['exercise_id' => (string) $exerciseId]
        ));
        $exchangeHistoryJson = $this->responseJson($apiExchangeHistory);
        $this->same(
            200,
            $apiExchangeHistory->status,
            'historique des changes exposé par exercice'
        );
        $this->same(
            [
                'kind', 'exercise', 'window', 'periods', 'currencies',
                'quote_currency', 'series', 'daily', 'refresh', 'definitions',
            ],
            array_keys($exchangeHistoryJson['data'] ?? []),
            'contrat des changes complet malgré une source indisponible'
        );
        $apiInterestHistory = $app->handle(new Request(
            'GET',
            '/api/v1/liquidites/taux-interet',
            query: ['exercise_id' => (string) $exerciseId]
        ));
        $interestHistoryJson = $this->responseJson($apiInterestHistory);
        $this->same(
            'interest',
            $interestHistoryJson['data']['kind'] ?? '',
            'historique des taux d’intérêt exposé'
        );
        $foreignMarketExercise = $app->handle(new Request(
            'GET',
            '/api/v1/liquidites/taux-change',
            query: ['exercise_id' => (string) $forbiddenExerciseId]
        ));
        $this->same(
            422,
            $foreignMarketExercise->status,
            'historique de marché refuse un exercice hors dossier'
        );
        $apiBilling = $app->handle(new Request(
            'GET',
            '/api/v1/facturation',
            query: ['as_of_date' => '2026-06-30']
        ));
        $apiBillingJson = $this->responseJson($apiBilling);
        $this->same(200, $apiBilling->status, 'facturation et aging exposés en API');
        $this->same(
            [
                'reference_date',
                'filters',
                'documents',
                'aging',
                'contacts',
                'contact_360',
                'payments',
                'allocations',
                'recurrences',
                'reminders',
                'catalog',
                'definitions',
                'capabilities',
            ],
            array_keys($apiBillingJson['data'] ?? []),
            'contrat facturation complet et stable'
        );
        $this->same(
            'CHF',
            $apiBillingJson['data']['catalog']['currencies'][0]['code'] ?? '',
            'devise de base disponible pour créer un document'
        );
        $this->same(
            '2026-06-30',
            $apiBillingJson['data']['reference_date'] ?? '',
            'date de référence visible dans le contrat facturation'
        );
        $billingExport = $app->handle(new Request(
            'GET',
            '/api/v1/facturation/export',
            query: ['as_of_date' => '2026-06-30', 'direction' => 'sales']
        ));
        $this->same(200, $billingExport->status, 'export facturation filtré accessible');
        $this->true(
            str_contains($billingExport->body, 'date_reference')
            && str_contains($billingExport->body, '2026-06-30'),
            'export facturation conserve la date de référence'
        );
        $billingWithoutCsrf = $app->handle(new Request(
            'POST',
            '/api/v1/facturation/contacts',
            json: ['data' => []]
        ));
        $this->same(
            403,
            $billingWithoutCsrf->status,
            'mutation de facturation sans CSRF refusée'
        );
        $apiConfiguration = $app->handle(new Request(
            'GET',
            '/api/v1/configuration'
        ));
        $apiConfigurationJson = $this->responseJson($apiConfiguration);
        $this->same(
            200,
            $apiConfiguration->status,
            'configuration centralisée exposée en API'
        );
        $apiConsolidation = $app->handle(new Request(
            'GET',
            '/api/v1/consolidation'
        ));
        $this->same(
            200,
            $apiConsolidation->status,
            'consolidation isolée exposée en API'
        );
        $this->same(
            [],
            $this->responseJson($apiConsolidation)['data']['groups'] ?? null,
            'contrat consolidation explicite sans groupe'
        );
        $consolidationWithoutCsrf = $app->handle(new Request(
            'POST',
            '/api/v1/consolidation/groups',
            json: ['data' => [
                'mode' => 'agregation_interne',
                'code' => 'HTTP-AGG',
                'label' => 'Agrégation HTTP',
                'currency' => 'CHF',
                'valid_from' => '2026-01-01',
            ]]
        ));
        $this->same(
            403,
            $consolidationWithoutCsrf->status,
            'assistant d’agrégation protégé par CSRF'
        );
        $consolidationWithoutMode = $app->handle(new Request(
            'POST',
            '/api/v1/consolidation/groups',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'code' => 'HTTP-SANS-MODE',
                'label' => 'Mode absent',
                'currency' => 'CHF',
                'valid_from' => '2026-01-01',
            ]]
        ));
        $this->same(
            422,
            $consolidationWithoutMode->status,
            'mode obligatoire contrôlé par le contrat HTTP'
        );
        $consolidationCreated = $app->handle(new Request(
            'POST',
            '/api/v1/consolidation/groups',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'mode' => 'agregation_interne',
                'code' => 'HTTP-AGG',
                'label' => 'Agrégation HTTP',
                'currency' => 'CHF',
                'valid_from' => '2026-01-01',
            ]]
        ));
        $consolidationCreatedJson = $this->responseJson($consolidationCreated);
        $this->same(
            200,
            $consolidationCreated->status,
            'brouillon d’agrégation créé par l’API versionnée'
        );
        $consolidationGroupId = (int) (
            $consolidationCreatedJson['data']['id'] ?? 0
        );
        $consolidationWorkspace = $app->handle(new Request(
            'GET',
            '/api/v1/consolidation',
            query: ['group_id' => (string) $consolidationGroupId]
        ));
        $consolidationWorkspaceJson = $this->responseJson(
            $consolidationWorkspace
        );
        $this->same(
            'agregation_interne',
            $consolidationWorkspaceJson['data']['selected_group']['mode'] ?? '',
            'mode distinct exposé sans ambiguïté'
        );
        $this->true(
            count(
                $consolidationWorkspaceJson['data']['available_members'] ?? []
            ) >= 1,
            'assistant propose uniquement des dossiers effectivement visibles'
        );
        $this->false(
            array_key_exists('references', $apiConfigurationJson['data'] ?? []),
            'aucune projection parallèle des référentiels dans Configuration'
        );
        $managedReferences = $app->handle(new Request(
            'GET',
            '/api/v1/configuration/references'
        ));
        $managedReferencesJson = $this->responseJson($managedReferences);
        $this->same(
            200,
            $managedReferences->status,
            'référentiels gérés exposés dans Configuration'
        );
        $this->false(
            str_contains($managedReferences->body, 'legacy'),
            'contrat des référentiels sans trace de navigation historique'
        );
        $this->same(
            [],
            $managedReferencesJson['data']['payroll']['suggested_rates'] ?? null,
            'aucun millésime OCAS inventé dans Configuration'
        );
        $this->same(
            4,
            count($managedReferencesJson['data']['vat']['legal_rates'] ?? []),
            'taux TVA légaux exposés sans copie côté Vue'
        );
        $this->true(
            isset(
                $managedReferencesJson['data']['treasury'],
                $managedReferencesJson['data']['accounting_setup']
            ),
            'référentiels métier de Configuration servis par leur contrat natif'
        );
        $currencyFromConfiguration = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/currencies',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => ['currency' => 'EUR', 'active' => true]]
        ));
        $this->same(
            200,
            $currencyFromConfiguration->status,
            'devise ajoutée depuis Configuration sans erreur interne'
        );
        $this->same(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM devises_dossier
                 WHERE dossier_id = {$ids['dossier_a']}
                   AND code = 'EUR' AND actif = 1"
            )->fetchColumn(),
            'devise configurée dans le dossier courant'
        );
        $exchangeAccounts = $pdo->query(
            "SELECT id FROM comptes
             WHERE dossier_id = {$ids['dossier_a']}
               AND actif = 1 AND imputable = 1
             ORDER BY id LIMIT 2"
        )->fetchAll(PDO::FETCH_COLUMN);
        $mappingFromConfiguration = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/exchange-mapping',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'realized_gain_account_id' => (int) $exchangeAccounts[0],
                'realized_loss_account_id' => (int) $exchangeAccounts[1],
                'unrealized_gain_account_id' => (int) $exchangeAccounts[0],
                'unrealized_loss_account_id' => (int) $exchangeAccounts[1],
            ]]
        ));
        $this->same(
            200,
            $mappingFromConfiguration->status,
            'comptes de change modifiés depuis Configuration sans erreur interne'
        );
        $this->same(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM parametres_change
                 WHERE dossier_id = {$ids['dossier_a']}"
            )->fetchColumn(),
            'comptes de différences de change persistés'
        );
        $treasuryLedgerId = (int) $pdo->query(
            "SELECT id FROM comptes
             WHERE dossier_id = {$ids['dossier_a']} AND numero = '1000'"
        )->fetchColumn();
        $treasuryFromConfiguration = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/treasury-accounts',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'id' => 0,
                'version' => 0,
                'ledger_account_id' => $treasuryLedgerId,
                'label' => 'Caisse Vue',
                'type' => 'caisse',
                'iban' => '',
                'bic' => '',
                'currency' => 'CHF',
                'accounting_multiplier' => 1,
                'active' => true,
            ]]
        ));
        $this->same(
            200,
            $treasuryFromConfiguration->status,
            'compte de trésorerie créé depuis Configuration Vue'
        );
        $this->same(
            'Caisse Vue',
            (string) $pdo->query(
                "SELECT libelle FROM comptes_tresorerie
                 WHERE dossier_id = {$ids['dossier_a']}"
            )->fetchColumn(),
            'compte de trésorerie persisté par son service métier'
        );
        $configurationTreasuryId = (int) (
            $this->responseJson($treasuryFromConfiguration)['data']['id'] ?? 0
        );
        $updatedTreasuryFromConfiguration = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/treasury-accounts',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'id' => $configurationTreasuryId,
                'version' => 1,
                'ledger_account_id' => $treasuryLedgerId,
                'label' => 'Caisse Vue fermée',
                'type' => 'caisse',
                'iban' => '',
                'bic' => '',
                'currency' => 'CHF',
                'accounting_multiplier' => 1,
                'active' => false,
            ]]
        ));
        $this->same(
            200,
            $updatedTreasuryFromConfiguration->status,
            'compte de trésorerie modifié avec contrôle optimiste'
        );
        $this->same(
            'Caisse Vue fermée|0|2',
            (string) $pdo->query(
                "SELECT libelle || '|' || actif || '|' || version
                 FROM comptes_tresorerie WHERE id = {$configurationTreasuryId}"
            )->fetchColumn(),
            'désactivation du compte conservée sans suppression'
        );
        $journalFromConfiguration = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/journals',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'id' => 0,
                'version' => 0,
                'code' => 'CFG',
                'label' => 'Configuration Vue',
                'type' => 'general',
                'active' => true,
            ]]
        ));
        $this->same(
            200,
            $journalFromConfiguration->status,
            'journal créé depuis Configuration Vue'
        );
        $exerciseFromConfiguration = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/exercises',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'id' => 0,
                'version' => 0,
                'label' => 'Exercice 2030',
                'start_date' => '2030-01-01',
                'end_date' => '2030-12-31',
                'status' => 'ouvert',
            ]]
        ));
        $this->same(
            200,
            $exerciseFromConfiguration->status,
            'exercice créé depuis Configuration Vue'
        );
        $configurationExerciseId = (int) (
            $this->responseJson($exerciseFromConfiguration)['data']['id'] ?? 0
        );
        $periodFromConfiguration = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/periods',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'id' => 0,
                'version' => 0,
                'exercise_id' => $configurationExerciseId,
                'label' => 'Janvier 2030',
                'start_date' => '2030-01-01',
                'end_date' => '2030-01-31',
                'status' => 'ouverte',
            ]]
        ));
        $this->same(
            200,
            $periodFromConfiguration->status,
            'période créée depuis Configuration Vue'
        );
        $configurationPeriodId = (int) (
            $this->responseJson($periodFromConfiguration)['data']['id'] ?? 0
        );
        $closePeriodFromConfiguration = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/periods',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'id' => $configurationPeriodId,
                'version' => 1,
                'exercise_id' => $configurationExerciseId,
                'label' => 'Janvier 2030',
                'start_date' => '2030-01-01',
                'end_date' => '2030-01-31',
                'status' => 'fermee',
            ]]
        ));
        $this->same(
            200,
            $closePeriodFromConfiguration->status,
            'période fermée avec contrôle optimiste depuis Vue'
        );
        $closeExerciseFromConfiguration = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/exercises',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'id' => $configurationExerciseId,
                'version' => 1,
                'label' => 'Exercice 2030',
                'start_date' => '2030-01-01',
                'end_date' => '2030-12-31',
                'status' => 'ferme',
            ]]
        ));
        $this->same(
            200,
            $closeExerciseFromConfiguration->status,
            'exercice fermé seulement après ses périodes'
        );
        $reopenPeriodInClosedExercise = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/periods',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'id' => $configurationPeriodId,
                'version' => 2,
                'exercise_id' => $configurationExerciseId,
                'label' => 'Janvier 2030',
                'start_date' => '2030-01-01',
                'end_date' => '2030-01-31',
                'status' => 'ouverte',
            ]]
        ));
        $this->same(
            422,
            $reopenPeriodInClosedExercise->status,
            'période non réouvrable dans un exercice fermé'
        );
        $readerRoleId = (int) $pdo->query(
            "SELECT id FROM roles WHERE code = 'lecteur'"
        )->fetchColumn();
        $accessFromConfiguration = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/access',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'user_id' => $accessUserId,
                'role_ids' => [$readerRoleId],
            ]]
        ));
        $this->same(
            404,
            $accessFromConfiguration->status,
            'ancienne mutation non prévisualisée retirée'
        );
        $dossierManagerAccess = $app->handle(new Request(
            'GET',
            '/api/v1/structures/access',
            query: [
                'scope' => 'dossier',
                'organisation_id' => (string) $ids['organisation_a'],
                'dossier_id' => (string) $ids['dossier_a'],
            ]
        ));
        $this->same(
            404,
            $dossierManagerAccess->status,
            'dossier.manage ne permet aucune auto-attribution'
        );
        $session->set('user_id', $registryAdminId);
        $usersCsvExportResponse = $app->handle(new Request(
            'GET',
            '/api/v1/structures/users/export'
        ));
        $this->same(
            200,
            $usersCsvExportResponse->status,
            'export CSV utilisateurs réservé à l’administrateur'
        );
        $this->true(
            str_contains(
                $usersCsvExportResponse->headers['Content-Disposition'] ?? '',
                'utilisateurs.csv'
            ),
            'export HTTP nomme explicitement le premier CSV'
        );
        $accessCsvExportResponse = $app->handle(new Request(
            'GET',
            '/api/v1/structures/access/export'
        ));
        $this->same(
            200,
            $accessCsvExportResponse->status,
            'export HTTP des rôles et accès disponible séparément'
        );
        $csvHttpPayload = [
            'users_csv' => implode("\n", [
                'email;prenom;nom;actif;mot_de_passe',
                'registry-admin-http@example.test;;;1;',
                '',
            ]),
            'access_csv' => implode("\n", [
                'email;portee;organisation;dossier_slug;role',
                'registry-admin-http@example.test;installation;;;administrateur',
                '',
            ]),
        ];
        $csvPreviewWithoutCsrf = $app->handle(new Request(
            'POST',
            '/api/v1/structures/access/csv-preview',
            json: ['data' => $csvHttpPayload]
        ));
        $this->same(
            403,
            $csvPreviewWithoutCsrf->status,
            'prévisualisation CSV protégée par CSRF'
        );
        $csvPreviewResponse = $app->handle(new Request(
            'POST',
            '/api/v1/structures/access/csv-preview',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => $csvHttpPayload]
        ));
        $this->same(
            200,
            $csvPreviewResponse->status,
            'prévisualisation coordonnée des deux CSV exposée par l’API'
        );
        $accessMatrixResponse = $app->handle(new Request(
            'GET',
            '/api/v1/structures/access',
            query: [
                'scope' => 'dossier',
                'organisation_id' => (string) $ids['organisation_a'],
                'dossier_id' => (string) $ids['dossier_a'],
            ]
        ));
        $this->same(
            200,
            $accessMatrixResponse->status,
            'matrice d’accès structure exposée à l’administrateur'
        );
        $accessMatrixJson = $this->responseJson($accessMatrixResponse);
        $accessVersion = (string) (
            $accessMatrixJson['data']['version'] ?? ''
        );
        $accessPreviewPayload = [
            'scope' => 'dossier',
            'organisation_id' => $ids['organisation_a'],
            'dossier_id' => $ids['dossier_a'],
            'user_id' => $accessUserId,
            'role_ids' => [$readerRoleId],
            'expected_version' => $accessVersion,
        ];
        $accessWithoutCsrf = $app->handle(new Request(
            'POST',
            '/api/v1/structures/access/preview',
            json: ['data' => $accessPreviewPayload]
        ));
        $this->same(
            403,
            $accessWithoutCsrf->status,
            'prévisualisation de mutation protégée par CSRF'
        );
        $accessPreviewResponse = $app->handle(new Request(
            'POST',
            '/api/v1/structures/access/preview',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => $accessPreviewPayload]
        ));
        $this->same(200, $accessPreviewResponse->status, 'aperçu RBAC exposé');
        $accessPreviewJson = $this->responseJson($accessPreviewResponse);
        $applyPayload = [
            ...$accessPreviewPayload,
            'confirmation_token' => (
                $accessPreviewJson['data']['confirmation_token'] ?? ''
            ),
        ];
        $accessApplyResponse = $app->handle(new Request(
            'POST',
            '/api/v1/structures/access/apply',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => $applyPayload]
        ));
        $this->same(200, $accessApplyResponse->status, 'attribution RBAC confirmée');
        $this->same(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM utilisateur_roles_dossier
                 WHERE utilisateur_id = {$accessUserId}
                   AND dossier_id = {$ids['dossier_a']}
                   AND role_id = {$readerRoleId}"
            )->fetchColumn(),
            'affectation persistée uniquement dans la table RBAC canonique'
        );
        $session->set('user_id', $accessUserId);
        $session->set('organisation_id', $ids['organisation_a']);
        $session->set('dossier_id', $ids['dossier_a']);
        $accessUserContext = $app->handle(new Request(
            'GET',
            '/api/v1/context'
        ));
        $this->same(
            $ids['dossier_a'],
            $this->responseJson($accessUserContext)['data']['selection']['dossier']['id']
                ?? 0,
            'seconde session voit le dossier attribué'
        );
        $session->set('user_id', $registryAdminId);
        $updatedMatrixResponse = $app->handle(new Request(
            'GET',
            '/api/v1/structures/access',
            query: [
                'scope' => 'dossier',
                'organisation_id' => (string) $ids['organisation_a'],
                'dossier_id' => (string) $ids['dossier_a'],
            ]
        ));
        $updatedVersion = (string) (
            $this->responseJson($updatedMatrixResponse)['data']['version'] ?? ''
        );
        $removePayload = [
            ...$accessPreviewPayload,
            'role_ids' => [],
            'expected_version' => $updatedVersion,
        ];
        $removePreviewResponse = $app->handle(new Request(
            'POST',
            '/api/v1/structures/access/preview',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => $removePayload]
        ));
        $removePayload['confirmation_token'] = (
            $this->responseJson($removePreviewResponse)['data']['confirmation_token']
            ?? ''
        );
        $removeResponse = $app->handle(new Request(
            'POST',
            '/api/v1/structures/access/apply',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => $removePayload]
        ));
        $this->same(200, $removeResponse->status, 'révocation directe confirmée');
        $session->set('user_id', $accessUserId);
        $revokedContext = $app->handle(new Request('GET', '/api/v1/context'));
        $this->same(
            null,
            $this->responseJson($revokedContext)['data']['selection'] ?? null,
            'contexte révoqué dès la requête suivante de l’autre session'
        );
        $revokedSelector = $app->handle(new Request('GET', '/api/v1/dossiers'));
        $this->same(
            [],
            $this->responseJson($revokedSelector)['data'] ?? null,
            'sélecteur mis à jour immédiatement après révocation'
        );
        $session->set('user_id', $registryManagerId);
        $foreignAccess = $app->handle(new Request(
            'GET',
            '/api/v1/structures/access',
            query: [
                'scope' => 'organisation',
                'organisation_id' => (string) $ids['organisation_b'],
            ]
        ));
        $this->same(404, $foreignAccess->status, 'IDOR organisation masquée');
        $session->set('user_id', $userId);
        $session->set('organisation_id', $ids['organisation_a']);
        $session->set('dossier_id', $ids['dossier_a']);
        $contactFromConfiguration = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/contacts',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'id' => 0,
                'version' => 0,
                'type' => 'entreprise',
                'company' => 'Contact Vue SA',
                'first_name' => '',
                'last_name' => '',
                'email' => 'contact-vue@example.test',
                'phone' => '+41 22 000 00 00',
                'language' => 'fr',
                'roles' => ['client', 'fournisseur'],
                'address_line1' => 'Rue de Vue 1',
                'address_line2' => '',
                'postal_code' => '1200',
                'city' => 'Genève',
                'country' => 'CH',
            ]]
        ));
        $this->same(
            200,
            $contactFromConfiguration->status,
            'contact multi-rôles créé depuis Configuration Vue'
        );
        $this->same(
            2,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM contact_roles r
                 JOIN contacts c ON c.id = r.contact_id
                 WHERE c.raison_sociale = 'Contact Vue SA'"
            )->fetchColumn(),
            'rôles du contact persistés par le service de Facturation'
        );
        $createdContactId = (int) (
            $this->responseJson($contactFromConfiguration)['data']['id'] ?? 0
        );
        $createdContactVersion = (int) $pdo->query(
            "SELECT version FROM contacts WHERE id = {$createdContactId}"
        )->fetchColumn();
        $updatedContact = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/contacts',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'id' => $createdContactId,
                'version' => $createdContactVersion,
                'type' => 'entreprise',
                'company' => 'Contact Vue modifié SA',
                'first_name' => '',
                'last_name' => '',
                'email' => 'contact-vue@example.test',
                'phone' => '+41 22 000 00 01',
                'language' => 'fr',
                'roles' => ['client'],
                'address_line1' => 'Rue de Vue 2',
                'address_line2' => '',
                'postal_code' => '1201',
                'city' => 'Genève',
                'country' => 'CH',
            ]]
        ));
        $this->same(
            200,
            $updatedContact->status,
            'contact édité depuis Configuration Vue'
        );
        $this->same(
            'Contact Vue modifié SA|2',
            (string) $pdo->query(
                "SELECT raison_sociale || '|' || version
                 FROM contacts WHERE id = {$createdContactId}"
            )->fetchColumn(),
            'édition optimiste du contact persistée'
        );
        $pdo->prepare(
            "INSERT INTO documents_financiers
             (organisation_id, dossier_id, contact_id, type, date_document,
              date_echeance, adresse_snapshot_json, contact_snapshot_json)
             VALUES (?, ?, ?, 'facture_client', '2026-01-15', '2026-02-15', '{}', '{}')"
        )->execute([
            $ids['organisation_a'],
            $ids['dossier_a'],
            $createdContactId,
        ]);
        $contactDocumentId = (int) $pdo->lastInsertId();
        $protectedContactDeletion = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/contacts/delete',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'id' => $createdContactId,
                'version' => 2,
            ]]
        ));
        $this->same(
            422,
            $protectedContactDeletion->status,
            'contact attaché à une facture protégé contre la suppression'
        );
        $this->same(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM contacts WHERE id = {$createdContactId}"
            )->fetchColumn(),
            'refus de suppression sans mutation partielle du contact'
        );
        $pdo->exec(
            "DELETE FROM documents_financiers WHERE id = {$contactDocumentId}"
        );
        $employeeContact = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/contacts',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'id' => 0,
                'version' => 0,
                'type' => 'personne',
                'company' => '',
                'first_name' => 'Jeanne',
                'last_name' => 'Salariée',
                'email' => 'jeanne.salariee@example.test',
                'phone' => '+41 22 000 00 02',
                'language' => 'fr',
                'roles' => ['employe'],
                'address_line1' => 'Rue du Travail 3',
                'address_line2' => '',
                'postal_code' => '1202',
                'city' => 'Genève',
                'country' => 'CH',
            ]]
        ));
        $employeeContactId = (int) (
            $this->responseJson($employeeContact)['data']['id'] ?? 0
        );
        $this->same(
            200,
            $employeeContact->status,
            'contact employé créé depuis Configuration'
        );
        $this->same(
            'Jeanne Salariée|1',
            (string) $pdo->query(
                "SELECT prenom || ' ' || nom || '|' || profil_incomplet
                 FROM employes WHERE contact_id = {$employeeContactId}"
            )->fetchColumn(),
            'contact employé immédiatement visible dans Salaires et à compléter'
        );
        $employeeContactVersion = (int) $pdo->query(
            "SELECT version FROM contacts WHERE id = {$employeeContactId}"
        )->fetchColumn();
        $updatedEmployeeContact = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/contacts',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'id' => $employeeContactId,
                'version' => $employeeContactVersion,
                'type' => 'personne',
                'company' => '',
                'first_name' => 'Jeanne',
                'last_name' => 'Salariée Martin',
                'email' => 'jeanne.martin@example.test',
                'phone' => '+41 22 000 00 03',
                'language' => 'fr',
                'roles' => ['employe'],
                'address_line1' => 'Rue du Travail 4',
                'address_line2' => '',
                'postal_code' => '1202',
                'city' => 'Genève',
                'country' => 'CH',
            ]]
        ));
        $this->same(
            200,
            $updatedEmployeeContact->status,
            'contact employé existant modifié sans conflit de référence'
        );
        $this->same(
            'Jeanne Salariée Martin|jeanne.martin@example.test|2',
            (string) $pdo->query(
                "SELECT e.prenom || ' ' || e.nom || '|' || e.email || '|' || c.version
                 FROM employes e
                 JOIN contacts c ON c.id = e.contact_id
                 WHERE e.contact_id = {$employeeContactId}"
            )->fetchColumn(),
            'modification du contact synchronisée vers le profil salarié'
        );
        $deletedEmployeeContact = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/contacts/delete',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'id' => $employeeContactId,
                'version' => 2,
            ]]
        ));
        $this->same(
            200,
            $deletedEmployeeContact->status,
            'contact sans pièce comptable ni fiche de salaire supprimé'
        );
        $this->same(
            '0|0',
            (string) $pdo->query(
                "SELECT
                    (SELECT COUNT(*) FROM contacts WHERE id = {$employeeContactId})
                    || '|' ||
                    (SELECT COUNT(*) FROM employes WHERE contact_id = {$employeeContactId})"
            )->fetchColumn(),
            'contact et profil salarié incomplet supprimés ensemble'
        );
        $normalVatRateId = (int) $pdo->query(
            "SELECT id FROM tva_taux_legaux WHERE categorie = 'normal'"
        )->fetchColumn();
        $vatAccountId = (int) $pdo->query(
            "SELECT id FROM comptes
             WHERE dossier_id = {$ids['dossier_a']} AND numero = '2200'"
        )->fetchColumn();
        $vatCodePayload = [
            'id' => 0,
            'active' => true,
            'code' => 'VUE81',
            'label' => 'Ventes Vue 8,1 %',
            'treatment' => 'normal',
            'nature' => 'collectee',
            'legal_rate_id' => $normalVatRateId,
            'deduction_right' => false,
            'default_deduction_bp' => 0,
            'afc_box' => '200',
            'account_id' => $vatAccountId,
            'valid_from' => '2026-01-01',
            'valid_until' => '',
        ];
        $vatFromConfiguration = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/vat-codes',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => $vatCodePayload]
        ));
        $this->same(
            200,
            $vatFromConfiguration->status,
            'code TVA daté créé depuis Configuration Vue'
        );
        $this->same(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM audit_events
                 WHERE action = 'tva.code_ajoute'"
            )->fetchColumn(),
            'création du code TVA auditée'
        );
        $vatCodeId = (int) ($this->responseJson($vatFromConfiguration)['data']['id'] ?? 0);
        $vatUpdated = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/vat-codes',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => array_replace($vatCodePayload, [
                'id' => $vatCodeId,
                'active' => false,
                'label' => 'Ventes Vue modifiées',
            ])]
        ));
        $this->same(200, $vatUpdated->status, 'code TVA modifié depuis Configuration Vue');
        $this->same(
            'Ventes Vue modifiées|0',
            (string) $pdo->query(
                "SELECT libelle || '|' || actif FROM tva_codes WHERE id = {$vatCodeId}"
            )->fetchColumn(),
            'activation du code TVA administrable'
        );
        $vatDeleted = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/vat-codes/delete',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => ['id' => $vatCodeId]]
        ));
        $this->same(200, $vatDeleted->status, 'code TVA inutilisé supprimé depuis Vue');
        $payrollPayload = [
            'year' => 2026,
            'avs_ppm' => 53000,
            'ac_ppm' => 11000,
            'amat_ppm' => 290,
            'laa_reduit_ppm' => 5300,
            'laa_plein_ppm' => 9600,
            'lpp_ppm' => 70000,
            'emp_avs_ppm' => 53000,
            'emp_ac_ppm' => 11000,
            'emp_amat_ppm' => 290,
            'emp_af_ppm' => 22200,
            'emp_laa_reduit_ppm' => 5300,
            'emp_laa_plein_ppm' => 9600,
            'emp_frais_ppm' => 0,
            'emp_cpe_ppm' => 700,
            'emp_lfp_ppm' => 820,
            'emp_lpp_ppm' => 80000,
            'source' => 'OCAS — test',
            'verified_on' => '2026-07-25',
        ];
        $payrollFromConfiguration = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/payroll-rates',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => $payrollPayload]
        ));
        $this->same(
            200,
            $payrollFromConfiguration->status,
            'taux salariaux OCAS enregistrés depuis Configuration Vue'
        );
        $this->same(
            53000,
            (int) $pdo->query(
                "SELECT avs_ppm FROM taux_salaires_annuels
                 WHERE dossier_id = {$ids['dossier_a']} AND annee = 2026"
            )->fetchColumn(),
            'taux salarial conservé sans flottant'
        );
        $injectedReferenceScope = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/references/payroll-rates',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => $payrollPayload + ['dossier_id' => $ids['dossier_b']]]
        ));
        $this->same(
            422,
            $injectedReferenceScope->status,
            'référentiel refuse un scope injecté'
        );
        $legacyContacts = $app->handle(new Request(
            'GET',
            '/facturation',
            query: ['onglet' => 'contacts']
        ));
        $this->same(
            303,
            $legacyContacts->status,
            'ancien écran de facturation redirigé vers Vue'
        );
        $this->same(
            '/edu/app/facturation',
            $legacyContacts->headers['Location'] ?? '',
            'redirection Facturation compatible avec le sous-répertoire'
        );
        $learningModule = array_values(array_filter(
            $apiConfigurationJson['data']['modules'] ?? [],
            static fn (array $module): bool => $module['code'] === 'apprentissage'
        ))[0] ?? [];
        $configurationWithoutCsrf = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/modules',
            json: ['data' => [
                'code' => 'apprentissage',
                'enabled' => false,
                'version' => (int) ($learningModule['version'] ?? 0),
            ]]
        ));
        $this->same(
            403,
            $configurationWithoutCsrf->status,
            'mutation de configuration sans CSRF refusée'
        );
        $disableLearning = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/modules',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'code' => 'apprentissage',
                'enabled' => false,
                'version' => (int) ($learningModule['version'] ?? 0),
            ]]
        ));
        $this->same(200, $disableLearning->status, 'module désactivable par API');
        $contextWithoutLearning = $this->responseJson(
            $app->handle(new Request('GET', '/api/v1/context'))
        );
        $this->false(
            in_array(
                'learning',
                array_column($contextWithoutLearning['data']['navigation'] ?? [], 'key'),
                true
            ),
            'navigation API omet le module désactivé'
        );
        $this->same(
            403,
            $app->handle(new Request('GET', '/app/apprentissage'))->status,
            'route Vue d’un module désactivé refusée côté serveur'
        );
        $this->same(
            403,
            $app->handle(new Request('GET', '/pedagogie'))->status,
            'route historique d’un module désactivé refusée côté serveur'
        );
        $this->same(
            403,
            $app->handle(new Request('GET', '/api/v1/pedagogie'))->status,
            'route API d’un module désactivé refusée côté serveur'
        );
        $reenableLearning = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/modules',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'code' => 'apprentissage',
                'enabled' => true,
                'version' => (int) ($learningModule['version'] ?? 0) + 1,
            ]]
        ));
        $this->same(200, $reenableLearning->status, 'module réactivable par API');
        $this->same(
            200,
            $app->handle(new Request('GET', '/app/apprentissage'))->status,
            'réactivation restaure la route Vue'
        );
        $invalidConfiguration = $app->handle(new Request(
            'POST',
            '/api/v1/configuration/modules',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'code' => 'salaires',
                'enabled' => true,
                'version' => 1,
                'dossier_id' => $ids['dossier_b'],
            ]]
        ));
        $this->same(
            422,
            $invalidConfiguration->status,
            'configuration refuse les identifiants de scope injectés'
        );
        $apiDashboard = $app->handle(new Request(
            'GET',
            '/api/v1/dashboard',
            query: [
                'exercise_id' => (string) $exerciseId,
                'as_of_date' => '2026-06-30',
            ],
            server: ['HTTP_X_CORRELATION_ID' => 'dashboard-test-0001']
        ));
        $apiDashboardJson = $this->responseJson($apiDashboard);
        $this->same(200, $apiDashboard->status, 'projection du tableau de bord exposée en API');
        $this->same(
            'CHF',
            $apiDashboardJson['data']['scope']['base_currency'] ?? '',
            'contrat du tableau de bord expose la devise de base'
        );
        $this->true(
            (bool) ($apiDashboardJson['data']['empty_state']['is_empty'] ?? false),
            'contrat du tableau de bord expose l’état vide'
        );
        $apiDashboardInvalid = $app->handle(new Request(
            'GET',
            '/api/v1/dashboard',
            query: [
                'exercise_id' => (string) $exerciseId,
                'as_of_date' => '2026-02-30',
                'dossier_id' => (string) $ids['dossier_b'],
            ]
        ));
        $this->same(
            422,
            $apiDashboardInvalid->status,
            'paramètres du tableau de bord strictement validés'
        );
        $apiDashboardIdor = $app->handle(new Request(
            'GET',
            '/api/v1/dashboard',
            query: [
                'exercise_id' => (string) $forbiddenExerciseId,
                'as_of_date' => '2026-06-30',
            ]
        ));
        $this->same(
            422,
            $apiDashboardIdor->status,
            'exercice hors dossier refusé par la projection'
        );
        $this->false(
            str_contains($apiDashboardIdor->body, 'confidentiel')
                || str_contains($apiDashboardIdor->body, 'Comptabilité B'),
            'refus du tableau de bord sans fuite inter-dossiers'
        );
        $shellHomeRedirect = $app->handle(new Request('GET', '/'));
        $this->same(302, $shellHomeRedirect->status, 'feature flag active le shell Vue');
        $this->same(
            '/edu/app',
            $shellHomeRedirect->headers['Location'] ?? '',
            'redirection vers le shell compatible sous-répertoire'
        );
        $deepShell = $app->handle(new Request('GET', '/app/compta/etats'));
        $this->same(200, $deepShell->status, 'rafraîchissement de route Vue profonde');
        $deepCurrencyReference = $app->handle(new Request(
            'GET',
            '/app/configuration/referentiels/currencies'
        ));
        $this->same(
            200,
            $deepCurrencyReference->status,
            'rafraîchissement direct du référentiel Devises et change'
        );
        $this->true(
            str_contains($deepShell->body, 'name="compta-api-base-url" content="/edu/api/v1"')
            && str_contains($deepShell->body, 'type="module"')
            && preg_match('~/edu/app/assets/[^"]+\\.js~', $deepShell->body) === 1,
            'shell injecte seulement chemins publics et assets versionnés'
        );
        $this->true(
            isset($deepShell->headers['Content-Security-Policy']),
            'shell Vue reçoit les en-têtes de sécurité'
        );

        $apiBadSort = $app->handle(new Request(
            'GET',
            '/api/v1/dossiers',
            query: ['sort' => 'sql_injection']
        ));
        $this->same(422, $apiBadSort->status, 'tri hors liste blanche refusé');

        $apiDossiers = $app->handle(new Request(
            'GET',
            '/api/v1/dossiers',
            query: ['page' => '1', 'per_page' => '1', 'sort' => 'name', 'order' => 'asc']
        ));
        $apiDossiersJson = $this->responseJson($apiDossiers);
        $this->same(1, $apiDossiersJson['meta']['pagination']['total'] ?? 0, 'dossiers API isolés');
        $this->same(
            $ids['dossier_a'],
            $apiDossiersJson['data'][0]['id'] ?? 0,
            'liste de dossiers limitée aux droits'
        );

        $apiExercises = $app->handle(new Request(
            'GET',
            '/api/v1/exercises',
            query: [
                'status' => 'ouvert',
                'sort' => 'start_date',
                'order' => 'desc',
                'page' => '1',
                'per_page' => '25',
            ]
        ));
        $apiExercisesJson = $this->responseJson($apiExercises);
        $this->same(200, $apiExercises->status, 'exercices API accessibles');
        $this->same(
            $exerciseId,
            $apiExercisesJson['data'][0]['id'] ?? 0,
            'exercices API limités au dossier courant'
        );
        $this->same(
            1,
            $apiExercisesJson['meta']['pagination']['total'] ?? 0,
            'pagination serveur documentée'
        );
        $apiUrlIdor = $app->handle(new Request(
            'GET',
            '/api/v1/exercises',
            query: ['dossier_id' => (string) $ids['dossier_b']]
        ));
        $this->same(
            422,
            $apiUrlIdor->status,
            'identifiant de dossier injecté dans l’URL refusé'
        );
        $this->false(
            str_contains($apiUrlIdor->body, 'Organisation B')
            || str_contains($apiUrlIdor->body, 'Comptabilité B'),
            'identifiant URL refusé sans fuite inter-dossiers'
        );

        $apiPermissions = $app->handle(new Request('GET', '/api/v1/permissions'));
        $this->true(
            in_array('compta.view', $this->responseJson($apiPermissions)['data'] ?? [], true),
            'permissions effectives exposées'
        );
        $apiNavigation = $app->handle(new Request('GET', '/api/v1/navigation'));
        $this->true(
            str_contains($apiNavigation->body, 'Comptabilité')
            && !str_contains($apiNavigation->body, 'Comptabilité B'),
            'navigation dérivée des permissions sans donnée étrangère'
        );
        $apiReferences = $app->handle(new Request('GET', '/api/v1/references'));
        $this->same(
            'CHF',
            $this->responseJson($apiReferences)['data']['currencies'][0]['code'] ?? '',
            'référentiels du shell exposés'
        );
        foreach ([
            'context.success.json',
            'collection.success.json',
            'dashboard.success.json',
            'configuration.success.json',
            'accounting.success.json',
            'assets.success.json',
            'consolidation.success.json',
            'managed-references.success.json',
            'treasury.success.json',
            'market-data.success.json',
            'billing.success.json',
            'payroll.success.json',
            'pedagogy.success.json',
            'organisations.success.json',
            'dossiers.success.json',
            'structure-access.success.json',
            'error.validation.json',
        ] as $example) {
            $payload = json_decode(
                (string) file_get_contents(
                    dirname(__DIR__) . '/docs/contracts/api-v1/' . $example
                ),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $this->same(
                ['data', 'meta', 'errors'],
                array_keys($payload),
                "exemple de contrat versionné {$example}"
            );
        }

        $missing = $app->handle(new Request('POST', '/context/dossier', post: [
            'dossier_compose' => $ids['organisation_a'] . ':' . $ids['dossier_a'],
        ]));
        $this->same(419, $missing->status, 'POST sans CSRF refusé');

        $forbidden = $app->handle(new Request('POST', '/context/dossier', post: [
            '_csrf' => $csrf->token(),
            'dossier_compose' => $ids['organisation_b'] . ':' . $ids['dossier_b'],
        ]));
        $this->same(403, $forbidden->status, 'IDOR autre organisation refusé');

        $allowed = $app->handle(new Request('POST', '/context/dossier', post: [
            '_csrf' => $csrf->token(),
            'dossier_compose' => $ids['organisation_a'] . ':' . $ids['dossier_a'],
        ]));
        $this->same(303, $allowed->status, 'sélection autorisée redirige');
        $this->same('/edu/app', $allowed->headers['Location'], 'redirection Vue avec base path');
        $this->same($ids['dossier_a'], $session->get('dossier_id'), 'contexte stocké');

        $dashboardRedirect = $app->handle(new Request('GET', '/', query: ['legacy' => '1']));
        $this->same(302, $dashboardRedirect->status, 'accueil redirigé vers Vue');
        $this->same(
            '/edu/app',
            $dashboardRedirect->headers['Location'] ?? '',
            'paramètre historique sans effet'
        );
        $dashboard = $app->handle(new Request('GET', '/app'));
        $this->same(200, $dashboard->status, 'interface Vue accessible');
        $this->true(
            isset($dashboard->headers['Content-Security-Policy']),
            'en-têtes de sécurité présents'
        );
        $this->false(
            str_contains($dashboard->body, 'Interface classique')
            || str_contains($dashboard->body, 'DÉMONSTRATION — DONNÉES FICTIVES'),
            'aucun retour classique ni bandeau de démonstration'
        );
        $accountingHome = $app->handle(new Request('GET', '/compta'));
        $this->same(303, $accountingHome->status, 'ancien espace comptable redirigé');
        $this->same(
            '/edu/app/compta',
            $accountingHome->headers['Location'] ?? '',
            'redirection comptable vers Vue'
        );
        $entryScreen = $app->handle(new Request('GET', '/compta/saisie'));
        $this->same(
            '/edu/app/compta',
            $entryScreen->headers['Location'] ?? '',
            'ancienne saisie redirigée vers Vue'
        );
        $entryCash = $this->accountId($pdo, $ids['dossier_a'], '1000');
        $entrySales = $this->accountId($pdo, $ids['dossier_a'], '3400');
        $apiAccounting = $app->handle(new Request(
            'GET',
            '/api/v1/accounting',
            query: ['exercise_id' => (string) $exerciseId]
        ));
        $apiAccountingJson = $this->responseJson($apiAccounting);
        $this->same(200, $apiAccounting->status, 'espace comptable Vue alimenté par API');
        $this->true(
            count($apiAccountingJson['data']['chart']['accounts'] ?? []) > 100
            && count($apiAccountingJson['data']['chart']['rubrics'] ?? []) > 10,
            'plan et structure exposés depuis la source métier unique'
        );
        $assetWorkspace = $app->handle(new Request(
            'GET',
            '/api/v1/accounting/assets',
            query: ['exercise_id' => (string) $exerciseId]
        ));
        $this->same(
            200,
            $assetWorkspace->status,
            'registre des immobilisations exposé dans Comptabilité Vue'
        );
        $this->same(
            [
                'exercise', 'categories', 'assets', 'selected_asset',
                'reconciliation', 'catalog', 'pagination', 'definitions',
                'capabilities',
            ],
            array_keys($this->responseJson($assetWorkspace)['data'] ?? []),
            'contrat immobilisations complet et stable'
        );
        $assetNoCsrf = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/assets/categories',
            json: ['data' => []]
        ));
        $this->same(
            403,
            $assetNoCsrf->status,
            'mutation immobilisation sans CSRF refusée'
        );
        $httpAssetCategory = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/assets/categories',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'id' => 0,
                'version' => 0,
                'code' => 'INFO',
                'label' => 'Informatique',
                'default_duration_months' => 36,
                'asset_account_id' =>
                    $this->accountId($pdo, $ids['dossier_a'], '1520'),
                'accumulated_depreciation_account_id' =>
                    $this->accountId($pdo, $ids['dossier_a'], '1529'),
                'depreciation_expense_account_id' =>
                    $this->accountId($pdo, $ids['dossier_a'], '6800'),
                'disposal_gain_account_id' =>
                    $this->accountId($pdo, $ids['dossier_a'], '8510'),
                'disposal_loss_account_id' =>
                    $this->accountId($pdo, $ids['dossier_a'], '8500'),
                'active' => true,
            ]]
        ));
        $this->same(
            200,
            $httpAssetCategory->status,
            'catégorie d’immobilisation créée par API'
        );
        $httpAssetCategoryId = (int) (
            $this->responseJson($httpAssetCategory)['data']['id'] ?? 0
        );
        $httpAsset = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/assets/records',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'id' => 0,
                'version' => 0,
                'category_id' => $httpAssetCategoryId,
                'code' => 'PC-001',
                'label' => 'Ordinateur pédagogique',
                'acquisition_reference' => 'FAC-PC-001',
                'acquisition_document_id' => null,
                'acquisition_attachment_id' => null,
                'acquisition_date' => '2026-02-01',
                'in_service_date' => '2026-02-15',
                'acquisition_value_cents' => 240000,
                'residual_value_cents' => 0,
                'duration_months' => 36,
                'note' => '',
            ]]
        ));
        $this->same(
            200,
            $httpAsset->status,
            'fiche et échéancier d’immobilisation créés par API'
        );
        $httpAssetId = (int) (
            $this->responseJson($httpAsset)['data']['id'] ?? 0
        );
        $assetDetail = $app->handle(new Request(
            'GET',
            '/api/v1/accounting/assets',
            query: [
                'exercise_id' => (string) $exerciseId,
                'asset_id' => (string) $httpAssetId,
            ]
        ));
        $assetDetailJson = $this->responseJson($assetDetail);
        $this->true(
            ($assetDetailJson['data']['selected_asset']['code'] ?? '') === 'PC-001'
            && count(
                $assetDetailJson['data']['selected_asset']['schedule'] ?? []
            ) > 30,
            'registre et plan prévisionnel relus depuis la source métier'
        );
        $assetInjectedScope = $app->handle(new Request(
            'GET',
            '/api/v1/accounting/assets',
            query: [
                'exercise_id' => (string) $exerciseId,
                'organisation_id' => (string) $ids['organisation_b'],
            ]
        ));
        $this->same(
            422,
            $assetInjectedScope->status,
            'API immobilisations refuse un scope injecté'
        );
        $entryNoCsrf = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/entries',
            json: ['data' => []]
        ));
        $this->same(403, $entryNoCsrf->status, 'saisie API sans CSRF refusée');
        $quickEntry = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/entries',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'exercise_id' => $exerciseId,
                'journal_id' => $httpJournal,
                'date' => '2026-05-10',
                'label' => '',
                'reference' => 'JOURNAL-VUE',
                'attachment_reference' => '',
                'validate' => true,
                'lines' => [
                    [
                        'account_id' => $entryCash,
                        'label' => '',
                        'debit_cents' => 2530,
                        'credit_cents' => 0,
                    ],
                    [
                        'account_id' => $entrySales,
                        'label' => '',
                        'debit_cents' => 0,
                        'credit_cents' => 2530,
                    ],
                ],
            ]]
        ));
        $this->same(
            200,
            $quickEntry->status,
            'journalisation Vue validée sans libellé d’écriture'
        );
        $this->same(
            2530,
            (int) $pdo->query(
                "SELECT l.debit_centimes FROM lignes_ecriture l
                 JOIN ecritures e ON e.id = l.ecriture_id
                 WHERE e.reference = 'JOURNAL-VUE' AND l.debit_centimes > 0"
            )->fetchColumn(),
            'montant API conservé rigoureusement en centimes'
        );
        $sameAccountEntry = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/entries',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'exercise_id' => $exerciseId,
                'journal_id' => $httpJournal,
                'date' => '2026-05-11',
                'label' => 'Même compte interdit',
                'reference' => 'SAME-ACCOUNT',
                'attachment_reference' => '',
                'validate' => true,
                'lines' => [
                    ['account_id' => $entryCash, 'label' => '', 'debit_cents' => 1000, 'credit_cents' => 0],
                    ['account_id' => $entryCash, 'label' => '', 'debit_cents' => 0, 'credit_cents' => 1000],
                ],
            ]]
        ));
        $this->same(422, $sameAccountEntry->status, 'API refuse une écriture sur un seul compte');
        $this->same(
            0,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM ecritures WHERE reference = 'SAME-ACCOUNT'"
            )->fetchColumn(),
            'refus API sans écriture partielle'
        );
        $apiLedger = $app->handle(new Request(
            'GET',
            '/api/v1/accounting',
            query: [
                'exercise_id' => (string) $exerciseId,
                'account_id' => (string) $entryCash,
            ]
        ));
        $apiLedgerJson = $this->responseJson($apiLedger);
        $this->true(
            ($apiLedgerJson['data']['ledger']['account']['numero'] ?? '') === '1000'
            && ($apiLedgerJson['data']['ledger']['total_debit_centimes'] ?? 0) >= 2530,
            'extrait liste et compte en T alimentés par la même projection'
        );
        $this->true(
            isset(
                $apiLedgerJson['data']['reports']['trial_balance'],
                $apiLedgerJson['data']['reports']['balance_sheet'],
                $apiLedgerJson['data']['reports']['income_statement'],
                $apiLedgerJson['data']['reports']['cash_flow'],
                $apiLedgerJson['data']['vat']['standard'],
                $apiLedgerJson['data']['closing']['automatic_controls'],
                $apiLedgerJson['data']['tax_file']['official_declaration']
            ),
            'rapports, TVA, clôture et dossier fiscal exposés dans le contrat Vue'
        );
        $this->true(
            ($apiLedgerJson['data']['reports']['controls']['debit_equals_credit'] ?? false)
            && ($apiLedgerJson['data']['reports']['controls']['balance_sheet_balanced'] ?? false)
            && ($apiLedgerJson['data']['reports']['controls']['result_reconciled'] ?? false)
            && ($apiLedgerJson['data']['reports']['controls']['cash_reconciled'] ?? false),
            'contrôles de cohérence financière exposés au client'
        );
        $this->same(
            false,
            $apiLedgerJson['data']['tax_file']['official_declaration'] ?? null,
            'dossier fiscal API explicitement préparatoire'
        );
        $archivePayload = ['data' => [
            'exercise_id' => $exerciseId,
            'type' => 'cloture',
            'date_start' => '2026-01-01',
            'date_end' => '2026-12-31',
        ]];
        $archiveWithoutCsrf = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/archives',
            json: $archivePayload
        ));
        $this->same(403, $archiveWithoutCsrf->status, 'archive financière sans CSRF refusée');
        $archiveResponse = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/archives',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: $archivePayload
        ));
        $archiveJson = $this->responseJson($archiveResponse);
        $archiveId = (int) ($archiveJson['data']['id'] ?? 0);
        $this->true(
            $archiveResponse->status === 200 && $archiveId > 0,
            'archive financière créée depuis Vue'
        );
        $archiveReplay = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/archives',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: $archivePayload
        ));
        $this->same(
            $archiveId,
            (int) ($this->responseJson($archiveReplay)['data']['id'] ?? 0),
            'archive financière API idempotente'
        );
        $archiveDownload = $app->handle(new Request(
            'GET',
            '/api/v1/accounting/archives/download',
            query: ['archive_id' => (string) $archiveId]
        ));
        $this->true(
            $archiveDownload->status === 200
            && strlen($archiveDownload->headers['X-Content-SHA256'] ?? '') === 64
            && hash(
                'sha256',
                $archiveDownload->body
            ) === ($archiveDownload->headers['X-Content-SHA256'] ?? ''),
            'archive téléchargée avec empreinte vérifiée'
        );
        $grandLivreGateway = $app->handle(new Request('GET', '/compta/grand-livre'));
        $this->same(303, $grandLivreGateway->status, 'ancien grand livre redirigé');
        $this->same(
            '/edu/app/compta/etats',
            $grandLivreGateway->headers['Location'] ?? '',
            'grand livre servi uniquement par Vue'
        );
        $this->true(
            isset($apiLedgerJson['data']['reports']['general_ledger']['items']),
            'grand livre synthétique alimenté par la projection financière unique'
        );
        $entryPost = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/entries',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'exercise_id' => $exerciseId,
                'journal_id' => $httpJournal,
                'date' => '2026-06-15',
                'label' => 'Vente saisie dans Vue',
                'reference' => 'UI-VUE-08',
                'attachment_reference' => '',
                'validate' => true,
                'lines' => [
                    ['account_id' => $entryCash, 'label' => '', 'debit_cents' => 12550, 'credit_cents' => 0],
                    ['account_id' => $entrySales, 'label' => '', 'debit_cents' => 0, 'credit_cents' => 12550],
                ],
            ]]
        ));
        $this->same(200, $entryPost->status, 'écriture équilibrée validée depuis Vue');
        $this->same(
            'validee',
            (string) $pdo->query(
                "SELECT statut FROM ecritures WHERE reference = 'UI-VUE-08'"
            )->fetchColumn(),
            'contrôle serveur conservé derrière l’interface Vue'
        );
        $journalScreen = $app->handle(new Request('GET', '/compta/journal', query: [
            'exercice' => (string) $exerciseId,
        ]));
        $this->same(303, $journalScreen->status, 'ancien journal redirigé');
        $this->same(
            '/edu/app/compta',
            $journalScreen->headers['Location'] ?? '',
            'journal servi uniquement par Vue'
        );
        $billingScreen = $app->handle(new Request('GET', '/facturation'));
        $this->same(303, $billingScreen->status, 'ancien écran facturation retiré');
        $this->same(
            '/edu/app/facturation',
            $billingScreen->headers['Location'] ?? '',
            'facturation servie uniquement par Vue'
        );
        $salaryScreen = $app->handle(new Request('GET', '/salaires'));
        $this->same(303, $salaryScreen->status, 'ancien écran des salaires retiré');
        $this->same(
            '/edu/app/salaires',
            $salaryScreen->headers['Location'] ?? '',
            'salaires servis uniquement par Vue'
        );
        $salaryWorkspace = $app->handle(new Request(
            'GET',
            '/api/v1/salaires',
            query: ['year' => '2026']
        ));
        $salaryWorkspaceJson = $this->responseJson($salaryWorkspace);
        $this->same(
            200,
            $salaryWorkspace->status,
            'espace salarial Vue alimenté par API'
        );
        $this->true(
            isset(
                $salaryWorkspaceJson['data']['employees'],
                $salaryWorkspaceJson['data']['payrolls'],
                $salaryWorkspaceJson['data']['payments'],
                $salaryWorkspaceJson['data']['annual'],
                $salaryWorkspaceJson['data']['certificates'],
                $salaryWorkspaceJson['data']['configuration'],
                $salaryWorkspaceJson['data']['capabilities']
            ),
            'contrat salarial Vue complet et stable'
        );
        $this->false(
            (bool) $salaryWorkspaceJson['data']['configuration']['employer_ready'],
            'absence d’employeur exposée comme prérequis plutôt que comme erreur de chargement'
        );
        $this->same(
            'Organisation A',
            (string) ($salaryWorkspaceJson['data']['employer_suggestion']['nom'] ?? ''),
            'employeur salarial prérempli depuis l’identité légale'
        );
        $salaryScopeInjection = $app->handle(new Request(
            'GET',
            '/api/v1/salaires',
            query: ['year' => '2026', 'dossier_id' => (string) $ids['dossier_b']]
        ));
        $this->same(
            422,
            $salaryScopeInjection->status,
            'API salaires refuse un scope injecté'
        );
        $ocasNoCsrf = $app->handle(new Request(
            'POST',
            '/api/v1/salaires/taux-ocas/previsualiser',
            json: ['data' => ['year' => 2026]]
        ));
        $this->same(403, $ocasNoCsrf->status, 'prévisualisation OCAS exige CSRF');
        $ocasMissing = $app->handle(new Request(
            'POST',
            '/api/v1/salaires/taux-ocas/previsualiser',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => ['year' => 2026]]
        ));
        $this->false(
            (bool) ($this->responseJson($ocasMissing)['data']['available'] ?? true),
            'API n’invente aucun taux lorsque la base OCAS manque'
        );
        $this->false(
            str_contains($salaryScreen->body, 'Swissdec'),
            'aucun écran salarial ne prétend transmettre via Swissdec'
        );
        $pedagogyScreen = $app->handle(new Request('GET', '/pedagogie'));
        $this->same(303, $pedagogyScreen->status, 'ancien écran d’enseignement retiré');
        $this->same(
            '/edu/app/apprentissage',
            $pedagogyScreen->headers['Location'] ?? '',
            'apprentissage servi uniquement par Vue'
        );
        $pedagogyCatalog = $app->handle(new Request(
            'POST',
            '/api/v1/pedagogie/catalogue/installer',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => []]
        ));
        $this->same(
            200,
            $pedagogyCatalog->status,
            'sept parcours ciblés installables depuis Vue'
        );
        $pedagogyWorkspace = $app->handle(new Request(
            'GET',
            '/api/v1/pedagogie'
        ));
        $pedagogyWorkspaceJson = $this->responseJson($pedagogyWorkspace);
        $this->same(
            200,
            $pedagogyWorkspace->status,
            'espace d’apprentissage Vue alimenté par API'
        );
        $this->same(
            7,
            count($pedagogyWorkspaceJson['data']['catalog'] ?? []),
            'catalogue API couvre les sept compétences'
        );
        $this->false(
            str_contains($pedagogyWorkspace->body, 'Débit 1000 Caisse'),
            'API ne livre aucune correction protégée dans le workspace'
        );
        $modelsBeforeInvalid = (int) $pdo->query(
            'SELECT COUNT(*) FROM modeles_exercice'
        )->fetchColumn();
        $invalidPedagogyModel = $app->handle(new Request(
            'POST',
            '/api/v1/pedagogie/modeles',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'title' => 'Scénario incomplet',
                'description' => '',
                'competence' => 'debit_credit',
                'level' => 'debutant',
                'duration_minutes' => 20,
                'instructions' => 'Consigne présente.',
                'steps' => [['code' => 'X']],
                'opening' => [],
                'initial' => [],
                'solution' => [],
                'correction_rule' => 'manuelle',
                'correction_value' => '',
            ]]
        ));
        $this->same(
            422,
            $invalidPedagogyModel->status,
            'publication pédagogique incomplète refusée'
        );
        $this->same(
            $modelsBeforeInvalid,
            (int) $pdo->query(
                'SELECT COUNT(*) FROM modeles_exercice'
            )->fetchColumn(),
            'échec de publication sans modèle orphelin'
        );

        $httpModel = $httpPedagogy->createModel(
            $ids['organisation_a'], 'Modèle HTTP'
        );
        $httpVersion = $httpPedagogy->createVersion(
            $ids['organisation_a'],
            $httpModel,
            $ids['dossier_a'],
            'Test de collaboration HTTP.',
            [[
                'code' => 'HTTP',
                'titre' => 'Brouillon partagé',
                'consigne' => 'Modifier le brouillon.',
                'regles' => [[
                    'type' => 'ecriture_equivalente',
                    'configuration' => [
                        'lignes' => [
                            ['compte' => '1000', 'sens' => 'debit', 'montant_centimes' => 1000],
                            ['compte' => '3400', 'sens' => 'credit', 'montant_centimes' => 1000],
                        ],
                    ],
                    'message_succes' => 'Écriture HTTP correcte.',
                    'message_echec' => 'Écriture HTTP à corriger.',
                ]],
            ]],
            [],
            [],
            ['explication' => 'Correction HTTP protégée.'],
            'manuelle'
        );
        $httpAssignment = $httpPedagogy->assignIndividual(
            $ids['organisation_a'],
            $httpVersion,
            $userId,
            'Copie HTTP'
        );
        $httpDossier = (int) $pdo->query(
            "SELECT dossier_id FROM assignations_exercice WHERE id = {$httpAssignment}"
        )->fetchColumn();
        $httpExercise = (int) $pdo->query(
            "SELECT id FROM exercices WHERE dossier_id = {$httpDossier}"
        )->fetchColumn();
        $httpCopiedJournal = (int) $pdo->query(
            "SELECT id FROM journaux WHERE dossier_id = {$httpDossier} AND code = 'OD'"
        )->fetchColumn();
        $httpCash = $this->accountId($pdo, $httpDossier, '1000');
        $httpSales = $this->accountId($pdo, $httpDossier, '3400');
        $httpCommand = [
            'exercice_id' => $httpExercise,
            'journal_id' => $httpCopiedJournal,
            'date_comptable' => '2026-03-01',
            'libelle' => 'Concurrence HTTP',
            'lignes' => [
                ['compte_id' => $httpCash, 'debit_centimes' => 1000],
                ['compte_id' => $httpSales, 'credit_centimes' => 1000],
            ],
        ];
        $httpDraft = $httpPedagogy->createDraft(
            $ids['organisation_a'], $httpDossier, $userId, $httpCommand
        );
        $httpDraftVersion = (int) $pdo->query(
            "SELECT version FROM ecritures WHERE id = {$httpDraft}"
        )->fetchColumn();
        $httpPedagogy->replaceDraft(
            $ids['organisation_a'],
            $httpDossier,
            $userId,
            $httpDraft,
            $httpDraftVersion,
            $httpCommand
        );
        $session->set('dossier_id', $httpDossier);
        $exerciseWorkspace = $app->handle(new Request(
            'GET',
            '/api/v1/pedagogie'
        ));
        $exerciseWorkspaceJson = $this->responseJson($exerciseWorkspace);
        $httpStep = (int) (
            $exerciseWorkspaceJson['data']['selected']['steps'][0]['id'] ?? 0
        );
        $this->same(
            $httpAssignment,
            (int) ($exerciseWorkspaceJson['data']['selected']['id'] ?? 0),
            'API ouvre uniquement la copie assignée du contexte'
        );
        $correctionHidden = $app->handle(new Request(
            'GET',
            '/api/v1/pedagogie/correction'
        ));
        $this->same(
            422,
            $correctionHidden->status,
            'correction protégée refusée avant autorisation'
        );
        $attemptHttp = $app->handle(new Request(
            'POST',
            '/api/v1/pedagogie/tentatives',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'step_id' => $httpStep,
                'entry_id' => $httpDraft,
            ]]
        ));
        $this->true(
            (bool) ($this->responseJson($attemptHttp)['data']['reussie'] ?? false),
            'tentative Vue validée par équivalence comptable'
        );
        $session->set('dossier_id', $ids['dossier_a']);
        $authorizeHttp = $app->handle(new Request(
            'POST',
            '/api/v1/pedagogie/correction/autoriser',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => ['assignment_id' => $httpAssignment]]
        ));
        $this->same(200, $authorizeHttp->status, 'formateur autorise la correction par API');
        $session->set('dossier_id', $httpDossier);
        $correctionVisible = $app->handle(new Request(
            'GET',
            '/api/v1/pedagogie/correction'
        ));
        $this->same(
            'Correction HTTP protégée.',
            (string) (
                $this->responseJson($correctionVisible)['data']['solution']['explication']
                ?? ''
            ),
            'correction livrée seulement après autorisation'
        );

        $realExerciseDossier = (new ScopeManager($pdo, $httpAudit))->createDossier(
            $ids['organisation_a'],
            'Dossier réel HTTP',
            'dossier-reel-http',
            'reel'
        );
        (new ScopeManager($pdo, $httpAudit))->grantRole(
            $userId, 'formateur', 'dossier', $realExerciseDossier
        );
        $session->set('dossier_id', $realExerciseDossier);
        $resetReal = $app->handle(new Request(
            'POST',
            '/api/v1/pedagogie/reinitialiser',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => ['assignment_id' => $httpAssignment]]
        ));
        $this->same(422, $resetReal->status, 'API refuse le reset depuis un dossier réel');
        $demoDossier = (new ScopeManager($pdo, $httpAudit))->createDossier(
            $ids['organisation_a'],
            'Démonstration HTTP',
            'demonstration-http',
            'demo'
        );
        (new ScopeManager($pdo, $httpAudit))->grantRole(
            $userId, 'comptable', 'dossier', $demoDossier
        );
        $session->set('dossier_id', $demoDossier);
        $session->set('dossier_id', $ids['dossier_a']);
        $this->false(
            str_contains($dashboard->body, 'cdn.jsdelivr.net'),
            'aucune dépendance au CDN'
        );
        $this->true(
            is_file(
                dirname(__DIR__)
                . '/public/assets/fonts/raleway/Raleway-Variable.ttf'
            ),
            'police Raleway de la charte embarquée'
        );
        $this->true(
            is_file(
                dirname(__DIR__)
                . '/public/assets/fonts/montserrat/Montserrat-Variable.ttf'
            ),
            'police Montserrat de la charte embarquée'
        );

        $balance = $app->handle(new Request('GET', '/compta/balance', query: [
            'exercice' => (string) $exerciseId,
        ]));
        $this->same(303, $balance->status, 'ancien rapport de balance redirigé');
        $this->same(
            '/edu/app/compta/etats',
            $balance->headers['Location'] ?? '',
            'balance servie uniquement par Vue'
        );
        $csv = $app->handle(new Request('GET', '/api/v1/accounting/reports/export', query: [
            'exercise_id' => (string) $exerciseId,
            'type' => 'balance',
            'date_start' => '2026-01-01',
            'date_end' => '2026-12-31',
        ]));
        $this->same(
            'text/csv; charset=UTF-8',
            $csv->headers['Content-Type'],
            'export CSV HTTP par le contrat Vue'
        );
        $plan = $app->handle(new Request('GET', '/compta/plan'));
        $this->same(303, $plan->status, 'ancien plan comptable redirigé');
        $this->same(
            '/edu/app/configuration/referentiels/plan',
            $plan->headers['Location'] ?? '',
            'plan comptable servi par Configuration Vue'
        );
        $chartCsv = $app->handle(new Request(
            'GET',
            '/api/v1/accounting/chart/export'
        ));
        $this->same(
            'text/csv; charset=UTF-8',
            $chartCsv->headers['Content-Type'] ?? '',
            'plan comptable exporté en CSV par l’API'
        );
        $chartPreviewNoCsrf = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/chart/import/preview',
            json: ['data' => ['csv' => $chartCsv->body]]
        ));
        $this->same(
            403,
            $chartPreviewNoCsrf->status,
            'prévisualisation CSV protégée par CSRF'
        );
        $chartPreview = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/chart/import/preview',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => ['csv' => $chartCsv->body]]
        ));
        $this->same(200, $chartPreview->status, 'plan CSV prévisualisé par l’API');
        $chartPreviewJson = $this->responseJson($chartPreview);
        $this->true(
            preg_match(
                '/^[a-f0-9]{64}$/',
                (string) ($chartPreviewJson['data']['fingerprint'] ?? '')
            ) === 1,
            'prévisualisation liée à une empreinte du plan courant'
        );
        $chartImport = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/chart/import',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'csv' => $chartCsv->body,
                'fingerprint' => $chartPreviewJson['data']['fingerprint'] ?? '',
            ]]
        ));
        $this->same(
            200,
            $chartImport->status,
            'import CSV validé et appliqué atomiquement par l’API'
        );
        $chartResetNoCsrf = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/chart/reset/preview'
        ));
        $this->same(
            403,
            $chartResetNoCsrf->status,
            'vérification de remise à zéro protégée par CSRF'
        );
        $chartResetPreview = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/chart/reset/preview',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()]
        ));
        $this->same(200, $chartResetPreview->status, 'dépendances du plan contrôlées par API');
        $chartResetJson = $this->responseJson($chartResetPreview);
        $this->false(
            (bool) ($chartResetJson['data']['allowed'] ?? true),
            'API interdit d’effacer un plan déjà mouvementé'
        );
        $chartReset = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/chart/reset',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'fingerprint' => $chartResetJson['data']['fingerprint'] ?? '',
                'confirmation' => 'EFFACER',
            ]]
        ));
        $this->same(
            422,
            $chartReset->status,
            'effacement bloqué sans aucune mutation partielle'
        );
        $this->true(
            !is_file(dirname(__DIR__) . '/templates/compta/plan.php')
            && !is_file(dirname(__DIR__) . '/templates/compta/report.php')
            && is_file(dirname(__DIR__) . '/frontend/admin-vue/src/views/AccountingView.vue'),
            'anciens templates comptables supprimés au profit de la vue unique'
        );
        $planNoCsrf = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/chart/sense-rules',
            json: ['data' => ['prefixes' => ['2', '3']]]
        ));
        $this->same(403, $planNoCsrf->status, 'configuration comptable API sans CSRF refusée');
        $planRules = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/chart/sense-rules',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => ['prefixes' => ['2', '3', '7']]]
        ));
        $this->same(200, $planRules->status, 'règles de sens modifiables par API');
        $this->same(
            3,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM regles_sens_comptes
                 WHERE dossier_id = {$ids['dossier_a']}"
            )->fetchColumn(),
            'règles API persistées par le service existant'
        );
        $scopeInjection = $app->handle(new Request(
            'POST',
            '/api/v1/accounting/chart/accounts',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'action' => 'save',
                'id' => 0,
                'number' => '1998',
                'label' => 'Injection',
                'sense_mode' => 'automatique',
                'rubric_id' => null,
                'version' => 0,
                'ordered_ids' => [],
                'dossier_id' => $ids['dossier_b'],
            ]]
        ));
        $this->same(422, $scopeInjection->status, 'API comptable refuse un scope injecté');

        $session->set('user_id', $registryAdminId);
        $registryList = $app->handle(new Request(
            'GET',
            '/api/v1/structures/organisations',
            query: ['status' => 'all', 'page' => '1', 'per_page' => '20']
        ));
        $this->same(200, $registryList->status, 'registre des organisations accessible par API');
        $registryNoCsrf = $app->handle(new Request(
            'POST',
            '/api/v1/structures/organisations',
            json: ['data' => [
                'name' => 'Sans jeton',
                'nature' => 'pedagogique',
            ]]
        ));
        $this->same(403, $registryNoCsrf->status, 'création d’organisation protégée par CSRF');
        $registryInvalid = $app->handle(new Request(
            'POST',
            '/api/v1/structures/organisations',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'name' => 'Réelle incomplète',
                'nature' => 'reelle',
            ]]
        ));
        $this->same(422, $registryInvalid->status, 'organisation réelle exige une identité sourcée');
        $registryCreate = $app->handle(new Request(
            'POST',
            '/api/v1/structures/organisations',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'name' => 'Organisation HTTP vide',
                'nature' => 'pedagogique',
            ]]
        ));
        $this->same(201, $registryCreate->status, 'organisation créée par API versionnée');
        $createdRegistryId = (int) (
            $this->responseJson($registryCreate)['data']['id'] ?? 0
        );
        $this->true($createdRegistryId > 0, 'identifiant de registre retourné');

        $session->set('user_id', $registryManagerId);
        $managerRegistryList = $app->handle(new Request(
            'GET',
            '/api/v1/structures/organisations',
            query: ['status' => 'all']
        ));
        $managerItems = $this->responseJson($managerRegistryList)['data']['items'] ?? [];
        $this->same(
            [$ids['organisation_a']],
            array_column($managerItems, 'id'),
            'API limite un gestionnaire aux organisations attribuées'
        );
        $registryIdor = $app->handle(new Request(
            'GET',
            '/api/v1/structures/organisations/detail',
            query: ['id' => (string) $ids['organisation_b']]
        ));
        $this->same(404, $registryIdor->status, 'IDOR organisation refusée sans découverte');
        $this->false(
            str_contains($registryIdor->body, 'Organisation B'),
            'refus IDOR sans fuite du nom de l’organisation'
        );

        $dossierPayload = [
            'organisation_id' => $createdRegistryId,
            'name' => 'Dossier HTTP initialisé',
            'slug' => 'dossier-http-initialise',
            'type' => 'demo',
            'currency' => 'CHF',
            'modules' => ['comptabilite', 'facturation'],
            'plan_variant' => 'personne_morale',
            'association' => [
                'enabled' => false,
                'projects' => false,
                'restricted_funds' => false,
            ],
            'exercise' => [
                'label' => 'Exercice 2027',
                'start' => '2027-01-01',
                'end' => '2027-12-31',
            ],
            'journal' => ['code' => 'OD', 'label' => 'Opérations diverses'],
        ];
        $dossierIdor = $app->handle(new Request(
            'POST',
            '/api/v1/structures/dossiers',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => array_replace($dossierPayload, [
                'organisation_id' => $ids['organisation_b'],
            ])]
        ));
        $this->same(404, $dossierIdor->status, 'IDOR de création de dossier refusée');

        $session->set('user_id', $userId);
        $siblingDenied = $app->handle(new Request(
            'POST',
            '/api/v1/structures/dossiers',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => array_replace($dossierPayload, [
                'organisation_id' => $ids['organisation_a'],
                'slug' => 'frere-interdit',
            ])]
        ));
        $this->same(
            404,
            $siblingDenied->status,
            'dossier.manage ne permet pas de créer un dossier frère'
        );

        $session->set('user_id', $registryAdminId);
        $dossierNoCsrf = $app->handle(new Request(
            'POST',
            '/api/v1/structures/dossiers',
            json: ['data' => $dossierPayload]
        ));
        $this->same(403, $dossierNoCsrf->status, 'assistant dossier protégé par CSRF');
        $dossierCreate = $app->handle(new Request(
            'POST',
            '/api/v1/structures/dossiers',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => $dossierPayload]
        ));
        $this->same(201, $dossierCreate->status, 'dossier initialisé par API');
        $createdDossier = $this->responseJson($dossierCreate)['data'] ?? [];
        $createdDossierId = (int) ($createdDossier['id'] ?? 0);
        $this->true(
            $createdDossierId > 0
            && (int) ($createdDossier['summary']['account_count'] ?? 0) > 0,
            'résumé d’initialisation retourné'
        );
        $selector = $app->handle(new Request('GET', '/api/v1/dossiers'));
        $selectorIds = array_column(
            $this->responseJson($selector)['data'] ?? [],
            'id'
        );
        $this->true(
            in_array($createdDossierId, $selectorIds, true),
            'nouveau dossier visible dans le sélecteur sans reconnexion'
        );
        $selectCreated = $app->handle(new Request(
            'POST',
            '/api/v1/context/dossier',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'organisation_id' => $createdRegistryId,
                'dossier_id' => $createdDossierId,
            ]]
        ));
        $this->same(200, $selectCreated->status, 'nouveau dossier immédiatement sélectionnable');
        $createdDetail = $app->handle(new Request(
            'GET',
            '/api/v1/structures/dossiers/detail',
            query: ['id' => (string) $createdDossierId]
        ));
        $createdVersion = (int) (
            $this->responseJson($createdDetail)['data']['version'] ?? 0
        );
        $archiveCreated = $app->handle(new Request(
            'POST',
            '/api/v1/structures/dossiers/archive',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'id' => $createdDossierId,
                'version' => $createdVersion,
            ]]
        ));
        $this->same(200, $archiveCreated->status, 'dossier archivé par API');
        $archivedVersion = (int) (
            $this->responseJson($archiveCreated)['data']['version'] ?? 0
        );
        $contextAfterArchive = $app->handle(new Request('GET', '/api/v1/context'));
        $this->same(
            null,
            $this->responseJson($contextAfterArchive)['data']['selection'] ?? null,
            'archivage du dossier courant retire le contexte de session'
        );
        $selectorAfterArchive = $app->handle(new Request('GET', '/api/v1/dossiers'));
        $this->false(
            in_array(
                $createdDossierId,
                array_column(
                    $this->responseJson($selectorAfterArchive)['data'] ?? [],
                    'id'
                ),
                true
            ),
            'dossier archivé retiré des nouvelles sélections'
        );
        $deleteCreated = $app->handle(new Request(
            'POST',
            '/api/v1/structures/dossiers/delete',
            server: ['HTTP_X_CSRF_TOKEN' => $csrf->token()],
            json: ['data' => [
                'id' => $createdDossierId,
                'version' => $archivedVersion,
            ]]
        ));
        $this->same(200, $deleteCreated->status, 'dossier initialisé vide supprimé par API');
    }
    private function assetTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $ids = $this->seedScopes($pdo);
        $audit = new AuditLogger($pdo);
        $scope = new ScopeManager($pdo, $audit);
        $exerciseId = $scope->createExercise(
            $ids['dossier_a'],
            'Exercice décalé 2026–2027',
            '2026-07-01',
            '2027-06-30'
        );
        (new PlanSeeder(
            $pdo,
            dirname(__DIR__) . '/database/seeds'
        ))->installForDossier(
            $ids['organisation_a'],
            $ids['dossier_a'],
            'personne_morale'
        );
        $setup = new AccountingSetupService($pdo, $audit);
        $julyPeriodId = $setup->createPeriod(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exerciseId,
            'Juillet 2026',
            '2026-07-01',
            '2026-07-31'
        );
        $setup->createPeriod(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exerciseId,
            'Août 2026 à juin 2027',
            '2026-08-01',
            '2027-06-30'
        );
        $journalId = $setup->createJournal(
            $ids['organisation_a'],
            $ids['dossier_a'],
            'AMO',
            'Immobilisations'
        );
        $assetAccount = $this->accountId($pdo, $ids['dossier_a'], '1500');
        $accumulatedAccount = $this->accountId(
            $pdo,
            $ids['dossier_a'],
            '1509'
        );
        $expenseAccount = $this->accountId($pdo, $ids['dossier_a'], '6800');
        $gainAccount = $this->accountId($pdo, $ids['dossier_a'], '8510');
        $lossAccount = $this->accountId($pdo, $ids['dossier_a'], '8500');
        $bankAccount = $this->accountId($pdo, $ids['dossier_a'], '1020');
        $entries = new EntryService($pdo, $audit);
        $assets = new AssetService($pdo, $audit, $entries);
        $categoryId = $assets->saveCategory(
            $ids['organisation_a'],
            $ids['dossier_a'],
            [
                'code' => 'MACH',
                'label' => 'Machines',
                'default_duration_months' => 12,
                'asset_account_id' => $assetAccount,
                'accumulated_depreciation_account_id' => $accumulatedAccount,
                'depreciation_expense_account_id' => $expenseAccount,
                'disposal_gain_account_id' => $gainAccount,
                'disposal_loss_account_id' => $lossAccount,
                'active' => true,
            ]
        );
        $assetId = $assets->createAsset(
            $ids['organisation_a'],
            $ids['dossier_a'],
            [
                'category_id' => $categoryId,
                'code' => 'M-001',
                'label' => 'Machine de production',
                'acquisition_reference' => 'FAC-IMM-001',
                'acquisition_document_id' => null,
                'acquisition_attachment_id' => null,
                'acquisition_date' => '2026-07-10',
                'in_service_date' => '2026-07-15',
                'acquisition_value_cents' => 120_001,
                'residual_value_cents' => 1,
                'duration_months' => 12,
                'note' => 'Test exercice décalé',
            ]
        );
        $plan = AssetService::linearSchedule('2026-07-15', 12, 120000);
        $this->same(
            120000,
            array_sum(array_column($plan, 'amount_cents')),
            'plan linéaire répartit exactement la base amortissable'
        );
        $this->same(
            '2026-07-15|2026-07-31',
            $plan[0]['start_date'] . '|' . $plan[0]['end_date'],
            'prorata de mise en service borné à son mois civil'
        );
        $this->same(
            360,
            array_sum(array_column($plan, 'days')),
            'plan annuel calculé sur 360 jours conventionnels'
        );
        $this->same(
            16,
            $plan[0]['days'],
            'premier mois calculé sur une base mensuelle de 30 jours'
        );
        $this->same(
            30,
            $plan[1]['days'],
            'mois complet limité à 30 jours'
        );
        $this->same(
            '2027-07-14',
            $plan[array_key_last($plan)]['end_date'],
            'durée en mois exacte malgré exercice décalé'
        );
        $centPlan = AssetService::linearSchedule('2026-01-31', 7, 10001);
        $this->same(
            10001,
            array_sum(array_column($centPlan, 'amount_cents')),
            'dernier centime conservé sans flottant'
        );
        $entries->postGenerated([
            'organisation_id' => $ids['organisation_a'],
            'dossier_id' => $ids['dossier_a'],
            'exercice_id' => $exerciseId,
            'journal_id' => $journalId,
            'date_comptable' => '2026-07-10',
            'libelle' => 'Acquisition machine',
            'source_type' => 'test_immobilisation',
            'source_id' => (string) $assetId,
            'source_action' => 'acquisition',
            'lignes' => [
                [
                    'compte_id' => $assetAccount,
                    'debit_centimes' => 120001,
                    'credit_centimes' => 0,
                ],
                [
                    'compte_id' => $bankAccount,
                    'debit_centimes' => 0,
                    'credit_centimes' => 120001,
                ],
            ],
        ], 'test:immobilisation:acquisition:' . $assetId);
        $setup->closePeriod(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $julyPeriodId
        );
        $workspace = $assets->read(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exerciseId,
            $assetId
        );
        $schedule = $workspace['selected_asset']['schedule'];
        $julyScheduleId = (int) $schedule[0]['id'];
        $augustScheduleId = (int) $schedule[1]['id'];
        $this->throws(
            fn () => $assets->postDepreciation(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $julyScheduleId,
                $exerciseId,
                $journalId
            ),
            'aucune dotation dans une période close'
        );
        $augustEntry = $assets->postDepreciation(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $augustScheduleId,
            $exerciseId,
            $journalId
        );
        $this->same(
            $augustEntry,
            $assets->postDepreciation(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $augustScheduleId,
                $exerciseId,
                $journalId
            ),
            'dotation périodique idempotente'
        );
        $reversalEntry = $assets->reverseDepreciation(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $augustScheduleId,
            '2026-08-31'
        );
        $this->true(
            $reversalEntry > $augustEntry,
            'correction de dotation par contre-passation'
        );
        $repostedEntry = $assets->postDepreciation(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $augustScheduleId,
            $exerciseId,
            $journalId
        );
        $this->true(
            $repostedEntry > $reversalEntry,
            'échéance corrigée de nouveau comptabilisable'
        );
        $pdo->prepare(
            "UPDATE periodes SET statut = 'ouverte', version = version + 1
             WHERE id = ?"
        )->execute([$julyPeriodId]);
        $assets->postDepreciation(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $julyScheduleId,
            $exerciseId,
            $journalId
        );
        $disposalEntry = $assets->dispose(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $assetId,
            'cession',
            '2026-09-15',
            90000,
            $bankAccount,
            $exerciseId,
            $journalId
        );
        $this->same(
            $disposalEntry,
            $assets->dispose(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $assetId,
                'cession',
                '2026-09-15',
                90000,
                $bankAccount,
                $exerciseId,
                $journalId
            ),
            'cession idempotente'
        );
        $disposed = $assets->read(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exerciseId,
            $assetId
        );
        $this->same(
            'cede',
            $disposed['selected_asset']['status'],
            'cession ferme le registre et annule le futur plan'
        );
        $this->same(
            0,
            $disposed['reconciliation'][0]['gross_difference_cents'],
            'registre brut réconcilié avec le compte d’actif'
        );
        $this->same(
            0,
            $disposed['reconciliation'][0]['accumulated_difference_cents'],
            'amortissements cumulés réconciliés au grand livre'
        );
        $exitReversal = $assets->reverseDisposal(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $assetId,
            '2026-09-15'
        );
        $this->true(
            $exitReversal > $disposalEntry,
            'sortie contre-passable sans effacer son historique'
        );
        $restored = $assets->read(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exerciseId,
            $assetId
        );
        $this->same(
            'actif',
            $restored['selected_asset']['status'],
            'contre-passation restaure l’actif et son échéancier'
        );
        $this->same(
            120000,
            $restored['selected_asset']['totals']['posted_depreciation_cents']
                + $restored['selected_asset']['totals']['remaining_depreciable_cents'],
            'dotations plus base restante concordent au centime'
        );
        $this->throws(
            fn () => $assets->read(
                $ids['organisation_b'],
                $ids['dossier_a'],
                $exerciseId,
                $assetId
            ),
            'registre strictement isolé entre organisations'
        );
        $this->true(
            IntegrityChecker::check($pdo)['ok'],
            'intégrité après immobilisations'
        );
    }

    private function accountingTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $ids = $this->seedScopes($pdo);
        $pdo->prepare(
            'INSERT INTO utilisateurs (email, mot_de_passe, prenom, nom)
             VALUES (?, ?, ?, ?)'
        )->execute([
            'compta-reports@example.test',
            'test-hash',
            'Rapports',
            'Test',
        ]);
        $reportActorId = (int) $pdo->lastInsertId();
        $audit = new AuditLogger($pdo);
        $scope = new ScopeManager($pdo, $audit);
        $exerciseA = $scope->createExercise(
            $ids['dossier_a'],
            'Exercice 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $exerciseB = $scope->createExercise(
            $ids['dossier_b'],
            'Exercice 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $setup = new AccountingSetupService($pdo, $audit);
        $periodA1 = $setup->createPeriod(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exerciseA,
            'Semestre 1',
            '2026-01-01',
            '2026-06-30'
        );
        $periodA2 = $setup->createPeriod(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exerciseA,
            'Semestre 2',
            '2026-07-01',
            '2026-12-31'
        );
        $setup->createPeriod(
            $ids['organisation_b'],
            $ids['dossier_b'],
            $exerciseB,
            'Année',
            '2026-01-01',
            '2026-12-31'
        );
        $journalA = $setup->createJournal(
            $ids['organisation_a'],
            $ids['dossier_a'],
            'OD',
            'Opérations diverses'
        );
        $journalOpening = $setup->createJournal(
            $ids['organisation_a'],
            $ids['dossier_a'],
            'OUV',
            'Ouverture',
            'ouverture'
        );
        $setup->createJournal(
            $ids['organisation_b'],
            $ids['dossier_b'],
            'OD',
            'Opérations diverses'
        );

        $seeder = new PlanSeeder($pdo, dirname(__DIR__) . '/database/seeds');
        $installed = $seeder->installForDossier(
            $ids['organisation_a'],
            $ids['dossier_a'],
            'personne_morale',
            true,
            ['projets' => true, 'fonds_affectes' => true]
        );
        $seeder->installForDossier(
            $ids['organisation_b'],
            $ids['dossier_b'],
            'personne_morale'
        );
        $this->true($installed > 120, 'plan VEB complet et overlay installés');
        $this->true(
            str_contains($seeder->attributions()[0]['attribution'], 'veb.ch'),
            'attribution VEB visible'
        );
        $this->same(
            'charge',
            (string) $pdo->query(
                "SELECT type FROM comptes
                 WHERE dossier_id = {$ids['dossier_a']} AND numero = '6950'"
            )->fetchColumn(),
            'type du compte hérité de sa classe'
        );
        $this->same(
            2,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM comptes
                 WHERE dossier_id = {$ids['dossier_a']}
                   AND numero IN ('3001', '2811')"
            )->fetchColumn(),
            'overlay association paramétré'
        );

        $chart = new ChartOfAccountsService($pdo, $audit);
        $this->same(
            ['2', '3'],
            $chart->creditPrefixes(
                $ids['organisation_a'],
                $ids['dossier_a']
            ),
            'préfixes moins / plus suisses initialisés'
        );
        $cashConfiguration = $pdo->query(
            "SELECT sens_normal, sens_mode FROM comptes
             WHERE dossier_id = {$ids['dossier_a']} AND numero = '1000'"
        )->fetch();
        $this->same('debit', $cashConfiguration['sens_normal'], 'caisse en plus / moins');
        $this->same(
            'automatique',
            $cashConfiguration['sens_mode'],
            'caisse pilotée automatiquement par les préfixes'
        );
        $correctionConfiguration = $pdo->query(
            "SELECT sens_normal, sens_mode FROM comptes
             WHERE dossier_id = {$ids['dossier_a']} AND numero = '1069'"
        )->fetch();
        $this->same('credit', $correctionConfiguration['sens_normal'], 'compte correcteur VEB au crédit');
        $this->same(
            'credit',
            $correctionConfiguration['sens_mode'],
            'exception VEB conservée explicitement'
        );
        $this->true(
            count($chart->rubrics(
                $ids['organisation_a'],
                $ids['dossier_a']
            )) > 20,
            'rubriques initiales créées depuis la structure VEB'
        );
        $rubrics = $chart->rubrics(
            $ids['organisation_a'],
            $ids['dossier_a']
        );
        $rubric100 = array_values(array_filter(
            $rubrics,
            static fn (array $row): bool => $row['code'] === '100'
        ))[0];
        $rubric10 = array_values(array_filter(
            $rubrics,
            static fn (array $row): bool => $row['code'] === '10'
        ))[0];
        $this->same(
            '2:passif|3:produit',
            (string) $pdo->query(
                "SELECT group_concat(code || ':' || type, '|')
                 FROM (
                     SELECT code, type FROM rubriques_comptables
                     WHERE dossier_id = {$ids['dossier_a']}
                       AND niveau_structure = 'classe'
                       AND code IN ('2', '3')
                     ORDER BY code
                 )"
            )->fetchColumn(),
            'classe 2 au passif et classe 3 aux produits'
        );
        $this->same(
            '9:hors_bilan|9200:hors_bilan',
            (string) $pdo->query(
                "SELECT group_concat(code || ':' || type, '|') FROM (
                     SELECT code, type FROM rubriques_comptables
                     WHERE dossier_id = {$ids['dossier_a']}
                       AND niveau_structure = 'classe' AND code = '9'
                     UNION ALL
                     SELECT numero AS code, type FROM comptes
                     WHERE dossier_id = {$ids['dossier_a']} AND numero = '9200'
                 )"
            )->fetchColumn(),
            'classe et comptes 9 classés hors bilan'
        );
        $this->same(
            (int) $rubric10['id'],
            (int) $rubric100['parent_id'],
            'groupe 100 rattaché au groupe principal 10'
        );
        $accountRows = $chart->accounts(
            $ids['organisation_a'],
            $ids['dossier_a']
        );
        $originalAccountOrder = array_map(
            static fn (array $row): int => (int) $row['id'],
            $accountRows
        );
        $reversedAccountOrder = $originalAccountOrder;
        [$reversedAccountOrder[0], $reversedAccountOrder[1]]
            = [$reversedAccountOrder[1], $reversedAccountOrder[0]];
        $chart->reorderAccounts(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $reversedAccountOrder
        );
        $this->same(
            array_slice($reversedAccountOrder, 0, 2),
            array_slice(array_map(
                static fn (array $row): int => (int) $row['id'],
                $chart->accounts($ids['organisation_a'], $ids['dossier_a'])
            ), 0, 2),
            'ordre global des comptes modifiable par panneau'
        );
        $chart->reorderAccounts(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $originalAccountOrder
        );
        $bankRow = array_values(array_filter(
            $accountRows,
            static fn (array $row): bool => $row['numero'] === '1020'
        ))[0];
        $this->same('100', $bankRow['rubrique_code'], 'compte bancaire rattaché directement au groupe 100');
        $this->same('Trésorerie', $bankRow['rubrique_libelle'], 'rubrique directe du compte exposée');
        $this->same(
            '100 Trésorerie ‹ 10 Actifs circulants ‹ 1 ACTIFS',
            $bankRow['rubrique_chemin'],
            'chemin structurel complet du compte'
        );
        $this->same(
            '10,14,20,24,28,30,38,39,40,50,59,60,68,69,70,75,80,85,89,92',
            (string) $pdo->query(
                "SELECT group_concat(code, ',') FROM (
                     SELECT code FROM rubriques_comptables
                     WHERE dossier_id = {$ids['dossier_a']}
                       AND niveau_structure = 'groupe_principal'
                     ORDER BY code
                 )"
            )->fetchColumn(),
            'groupes principaux du plan édité conservés'
        );
        $this->same(
            0,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM rubriques_comptables
                 WHERE dossier_id = {$ids['dossier_a']}
                   AND libelle GLOB 'Groupe*'"
            )->fetchColumn(),
            'aucune rubrique artificielle Groupe*'
        );
        $class1 = array_values(array_filter(
            $rubrics,
            static fn (array $row): bool => $row['code'] === '1'
        ))[0];
        $chart->saveRubric(
            $ids['organisation_a'],
            $ids['dossier_a'],
            (int) $class1['id'],
            'classe',
            '1',
            'ACTIFS',
            'passif',
            null,
            (int) $class1['ordre'],
            (int) $class1['version']
        );
        $this->same(
            'passif|passif',
            (string) $pdo->query(
                "SELECT r.type || '|' || c.type
                 FROM comptes c JOIN rubriques_comptables r ON r.id = c.rubrique_id
                 WHERE c.numero = '1020'"
            )->fetchColumn(),
            'type du groupe et du compte propagé depuis la classe'
        );
        $classVersion = (int) $pdo->query(
            "SELECT version FROM rubriques_comptables WHERE id = {$class1['id']}"
        )->fetchColumn();
        $chart->saveRubric(
            $ids['organisation_a'],
            $ids['dossier_a'],
            (int) $class1['id'],
            'classe',
            '1',
            'ACTIFS',
            'actif',
            null,
            (int) $class1['ordre'],
            $classVersion
        );
        $accountTypes = $chart->accountTypes(
            $ids['organisation_a'],
            $ids['dossier_a']
        );
        $this->same(5, count($accountTypes), 'cinq types de comptes configurables');
        $chart->renameAccountType(
            $ids['organisation_a'],
            $ids['dossier_a'],
            (int) $accountTypes[4]['id'],
            'Extrabilan',
            (int) $accountTypes[4]['version']
        );
        $this->same(
            'Extrabilan',
            $chart->accountTypes(
                $ids['organisation_a'],
                $ids['dossier_a']
            )[4]['libelle'],
            'libellé de type modifiable'
        );
        $this->same(
            'Hors bilan',
            $chart->accountTypes(
                $ids['organisation_b'],
                $ids['dossier_b']
            )[4]['libelle'],
            'plan comptable modifiable indépendamment pour chaque dossier'
        );
        $bankRubric = $chart->saveRubric(
            $ids['organisation_a'],
            $ids['dossier_a'],
            null,
            'sous_groupe',
            '',
            'Banques suisses',
            'actif',
            (int) $rubric100['id'],
            15
        );
        $rubricVersion = (int) $pdo->query(
            "SELECT version FROM rubriques_comptables WHERE id = {$bankRubric}"
        )->fetchColumn();
        $chart->saveRubric(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $bankRubric,
            'sous_groupe',
            '',
            'Liquidités bancaires',
            'actif',
            (int) $rubric100['id'],
            15,
            $rubricVersion
        );
        $this->same(
            'Liquidités bancaires',
            (string) $pdo->query(
                "SELECT libelle FROM rubriques_comptables WHERE id = {$bankRubric}"
            )->fetchColumn(),
            'rubrique modifiable'
        );
        $automaticAccount = $chart->createConfigured(
            $ids['organisation_a'],
            $ids['dossier_a'],
            '6998',
            'Compte automatique de test',
            'charge'
        );
        $orderedAfterCreate = $chart->accounts(
            $ids['organisation_a'],
            $ids['dossier_a']
        );
        $this->same(
            $automaticAccount,
            (int) $orderedAfterCreate[array_key_last($orderedAfterCreate)]['id'],
            'nouveau compte ajouté en bas de liste'
        );
        $structuredAccount = $chart->createConfigured(
            $ids['organisation_a'],
            $ids['dossier_a'],
            '1099',
            'Compte structuré de test',
            'actif',
            'automatique',
            null,
            (int) $rubric100['id']
        );
        $this->same(
            (int) $rubric100['id'],
            (int) $pdo->query(
                "SELECT rubrique_id FROM comptes WHERE id = {$structuredAccount}"
            )->fetchColumn(),
            'groupe parent du compte enregistré explicitement'
        );
        $this->throws(
            fn () => $chart->createConfigured(
                $ids['organisation_a'],
                $ids['dossier_a'],
                '9998',
                'Compte 9 au mauvais type',
                'actif',
                'automatique',
                null,
                (int) $rubric100['id']
            ),
            'compte 9 refusé hors du type Hors bilan'
        );
        $manualAccount = $chart->createConfigured(
            $ids['organisation_a'],
            $ids['dossier_a'],
            '6997',
            'Exception de test',
            'produit',
            'credit'
        );
        $chart->replaceCreditPrefixes(
            $ids['organisation_a'],
            $ids['dossier_a'],
            ['2', '3', '6']
        );
        $this->same(
            'credit',
            (string) $pdo->query(
                "SELECT sens_normal FROM comptes WHERE id = {$automaticAccount}"
            )->fetchColumn(),
            'nouveau préfixe moins / plus appliqué aux comptes automatiques'
        );
        $chart->replaceCreditPrefixes(
            $ids['organisation_a'],
            $ids['dossier_a'],
            ['2', '3']
        );
        $this->same(
            'debit',
            (string) $pdo->query(
                "SELECT sens_normal FROM comptes WHERE id = {$automaticAccount}"
            )->fetchColumn(),
            'retrait du préfixe restaure le mode plus / moins'
        );
        $this->same(
            'credit',
            (string) $pdo->query(
                "SELECT sens_normal FROM comptes WHERE id = {$manualAccount}"
            )->fetchColumn(),
            'exception manuelle indépendante des préfixes'
        );
        $automaticVersion = (int) $pdo->query(
            "SELECT version FROM comptes WHERE id = {$automaticAccount}"
        )->fetchColumn();
        $chart->updateAccount(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $automaticAccount,
            '6996',
            'Compte renommé',
            'charge',
            'automatique',
            $automaticVersion
        );
        $this->same(
            '6996|Compte renommé',
            (string) $pdo->query(
                "SELECT numero || '|' || libelle FROM comptes
                 WHERE id = {$automaticAccount}"
            )->fetchColumn(),
            'numéro et libellé de compte modifiables'
        );
        $this->same(
            'supprime',
            $chart->removeOrDeactivate(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $automaticAccount
            ),
            'compte inutilisé supprimable'
        );
        $chart->removeOrDeactivate(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $manualAccount
        );
        $chartCsv = $chart->exportCsv(
            $ids['organisation_a'],
            $ids['dossier_a']
        );
        $this->true(
            str_starts_with(
                $chartCsv,
                "\xEF\xBB\xBFtype_ligne;niveau;code;libelle;parent_code;"
            ),
            'plan comptable exporté en CSV UTF-8 structuré'
        );
        $chartPreview = $chart->previewCsv(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $chartCsv
        );
        $this->same(
            0,
            $chartPreview['summary']['account_updates'],
            'réimport sans modification prévisualisé sans faux positif'
        );
        $modifiedChartCsv = str_replace(
            ';1000;Caisse;',
            ';1000;Caisse importée;',
            $chartCsv
        );
        $this->true(
            $modifiedChartCsv !== $chartCsv,
            'modification CSV de test appliquée'
        );
        $modifiedPreview = $chart->previewCsv(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $modifiedChartCsv
        );
        $this->same(
            1,
            $modifiedPreview['summary']['account_updates'],
            'modification de compte identifiée avant import'
        );
        $chart->importCsv(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $modifiedChartCsv,
            $modifiedPreview['fingerprint'],
            $reportActorId
        );
        $this->same(
            'Caisse importée',
            (string) $pdo->query(
                "SELECT libelle FROM comptes
                 WHERE dossier_id = {$ids['dossier_a']} AND numero = '1000'"
            )->fetchColumn(),
            'import CSV appliqué atomiquement'
        );
        $this->throws(
            fn () => $chart->importCsv(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $modifiedChartCsv,
                $modifiedPreview['fingerprint'],
                $reportActorId
            ),
            'empreinte périmée refuse un import concurrent'
        );
        $invalidChartCsv = preg_replace(
            '/^compte;;1000;([^;]+);100;/m',
            'compte;;1000;$1;X;',
            $chart->exportCsv($ids['organisation_a'], $ids['dossier_a']),
            1,
            $invalidReplacementCount
        );
        $this->same(1, $invalidReplacementCount, 'CSV invalide de test préparé');
        $this->throws(
            fn () => $chart->previewCsv(
                $ids['organisation_a'],
                $ids['dossier_a'],
                (string) $invalidChartCsv
            ),
            'rubrique inconnue refuse tout le CSV avant mutation'
        );
        $this->same(
            'Caisse importée',
            (string) $pdo->query(
                "SELECT libelle FROM comptes
                 WHERE dossier_id = {$ids['dossier_a']} AND numero = '1000'"
            )->fetchColumn(),
            'échec de prévisualisation sans mutation partielle'
        );
        $resetReferenceAccount = $this->accountId(
            $pdo,
            $ids['dossier_a'],
            '1000'
        );
        $pdo->prepare(
            "INSERT INTO comptes_tresorerie
                (organisation_id, dossier_id, compte_comptable_id, libelle, type)
             VALUES (?, ?, ?, 'Blocage temporaire', 'caisse')"
        )->execute([
            $ids['organisation_a'],
            $ids['dossier_a'],
            $resetReferenceAccount,
        ]);
        $temporaryTreasuryId = (int) $pdo->lastInsertId();
        $blockedReset = $chart->previewReset(
            $ids['organisation_a'],
            $ids['dossier_a']
        );
        $this->false(
            (bool) $blockedReset['allowed'],
            'remise à zéro refusée quand le plan reste référencé'
        );
        $pdo->prepare('DELETE FROM comptes_tresorerie WHERE id = ?')
            ->execute([$temporaryTreasuryId]);
        $emptyDossier = (new ScopeManager($pdo, $audit))->createDossier(
            $ids['organisation_a'],
            'Plan vierge',
            'plan-vierge',
            'reel'
        );
        $chart->createConfigured(
            $ids['organisation_a'],
            $emptyDossier,
            '1000',
            'Compte temporaire',
            'actif'
        );
        $resetPreview = $chart->previewReset(
            $ids['organisation_a'],
            $emptyDossier
        );
        $this->true((bool) $resetPreview['allowed'], 'plan inutilisé effaçable');
        $chart->reset(
            $ids['organisation_a'],
            $emptyDossier,
            (string) $resetPreview['fingerprint'],
            'EFFACER',
            $reportActorId
        );
        $this->same(
            0,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM comptes WHERE dossier_id = {$emptyDossier}"
            )->fetchColumn(),
            'définitions du plan entièrement effacées'
        );
        $flatCsv = "\xEF\xBB\xBF"
            . "type_ligne;niveau;code;libelle;parent_code;type_compte;sens;ordre\n"
            . "compte;;1000;Caisse;;actif;automatique;10\n";
        $flatPreview = $chart->previewCsv(
            $ids['organisation_a'],
            $emptyDossier,
            $flatCsv
        );
        $this->same(
            1,
            $flatPreview['summary']['type_creates'],
            'type requis détecté pour un plan plat'
        );
        $chart->importCsv(
            $ids['organisation_a'],
            $emptyDossier,
            $flatCsv,
            (string) $flatPreview['fingerprint'],
            $reportActorId
        );
        $this->same(
            0,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM rubriques_comptables
                 WHERE dossier_id = {$emptyDossier}"
            )->fetchColumn(),
            'plan plat importé sans rubrique'
        );
        $this->same(
            'actif',
            (string) $pdo->query(
                "SELECT type FROM comptes
                 WHERE dossier_id = {$emptyDossier} AND numero = '1000'"
            )->fetchColumn(),
            'compte plat importé avec son type explicite'
        );

        $bank = $this->accountId($pdo, $ids['dossier_a'], '1020');
        $sales = $this->accountId($pdo, $ids['dossier_a'], '3400');
        $admin = $this->accountId($pdo, $ids['dossier_a'], '6500');
        $vat = $this->accountId($pdo, $ids['dossier_a'], '1171');
        $supplier = $this->accountId($pdo, $ids['dossier_a'], '2000');
        $capital = $this->accountId($pdo, $ids['dossier_a'], '2800');
        $foreignBank = $this->accountId($pdo, $ids['dossier_b'], '1020');
        $entries = new EntryService($pdo, $audit);
        $base = [
            'organisation_id' => $ids['organisation_a'],
            'dossier_id' => $ids['dossier_a'],
            'exercice_id' => $exerciseA,
            'journal_id' => $journalA,
            'date_comptable' => '2026-03-15',
        ];

        $simple = $entries->createDraft($base + [
            'libelle' => 'Vente au comptant',
            'reference' => 'V-1',
            'lignes' => [
                ['compte_id' => $bank, 'debit_centimes' => 10000],
                ['compte_id' => $sales, 'credit_centimes' => 10000],
            ],
        ]);
        $number = $entries->validate(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $simple
        );
        $this->true(str_starts_with($number, 'OD-2026-'), 'écriture simple validée et numérotée');

        $composed = $entries->createDraft($base + [
            'libelle' => 'Facture fournisseur composée',
            'reference' => 'F-1',
            'lignes' => [
                ['compte_id' => $admin, 'debit_centimes' => 10000],
                ['compte_id' => $vat, 'debit_centimes' => 810],
                ['compte_id' => $supplier, 'credit_centimes' => 10810],
            ],
        ]);
        $entries->validate(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $composed
        );
        $this->same(
            3,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM lignes_ecriture WHERE ecriture_id = {$composed}"
            )->fetchColumn(),
            'écriture composée validée'
        );

        $unbalanced = $entries->createDraft($base + [
            'libelle' => 'Écart d’un centime',
            'lignes' => [
                ['compte_id' => $bank, 'debit_centimes' => 100],
                ['compte_id' => $sales, 'credit_centimes' => 99],
            ],
        ]);
        $this->throws(
            fn () => $entries->validate(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $unbalanced
            ),
            'déséquilibre d’un centime refusé'
        );
        $this->same(
            'brouillon',
            (string) $pdo->query(
                "SELECT statut FROM ecritures WHERE id = {$unbalanced}"
            )->fetchColumn(),
            'échec de validation atomique'
        );

        $this->throws(
            fn () => $entries->createDraft($base + [
                'libelle' => 'Compte étranger',
                'lignes' => [
                    ['compte_id' => $foreignBank, 'debit_centimes' => 100],
                    ['compte_id' => $sales, 'credit_centimes' => 100],
                ],
            ]),
            'compte d’un autre dossier refusé'
        );
        $this->throws(
            fn () => $entries->createDraft([
                ...$base,
                'organisation_id' => $ids['organisation_b'],
                'libelle' => 'Scope croisé',
                'lignes' => [
                    ['compte_id' => $bank, 'debit_centimes' => 100],
                    ['compte_id' => $sales, 'credit_centimes' => 100],
                ],
            ]),
            'écriture traversant deux organisations refusée'
        );

        $outside = $entries->createDraft([
            ...$base,
            'date_comptable' => '2027-01-01',
            'libelle' => 'Hors exercice',
            'lignes' => [
                ['compte_id' => $bank, 'debit_centimes' => 100],
                ['compte_id' => $sales, 'credit_centimes' => 100],
            ],
        ]);
        $this->throws(
            fn () => $entries->validate(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $outside
            ),
            'date hors exercice refusée'
        );
        $closed = $entries->createDraft([
            ...$base,
            'date_comptable' => '2026-08-01',
            'libelle' => 'Période fermée',
            'lignes' => [
                ['compte_id' => $bank, 'debit_centimes' => 100],
                ['compte_id' => $sales, 'credit_centimes' => 100],
            ],
        ]);
        $setup->closePeriod(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $periodA2
        );
        $this->throws(
            fn () => $entries->validate(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $closed
            ),
            'période fermée refusée'
        );

        $generatedCommand = $base + [
            'libelle' => 'Encaissement généré',
            'source_type' => 'facture_client',
            'source_id' => '42',
            'source_action' => 'encaisser',
            'lignes' => [
                ['compte_id' => $bank, 'debit_centimes' => 500],
                ['compte_id' => $sales, 'credit_centimes' => 500],
            ],
        ];
        $generatedA = $entries->postGenerated($generatedCommand, 'facture:42:encaisser');
        $generatedB = (new EntryService($pdo, $audit))->postGenerated(
            $generatedCommand,
            'facture:42:encaisser'
        );
        $this->same($generatedA, $generatedB, 'rejeu idempotent retourne la même écriture');
        $this->same(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM ecritures
                 WHERE cle_idempotence = 'facture:42:encaisser'"
            )->fetchColumn(),
            'clé idempotente sans doublon'
        );
        $this->throws(
            fn () => $entries->postGenerated(
                [...$generatedCommand, 'libelle' => 'Commande différente'],
                'facture:42:encaisser'
            ),
            'réutilisation incohérente d’une clé refusée'
        );

        $reversal = $entries->reverse(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $simple,
            '2026-03-20'
        );
        $pairs = $pdo->query(
            "SELECT o.debit_centimes AS od, o.credit_centimes AS oc,
                    r.debit_centimes AS rd, r.credit_centimes AS rc
             FROM lignes_ecriture o
             JOIN lignes_ecriture r ON r.ordre = o.ordre
             WHERE o.ecriture_id = {$simple} AND r.ecriture_id = {$reversal}
             ORDER BY o.ordre"
        )->fetchAll();
        $exact = $pairs !== [];
        foreach ($pairs as $pair) {
            $exact = $exact
                && (int) $pair['od'] === (int) $pair['rc']
                && (int) $pair['oc'] === (int) $pair['rd'];
        }
        $this->true(
            $exact,
            'contre-passation exacte'
        );
        $this->same(
            'contre_passee',
            (string) $pdo->query(
                "SELECT statut FROM ecritures WHERE id = {$simple}"
            )->fetchColumn(),
            'original conservé et marqué contre-passé'
        );
        $this->same(
            $reversal,
            $entries->reverse(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $simple,
                '2026-03-20'
            ),
            'contre-passation rejouée sans doublon'
        );
        $this->throws(
            fn () => $pdo->exec(
                "UPDATE lignes_ecriture SET debit_centimes = 1
                 WHERE ecriture_id = {$composed} AND ordre = 1"
            ),
            'lignes validées immuables en base'
        );

        $opening = $entries->postOpeningBalances(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exerciseA,
            $journalOpening,
            [
                ['compte_id' => $bank, 'debit_centimes' => 1000],
                ['compte_id' => $capital, 'credit_centimes' => 1000],
            ],
            'OUV-2026'
        );
        $this->true($opening > 0, 'soldes d’ouverture comptabilisés');

        $nextExercise = $scope->createExercise(
            $ids['dossier_a'],
            'Exercice 2027',
            '2027-01-01',
            '2027-12-31'
        );
        $period2027 = $setup->createPeriod(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $nextExercise,
            'Année 2027',
            '2027-01-01',
            '2027-12-31'
        );
        $this->throws(
            fn () => $entries->saveOpeningDraft(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $nextExercise,
                $journalOpening,
                [$sales => 1000]
            ),
            'compte de produit refusé dans les soldes d’ouverture'
        );
        $openingDraft = $entries->saveOpeningDraft(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $nextExercise,
            $journalOpening,
            [$bank => 150000, $capital => 150000]
        );
        $openingState = $entries->openingState(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $nextExercise
        );
        $this->same('brouillon', $openingState['status'], 'ouverture enregistrée en brouillon');
        $this->same(150000, $openingState['soldes'][$bank], 'solde débiteur naturel préparé');
        $this->same(150000, $openingState['soldes'][$capital], 'solde créditeur naturel préparé');
        $editedDraft = $entries->saveOpeningDraft(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $nextExercise,
            $journalOpening,
            [$bank => 175000, $capital => 175000]
        );
        $this->same($openingDraft, $editedDraft, 'brouillon d’ouverture édité sans doublon');
        $accountingCsv = new AccountingCsvService($pdo, $chart, $entries);
        $openingExport = $accountingCsv->exportOpening(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $nextExercise
        );
        $this->true(
            str_starts_with($openingExport['content'], "\xEF\xBB\xBFnumero;libelle;sens;solde"),
            'soldes d’ouverture exportés dans leur format CSV propre'
        );
        $openingPreview = $accountingCsv->previewOpening(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $nextExercise,
            $openingExport['content']
        );
        $this->same(
            2,
            $openingPreview['summary']['non_zero'],
            'réimport des soldes d’ouverture prévisualisé et équilibré'
        );
        $this->same(
            $openingDraft,
            $accountingCsv->importOpening(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $nextExercise,
                $openingExport['content'],
                $openingPreview['fingerprint'],
                $reportActorId
            )['id'],
            'import d’ouverture remplace atomiquement le même brouillon'
        );
        $openingNumber = $entries->validateOpeningDraft(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $nextExercise
        );
        $this->true(str_starts_with($openingNumber, 'OUV-2027-'), 'ouverture validée dans son journal');
        $this->throws(
            fn () => $entries->saveOpeningDraft(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $nextExercise,
                $journalOpening,
                [$bank => 100000, $capital => 100000]
            ),
            'ouverture validée immuable'
        );

        $reports = new ReportingService($pdo);
        $journalDetails = $accountingCsv->journalDetails(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exerciseA
        );
        $this->true(
            $journalDetails['total_lines'] >= 2
            && $journalDetails['total_entries'] >= 1,
            'journal détaillé expose toutes les écritures et leurs lignes'
        );
        $this->true(
            str_starts_with(
                $accountingCsv->exportJournal(
                    $ids['organisation_a'],
                    $ids['dossier_a'],
                    $exerciseA
                )['content'],
                "\xEF\xBB\xBFecriture;date;journal;reference;piece;"
            ),
            'journal détaillé exporté dans un CSV réimportable'
        );
        $journalCode = (string) $pdo->query(
            "SELECT code FROM journaux WHERE id = {$journalA}"
        )->fetchColumn();
        $journalImportCsv = "\xEF\xBB\xBF"
            . "ecriture;date;journal;reference;piece;libelle_ecriture;"
            . "compte;libelle_ligne;debit;credit;statut\n"
            . "IMPORT-1;2027-04-01;{$journalCode};IMP-1;;Import contrôlé;"
            . "6500;Charge importée;12.50;0.00;validee\n"
            . "IMPORT-1;2027-04-01;{$journalCode};IMP-1;;Import contrôlé;"
            . "3400;Produit importé;0.00;12.50;validee\n";
        $journalImportPreview = $accountingCsv->previewJournalImport(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $nextExercise,
            $journalImportCsv
        );
        $this->same(
            1,
            $journalImportPreview['summary']['entries'],
            'import du journal prévisualisé par écriture'
        );
        $this->same(
            2,
            $accountingCsv->importJournal(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $nextExercise,
                $journalImportCsv,
                $journalImportPreview['fingerprint'],
                $reportActorId
            )['lines'],
            'écriture de journal importée atomiquement avec ses détails'
        );
        $this->throws(
            fn () => $accountingCsv->importJournal(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $nextExercise,
                $journalImportCsv,
                $journalImportPreview['fingerprint'],
                $reportActorId
            ),
            'réimport du même journal refusé sans doublon'
        );
        $generalLedger2027 = $reports->generalLedger(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $nextExercise
        );
        $bankGeneralLedger = array_values(array_filter(
            $generalLedger2027['items'],
            static fn (array $row): bool => $row['numero'] === '1020'
        ))[0];
        $this->same(
            175000,
            (int) $bankGeneralLedger['initial_centimes'],
            'grand livre distingue rigoureusement le solde initial'
        );
        $this->same(
            175000,
            (int) $bankGeneralLedger['solde_centimes'],
            'grand livre calcule le solde final naturel'
        );
        $balance = $reports->trialBalance(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exerciseA
        );
        $this->true($balance['equilibree'], 'balance débit/crédit égale');
        $this->same(
            10000,
            $this->balanceFor($balance['items'], '6500'),
            'solde de charge selon son sens normal'
        );
        $this->same(
            500,
            $this->balanceFor($balance['items'], '3400'),
            'solde de produit selon son sens normal'
        );
        $bankBalanceRow = array_values(array_filter(
            $balance['items'],
            static fn (array $row): bool => $row['numero'] === '1020'
        ))[0];
        $this->same(
            '100 Trésorerie ‹ 10 Actifs circulants ‹ 1 ACTIFS',
            $bankBalanceRow['rubrique_chemin'],
            'hiérarchie configurable propagée aux rapports'
        );
        $journal = $reports->journal(
            $ids['organisation_a'],
            $ids['dossier_a'],
            ['statut' => 'comptabilisee', 'page' => 1, 'par_page' => 2]
        );
        $this->same(2, count($journal['items']), 'pagination serveur du journal');
        $this->true($journal['total'] >= 5, 'journal filtré et totalisé');
        $ledger = $reports->ledger(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $bank,
            ['exercice_id' => $exerciseA]
        );
        $this->true($ledger['items'] !== [], 'grand livre généré');
        $this->true(
            str_starts_with(
                $reports->csv($balance['items'], ['numero' => 'Compte']),
                "\xEF\xBB\xBF"
            ),
            'export CSV UTF-8'
        );
        $this->true(
            array_key_exists(
                'resultat_centimes',
                $reports->incomeStatement(
                    $ids['organisation_a'],
                    $ids['dossier_a'],
                    $exerciseA
                )
            ),
            'compte de résultat généré'
        );
        $sheet = $reports->balanceSheet(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exerciseA
        );
        $this->true(
            array_key_exists('total_actif_centimes', $sheet),
            'bilan généré'
        );
        $this->true($sheet['equilibre'], 'résultat courant intégré au bilan');

        (new TreasuryAccountService($pdo, $audit))->create([
            'organisation_id' => $ids['organisation_a'],
            'dossier_id' => $ids['dossier_a'],
            'compte_comptable_id' => $bank,
            'libelle' => 'Banque rapports',
            'type' => 'banque',
            'iban' => 'CH9300762011623852957',
            'bic' => 'POFICHBEXXX',
            'monnaie' => 'CHF',
        ]);
        $financial = new FinancialReportingService($pdo, $reports);
        $financial2027 = $financial->read(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $nextExercise,
            '2027-01-01',
            '2027-12-31'
        );
        $this->true(
            $financial2027['controls']['debit_equals_credit']
            && $financial2027['controls']['balance_sheet_balanced']
            && $financial2027['controls']['result_reconciled'],
            'balance, résultat et bilan réconciliés dans la projection financière'
        );
        $this->same(
            175000,
            $financial2027['cash_flow']['opening_cash_cents'],
            'flux reprend les liquidités d’ouverture'
        );
        $this->same(
            0,
            $financial2027['cash_flow']['reconciliation_difference_cents'],
            'flux réconcilié au centime avec la variation des liquidités'
        );
        $this->same(
            $exerciseA,
            $financial2027['income_statement']['previous']['exercise_id'],
            'compte de résultat comparé au dernier exercice antérieur'
        );
        $vatWorkspace = new VatWorkspaceService(
            $pdo,
            new VatStatementService($pdo, $audit),
            new Ech0217ExportService(
                $pdo,
                $audit,
                new Ech0217Validator(
                    dirname(__DIR__)
                    . '/resources/xsd/ech-0217-2-0-0-current-profile.xsd'
                ),
                'test'
            )
        );
        $vat2027 = $vatWorkspace->read(
            $ids['organisation_a'],
            $ids['dossier_a'],
            '2027-01-01',
            '2027-12-31'
        );
        $this->same(null, $vat2027['regime'], 'TVA Vue explicite sans régime configuré');
        $closing = new ClosingAndTaxService(
            $pdo,
            $audit,
            $setup,
            $financial
        );
        $closing->saveManualControl(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $nextExercise,
            'pieces',
            'termine',
            'Pièces revues.',
            0,
            $reportActorId
        );
        $closingState = $closing->read(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $nextExercise,
            '2027-01-01',
            '2027-12-31',
            $financial2027,
            $vat2027
        );
        $this->true($closingState['closing']['can_close'], 'checklist automatique autorise la clôture');
        $this->same(
            'termine',
            $closingState['closing']['manual_controls'][0]['status'],
            'checklist manuelle versionnée conservée'
        );
        $adjustmentA = $closing->createAdjustment(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $nextExercise,
            'Réserve préparatoire',
            'information',
            2500,
            'À valider par le fiscaliste.',
            'fiscal-2027-reserve',
            $reportActorId
        );
        $adjustmentB = $closing->createAdjustment(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $nextExercise,
            'Réserve préparatoire',
            'information',
            2500,
            'À valider par le fiscaliste.',
            'fiscal-2027-reserve',
            $reportActorId
        );
        $this->same($adjustmentA, $adjustmentB, 'ajustement fiscal rejouable sans doublon');
        $this->throws(
            fn () => $closing->createAdjustment(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $nextExercise,
                'Réserve préparatoire',
                'information',
                2501,
                'À valider par le fiscaliste.',
                'fiscal-2027-reserve',
                $reportActorId
            ),
            'clé fiscale refuse un ajustement différent'
        );
        $this->same(
            false,
            $closingState['tax_file']['official_declaration'],
            'dossier fiscal ne prétend pas produire une déclaration officielle'
        );
        $archivePayload = [
            'reports' => $financial2027,
            'vat' => $vat2027,
            'tax_file' => $closingState['tax_file'],
        ];
        $archiveA = $closing->archive(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $nextExercise,
            'cloture',
            '2027-01-01',
            '2027-12-31',
            $archivePayload,
            $reportActorId
        );
        $archiveB = $closing->archive(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $nextExercise,
            'cloture',
            '2027-01-01',
            '2027-12-31',
            $archivePayload,
            $reportActorId
        );
        $this->same($archiveA, $archiveB, 'archive financière idempotente à grand livre identique');
        $archiveChanged = $closing->archive(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $nextExercise,
            'cloture',
            '2027-01-01',
            '2027-12-31',
            [...$archivePayload, 'review_note' => 'Revue complétée'],
            $reportActorId
        );
        $this->true(
            $archiveChanged !== $archiveA,
            'contenu de clôture différent produit une archive distincte'
        );
        $archive = $closing->archiveContent(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $archiveA
        );
        $this->same(
            hash('sha256', $archive['content']),
            $archive['hash'],
            'empreinte de l’archive financière vérifiée'
        );
        $this->throws(
            fn () => $pdo->exec(
                "UPDATE archives_rapports_financiers
                 SET date_fin = '2027-11-30' WHERE id = {$archiveA}"
            ),
            'archive financière immuable'
        );
        $closing->setPeriodStatus(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $nextExercise,
            $period2027,
            'fermee',
            1,
            $reportActorId
        );
        $this->same(
            'fermee',
            (string) $pdo->query(
                "SELECT statut FROM periodes WHERE id = {$period2027}"
            )->fetchColumn(),
            'période verrouillée depuis la checklist de clôture'
        );
        $this->same(
            3,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM archives_rapports_financiers
                 WHERE exercice_id = {$nextExercise} AND type = 'cloture'"
            )->fetchColumn(),
            'fermeture archive automatiquement ses rapports et contrôles'
        );

        $chart = new ChartOfAccountsService($pdo, $audit);
        $adminVersion = (int) $pdo->query(
            "SELECT version FROM comptes WHERE id = {$admin}"
        )->fetchColumn();
        $chart->updateAccount(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $admin,
            '6501',
            'Charges administratives renommées',
            'charge',
            'automatique',
            $adminVersion
        );
        $this->true(
            (int) $pdo->query(
                "SELECT COUNT(*) FROM lignes_ecriture WHERE compte_id = {$admin}"
            )->fetchColumn() > 0,
            'renumérotation conserve les écritures par identifiant stable'
        );
        $this->same(
            'desactive',
            $chart->removeOrDeactivate(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $admin
            ),
            'compte utilisé seulement désactivable'
        );
        $this->same(
            0,
            (int) $pdo->query(
                "SELECT actif FROM comptes WHERE id = {$admin}"
            )->fetchColumn(),
            'historique du compte désactivé conservé'
        );
        $this->true(IntegrityChecker::check($pdo)['ok'], 'intégrité après opérations comptables');
        $this->true($periodA1 > 0, 'périodes rattachées à l’exercice');
    }

    private function operationsTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $pdo->exec('CREATE TABLE qualification_restore_marker (marker TEXT NOT NULL)');
        $pdo->prepare(
            'INSERT INTO qualification_restore_marker (marker) VALUES (?)'
        )->execute(['LOT15-RESTORE-OK']);
        $directory = $this->tempDir() . '/backups';
        $backup = BackupService::create($pdo, $directory, 'test-instance');
        $this->true(is_file($backup), 'sauvegarde créée');
        $copy = ConnectionFactory::sqlite($backup);
        $this->true(IntegrityChecker::check($copy)['ok'], 'sauvegarde restaurable/intègre');
        $restoredPath = $this->tempDir() . '/restored.sqlite';
        file_put_contents($restoredPath, 'base simulée perdue');
        $this->true(copy($backup, $restoredPath), 'sauvegarde restaurée vers une base cible');
        $restored = ConnectionFactory::sqlite($restoredPath);
        $this->same(
            'LOT15-RESTORE-OK',
            (string) $restored->query(
                'SELECT marker FROM qualification_restore_marker'
            )->fetchColumn(),
            'donnée témoin relue après restauration réelle'
        );
        $this->true(
            IntegrityChecker::check($restored)['ok'],
            'base effectivement restaurée intègre'
        );

        $config = AppConfig::load(dirname(__DIR__), [
            'instance_id' => 'doctor-test',
            'storage_path' => $this->tempDir(),
            'database_path' => $this->tempDir() . '/doctor.sqlite',
        ]);
        $levels = array_column((new Doctor($config, $pdo, $runner))->run(), 'level');
        $this->false(in_array('error', $levels, true), 'diagnostic sans erreur bloquante');
        $this->true(
            !is_dir(dirname(__DIR__) . '/public/storage'),
            'storage absent du webroot'
        );
    }

    private function pedagogyTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $ids = $this->seedScopes($pdo);
        $organisation = $ids['organisation_a'];
        $source = $ids['dossier_a'];
        $audit = new AuditLogger($pdo);
        $users = new UserRepository($pdo);
        $learnerA = $users->create('a.apprenant@example.test', 'mot-de-passe-apprenant-a');
        $learnerB = $users->create('b.apprenant@example.test', 'mot-de-passe-apprenant-b');
        $learnerC = $users->create('c.apprenant@example.test', 'mot-de-passe-apprenant-c');
        $learnerRole = (int) $pdo->query(
            "SELECT id FROM roles WHERE code = 'apprenant'"
        )->fetchColumn();
        foreach ([$learnerA, $learnerB, $learnerC] as $learner) {
            $pdo->prepare(
                'INSERT INTO utilisateur_roles_installation
                 (utilisateur_id, role_id) VALUES (?, ?)'
            )->execute([$learner, $learnerRole]);
        }
        $scope = new ScopeManager($pdo, $audit);
        $exercise = $scope->createExercise(
            $source, 'Modèle 2026', '2026-01-01', '2026-12-31'
        );
        $setup = new AccountingSetupService($pdo, $audit);
        $setup->createPeriod(
            $organisation, $source, $exercise, '2026',
            '2026-01-01', '2026-12-31'
        );
        $setup->createJournal(
            $organisation, $source, 'OD', 'Opérations diverses'
        );
        (new PlanSeeder($pdo, dirname(__DIR__) . '/database/seeds'))
            ->installForDossier($organisation, $source, 'personne_morale');
        $entries = new EntryService($pdo, $audit);
        $pedagogy = new PedagogyService($pdo, $audit, $entries);
        $catalogInstall = $pedagogy->installTargetedCatalog(
            $organisation,
            $source
        );
        $this->same(
            ['created' => 7, 'existing' => 0],
            $catalogInstall,
            'catalogue ciblé installe les sept compétences'
        );
        $this->same(
            ['created' => 0, 'existing' => 7],
            $pedagogy->installTargetedCatalog($organisation, $source),
            'installation du catalogue ciblé idempotente'
        );
        $catalogModels = $pedagogy->models($organisation);
        $catalogCompetences = array_values(array_unique(
            array_column($catalogModels, 'competence')
        ));
        $expectedCompetences = array_keys(PedagogyService::COMPETENCES);
        sort($catalogCompetences);
        sort($expectedCompetences);
        $this->same(
            $expectedCompetences,
            $catalogCompetences,
            'catalogue structuré dans les sept compétences'
        );
        $scenarioLines = [
            'debit_credit' => [['1000', 10000, 0], ['3400', 0, 10000]],
            'tva' => [['1000', 10810, 0], ['3400', 0, 10000], ['2200', 0, 810]],
            'facturation' => [['1100', 10000, 0], ['3400', 0, 10000]],
            'salaires' => [['5000', 10000, 0], ['2000', 0, 10000]],
            'rapprochement' => [['1020', 10000, 0], ['1100', 0, 10000]],
            'cloture' => [['1000', 10000, 0], ['3400', 0, 10000]],
            'lecture_etats' => [['1000', 10000, 0], ['3400', 0, 10000]],
        ];
        foreach ($catalogModels as $catalogModel) {
            $competence = (string) $catalogModel['competence'];
            $scenarioAssignment = $pedagogy->assignIndividual(
                $organisation,
                (int) $catalogModel['version_id'],
                $learnerC,
                'Cible ' . $competence
            );
            $scenarioDossier = (int) $pdo->query(
                "SELECT dossier_id FROM assignations_exercice
                 WHERE id = {$scenarioAssignment}"
            )->fetchColumn();
            $scenarioExercise = (int) $pdo->query(
                "SELECT id FROM exercices WHERE dossier_id = {$scenarioDossier}"
            )->fetchColumn();
            $scenarioJournal = (int) $pdo->query(
                "SELECT id FROM journaux WHERE dossier_id = {$scenarioDossier}
                 ORDER BY id LIMIT 1"
            )->fetchColumn();
            $scenarioStep = (int) $pdo->query(
                "SELECT etape_id FROM progressions_etapes
                 WHERE assignation_id = {$scenarioAssignment}"
            )->fetchColumn();
            $failure = $pedagogy->attempt(
                $organisation,
                $scenarioDossier,
                $learnerC,
                $scenarioStep,
                null
            );
            $this->false(
                $failure['reussie'],
                "retour pédagogique d’échec ciblé {$competence}"
            );
            $this->true(
                trim((string) ($failure['messages'][0] ?? '')) !== '',
                "message d’échec explicatif {$competence}"
            );
            $lines = array_map(
                fn (array $line): array => [
                    'compte_id' => $this->accountId(
                        $pdo,
                        $scenarioDossier,
                        $line[0]
                    ),
                    'debit_centimes' => $line[1],
                    'credit_centimes' => $line[2],
                ],
                $scenarioLines[$competence]
            );
            $scenarioEntry = $pedagogy->createDraft(
                $organisation,
                $scenarioDossier,
                $learnerC,
                [
                    'exercice_id' => $scenarioExercise,
                    'journal_id' => $scenarioJournal,
                    'date_comptable' => '2026-04-15',
                    'libelle' => 'Réponse ' . $competence,
                    'lignes' => $lines,
                ]
            );
            $pedagogy->validateDraft(
                $organisation,
                $scenarioDossier,
                $learnerC,
                $scenarioEntry
            );
            $success = $pedagogy->attempt(
                $organisation,
                $scenarioDossier,
                $learnerC,
                $scenarioStep,
                $scenarioEntry
            );
            $this->true(
                $success['reussie'],
                "validation comptable réussie {$competence}"
            );
            $this->true(
                trim((string) ($success['messages'][0] ?? '')) !== '',
                "message de réussite explicatif {$competence}"
            );
        }
        $learnerWorkspace = $pedagogy->workspace(
            $organisation,
            (int) $pdo->query(
                "SELECT dossier_id FROM assignations_exercice
                 WHERE utilisateur_id = {$learnerC} ORDER BY id LIMIT 1"
            )->fetchColumn(),
            $learnerC,
            false
        );
        $this->false(
            str_contains(
                json_encode($learnerWorkspace, JSON_THROW_ON_ERROR),
                'Débit 1000 Caisse'
            ),
            'solutions protégées absentes du contrat espace apprenant'
        );
        $model = $pedagogy->createModel(
            $organisation, 'Caisse et ventes', 'Exercice de saisie'
        );
        $version = $pedagogy->createVersion(
            $organisation,
            $model,
            $source,
            'Comptabilisez une vente au comptant.',
            [[
                'code' => 'E1',
                'titre' => 'Vente au comptant',
                'consigne' => 'Débitez la caisse et créditez les ventes.',
                'indices' => [
                    'Cherchez un compte de liquidités.',
                    'La vente augmente au crédit.',
                ],
                'regles' => [[
                    'type' => 'ecriture_equivalente',
                    'configuration' => [
                        'lignes' => [
                            ['compte' => '1000', 'sens' => 'debit', 'montant_centimes' => 10000],
                            ['compte' => '3400', 'sens' => 'credit', 'montant_centimes' => 10000],
                        ],
                    ],
                ]],
            ]],
            [],
            [],
            ['explication' => 'Caisse à Ventes, CHF 100.00'],
            'manuelle'
        );
        $this->same(
            1,
            (int) $pdo->query(
                "SELECT version_courante FROM modeles_exercice WHERE id = {$model}"
            )->fetchColumn(),
            'modèle pédagogique versionné'
        );

        $assignmentA = $pedagogy->assignIndividual(
            $organisation, $version, $learnerA, 'Copie individuelle A'
        );
        $assignmentB = $pedagogy->assignIndividual(
            $organisation, $version, $learnerB, 'Copie individuelle B'
        );
        $copiesA = $pedagogy->assignmentsForUser($learnerA);
        $this->same(1, count($copiesA), 'apprenant A ne voit que sa copie individuelle');
        $this->false(
            in_array($assignmentB, array_column($copiesA, 'id'), true),
            'apprenant A ne voit pas la copie de B'
        );
        $this->false(
            str_contains(json_encode($copiesA, JSON_THROW_ON_ERROR), 'explication'),
            'solution absente des données envoyées à l’apprenant'
        );

        $group = $pedagogy->createGroup($organisation, 'Groupe AB');
        $pedagogy->addMember($organisation, $group, $learnerA);
        $pedagogy->addMember($organisation, $group, $learnerB);
        $groupAssignment = $pedagogy->assignGroup(
            $organisation, $version, $group, 'Copie collaborative AB'
        );
        $groupDossier = (int) $pdo->query(
            "SELECT dossier_id FROM assignations_exercice WHERE id = {$groupAssignment}"
        )->fetchColumn();
        $groupExercise = (int) $pdo->query(
            "SELECT id FROM exercices WHERE dossier_id = {$groupDossier}"
        )->fetchColumn();
        $groupJournal = (int) $pdo->query(
            "SELECT id FROM journaux WHERE dossier_id = {$groupDossier} AND code = 'OD'"
        )->fetchColumn();
        $cash = $this->accountId($pdo, $groupDossier, '1000');
        $sales = $this->accountId($pdo, $groupDossier, '3400');
        $draftA = $pedagogy->createDraft(
            $organisation,
            $groupDossier,
            $learnerA,
            [
                'exercice_id' => $groupExercise,
                'journal_id' => $groupJournal,
                'date_comptable' => '2026-01-10',
                'libelle' => 'Travail A',
                'lignes' => [
                    ['compte_id' => $cash, 'debit_centimes' => 10000],
                    ['compte_id' => $sales, 'credit_centimes' => 10000],
                ],
            ]
        );
        $draftB = $pedagogy->createDraft(
            $organisation,
            $groupDossier,
            $learnerB,
            [
                'exercice_id' => $groupExercise,
                'journal_id' => $groupJournal,
                'date_comptable' => '2026-01-11',
                'libelle' => 'Travail B',
                'lignes' => [
                    ['compte_id' => $cash, 'debit_centimes' => 5000],
                    ['compte_id' => $sales, 'credit_centimes' => 5000],
                ],
            ]
        );
        $this->same(2, count(array_unique([$draftA, $draftB])), 'deux créations simultanées distinctes conservées');
        $this->same(
            2,
            (int) $pdo->query(
                "SELECT COUNT(DISTINCT utilisateur_id)
                 FROM contributions_pedagogiques WHERE assignation_id = {$groupAssignment}"
            )->fetchColumn(),
            'contributions individuelles attribuées à leur auteur'
        );
        $replacement = [
            'exercice_id' => $groupExercise,
            'journal_id' => $groupJournal,
            'date_comptable' => '2026-01-10',
            'libelle' => 'Travail A modifié',
            'lignes' => [
                ['compte_id' => $cash, 'debit_centimes' => 10000],
                ['compte_id' => $sales, 'credit_centimes' => 10000],
            ],
        ];
        $draftVersion = (int) $pdo->query(
            "SELECT version FROM ecritures WHERE id = {$draftA}"
        )->fetchColumn();
        $pedagogy->replaceDraft(
            $organisation, $groupDossier, $learnerA, $draftA, $draftVersion, $replacement
        );
        $this->throws(
            fn () => $pedagogy->replaceDraft(
                $organisation, $groupDossier, $learnerB, $draftA, $draftVersion, $replacement
            ),
            'seconde modification de la même version refusée explicitement'
        );
        try {
            $pedagogy->replaceDraft(
                $organisation, $groupDossier, $learnerB, $draftA, $draftVersion, $replacement
            );
        } catch (PedagogyConflictException $e) {
            $this->true(
                str_contains($e->getMessage(), 'Conflit'),
                'conflit optimiste explicite et sans écrasement'
            );
        }

        $equivalent = $pedagogy->createDraft(
            $organisation,
            $groupDossier,
            $learnerA,
            [
                'exercice_id' => $groupExercise,
                'journal_id' => $groupJournal,
                'date_comptable' => '2026-02-01',
                'libelle' => 'Solution équivalente',
                'lignes' => [
                    ['compte_id' => $cash, 'debit_centimes' => 4000],
                    ['compte_id' => $cash, 'debit_centimes' => 6000],
                    ['compte_id' => $sales, 'credit_centimes' => 10000],
                ],
            ]
        );
        $step = (int) $pdo->query(
            "SELECT etape_id FROM progressions_etapes
             WHERE assignation_id = {$groupAssignment}"
        )->fetchColumn();
        $result = $pedagogy->attempt(
            $organisation, $groupDossier, $learnerA, $step, $equivalent
        );
        $this->true($result['reussie'], 'solution comptablement équivalente acceptée');
        $hint1 = $pedagogy->nextHint(
            $organisation, $groupDossier, $learnerA, $step
        );
        $hint2 = $pedagogy->nextHint(
            $organisation, $groupDossier, $learnerA, $step
        );
        $this->same([1, 2], [$hint1['niveau'], $hint2['niveau']], 'indices affichés graduellement');
        $this->throws(
            fn () => $pedagogy->correction(
                $organisation, $groupDossier, $learnerA
            ),
            'correction masquée avant autorisation'
        );
        $pedagogy->authorizeCorrection($organisation, $groupAssignment);
        $this->true(
            isset($pedagogy->correction(
                $organisation, $groupDossier, $learnerA
            )['explication']),
            'correction visible après règle du formateur'
        );
        $this->same(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM tentatives_pedagogiques
                 WHERE id = {$result['tentative_id']}"
            )->fetchColumn(),
            'tentative conservée après affichage de la correction'
        );

        $pedagogy->removeMember($organisation, $group, $learnerB);
        $this->throws(
            fn () => $pedagogy->createDraft(
                $organisation, $groupDossier, $learnerB, $replacement
            ),
            'ancien membre perd immédiatement l’accès au groupe'
        );
        $modelHash = (string) $pdo->query(
            "SELECT plan_snapshot_json FROM versions_modeles_exercice WHERE id = {$version}"
        )->fetchColumn();
        $newDossier = $pedagogy->reset($organisation, $groupAssignment);
        $this->true($newDossier !== $groupDossier, 'reset crée atomiquement une copie propre');
        $this->same(
            $modelHash,
            (string) $pdo->query(
                "SELECT plan_snapshot_json FROM versions_modeles_exercice WHERE id = {$version}"
            )->fetchColumn(),
            'reset sans effet sur le modèle'
        );
        $this->same(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM audit_events
                 WHERE action = 'pedagogie.assignation_reinitialisee'"
            )->fetchColumn(),
            'reset audité'
        );
        $this->throws(
            fn () => $pedagogy->assertResetAllowed(
                $ids['organisation_b'], $ids['dossier_b']
            ),
            'service refuse la réinitialisation d’un dossier réel'
        );
        $access = new AccessControl($pdo);
        $this->false(
            $access->canViewDossier(
                $learnerA, $ids['organisation_b'], $ids['dossier_b']
            ),
            'apprenant sans accès à un dossier réel en instance mixte'
        );
        $learnerList = json_encode(
            $access->dossiersForUser($learnerA), JSON_THROW_ON_ERROR
        );
        $this->false(
            str_contains($learnerList, 'Organisation B')
                || str_contains($learnerList, 'Comptabilité B'),
            'listes apprenant sans organisation ni métadonnée réelle'
        );
        $teacher = json_encode(
            $pedagogy->dashboard($organisation), JSON_THROW_ON_ERROR
        );
        $this->false(
            str_contains($teacher, 'Comptabilité B'),
            'tableau formateur sans dossier réel'
        );
        $this->true(IntegrityChecker::check($pdo)['ok'], 'intégrité après pédagogie');
    }

    private function payrollTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $ids = $this->seedScopes($pdo);
        $organisationId = $ids['organisation_a'];
        $dossierId = $ids['dossier_a'];
        $audit = new AuditLogger($pdo);
        $scope = new ScopeManager($pdo, $audit);
        $exercise = $scope->createExercise(
            $dossierId,
            'Exercice 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $setup = new AccountingSetupService($pdo, $audit);
        $setup->createPeriod(
            $organisationId,
            $dossierId,
            $exercise,
            '2026',
            '2026-01-01',
            '2026-12-31'
        );
        $journal = $setup->createJournal(
            $organisationId,
            $dossierId,
            'SAL',
            'Salaires',
            'salaires'
        );
        (new PlanSeeder($pdo, dirname(__DIR__) . '/database/seeds'))
            ->installForDossier(
                $organisationId,
                $dossierId,
                'personne_morale'
            );
        $account = fn (string $number): int =>
            $this->accountId($pdo, $dossierId, $number);
        $configuration = new PayrollConfigurationService($pdo, $audit);
        $configuration->saveEmployer(
            $organisationId,
            $dossierId,
            [
                'nom' => 'Atelier genevois',
                'rue' => 'Rue du Rhône 1',
                'npa' => '1204',
                'localite' => 'Genève',
                'heures_hebdo_milli' => 40000,
            ]
        );
        $this->same(
            'Organisation A',
            (string) $configuration->employer(
                $organisationId,
                $dossierId
            )['nom'],
            'identité employeur reprise de l’entité légale'
        );
        $rates = [
            'avs_ppm' => 53000,
            'ac_ppm' => 11000,
            'amat_ppm' => 290,
            'laa_reduit_ppm' => 10600,
            'laa_plein_ppm' => 21000,
            'lpp_ppm' => 35000,
            'emp_avs_ppm' => 53000,
            'emp_ac_ppm' => 11000,
            'emp_amat_ppm' => 290,
            'emp_af_ppm' => 22700,
            'emp_laa_reduit_ppm' => 10600,
            'emp_laa_plein_ppm' => 21000,
            'emp_frais_ppm' => 2000,
            'emp_cpe_ppm' => 600,
            'emp_lfp_ppm' => 700,
            'emp_lpp_ppm' => 35000,
            'source' => 'Paramètres de test explicitement vérifiés',
            'verifie_le' => '2026-01-01',
        ];
        $configuration->saveRates(
            $organisationId,
            $dossierId,
            2026,
            $rates
        );
        $socialPayable = $account('2270');
        $configuration->saveMapping(
            $organisationId,
            $dossierId,
            [
                'charge_salaires_id' => $account('5000'),
                'charge_ocas_id' => $account('5700'),
                'charge_laa_id' => $account('5700'),
                'charge_lpp_id' => $account('5700'),
                'dette_net_id' => $account('2000'),
                'dette_ocas_id' => $socialPayable,
                'dette_laa_id' => $socialPayable,
                'dette_lpp_id' => $socialPayable,
                'dette_impot_id' => $socialPayable,
            ]
        );
        $employee = $configuration->createEmployee(
            $organisationId,
            $dossierId,
            [
                'prenom' => 'Ada',
                'nom' => 'Martin',
                'email' => 'ada@example.test',
                'numero_avs' => '756.1234.5678.90',
                'date_naissance' => '1990-05-12',
                'procedure' => 'ordinaire_impot_source',
                'supplement_vacances_ppm' => 83300,
                'impot_source_ppm' => 50000,
            ]
        );
        $this->same(
            'GE',
            (string) $configuration->employee(
                $organisationId,
                $dossierId,
                $employee
            )['canton'],
            'module salarial strictement genevois'
        );
        $this->same(
            '756.****.****.90',
            (string) $configuration->employees(
                $organisationId,
                $dossierId
            )[0]['numero_avs'],
            'AVS masqué sans droit PII'
        );

        $temporaryEmployee = $configuration->createEmployee(
            $organisationId,
            $dossierId,
            [
                'prenom' => 'Employé',
                'nom' => 'Temporaire',
                'email' => 'temporaire@example.test',
                'numero_avs' => '756.9999.9999.99',
                'procedure' => 'ordinaire',
                'supplement_vacances_ppm' => 83300,
                'impot_source_ppm' => 0,
            ]
        );
        $temporaryRow = $configuration->employee(
            $organisationId,
            $dossierId,
            $temporaryEmployee
        );
        $configuration->saveEmployee(
            $organisationId,
            $dossierId,
            [
                'id' => $temporaryEmployee,
                'version' => (int) $temporaryRow['version'],
                'prenom' => 'Employée',
                'nom' => 'Corrigée',
                'email' => 'corrigee@example.test',
                'numero_avs' => '756.9999.9999.99',
                'procedure' => 'ordinaire',
                'supplement_vacances_ppm' => 83300,
                'impot_source_ppm' => 0,
                'actif' => 1,
            ]
        );
        $this->same(
            'Employée',
            (string) $configuration->employee(
                $organisationId,
                $dossierId,
                $temporaryEmployee
            )['prenom'],
            'données d’un employé modifiables avec contrôle de version'
        );
        $temporaryContract = $configuration->saveContract(
            $organisationId,
            $dossierId,
            [
                'employe_id' => $temporaryEmployee,
                'type' => 'mensuel',
                'date_debut' => '2026-01-01',
                'date_fin' => '',
                'taux_horaire_centimes' => 0,
                'salaire_mensuel_centimes' => 400000,
                'heures_hebdo_milli' => 40000,
                'taux_activite_ppm' => 1_000_000,
                'source' => 'Contrat temporaire',
            ]
        );
        $temporaryContractRow = array_values(array_filter(
            $configuration->catalog($organisationId, $dossierId)['contracts'],
            static fn (array $row): bool => (int) $row['id'] === $temporaryContract
        ))[0];
        $configuration->saveContract(
            $organisationId,
            $dossierId,
            [
                'id' => $temporaryContract,
                'version' => (int) $temporaryContractRow['version'],
                'employe_id' => $temporaryEmployee,
                'type' => 'mensuel',
                'date_debut' => '2026-01-01',
                'date_fin' => '2026-06-30',
                'taux_horaire_centimes' => 0,
                'salaire_mensuel_centimes' => 420000,
                'heures_hebdo_milli' => 40000,
                'taux_activite_ppm' => 800000,
                'source' => 'Avenant temporaire',
                'actif' => 1,
            ]
        );
        $updatedTemporaryContract = array_values(array_filter(
            $configuration->catalog($organisationId, $dossierId)['contracts'],
            static fn (array $row): bool => (int) $row['id'] === $temporaryContract
        ))[0];
        $this->same(
            420000,
            (int) $updatedTemporaryContract['salaire_mensuel_centimes'],
            'contrat modifiable sans créer de doublon'
        );
        $configuration->deleteContract(
            $organisationId,
            $dossierId,
            $temporaryContract,
            (int) $updatedTemporaryContract['version']
        );
        $temporaryRow = $configuration->employee(
            $organisationId,
            $dossierId,
            $temporaryEmployee
        );
        $configuration->deleteEmployee(
            $organisationId,
            $dossierId,
            $temporaryEmployee,
            (int) $temporaryRow['version']
        );
        $this->throws(
            fn () => $configuration->employee(
                $organisationId,
                $dossierId,
                $temporaryEmployee
            ),
            'employé sans historique et ses contrats supprimables'
        );

        $payrolls = new PayrollService(
            $pdo,
            $audit,
            new EntryService($pdo, $audit)
        );
        $hourlyContractId = $configuration->saveContract(
            $organisationId,
            $dossierId,
            [
                'employe_id' => $employee,
                'type' => 'horaire',
                'date_debut' => '2026-01-01',
                'date_fin' => '2026-06-30',
                'taux_horaire_centimes' => 3000,
                'salaire_mensuel_centimes' => 0,
                'heures_hebdo_milli' => 40000,
                'taux_activite_ppm' => 1_000_000,
                'source' => 'Contrat horaire signé',
            ]
        );
        $configuration->saveContract(
            $organisationId,
            $dossierId,
            [
                'employe_id' => $employee,
                'type' => 'mensuel',
                'date_debut' => '2026-07-01',
                'date_fin' => '',
                'taux_horaire_centimes' => 0,
                'salaire_mensuel_centimes' => 500000,
                'heures_hebdo_milli' => 40000,
                'taux_activite_ppm' => 1_000_000,
                'source' => 'Avenant mensuel signé',
            ]
        );
        $hourlyDraftId = $payrolls->createPeriodDraft(
            $organisationId,
            $dossierId,
            $employee,
            2026,
            3,
            [[
                'type' => 'heures',
                'libelle' => 'Heures mars',
                'quantite_milli' => 10000,
            ]]
        );
        $this->same(
            30000,
            (int) $payrolls->payroll(
                $organisationId, $dossierId, $hourlyDraftId, true
            )['salaire_travail_centimes'],
            'contrat horaire appliqué au millième sans flottant'
        );
        $hourlyContractRow = array_values(array_filter(
            $configuration->catalog($organisationId, $dossierId)['contracts'],
            static fn (array $row): bool => (int) $row['id'] === $hourlyContractId
        ))[0];
        $this->throws(
            fn () => $configuration->deleteContract(
                $organisationId,
                $dossierId,
                $hourlyContractId,
                (int) $hourlyContractRow['version']
            ),
            'contrat utilisé protégé contre la suppression'
        );
        $employeeRow = $configuration->employee(
            $organisationId,
            $dossierId,
            $employee
        );
        $this->throws(
            fn () => $configuration->deleteEmployee(
                $organisationId,
                $dossierId,
                $employee,
                (int) $employeeRow['version']
            ),
            'employé avec fiches protégé contre la suppression'
        );
        $hourlyDraft = $payrolls->payroll(
            $organisationId,
            $dossierId,
            $hourlyDraftId,
            true
        );
        $updatedHourlyDraftId = $payrolls->createPeriodDraft(
            $organisationId,
            $dossierId,
            $employee,
            2026,
            3,
            [[
                'type' => 'heures',
                'libelle' => 'Heures mars corrigées',
                'quantite_milli' => 20000,
            ]],
            null,
            $hourlyDraftId,
            (int) $hourlyDraft['version']
        );
        $updatedHourlyDraft = $payrolls->payroll(
            $organisationId,
            $dossierId,
            $updatedHourlyDraftId,
            true
        );
        $this->same(
            $hourlyDraftId,
            $updatedHourlyDraftId,
            'recalcul d’un brouillon sans créer de doublon'
        );
        $this->same(
            60000,
            (int) $updatedHourlyDraft['salaire_travail_centimes'],
            'heures corrigées intégralement recalculées'
        );
        $this->same(
            (int) $hourlyDraft['version'] + 1,
            (int) $updatedHourlyDraft['version'],
            'version du brouillon incrémentée après recalcul'
        );
        $this->same(
            1,
            count($payrolls->periodElements($updatedHourlyDraftId)),
            'variables précédentes remplacées lors du recalcul'
        );
        $this->throws(
            fn () => $payrolls->createPeriodDraft(
                $organisationId,
                $dossierId,
                $employee,
                2026,
                3,
                [[
                    'type' => 'heures',
                    'libelle' => 'Doublon mars',
                    'quantite_milli' => 10000,
                ]]
            ),
            'doublon de période renvoyé vers la modification du brouillon'
        );
        $payrolls->deleteDraft(
            $organisationId,
            $dossierId,
            $updatedHourlyDraftId,
            (int) $updatedHourlyDraft['version']
        );
        $this->throws(
            fn () => $payrolls->payroll(
                $organisationId,
                $dossierId,
                $updatedHourlyDraftId,
                true
            ),
            'suppression définitive du brouillon et de ses données de travail'
        );
        $monthlyDraftId = $payrolls->createPeriodDraft(
            $organisationId,
            $dossierId,
            $employee,
            2026,
            8,
            [
                ['type' => 'absence', 'libelle' => 'Absence', 'montant_centimes' => 10000],
                ['type' => 'prime', 'libelle' => 'Prime', 'montant_centimes' => 5000],
            ]
        );
        $this->same(
            495000,
            (int) $payrolls->payroll(
                $organisationId, $dossierId, $monthlyDraftId, true
            )['salaire_travail_centimes'],
            'mensuel, absence et prime explicitement réconciliés'
        );
        $this->same(
            2,
            count($payrolls->periodElements($monthlyDraftId)),
            'variables de période archivées séparément'
        );
        $this->true(
            (bool) $configuration->rates($organisationId, $dossierId, 2027)['_fallback'],
            'repli annuel reprend le dernier millésime antérieur'
        );
        $missingOCAS = new OcasRateImportService('', $configuration, $audit);
        $this->false(
            (bool) $missingOCAS->preview(2027)['available'],
            'source OCAS absente signalée sans millésime inventé'
        );
        $ocasPath = $this->tempDir() . '/ocas.sqlite';
        $ocasPdo = new PDO('sqlite:' . $ocasPath);
        $ocasPdo->exec(
            'CREATE TABLE taux_par_annee (
                annee INTEGER NOT NULL, cle TEXT NOT NULL, valeur TEXT NOT NULL,
                PRIMARY KEY (annee, cle)
            )'
        );
        $ocasInsert = $ocasPdo->prepare(
            'INSERT INTO taux_par_annee (annee, cle, valeur) VALUES (2027, ?, ?)'
        );
        foreach (OcasRateImportService::KEY_MAP as $key => $field) {
            $ppmValue = (int) ($rates[$field] ?? 0);
            $ocasInsert->execute([
                $key,
                '0.' . str_pad((string) $ppmValue, 6, '0', STR_PAD_LEFT),
            ]);
        }
        $ocasInsert->execute(['cle_future_inconnue', '0.001']);
        $ocas = new OcasRateImportService($ocasPath, $configuration, $audit);
        $ocasPreview = $ocas->preview(2027);
        $this->same([], $ocasPreview['missing_keys'], 'mapping OCAS exhaustif');
        $this->same(
            ['cle_future_inconnue'],
            $ocasPreview['unknown_keys'],
            'clé OCAS inconnue justifiée comme non applicable'
        );
        $confirmedOCAS = $ocas->confirm(
            $organisationId,
            $dossierId,
            2027,
            (string) $ocasPreview['fingerprint'],
            '2027-01-15'
        );
        $this->false($confirmedOCAS['idempotent'], 'confirmation OCAS auditée');
        $this->true(
            $ocas->confirm(
                $organisationId,
                $dossierId,
                2027,
                (string) $ocasPreview['fingerprint'],
                '2027-01-15'
            )['idempotent'],
            'rejeu de confirmation OCAS idempotent'
        );
        $payrollId = $payrolls->createDraft(
            $organisationId,
            $dossierId,
            $employee,
            2026,
            5,
            [[
                'libelle' => 'Travail mensuel',
                'unite_libelle' => 'Heure',
                'heures_unite_milli' => 1000,
                'quantite_milli' => 160000,
                'taux_horaire_centimes' => 3000,
            ]]
        );
        $draft = $payrolls->payroll(
            $organisationId,
            $dossierId,
            $payrollId,
            true
        );
        $this->same(160000, (int) $draft['nombre_heures_milli'], 'heures conservées en millièmes');
        $this->same(
            480000,
            (int) $draft['salaire_travail_centimes'],
            'prestations converties en centimes sans flottants'
        );
        $components = $payrolls->components($payrollId);
        $this->same(
            18,
            count($components),
            'composants, bases et taux archivés sur la fiche'
        );
        $employeeDeductions = array_sum(array_map(
            static fn (array $row): int => $row['categorie'] === 'retenue_employe'
                ? (int) $row['montant_centimes']
                : 0,
            $components
        ));
        $employerCharges = array_sum(array_map(
            static fn (array $row): int => $row['categorie'] === 'charge_employeur'
                ? (int) $row['montant_centimes']
                : 0,
            $components
        ));
        $this->same(
            (int) $draft['total_deductions_centimes'],
            $employeeDeductions,
            'arrondis des retenues par composant cohérents avec le total'
        );
        $this->same(
            (int) $draft['total_charges_employeur_centimes'],
            $employerCharges,
            'arrondis patronaux par composant cohérents avec le total'
        );
        $rateSnapshot = (string) $draft['taux_snapshot_json'];
        $payrolls->validate(
            $organisationId,
            $dossierId,
            $payrollId,
            (int) $draft['version']
        );
        $changedRates = $rates;
        $changedRates['avs_ppm'] = 99000;
        $this->throws(
            fn () => $configuration->saveRates(
                $organisationId,
                $dossierId,
                2026,
                $changedRates
            ),
            'taux annuel utilisé impossible à remplacer'
        );
        $validated = $payrolls->payroll(
            $organisationId,
            $dossierId,
            $payrollId,
            true
        );
        $this->same(
            $rateSnapshot,
            (string) $validated['taux_snapshot_json'],
            'snapshot de taux de la fiche validée immuable'
        );
        $this->same(
            5,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM dettes_salaires
                 WHERE fiche_salaire_id = {$payrollId}"
            )->fetchColumn(),
            'dettes net, OCAS, LAA, LPP et impôt individualisées'
        );
        $entryId = $payrolls->post(
            $organisationId,
            $dossierId,
            $payrollId,
            $exercise,
            $journal,
            '2026-05-31'
        );
        $this->same(
            $entryId,
            $payrolls->post(
                $organisationId,
                $dossierId,
                $payrollId,
                $exercise,
                $journal,
                '2026-05-31'
            ),
            'comptabilisation de fiche idempotente'
        );
        $balance = $pdo->query(
            "SELECT SUM(debit_centimes) AS debit, SUM(credit_centimes) AS credit
             FROM lignes_ecriture WHERE ecriture_id = {$entryId}"
        )->fetch();
        $this->same(
            (int) $balance['debit'],
            (int) $balance['credit'],
            'écriture salariale détaillée équilibrée'
        );

        $paymentService = new PayrollPaymentService(
            $pdo,
            $audit,
            new EntryService($pdo, $audit)
        );
        $liabilities = $paymentService->liabilities($organisationId, $dossierId);
        $netDebt = array_values(array_filter(
            $liabilities,
            static fn (array $row): bool => $row['type'] === 'net'
        ))[0];
        $netPayment = $paymentService->create(
            $organisationId,
            $dossierId,
            'employe',
            $employee,
            '2026-06-01',
            (int) $netDebt['montant_centimes'],
            $account('1020'),
            'NET-MAI'
        );
        $paymentService->allocate(
            $organisationId,
            $dossierId,
            $netPayment,
            (int) $netDebt['id'],
            (int) $netDebt['montant_centimes']
        );
        $this->throws(
            fn () => $paymentService->allocate(
                $organisationId,
                $dossierId,
                $netPayment,
                (int) $liabilities[1]['id'],
                1
            ),
            'un paiement employé ne règle pas une dette d’organisme'
        );
        $paymentService->post(
            $organisationId,
            $dossierId,
            $netPayment,
            $exercise,
            $journal
        );
        $organismDebts = array_values(array_filter(
            $liabilities,
            static fn (array $row): bool => $row['type'] !== 'net'
        ));
        $organismTotal = array_sum(array_map(
            static fn (array $row): int => (int) $row['montant_centimes'],
            $organismDebts
        ));
        $organismPayment = $paymentService->create(
            $organisationId,
            $dossierId,
            'organisme',
            null,
            '2026-06-05',
            $organismTotal,
            $account('1020'),
            'CHARGES-MAI'
        );
        foreach ($organismDebts as $debt) {
            $paymentService->allocate(
                $organisationId,
                $dossierId,
                $organismPayment,
                (int) $debt['id'],
                (int) $debt['montant_centimes']
            );
        }
        $paymentService->post(
            $organisationId,
            $dossierId,
            $organismPayment,
            $exercise,
            $journal
        );
        $this->same(
            'payee',
            (string) $payrolls->payroll(
                $organisationId,
                $dossierId,
                $payrollId,
                true
            )['statut'],
            'fiche payée quand toutes ses dettes sont allouées'
        );

        $cancelledId = $payrolls->createDraft(
            $organisationId,
            $dossierId,
            $employee,
            2026,
            6,
            [[
                'libelle' => 'Fiche à corriger',
                'unite_libelle' => 'Heure',
                'heures_unite_milli' => 1000,
                'quantite_milli' => 10000,
                'taux_horaire_centimes' => 3000,
            ]]
        );
        $cancelledDraft = $payrolls->payroll(
            $organisationId, $dossierId, $cancelledId, true
        );
        $payrolls->validate(
            $organisationId,
            $dossierId,
            $cancelledId,
            (int) $cancelledDraft['version']
        );
        $cancelledEntry = $payrolls->post(
            $organisationId,
            $dossierId,
            $cancelledId,
            $exercise,
            $journal,
            '2026-06-30'
        );
        $reversal = $payrolls->cancel(
            $organisationId,
            $dossierId,
            $cancelledId,
            '2026-06-30'
        );
        $this->true(
            $reversal !== null && $reversal !== $cancelledEntry,
            'annulation comptabilisée par contre-passation distincte'
        );
        $this->same(
            'annulee',
            (string) $payrolls->payroll(
                $organisationId, $dossierId, $cancelledId, true
            )['statut'],
            'historique de la fiche annulée conservé'
        );

        $certificate = new PayrollCertificateService($pdo, $audit);
        $xml = $certificate->generateXml(
            $organisationId,
            $dossierId,
            $employee,
            2026
        );
        $this->true(
            str_contains($xml, '<certificatSalaire')
                && str_contains($xml, 'transmis="false"'),
            'certificat annuel portable, archivé et explicitement non transmis'
        );
        $this->same(
            64,
            strlen((string) $pdo->query(
                'SELECT empreinte_sha256 FROM certificats_salaires'
            )->fetchColumn()),
            'empreinte du certificat archivée'
        );
        $certificate->control($organisationId, $dossierId, $employee, 2026);
        $exportedXml = $certificate->export(
            $organisationId,
            $dossierId,
            $employee,
            2026
        );
        $this->same($xml, $exportedXml, 'certificat contrôlé exporté sans mutation');
        $this->same(
            'exporte:0',
            (string) $pdo->query(
                "SELECT statut || ':' || transmis FROM certificats_salaires"
            )->fetchColumn(),
            'certificat exporté toujours explicitement non transmis'
        );

        $import = new PayrollImportService($pdo, $audit, $payrolls);
        $json = json_encode([
            'type' => 'fiches_salaires',
            'fiches' => [[
                'numero_avs' => '756.1234.5678.90',
                'annee' => 2026,
                'mois' => 7,
                'prestations' => [[
                    'libelle' => 'Cours importé',
                    'unite_libelle' => 'Heure',
                    'heures_unite_milli' => 1000,
                    'quantite_milli' => 10000,
                    'taux_horaire_centimes' => 3000,
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);
        $simulation = $import->import(
            $organisationId,
            $dossierId,
            $json,
            true
        );
        $this->same([], $simulation['crees'], 'simulation JSON sans écriture');
        $applied = $import->import(
            $organisationId,
            $dossierId,
            $json,
            false
        );
        $this->same(1, count($applied['crees']), 'import JSON appliqué après simulation');
        $replayed = $import->import(
            $organisationId,
            $dossierId,
            $json,
            false
        );
        $this->same(1, count($replayed['ignores']), 'rejeu AVS/période idempotent');
        $this->true(IntegrityChecker::check($pdo)['ok'], 'intégrité après salaires');
    }

    private function multiCurrencyTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $ids = $this->seedScopes($pdo);
        $organisationId = $ids['organisation_a'];
        $dossierId = $ids['dossier_a'];
        $audit = new AuditLogger($pdo);
        $scope = new ScopeManager($pdo, $audit);
        $exerciseId = $scope->createExercise(
            $dossierId,
            'Exercice 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $setup = new AccountingSetupService($pdo, $audit);
        $setup->createPeriod(
            $organisationId,
            $dossierId,
            $exerciseId,
            '2026',
            '2026-01-01',
            '2026-12-31'
        );
        $journalId = $setup->createJournal(
            $organisationId,
            $dossierId,
            'OD',
            'Opérations diverses'
        );
        (new PlanSeeder($pdo, dirname(__DIR__) . '/database/seeds'))
            ->installForDossier($organisationId, $dossierId, 'personne_morale');
        $receivable = $this->accountId($pdo, $dossierId, '1100');
        $bank = $this->accountId($pdo, $dossierId, '1020');
        $gain = $this->accountId($pdo, $dossierId, '3400');
        $loss = $this->accountId($pdo, $dossierId, '6500');
        $contactId = (new ContactService($pdo, $audit))->create(
            $organisationId,
            $dossierId,
            ['type_personne' => 'entreprise', 'raison_sociale' => 'Client EUR'],
            ['client'],
            [
                'ligne1' => 'Rue du Change 1',
                'code_postal' => '1200',
                'localite' => 'Genève',
                'pays' => 'CH',
            ]
        );
        $exchange = new ExchangeRateService($pdo, $audit);
        $exchange->saveCurrency(
            $organisationId,
            $dossierId,
            'EUR',
            true
        );
        $invoiceRate = $exchange->saveRate(
            $organisationId,
            $dossierId,
            [
                'source_currency' => 'EUR',
                'rate_date' => '2026-01-10',
                'numerator' => 95,
                'denominator' => 100,
                'source' => 'Taux de test contrôlé',
                'verified_on' => '2026-01-10',
                'active' => true,
            ]
        );
        $firstPaymentRate = $exchange->saveRate(
            $organisationId,
            $dossierId,
            [
                'source_currency' => 'EUR',
                'rate_date' => '2026-02-01',
                'numerator' => 96,
                'denominator' => 100,
                'source' => 'Taux de test contrôlé',
                'verified_on' => '2026-02-01',
                'active' => true,
            ]
        );
        $secondPaymentRate = $exchange->saveRate(
            $organisationId,
            $dossierId,
            [
                'source_currency' => 'EUR',
                'rate_date' => '2026-03-01',
                'numerator' => 94,
                'denominator' => 100,
                'source' => 'Taux de test contrôlé',
                'verified_on' => '2026-03-01',
                'active' => true,
            ]
        );
        $exchange->saveMapping(
            $organisationId,
            $dossierId,
            [
                'realized_gain_account_id' => $gain,
                'realized_loss_account_id' => $loss,
                'unrealized_gain_account_id' => $gain,
                'unrealized_loss_account_id' => $loss,
            ]
        );
        $rate = $exchange->snapshot(
            $organisationId,
            $dossierId,
            'EUR',
            '2026-01-15',
            $invoiceRate
        );
        $this->same(9500, ExchangeRateService::convert(10000, 95, 100), 'conversion rationnelle EUR/CHF exacte');
        $document = $pdo->prepare(
            "INSERT INTO documents_financiers
             (organisation_id, dossier_id, contact_id, type, statut, numero,
              date_document, date_echeance, monnaie, devise_base,
              taux_change_numerateur, taux_change_denominateur,
              taux_change_date, taux_change_source,
              adresse_snapshot_json, contact_snapshot_json,
              total_net_centimes, total_tva_centimes, total_brut_centimes,
              total_net_base_centimes, total_tva_base_centimes,
              total_brut_base_centimes, compte_collectif_id)
             VALUES (?, ?, ?, 'facture_client', 'comptabilise', 'F-EUR-001',
                     '2026-01-15', '2026-02-15', 'EUR', 'CHF',
                     ?, ?, ?, ?, '{}', '{}',
                     10000, 0, 10000, 9500, 0, 9500, ?)"
        );
        $document->execute([
            $organisationId, $dossierId, $contactId,
            $rate['numerator'], $rate['denominator'],
            $rate['rate_date'], $rate['source'], $receivable,
        ]);
        $documentId = (int) $pdo->lastInsertId();
        $entries = new EntryService($pdo, $audit);
        $payments = new PaymentService($pdo, $audit, $entries);
        $paymentOne = $payments->create(
            $organisationId,
            $dossierId,
            $contactId,
            'encaissement',
            '2026-02-01',
            4000,
            'EUR-1',
            $bank,
            currency: 'EUR',
            exchangeRateId: $firstPaymentRate
        );
        $allocationOne = $payments->allocatePayment(
            $organisationId,
            $dossierId,
            $paymentOne,
            $documentId,
            4000
        );
        $payments->post(
            $organisationId,
            $dossierId,
            $paymentOne,
            $receivable,
            $exerciseId,
            $journalId
        );
        $first = $pdo->query(
            "SELECT * FROM allocations WHERE id = {$allocationOne}"
        )->fetch();
        $this->same(3800, (int) $first['montant_document_base_centimes'], 'premier paiement libère la créance au taux historique');
        $this->same(3840, (int) $first['montant_paiement_base_centimes'], 'premier paiement conserve sa conversion figée');
        $this->same(40, (int) $first['ecart_change_realise_centimes'], 'gain réalisé du premier paiement');

        $revaluations = new ExchangeRevaluationService($pdo, $audit, $entries);
        $revaluationId = $revaluations->post(
            $organisationId,
            $dossierId,
            $exerciseId,
            $journalId,
            '2026-02-15',
            'reevaluation-eur-2026-02'
        );
        $latent = $pdo->query(
            "SELECT * FROM lignes_reevaluation_change
             WHERE reevaluation_id = {$revaluationId}"
        )->fetch();
        $this->same(60, (int) $latent['ecart_latent_centimes'], 'écart latent calculé sur le solde ouvert');
        $this->same('Taux de test contrôlé', (string) $latent['taux_change_source'], 'source du taux de clôture archivée');
        $reversalId = $revaluations->reverse(
            $organisationId,
            $dossierId,
            $revaluationId,
            '2026-02-15'
        );
        $this->true($reversalId > 0, 'réévaluation explicitement contre-passable');

        $paymentTwo = $payments->create(
            $organisationId,
            $dossierId,
            $contactId,
            'encaissement',
            '2026-03-01',
            6000,
            'EUR-2',
            $bank,
            currency: 'EUR',
            exchangeRateId: $secondPaymentRate
        );
        $allocationTwo = $payments->allocatePayment(
            $organisationId,
            $dossierId,
            $paymentTwo,
            $documentId,
            6000
        );
        $payments->post(
            $organisationId,
            $dossierId,
            $paymentTwo,
            $receivable,
            $exerciseId,
            $journalId
        );
        $second = $pdo->query(
            "SELECT * FROM allocations WHERE id = {$allocationTwo}"
        )->fetch();
        $this->same(5700, (int) $second['montant_document_base_centimes'], 'second paiement solde la valeur historique au centime');
        $this->same(5640, (int) $second['montant_paiement_base_centimes'], 'second paiement converti à son propre taux');
        $this->same(-60, (int) $second['ecart_change_realise_centimes'], 'perte réalisée du second paiement');
        $this->same(
            -20,
            (int) $pdo->query(
                "SELECT SUM(ecart_change_realise_centimes)
                 FROM allocations WHERE document_id = {$documentId}"
            )->fetchColumn(),
            'deux paiements à des taux différents réconciliés au centime'
        );
        $this->same(
            0,
            10000 - (int) $pdo->query(
                "SELECT SUM(montant_centimes)
                 FROM allocations WHERE document_id = {$documentId}
                   AND statut = 'valide'"
            )->fetchColumn(),
            'facture EUR intégralement soldée en devise d’origine'
        );
        $ledgerSnapshot = $pdo->query(
            "SELECT devise_origine, taux_change_date, taux_change_source,
                    montant_origine_centimes, montant_base_centimes
             FROM lignes_ecriture
             WHERE devise_origine = 'EUR' ORDER BY id LIMIT 1"
        )->fetch();
        $this->same('EUR', (string) $ledgerSnapshot['devise_origine'], 'devise d’origine traçable depuis le grand livre');
        $this->same('Taux de test contrôlé', (string) $ledgerSnapshot['taux_change_source'], 'source du taux traçable depuis le grand livre');
        $this->throws(
            fn () => $exchange->snapshot(
                $organisationId,
                $dossierId,
                'USD',
                '2026-01-15'
            ),
            'devise non activée clairement refusée'
        );
        $this->throws(
            fn () => $exchange->snapshot(
                $organisationId,
                $dossierId,
                'EUR',
                '2026-01-01',
                $firstPaymentRate
            ),
            'taux futur clairement refusé'
        );
        $chfPayment = $payments->create(
            $organisationId,
            $dossierId,
            $contactId,
            'encaissement',
            '2026-04-01',
            12345,
            'CHF',
            $bank
        );
        $this->same(
            12345,
            (int) $pdo->query(
                "SELECT montant_base_centimes FROM paiements WHERE id = {$chfPayment}"
            )->fetchColumn(),
            'parcours mono-CHF strictement inchangé'
        );
        $this->true(IntegrityChecker::check($pdo)['ok'], 'intégrité après opérations multidevises');
    }

    private function consolidationTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $ids = $this->seedScopes($pdo);
        $audit = new AuditLogger($pdo);
        $scope = new ScopeManager($pdo, $audit);
        $setup = new AccountingSetupService($pdo, $audit);
        $entries = new EntryService($pdo, $audit);
        $seeder = new PlanSeeder($pdo, dirname(__DIR__) . '/database/seeds');
        $pdo->prepare('UPDATE dossiers SET monnaie = ? WHERE id = ?')
            ->execute(['EUR', $ids['dossier_b']]);

        $exerciseA = $scope->createExercise(
            $ids['dossier_a'],
            'Exercice A 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $exerciseB = $scope->createExercise(
            $ids['dossier_b'],
            'Exercice B 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $setup->createPeriod(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exerciseA,
            '2026',
            '2026-01-01',
            '2026-12-31'
        );
        $setup->createPeriod(
            $ids['organisation_b'],
            $ids['dossier_b'],
            $exerciseB,
            '2026',
            '2026-01-01',
            '2026-12-31'
        );
        $journalA = $setup->createJournal(
            $ids['organisation_a'],
            $ids['dossier_a'],
            'OD',
            'Opérations diverses'
        );
        $journalB = $setup->createJournal(
            $ids['organisation_b'],
            $ids['dossier_b'],
            'OD',
            'Opérations diverses'
        );
        $seeder->installForDossier(
            $ids['organisation_a'], $ids['dossier_a'], 'personne_morale'
        );
        $seeder->installForDossier(
            $ids['organisation_b'], $ids['dossier_b'], 'personne_morale'
        );
        $receivableA = $this->accountId($pdo, $ids['dossier_a'], '1100');
        $salesA = $this->accountId($pdo, $ids['dossier_a'], '3000');
        $payableB = $this->accountId($pdo, $ids['dossier_b'], '2000');
        $expenseB = $this->accountId($pdo, $ids['dossier_b'], '4000');
        $entries->postGenerated([
            'organisation_id' => $ids['organisation_a'],
            'dossier_id' => $ids['dossier_a'],
            'exercice_id' => $exerciseA,
            'journal_id' => $journalA,
            'date_comptable' => '2026-06-30',
            'libelle' => 'Vente inter-entités',
            'source_type' => 'test',
            'source_id' => 'interco-a',
            'source_action' => 'vente',
            'lignes' => [
                ['compte_id' => $receivableA, 'debit_centimes' => 10000],
                ['compte_id' => $salesA, 'credit_centimes' => 10000],
            ],
        ], 'test-consolidation:a');
        $entries->postGenerated([
            'organisation_id' => $ids['organisation_b'],
            'dossier_id' => $ids['dossier_b'],
            'exercice_id' => $exerciseB,
            'journal_id' => $journalB,
            'date_comptable' => '2026-06-30',
            'libelle' => 'Achat inter-entités',
            'source_type' => 'test',
            'source_id' => 'interco-b',
            'source_action' => 'achat',
            'lignes' => [
                ['compte_id' => $expenseB, 'debit_centimes' => 8000],
                ['compte_id' => $payableB, 'credit_centimes' => 8000],
            ],
        ], 'test-consolidation:b');

        $pdo->prepare(
            'INSERT INTO utilisateurs (email, mot_de_passe) VALUES (?, ?)'
        )->execute([
            'administrateur-consolidation@example.test',
            password_hash('secret', PASSWORD_DEFAULT),
        ]);
        $service = new ConsolidationService($pdo, $audit);
        $legalA1 = $service->saveLegalAttributes(
            $ids['organisation_a'],
            '2025-01-01',
            'Organisation A SA',
            'SA',
            'CHE-111.111.111',
            ['city' => 'Genève', 'country' => 'CH'],
            'Registre test 2025',
            1
        );
        $legalA2 = $service->saveLegalAttributes(
            $ids['organisation_a'],
            '2026-01-01',
            'Organisation A Groupe SA',
            'SA',
            'CHE-111.111.111',
            ['city' => 'Genève', 'country' => 'CH'],
            'Registre test 2026',
            1
        );
        $this->true($legalA2 > $legalA1, 'attributs juridiques versionnés sans changer l’organisation');
        $this->same(
            '2025-12-31',
            (string) $pdo->query(
                "SELECT date_fin FROM attributs_juridiques_organisation
                 WHERE id = {$legalA1}"
            )->fetchColumn(),
            'ancienne identité juridique fermée à la veille'
        );

        $group = $service->createGroup(
            $ids['organisation_a'],
            $ids['dossier_a'],
            'GROUPE',
            'Groupe de test',
            'CHF',
            '2026-01-01',
            1,
            'consolidation_legale'
        );
        $this->same(
            'brouillon',
            (string) $pdo->query(
                "SELECT statut FROM groupes_consolidation WHERE id = {$group}"
            )->fetchColumn(),
            'groupe légal créé en brouillon sans prétendre être activé'
        );
        $memberA = (int) $pdo->query(
            "SELECT id FROM membres_groupe_consolidation
             WHERE groupe_id = {$group} AND dossier_id = {$ids['dossier_a']}"
        )->fetchColumn();
        $memberB = $service->addMember(
            $group,
            $ids['organisation_b'],
            $ids['dossier_b'],
            '2026-01-01',
            null,
            1
        );
        $period = $service->createPeriod(
            $group,
            'Semestre 1 2026',
            '2026-01-01',
            '2026-06-30',
            [
                [
                    'member_id' => $memberA,
                    'numerator' => 1,
                    'denominator' => 1,
                    'rate_date' => '2026-06-30',
                    'source' => 'Devise de consolidation',
                ],
                [
                    'member_id' => $memberB,
                    'numerator' => 5,
                    'denominator' => 4,
                    'rate_date' => '2026-06-30',
                    'source' => 'Taux de clôture contrôlé',
                ],
            ],
            1
        );
        $mappingIds = [];
        foreach ([
            [$memberA, $receivableA, '1300', 'Créances inter-entités', 'actif'],
            [$memberA, $salesA, '3000', 'Produits consolidés', 'produit'],
            [$memberB, $payableB, '2300', 'Dettes inter-entités', 'passif'],
            [$memberB, $expenseB, '4000', 'Charges consolidées', 'charge'],
        ] as [$member, $account, $target, $label, $type]) {
            $mappingIds[] = $service->saveMapping(
                $group, $member, $account, $target, $label, $type, 0, 1
            );
        }
        $pairId = $service->saveIntercompanyPair(
            $group,
            'Créance / dette réciproque',
            $memberA,
            $receivableA,
            $memberB,
            $payableB,
            1
        );
        $preview = $service->activationPreview($group);
        $this->true($preview['ready'], 'prévisualisation légale prête et réconciliée');
        $this->same(
            'balances sources converties + éliminations = résultat du groupe',
            $preview['formula'],
            'formule d’activation rendue explicite'
        );
        $service->activateGroup($group, 1, 1);
        $this->same(
            'actif',
            (string) $pdo->query(
                "SELECT statut FROM groupes_consolidation WHERE id = {$group}"
            )->fetchColumn(),
            'consolidation légale activée explicitement'
        );
        $statutoryEntries = (int) $pdo->query(
            'SELECT COUNT(*) FROM ecritures'
        )->fetchColumn();
        $elimination = $service->createElimination(
            $group,
            $period,
            'ELIM-001',
            'Élimination des soldes réciproques',
            'Créance et dette confirmées par les deux entités.',
            [
                [
                    'target_account' => '2300',
                    'label' => 'Extourne de la dette',
                    'debit_cents' => 10000,
                    'credit_cents' => 0,
                ],
                [
                    'target_account' => '1300',
                    'label' => 'Extourne de la créance',
                    'debit_cents' => 0,
                    'credit_cents' => 10000,
                ],
            ],
            1
        );
        $this->same(
            $statutoryEntries,
            (int) $pdo->query('SELECT COUNT(*) FROM ecritures')->fetchColumn(),
            'élimination absente des grands livres statutaires'
        );
        $workspace = $service->read([$group], $group, $period);
        $this->true(
            (bool) $workspace['balance']['formula_verified'],
            'somme des balances et éliminations égale la consolidation'
        );
        $receivableRow = array_values(array_filter(
            $workspace['balance']['rows'],
            static fn (array $row): bool => $row['account'] === '1300'
        ))[0];
        $this->same(0, $receivableRow['consolidated_cents'], 'créance inter-entités éliminée');
        $this->same(
            $ids['dossier_a'],
            $receivableRow['sources'][0]['dossier_id'],
            'montant consolidé drillable jusqu’à la balance source'
        );
        $this->same(
            0,
            $workspace['reconciliation'][0]['difference_cents'],
            'comptes inter-entités réconciliés après conversion'
        );
        $this->same(
            [],
            $workspace['balance']['unmapped_accounts'],
            'tous les comptes mouvementés sont explicitement mappés'
        );
        $export = $service->export($group, $period);
        $exported = json_decode($export['content'], true, 512, JSON_THROW_ON_ERROR);
        $this->same(
            $export['hash'],
            $exported['sha256'],
            'export autonome muni de son empreinte SHA-256'
        );
        $this->same(2, count($exported['members']), 'export contient les deux entités sources');
        $this->same(
            'consolidation_legale',
            $exported['report_kind'],
            'export qualifie explicitement la consolidation légale'
        );
        $this->true(
            str_contains($exported['legal_notice'], 'livres statutaires'),
            'export rappelle la séparation des livres statutaires'
        );
        $this->true(
            str_contains(
                (string) $workspace['mappings'][0]['member_label'],
                '—'
            ),
            'mapping libellé avec organisation et dossier'
        );
        $this->throws(
            fn () => $pdo->exec(
                "UPDATE lignes_elimination_consolidation
                 SET debit_centimes = 1 WHERE elimination_id = {$elimination}"
            ),
            'élimination validée immuable'
        );
        $service->closePeriod($group, $period, 1);
        $balanceBeforeVersion = $service->read([$group], $group, $period)['balance'];
        $mappingVersion = (int) $pdo->query(
            "SELECT version FROM mappings_comptes_consolidation
             WHERE id = {$mappingIds[1]}"
        )->fetchColumn();
        $service->disableMapping(
            $group,
            $mappingIds[1],
            $mappingVersion,
            '2027-01-01',
            1
        );
        $this->same(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM versions_mappings_consolidation
                 WHERE mapping_id = {$mappingIds[1]}"
            )->fetchColumn(),
            'mapping figé conservé dans une version datée'
        );
        $service->disableIntercompanyPair(
            $group,
            $pairId,
            1,
            '2027-01-01',
            1
        );
        $this->same(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM versions_paires_interentites
                 WHERE paire_id = {$pairId}"
            )->fetchColumn(),
            'paire figée conservée dans une version datée'
        );
        $afterVersion = $service->read([$group], $group, $period);
        $this->same(
            $balanceBeforeVersion['consolidated_total_cents'],
            $afterVersion['balance']['consolidated_total_cents'],
            'nouvelle version sans effet sur la période clôturée'
        );
        $this->same(
            0,
            $afterVersion['reconciliation'][0]['difference_cents'],
            'paire historique inchangée après désactivation datée'
        );
        $this->throws(
            fn () => $service->updateGroup(
                $group,
                'Mode interdit',
                'CHF',
                'agregation_interne',
                2,
                1
            ),
            'mode immuable après la première période'
        );
        $memberBVersion = (int) $pdo->query(
            "SELECT version FROM membres_groupe_consolidation
             WHERE id = {$memberB}"
        )->fetchColumn();
        $memberExit = $service->removeMember(
            $group,
            $memberB,
            $memberBVersion,
            '2027-12-31',
            1
        );
        $this->false($memberExit['deleted'], 'membre utilisé sorti par date de fin');
        $service->archiveGroup($group, 2, 1);
        $service->reactivateGroup($group, 3, 1);
        $this->same(
            'actif',
            (string) $pdo->query(
                "SELECT statut FROM groupes_consolidation WHERE id = {$group}"
            )->fetchColumn(),
            'groupe archivé puis réactivé avec versions optimistes'
        );

        $pdo->prepare(
            'INSERT INTO utilisateurs (email, mot_de_passe) VALUES (?, ?)'
        )->execute(['auditeur-consolidation@example.test', password_hash('secret', PASSWORD_DEFAULT)]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO utilisateur_roles_organisation
             (utilisateur_id, organisation_id, role_id) VALUES (?, ?, 6)'
        )->execute([$userId, $ids['organisation_a']]);
        $session = new ArraySessionStore([
            'user_id' => $userId,
            'organisation_id' => $ids['organisation_a'],
            'dossier_id' => $ids['dossier_a'],
        ]);
        $auth = new AuthService(
            new UserRepository($pdo),
            new LoginThrottle($pdo, 5, 900),
            $audit,
            $session
        );
        $controller = new ConsolidationApiController(
            $session,
            $auth,
            new AccessControl($pdo),
            $service,
            new ConsolidationInputValidator()
        );
        $hidden = $controller->show(new Request('GET', '/api/v1/consolidation'));
        $this->same(
            [],
            $this->responseJson($hidden)['data']['groups'],
            'groupe entièrement masqué sans droit sur chaque entité'
        );
        $this->throws(
            fn () => $controller->show(new Request(
                'GET',
                '/api/v1/consolidation',
                query: ['group_id' => (string) $group]
            )),
            'accès direct au groupe refusé sans fuite horizontale'
        );
        $pdo->prepare(
            'INSERT INTO utilisateur_roles_organisation
             (utilisateur_id, organisation_id, role_id) VALUES (?, ?, 6)'
        )->execute([$userId, $ids['organisation_b']]);
        $visible = $controller->show(new Request(
            'GET',
            '/api/v1/consolidation',
            query: [
                'group_id' => (string) $group,
                'period_id' => (string) $period,
            ]
        ));
        $this->same(200, $visible->status, 'lecture autorisée après droit explicite sur chaque entité');

        $sibling = $scope->createDossier(
            $ids['organisation_a'],
            'Comptabilité A secondaire',
            'comptabilite-a-secondaire',
            'reel',
            1
        );
        $siblingExercise = $scope->createExercise(
            $sibling,
            'Exercice secondaire 2026',
            '2026-01-01',
            '2026-12-31',
            1
        );
        $setup->createPeriod(
            $ids['organisation_a'],
            $sibling,
            $siblingExercise,
            '2026',
            '2026-01-01',
            '2026-12-31',
            1
        );
        $siblingJournal = $setup->createJournal(
            $ids['organisation_a'],
            $sibling,
            'OD',
            'Opérations diverses',
            'general',
            1
        );
        $seeder->installForDossier(
            $ids['organisation_a'],
            $sibling,
            'personne_morale'
        );
        $siblingReceivable = $this->accountId($pdo, $sibling, '1100');
        $siblingSales = $this->accountId($pdo, $sibling, '3000');
        $entries->postGenerated([
            'organisation_id' => $ids['organisation_a'],
            'dossier_id' => $sibling,
            'exercice_id' => $siblingExercise,
            'journal_id' => $siblingJournal,
            'date_comptable' => '2026-06-30',
            'libelle' => 'Activité du dossier secondaire',
            'source_type' => 'test',
            'source_id' => 'aggregation-sibling',
            'source_action' => 'vente',
            'lignes' => [
                ['compte_id' => $siblingReceivable, 'debit_centimes' => 2500],
                ['compte_id' => $siblingSales, 'credit_centimes' => 2500],
            ],
        ], 'test-aggregation:sibling');
        $aggregation = $service->createGroup(
            $ids['organisation_a'],
            $ids['dossier_a'],
            'AGREGATION',
            'Agrégation interne A',
            'CHF',
            '2026-01-01',
            1,
            'agregation_interne'
        );
        $aggregationPilot = (int) $pdo->query(
            "SELECT id FROM membres_groupe_consolidation
             WHERE groupe_id = {$aggregation}
               AND dossier_id = {$ids['dossier_a']}"
        )->fetchColumn();
        $aggregationSibling = $service->addMember(
            $aggregation,
            $ids['organisation_a'],
            $sibling,
            '2026-01-01',
            null,
            1
        );
        $this->throws(
            fn () => $service->addMember(
                $aggregation,
                $ids['organisation_b'],
                $ids['dossier_b'],
                '2026-01-01',
                null,
                1
            ),
            'agrégation interne refuse un dossier d’une autre organisation'
        );
        $aggregationPeriod = $service->createPeriod(
            $aggregation,
            'Année 2026',
            '2026-01-01',
            '2026-12-31',
            [
                [
                    'member_id' => $aggregationPilot,
                    'numerator' => 1,
                    'denominator' => 1,
                    'rate_date' => '2026-12-31',
                    'source' => 'Même devise',
                ],
                [
                    'member_id' => $aggregationSibling,
                    'numerator' => 1,
                    'denominator' => 1,
                    'rate_date' => '2026-12-31',
                    'source' => 'Même devise',
                ],
            ],
            1
        );
        foreach ([
            [$aggregationPilot, $receivableA, '1100', 'Créances', 'actif'],
            [$aggregationPilot, $salesA, '3000', 'Produits', 'produit'],
            [$aggregationSibling, $siblingReceivable, '1100', 'Créances', 'actif'],
            [$aggregationSibling, $siblingSales, '3000', 'Produits', 'produit'],
        ] as [$member, $account, $target, $label, $type]) {
            $service->saveMapping(
                $aggregation,
                $member,
                $account,
                $target,
                $label,
                $type,
                0,
                1
            );
        }
        $aggregationPreview = $service->activationPreview($aggregation);
        $this->true(
            $aggregationPreview['ready'],
            'deux dossiers de la même organisation produisent une agrégation prête'
        );
        $service->activateGroup($aggregation, 1, 1);
        $aggregationWorkspace = $service->read(
            [$aggregation],
            $aggregation,
            $aggregationPeriod
        );
        $this->true(
            $aggregationWorkspace['balance']['formula_verified'],
            'agrégation interne réconciliée au centime'
        );
        $this->same(
            2,
            count($aggregationWorkspace['balance']['rows'][0]['sources']),
            'agrégation drillable vers ses deux dossiers membres'
        );
        $aggregationExport = json_decode(
            $service->export($aggregation, $aggregationPeriod)['content'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->same(
            'agregation_interne',
            $aggregationExport['report_kind'],
            'export autonome qualifié comme agrégation interne'
        );
        $this->true(
            str_contains(
                $aggregationExport['legal_notice'],
                'ne constitue pas une consolidation légale'
            ),
            'agrégation jamais présentée comme consolidation légale'
        );

        $temporaryGroup = $service->createGroup(
            $ids['organisation_a'],
            $ids['dossier_a'],
            'TEMPORAIRE',
            'Groupe sans donnée',
            'CHF',
            '2026-01-01',
            1,
            'agregation_interne'
        );
        $temporaryMember = $service->addMember(
            $temporaryGroup,
            $ids['organisation_a'],
            $sibling,
            '2026-01-01',
            null,
            1
        );
        $removed = $service->removeMember(
            $temporaryGroup,
            $temporaryMember,
            1,
            null,
            1
        );
        $this->true($removed['deleted'], 'membre sans période ni donnée supprimé physiquement');

        $illegalLegalGroup = $service->createGroup(
            $ids['organisation_a'],
            $ids['dossier_a'],
            'LEGAL-INVALIDE',
            'Consolidation mono-organisation invalide',
            'CHF',
            '2026-01-01',
            1,
            'consolidation_legale'
        );
        $illegalPilot = (int) $pdo->query(
            "SELECT id FROM membres_groupe_consolidation
             WHERE groupe_id = {$illegalLegalGroup}
               AND dossier_id = {$ids['dossier_a']}"
        )->fetchColumn();
        $illegalSibling = $service->addMember(
            $illegalLegalGroup,
            $ids['organisation_a'],
            $sibling,
            '2026-01-01',
            null,
            1
        );
        $this->throws(
            fn () => $service->createPeriod(
                $illegalLegalGroup,
                'Période interdite',
                '2026-01-01',
                '2026-12-31',
                [
                    [
                        'member_id' => $illegalPilot,
                        'numerator' => 1,
                        'denominator' => 1,
                        'rate_date' => '2026-12-31',
                        'source' => 'Même devise',
                    ],
                    [
                        'member_id' => $illegalSibling,
                        'numerator' => 1,
                        'denominator' => 1,
                        'rate_date' => '2026-12-31',
                        'source' => 'Même devise',
                    ],
                ],
                1
            ),
            'consolidation légale mono-organisation refusée'
        );
        $this->same(
            0,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM periodes_consolidation
                 WHERE groupe_id = {$illegalLegalGroup}"
            )->fetchColumn(),
            'composition incompatible refusée sans période partielle'
        );
        $this->true(
            (int) $pdo->query(
                "SELECT COUNT(*) FROM audit_events
                 WHERE action IN (
                   'consolidation.groupe_archive',
                   'consolidation.groupe_reactive',
                   'consolidation.membre_sorti',
                   'consolidation.mapping_desactive',
                   'consolidation.paire_interentites_desactivee'
                 )"
            )->fetchColumn() >= 5,
            'cycles de vie et versions 14e audités'
        );
        $this->true(IntegrityChecker::check($pdo)['ok'], 'intégrité après consolidation');
    }

    private function billingTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $ids = $this->seedScopes($pdo);
        $organisationId = $ids['organisation_a'];
        $dossierId = $ids['dossier_a'];
        $audit = new AuditLogger($pdo);
        $scope = new ScopeManager($pdo, $audit);
        $exercise = $scope->createExercise(
            $dossierId,
            'Exercice 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $setup = new AccountingSetupService($pdo, $audit);
        $setup->createPeriod(
            $organisationId,
            $dossierId,
            $exercise,
            '2026',
            '2026-01-01',
            '2026-12-31'
        );
        $journal = $setup->createJournal(
            $organisationId,
            $dossierId,
            'FAC',
            'Facturation'
        );
        (new PlanSeeder($pdo, dirname(__DIR__) . '/database/seeds'))
            ->installForDossier(
                $organisationId,
                $dossierId,
                'personne_morale'
            );
        $account = fn (string $number): int =>
            $this->accountId($pdo, $dossierId, $number);
        $receivable = $account('1100');
        $payable = $account('2000');
        $bank = $account('1020');
        $revenue = $account('3400');
        $expense = $account('6500');
        $inputVat = $account('1170');
        $vatDue = $account('2200');

        $vat = new VatConfigurationService($pdo, $audit);
        $vat->addRegime([
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
            'statut' => 'assujetti',
            'numero_tva' => 'CHE-123.456.789 TVA',
            'methode' => 'effective',
            'mode_decompte' => 'convenues',
            'periodicite' => 'trimestrielle',
            'date_debut' => '2026-01-01',
            'compte_impot_prealable_materiel_id' => $inputVat,
            'compte_impot_prealable_investissements_id' => $account('1171'),
            'compte_tva_due_id' => $vatDue,
            'compte_decompte_tva_id' => $account('2201'),
            'compte_corrections_id' => $expense,
        ]);
        $rates = [];
        foreach ($pdo->query(
            "SELECT id, categorie FROM tva_taux_legaux
             WHERE date_debut = '2024-01-01'"
        )->fetchAll() as $rate) {
            $rates[(string) $rate['categorie']] = (int) $rate['id'];
        }
        $addCode = function (
            string $code,
            string $label,
            string $treatment,
            string $nature,
            ?int $rateId,
            ?int $vatAccount = null,
            bool $deductible = false,
        ) use ($vat, $organisationId, $dossierId): int {
            return $vat->addCode([
                'organisation_id' => $organisationId,
                'dossier_id' => $dossierId,
                'code' => $code,
                'libelle' => $label,
                'traitement' => $treatment,
                'nature' => $nature,
                'taux_legal_id' => $rateId,
                'droit_deduction' => $deductible,
                'deduction_defaut_bp' => $deductible ? 10000 : 0,
                'compte_tva_id' => $vatAccount,
                'date_debut' => '2024-01-01',
            ]);
        };
        $saleNormal = $addCode(
            'VN81',
            'Ventes 8,1 %',
            'normal',
            'collectee',
            $rates['normal'],
            $vatDue
        );
        $saleReduced = $addCode(
            'VR26',
            'Ventes 2,6 %',
            'reduit',
            'collectee',
            $rates['reduit'],
            $vatDue
        );
        $exempt = $addCode('EXO', 'Exonéré', 'exonere', 'non_taxable', null);
        $purchase = $addCode(
            'AM81',
            'Achats 8,1 %',
            'normal',
            'prealable',
            $rates['normal'],
            $inputVat,
            true
        );

        $contacts = new ContactService($pdo, $audit);
        $customer = $contacts->create(
            $organisationId,
            $dossierId,
            [
                'type_personne' => 'entreprise',
                'raison_sociale' => 'Client SA',
                'email' => 'client@example.test',
            ],
            ['client'],
            [
                'ligne1' => 'Rue du Test 1',
                'code_postal' => '1000',
                'localite' => 'Lausanne',
                'pays' => 'CH',
            ]
        );
        $supplier = $contacts->create(
            $organisationId,
            $dossierId,
            [
                'type_personne' => 'entreprise',
                'raison_sociale' => 'Fournisseur SA',
            ],
            ['fournisseur', 'autre'],
            [
                'ligne1' => 'Route du Test 2',
                'code_postal' => '1700',
                'localite' => 'Fribourg',
                'pays' => 'CH',
            ]
        );
        $this->same(
            2,
            count($contacts->all($organisationId, $dossierId)),
            'contacts multi-rôles propres au dossier'
        );

        $entries = new EntryService($pdo, $audit);
        $billing = new BillingService($pdo, $audit, $entries);
        $payments = new PaymentService($pdo, $audit, $entries);
        $line = static fn (
            string $label,
            int $amount,
            int $accountId,
            int $vatCode
        ): array => [
            'libelle' => $label,
            'quantite_milli' => 1000,
            'prix_unitaire_centimes' => $amount,
            'mode_saisie' => 'net',
            'compte_id' => $accountId,
            'code_tva_id' => $vatCode,
            'date_prestation' => '2026-03-15',
        ];
        $this->throws(
            fn () => $billing->createDraft(
                $organisationId,
                $dossierId,
                'facture_client',
                $customer,
                '2026-03-15',
                '2026-04-15',
                [$line('Mauvais sens TVA', 10000, $revenue, $purchase)],
                $receivable
            ),
            'code TVA d’achat refusé sur une vente'
        );
        $draft = function (
            int $amount,
            string $label = 'Prestation'
        ) use (
            $billing,
            $organisationId,
            $dossierId,
            $customer,
            $receivable,
            $revenue,
            $exempt,
            $line
        ): int {
            return $billing->createDraft(
                $organisationId,
                $dossierId,
                'facture_client',
                $customer,
                '2026-03-15',
                '2026-04-15',
                [$line($label, $amount, $revenue, $exempt)],
                $receivable
            );
        };

        $draftA = $draft(100000, 'Facture mille');
        $draftB = $draft(5000, 'Second brouillon');
        $this->throws(
            fn () => $vat->deleteCode(
                $organisationId,
                $dossierId,
                $exempt
            ),
            'code TVA utilisé non supprimable'
        );
        $this->same(
            2,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM documents_financiers
                 WHERE statut = 'brouillon' AND numero = ''"
            )->fetchColumn(),
            'plusieurs brouillons ne consomment aucun numéro'
        );
        $numberA = $billing->issue(
            $organisationId,
            $dossierId,
            $draftA,
            (int) $billing->document($organisationId, $dossierId, $draftA)['version']
        );
        $numberB = $billing->issue(
            $organisationId,
            $dossierId,
            $draftB,
            (int) $billing->document($organisationId, $dossierId, $draftB)['version']
        );
        $this->true($numberA !== $numberB, 'numérotation transactionnelle sans collision');
        $this->true(ScorReference::valid(
            (string) $billing->document(
                $organisationId,
                $dossierId,
                $draftA
            )['reference_scor']
        ), 'référence SCOR valide à l’émission');

        $payment400 = $payments->create(
            $organisationId,
            $dossierId,
            $customer,
            'encaissement',
            '2026-03-20',
            40000
        );
        $payment600 = $payments->create(
            $organisationId,
            $dossierId,
            $customer,
            'encaissement',
            '2026-03-21',
            60000
        );
        $payments->allocatePayment(
            $organisationId,
            $dossierId,
            $payment400,
            $draftA,
            40000
        );
        $payments->allocatePayment(
            $organisationId,
            $dossierId,
            $payment600,
            $draftA,
            60000
        );
        $paid = array_values(array_filter(
            $billing->documents($organisationId, $dossierId),
            static fn (array $row): bool => (int) $row['id'] === $draftA
        ))[0];
        $this->same('paye', $paid['etat_paiement'], 'paiements 400 + 600 sur facture 1 000');
        $this->throws(
            fn () => $payments->allocatePayment(
                $organisationId,
                $dossierId,
                $payment600,
                $draftA,
                1
            ),
            'surallocation de 1 centime refusée'
        );

        $splitInvoiceA = $draft(10000, 'Répartition A');
        $splitInvoiceB = $draft(5000, 'Répartition B');
        foreach ([$splitInvoiceA, $splitInvoiceB] as $id) {
            $billing->issue(
                $organisationId,
                $dossierId,
                $id,
                (int) $billing->document($organisationId, $dossierId, $id)['version']
            );
        }
        $splitPayment = $payments->create(
            $organisationId,
            $dossierId,
            $customer,
            'encaissement',
            '2026-03-22',
            15000
        );
        $payments->allocatePayment(
            $organisationId,
            $dossierId,
            $splitPayment,
            $splitInvoiceA,
            10000
        );
        $payments->allocatePayment(
            $organisationId,
            $dossierId,
            $splitPayment,
            $splitInvoiceB,
            5000
        );
        $this->same(
            2,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM allocations
                 WHERE paiement_id = {$splitPayment} AND statut = 'valide'"
            )->fetchColumn(),
            'un paiement réparti sur plusieurs factures'
        );

        $clientInvoice = $billing->createDraft(
            $organisationId,
            $dossierId,
            'facture_client',
            $customer,
            '2026-04-01',
            '2026-04-30',
            [$line('Vente', 10000, $revenue, $saleNormal)],
            $receivable
        );
        $billing->issue(
            $organisationId,
            $dossierId,
            $clientInvoice,
            (int) $billing->document(
                $organisationId,
                $dossierId,
                $clientInvoice
            )['version']
        );
        $clientEntry = $billing->post(
            $organisationId,
            $dossierId,
            $clientInvoice,
            $exercise,
            $journal
        );
        $supplierInvoice = $billing->createDraft(
            $organisationId,
            $dossierId,
            'facture_fournisseur',
            $supplier,
            '2026-04-02',
            '2026-05-02',
            [$line('Achat', 10000, $expense, $purchase)],
            $payable,
            'FOU-2026-001'
        );
        $billing->issue(
            $organisationId,
            $dossierId,
            $supplierInvoice,
            (int) $billing->document(
                $organisationId,
                $dossierId,
                $supplierInvoice
            )['version']
        );
        $supplierEntry = $billing->post(
            $organisationId,
            $dossierId,
            $supplierInvoice,
            $exercise,
            $journal
        );
        foreach ([$clientEntry, $supplierEntry] as $entryId) {
            $totals = $pdo->query(
                "SELECT SUM(debit_centimes) AS debit, SUM(credit_centimes) AS credit
                 FROM lignes_ecriture WHERE ecriture_id = {$entryId}"
            )->fetch();
            $this->same(
                (int) $totals['debit'],
                (int) $totals['credit'],
                'écriture client/fournisseur équilibrée'
            );
        }
        $this->same(
            $clientEntry,
            $billing->post(
                $organisationId,
                $dossierId,
                $clientInvoice,
                $exercise,
                $journal
            ),
            'comptabilisation de facture idempotente'
        );

        $multi = $billing->createDraft(
            $organisationId,
            $dossierId,
            'facture_client',
            $customer,
            '2026-05-01',
            '2026-05-31',
            [
                $line('Normal', 10000, $revenue, $saleNormal),
                $line('Réduit', 10000, $revenue, $saleReduced),
                $line('Exonéré', 10000, $revenue, $exempt),
            ],
            $receivable
        );
        $multiDocument = $billing->document($organisationId, $dossierId, $multi);
        $this->same(31070, (int) $multiDocument['total_brut_centimes'], 'facture multi-taux et exonérée');
        $billing->issue(
            $organisationId,
            $dossierId,
            $multi,
            (int) $multiDocument['version']
        );
        $billing->post(
            $organisationId,
            $dossierId,
            $multi,
            $exercise,
            $journal
        );
        $credit = $billing->creditFrom(
            $organisationId,
            $dossierId,
            $multi,
            '2026-05-10'
        );
        $creditDraft = $billing->document($organisationId, $dossierId, $credit);
        $this->same(
            -31070,
            (int) $creditDraft['total_brut_centimes'],
            'avoir conserve bases et TVA avec signe inverse'
        );
        $billing->issue(
            $organisationId,
            $dossierId,
            $credit,
            (int) $creditDraft['version']
        );
        $billing->post(
            $organisationId,
            $dossierId,
            $credit,
            $exercise,
            $journal
        );
        $billing->markCancelledByCredit(
            $organisationId,
            $dossierId,
            $multi,
            $credit
        );
        $this->same(
            'annule',
            $billing->document($organisationId, $dossierId, $multi)['statut'],
            'annulation par avoir sans suppression historique'
        );
        $this->same(
            6,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM tva_lignes
                 WHERE document_id IN ('{$multi}', '{$credit}')"
            )->fetchColumn(),
            'snapshots TVA du document et de son avoir conservés'
        );

        $pdfWithoutQr = (new InvoicePdfService($pdo, $audit))->archive(
            $organisationId,
            $dossierId,
            $clientInvoice,
            $billing->creditorProfile($organisationId, $dossierId)
        );
        $this->true(
            str_starts_with($pdfWithoutQr, '%PDF-'),
            'PDF généré même sans coordonnées Swiss QR'
        );
        $this->same(
            '',
            (string) $billing->document(
                $organisationId,
                $dossierId,
                $clientInvoice
            )['qr_payload'],
            'section Swiss QR omise si la configuration est incomplète'
        );

        $billing->saveCreditorProfile(
            $organisationId,
            $dossierId,
            [
                'adresse_ligne1' => 'Rue de la Comptabilité 1',
                'code_postal' => '1000',
                'localite' => 'Lausanne',
                'pays' => 'CH',
                'iban_facturation' => 'CH9300762011623852957',
            ]
        );
        $creditor = $billing->creditorProfile($organisationId, $dossierId);
        $this->same(
            'CH9300762011623852957',
            $creditor['iban'],
            'coordonnées PDF/QR configurables par organisation'
        );
        $pdf = (new InvoicePdfService($pdo, $audit))->archive(
            $organisationId,
            $dossierId,
            $clientInvoice,
            $creditor
        );
        $archived = $billing->document(
            $organisationId,
            $dossierId,
            $clientInvoice
        );
        $this->true(str_starts_with($pdf, '%PDF-'), 'PDF généré sous PHP 8.2');
        $this->true(
            str_starts_with((string) $archived['qr_payload'], "SPC\r\n0200"),
            'payload QR-facture suisse archivé'
        );
        $this->true(
            str_starts_with(
                (new SwissQrService())->png((string) $archived['qr_payload']),
                "\x89PNG"
            ),
            'Swiss QR PNG générable avec les dépendances de release'
        );
        $this->true(
            PHP_VERSION_ID >= 80200 && PHP_VERSION_ID < 80300,
            'test SCOR/QR/PDF exécuté sur PHP 8.2'
        );

        $boundaryCustomerData = [
            'type_personne' => 'entreprise',
            'raison_sociale' => 'Client Aging SA',
        ];
        $boundaryAddress = [
            'ligne1' => 'Rue des Échéances 8',
            'code_postal' => '1200',
            'localite' => 'Genève',
            'pays' => 'CH',
        ];
        $boundaryCustomer = $contacts->create(
            $organisationId,
            $dossierId,
            $boundaryCustomerData,
            ['client'],
            $boundaryAddress,
            idempotencyKey: 'test-contact-aging'
        );
        $this->same(
            $boundaryCustomer,
            $contacts->create(
                $organisationId,
                $dossierId,
                $boundaryCustomerData,
                ['client'],
                $boundaryAddress,
                idempotencyKey: 'test-contact-aging'
            ),
            'rejeu idempotent du contact sans doublon'
        );
        $boundaryInvoices = [];
        foreach ([
            '2026-07-01',
            '2026-06-01',
            '2026-05-31',
            '2026-05-02',
            '2026-05-01',
            '2026-04-02',
            '2026-04-01',
        ] as $dueDate) {
            $id = $billing->createDraft(
                $organisationId,
                $dossierId,
                'facture_client',
                $boundaryCustomer,
                '2026-01-15',
                $dueDate,
                [$line('Borne ' . $dueDate, 100, $revenue, $exempt)],
                $receivable
            );
            $version = (int) $billing->document(
                $organisationId,
                $dossierId,
                $id
            )['version'];
            $number = $billing->issue(
                $organisationId,
                $dossierId,
                $id,
                $version
            );
            $this->same(
                $number,
                $billing->issue(
                    $organisationId,
                    $dossierId,
                    $id,
                    $version
                ),
                'rejeu d’émission conserve le numéro existant'
            );
            $boundaryInvoices[$dueDate] = $id;
        }
        $partial = $payments->create(
            $organisationId,
            $dossierId,
            $boundaryCustomer,
            'encaissement',
            '2026-06-15',
            40
        );
        $payments->allocatePayment(
            $organisationId,
            $dossierId,
            $partial,
            $boundaryInvoices['2026-06-01'],
            40
        );
        $boundaryCredit = $billing->createDraft(
            $organisationId,
            $dossierId,
            'avoir_client',
            $boundaryCustomer,
            '2026-06-20',
            '2026-07-01',
            [$line('Avoir partiel', 50, $revenue, $exempt)],
            $receivable
        );
        $billing->issue(
            $organisationId,
            $dossierId,
            $boundaryCredit,
            (int) $billing->document(
                $organisationId,
                $dossierId,
                $boundaryCredit
            )['version']
        );
        $payments->allocateCredit(
            $organisationId,
            $dossierId,
            $boundaryCredit,
            $boundaryInvoices['2026-06-01'],
            20
        );
        $advance = $payments->create(
            $organisationId,
            $dossierId,
            $boundaryCustomer,
            'encaissement',
            '2026-06-25',
            75,
            'ACOMPTE'
        );
        $workspace = new BillingWorkspaceService(
            $pdo,
            $billing,
            $payments,
            $contacts
        );
        $projection = $workspace->read(
            $organisationId,
            $dossierId,
            '2026-07-01',
            [
                'direction' => 'sales',
                'status' => 'all',
                'search' => '',
                'contact_id' => $boundaryCustomer,
            ]
        );
        $receivablesAging = $projection['aging']['receivables'];
        $this->same(
            110,
            (int) $receivablesAging['buckets']['days_0_30'],
            'aging inclut exactement les bornes 0 et 30 jours, paiement et avoir'
        );
        $this->same(
            200,
            (int) $receivablesAging['buckets']['days_31_60'],
            'aging inclut exactement les bornes 31 et 60 jours'
        );
        $this->same(
            200,
            (int) $receivablesAging['buckets']['days_61_90'],
            'aging inclut exactement les bornes 61 et 90 jours'
        );
        $this->same(
            100,
            (int) $receivablesAging['buckets']['days_91_plus'],
            'aging classe 91 jours dans la dernière tranche'
        );
        $this->same(
            75,
            (int) $receivablesAging['unallocated_payments_cents'],
            'paiement anticipé visible séparément'
        );
        $this->same(
            535,
            (int) $receivablesAging['net_open_cents'],
            'aging, avoir, allocation partielle et acompte concordent au centime'
        );
        $this->same(
            $advance,
            (int) array_values(array_filter(
                $projection['payments'],
                static fn (array $row): bool => $row['reference'] === 'ACOMPTE'
            ))[0]['id'],
            'paiement anticipé traçable dans la vue 360'
        );

        $recurrences = new RecurringBillingService(
            $pdo,
            $audit,
            $billing
        );
        $recurrenceId = $recurrences->create(
            $organisationId,
            $dossierId,
            'facture_client',
            $boundaryCustomer,
            'Abonnement client',
            'mensuelle',
            1,
            '2026-08-31',
            null,
            30,
            $receivable,
            '',
            [$line('Abonnement', 1000, $revenue, $exempt)]
        );
        $generated = $recurrences->generateDue(
            $organisationId,
            $dossierId,
            '2026-08-31'
        );
        $this->same(1, count($generated), 'récurrence client génère un brouillon');
        $this->same(
            [],
            $recurrences->generateDue(
                $organisationId,
                $dossierId,
                '2026-08-31'
            ),
            'rejeu de génération ne duplique aucun brouillon'
        );
        $this->same(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM generations_factures_recurrentes
                 WHERE modele_id = {$recurrenceId}"
            )->fetchColumn(),
            'génération de facture récurrente historisée une seule fois'
        );
        $this->same(
            'brouillon',
            (string) $billing->document(
                $organisationId,
                $dossierId,
                $generated[0]
            )['statut'],
            'une récurrence ne fait qu’un brouillon'
        );
        $supplierRecurrenceId = $recurrences->create(
            $organisationId,
            $dossierId,
            'facture_fournisseur',
            $supplier,
            'Abonnement fournisseur',
            'trimestrielle',
            1,
            '2026-09-01',
            null,
            15,
            $payable,
            'ABO-FOU',
            [$line('Service fournisseur', 2000, $expense, $purchase)]
        );
        $supplierGenerated = $recurrences->generateDue(
            $organisationId,
            $dossierId,
            '2026-09-01'
        );
        $supplierRecurringDocument = $billing->document(
            $organisationId,
            $dossierId,
            $supplierGenerated[0]
        );
        $this->same(
            'facture_fournisseur',
            $supplierRecurringDocument['type'],
            'récurrence fournisseur utilise le parcours achat'
        );
        $this->same(
            'ABO-FOU-2026-09-01',
            $supplierRecurringDocument['numero_externe'],
            'numéro externe récurrent fournisseur déterministe'
        );
        $this->same(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM generations_factures_recurrentes
                 WHERE modele_id = {$supplierRecurrenceId}"
            )->fetchColumn(),
            'récurrence fournisseur historisée sans doublon'
        );
        $this->true(IntegrityChecker::check($pdo)['ok'], 'intégrité après facturation');
    }

    private function expenseTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $ids = $this->seedScopes($pdo);
        $organisationId = $ids['organisation_a'];
        $dossierId = $ids['dossier_a'];
        $audit = new AuditLogger($pdo);
        $scope = new ScopeManager($pdo, $audit);
        $exercise = $scope->createExercise(
            $dossierId,
            'Exercice 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $setup = new AccountingSetupService($pdo, $audit);
        $setup->createPeriod(
            $organisationId,
            $dossierId,
            $exercise,
            '2026',
            '2026-01-01',
            '2026-12-31'
        );
        $journal = $setup->createJournal(
            $organisationId,
            $dossierId,
            'ACH',
            'Achats'
        );
        (new PlanSeeder($pdo, dirname(__DIR__) . '/database/seeds'))
            ->installForDossier(
                $organisationId,
                $dossierId,
                'personne_morale'
            );
        $payable = $this->accountId($pdo, $dossierId, '2000');
        $expenseAccount = $this->accountId($pdo, $dossierId, '6500');
        $inputVat = $this->accountId($pdo, $dossierId, '1170');
        $vat = new VatConfigurationService($pdo, $audit);
        $vat->addRegime([
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
            'statut' => 'assujetti',
            'numero_tva' => 'CHE-123.456.789 TVA',
            'methode' => 'effective',
            'mode_decompte' => 'convenues',
            'periodicite' => 'trimestrielle',
            'date_debut' => '2026-01-01',
            'compte_impot_prealable_materiel_id' => $inputVat,
            'compte_impot_prealable_investissements_id' =>
                $this->accountId($pdo, $dossierId, '1171'),
            'compte_tva_due_id' => $this->accountId($pdo, $dossierId, '2200'),
            'compte_decompte_tva_id' => $this->accountId($pdo, $dossierId, '2201'),
            'compte_corrections_id' => $expenseAccount,
        ]);
        $rate = (int) $pdo->query(
            "SELECT id FROM tva_taux_legaux WHERE categorie = 'normal'
             AND date_debut = '2024-01-01'"
        )->fetchColumn();
        $purchaseVat = $vat->addCode([
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
            'code' => 'AM81',
            'libelle' => 'Achats 8,1 %',
            'traitement' => 'normal',
            'nature' => 'prealable',
            'taux_legal_id' => $rate,
            'droit_deduction' => true,
            'deduction_defaut_bp' => 10000,
            'compte_tva_id' => $inputVat,
            'date_debut' => '2024-01-01',
        ]);
        $supplier = (new ContactService($pdo, $audit))->create(
            $organisationId,
            $dossierId,
            [
                'type_personne' => 'entreprise',
                'raison_sociale' => 'Fournisseur Liquidités SA',
            ],
            ['fournisseur'],
            [
                'ligne1' => 'Rue des Dépenses 6',
                'code_postal' => '1200',
                'localite' => 'Genève',
                'pays' => 'CH',
            ]
        );
        $proofId = (new AttachmentService($pdo, $audit))->store(
            $organisationId,
            $dossierId,
            '../../justificatif.pdf',
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"
        );
        $entries = new EntryService($pdo, $audit);
        $expenses = new ExpenseService($pdo, $audit, $entries);

        $withoutVatDossier = $scope->createDossier(
            $organisationId,
            'Dépenses sans régime TVA',
            'depenses-sans-regime-tva',
            'reel'
        );
        (new PlanSeeder($pdo, dirname(__DIR__) . '/database/seeds'))
            ->installForDossier(
                $organisationId,
                $withoutVatDossier,
                'personne_morale'
            );
        (new DefaultVatCodeInstaller($pdo, $audit))->install(
            $organisationId,
            $withoutVatDossier
        );
        $withoutVatSupplier = (new ContactService($pdo, $audit))->create(
            $organisationId,
            $withoutVatDossier,
            [
                'type_personne' => 'entreprise',
                'raison_sociale' => 'Fournisseur sans régime TVA',
            ],
            ['fournisseur'],
            [
                'ligne1' => 'Rue de la TVA 1',
                'code_postal' => '1200',
                'localite' => 'Genève',
                'pays' => 'CH',
            ]
        );
        $withoutVatCode = $pdo->prepare(
            "SELECT id FROM tva_codes
             WHERE dossier_id = ? AND code = 'AM81'"
        );
        $withoutVatCode->execute([$withoutVatDossier]);
        try {
            (new ExpenseService($pdo, $audit, $entries))->createDraft(
                $organisationId,
                $withoutVatDossier,
                $withoutVatSupplier,
                '2026-01-31',
                '2026-02-28',
                'SANS-TVA-001',
                $this->accountId($pdo, $withoutVatDossier, '2000'),
                [[
                    'libelle' => 'Fournitures',
                    'quantite_milli' => 1000,
                    'prix_unitaire_centimes' => 10000,
                    'mode_saisie' => 'net',
                    'compte_id' => $this->accountId(
                        $pdo,
                        $withoutVatDossier,
                        '6500'
                    ),
                    'code_tva_id' => (int) $withoutVatCode->fetchColumn(),
                    'date_prestation' => '2026-01-31',
                ]]
            );
            $this->true(false, 'absence de régime TVA expliquée sans erreur interne');
        } catch (Throwable $exception) {
            $this->same(
                ExpenseException::class,
                $exception::class,
                'absence de régime TVA convertie en erreur métier de dépense'
            );
            $this->same(
                'Aucun régime TVA applicable à cette date. '
                . 'Configurez-le dans Comptabilité → Clôture → TVA.',
                $exception->getMessage(),
                'prérequis TVA précisément expliqué à l’utilisateur'
            );
        }

        $line = [
            'libelle' => 'Fournitures',
            'quantite_milli' => 1000,
            'prix_unitaire_centimes' => 10000,
            'mode_saisie' => 'net',
            'compte_id' => $expenseAccount,
            'code_tva_id' => $purchaseVat,
            'date_prestation' => '2026-01-31',
        ];
        $expenseId = $expenses->createDraft(
            $organisationId,
            $dossierId,
            $supplier,
            '2026-01-31',
            '2026-02-28',
            'FOU-2026-001',
            $payable,
            [$line],
            $proofId
        );
        $created = $expenses->read($organisationId, $dossierId)['expenses'][0];
        $expenseVatCodes = array_column(
            $expenses->read($organisationId, $dossierId)['catalog']['vat_codes'],
            'code'
        );
        $this->true(
            in_array('AM81', $expenseVatCodes, true)
            && !in_array('VN81', $expenseVatCodes, true),
            'dépense propose les codes TVA d’achat mais aucun code de vente'
        );
        $billing = new BillingService($pdo, $audit, $entries);
        $this->same(
            [],
            $billing->documents($organisationId, $dossierId),
            'dépense absente de l’ancien parcours Facturation'
        );
        $this->same(10000, $created['net_cents'], 'dépense nette exacte au centime');
        $this->same(810, $created['vat_cents'], 'TVA de dépense exacte au centime');
        $this->same(10810, $created['gross_cents'], 'dépense brute exacte au centime');
        $this->same(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM ecritures')->fetchColumn(),
            'création sans comptabilisation automatique'
        );
        $this->same(
            'application/pdf',
            (string) $pdo->query(
                "SELECT type_mime FROM pieces_jointes WHERE id = {$proofId}"
            )->fetchColumn(),
            'justificatif validé conservé dans SQLite hors webroot'
        );
        $this->same(
            'justificatif.pdf',
            (string) $pdo->query(
                "SELECT nom_fichier FROM pieces_jointes WHERE id = {$proofId}"
            )->fetchColumn(),
            'traversée de chemin neutralisée sur le nom du justificatif'
        );
        $number = $expenses->submit(
            $organisationId,
            $dossierId,
            $expenseId,
            2
        );
        $this->true(str_starts_with($number, 'DEP-2026-'), 'soumission numérotée');
        $expenses->approve($organisationId, $dossierId, $expenseId, 3);
        $this->throws(
            fn () => $billing->post(
                $organisationId,
                $dossierId,
                $expenseId,
                $exercise,
                $journal
            ),
            'ancien workflow Facturation ne contourne pas l’approbation dépenses'
        );
        $entryId = $expenses->post(
            $organisationId,
            $dossierId,
            $expenseId,
            $exercise,
            $journal
        );
        $totals = $pdo->query(
            "SELECT SUM(debit_centimes) AS debit, SUM(credit_centimes) AS credit
             FROM lignes_ecriture WHERE ecriture_id = {$entryId}"
        )->fetch();
        $this->same(10810, (int) $totals['debit'], 'débits fournisseur équilibrés');
        $this->same(10810, (int) $totals['credit'], 'crédits fournisseur équilibrés');
        $this->same(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM paiements')->fetchColumn(),
            'paiement non créé par la comptabilisation'
        );
        $reversal = $expenses->cancel(
            $organisationId,
            $dossierId,
            $expenseId,
            5,
            '2026-02-01'
        );
        $this->true(is_int($reversal) && $reversal > 0, 'annulation par contre-passation');

        $recurrenceId = $expenses->createRecurrence(
            $organisationId,
            $dossierId,
            $supplier,
            'Abonnement mensuel',
            'mensuelle',
            1,
            '2026-01-31',
            '2026-03-31',
            30,
            $payable,
            'ABO',
            [$line]
        );
        $firstRun = $expenses->generateDue(
            $organisationId,
            $dossierId,
            '2026-01-31'
        );
        $secondRun = $expenses->generateDue(
            $organisationId,
            $dossierId,
            '2026-01-31'
        );
        $this->same(1, count($firstRun), 'une échéance récurrente générée');
        $this->same([], $secondRun, 'rejeu de génération sans doublon');
        $this->same(
            1,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM generations_depenses_recurrentes
                 WHERE modele_id = {$recurrenceId}"
            )->fetchColumn(),
            'unicité modèle/date persistée'
        );
        $this->same(
            '2026-02-28',
            (string) $pdo->query(
                "SELECT prochaine_echeance FROM modeles_depenses_recurrentes
                 WHERE id = {$recurrenceId}"
            )->fetchColumn(),
            'fin de mois calculée sans saut de février'
        );
        $generatedId = $firstRun[0];
        $generatedNumber = $expenses->submit(
            $organisationId,
            $dossierId,
            $generatedId,
            2
        );
        $this->true(
            str_starts_with($generatedNumber, 'DEP-2026-'),
            'brouillon sans justificatif numérique soumis explicitement'
        );
        $this->same(
            [],
            $expenses->read(
                $ids['organisation_b'],
                $ids['dossier_b']
            )['expenses'],
            'aucune fuite de dépense inter-dossiers'
        );
        $this->same(
            2,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM permissions
                 WHERE code IN ('depenses.approve', 'depenses.post')"
            )->fetchColumn(),
            'permissions approbation et comptabilisation distinctes'
        );
        $this->true(
            IntegrityChecker::check($pdo)['ok'],
            'intégrité après dépenses et récurrences'
        );
    }

    private function publicMarketDataTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $ids = $this->seedScopes($pdo);
        $scope = new ScopeManager($pdo, new AuditLogger($pdo));
        $exerciseA = $scope->createExercise(
            $ids['dossier_a'],
            'Exercice marché 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $exerciseB = $scope->createExercise(
            $ids['dossier_b'],
            'Exercice partagé 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $currency = $pdo->prepare(
            'INSERT INTO devises_dossier
             (organisation_id, dossier_id, code, actif)
             VALUES (?, ?, \'EUR\', 1)'
        );
        $currency->execute([$ids['organisation_a'], $ids['dossier_a']]);
        $currency->execute([$ids['organisation_b'], $ids['dossier_b']]);

        $exchangeJson = json_encode([
            'timeseries' => [
                [
                    'header' => [
                        ['dim' => 'Moyenne mensuelle/Fin de mois', 'dimItem' => 'Moyenne mensuelle'],
                        ['dim' => 'Monnaie', 'dimItem' => 'Europe - EUR 1.-'],
                    ],
                    'metadata' => [
                        'key' => 'EPB@SNB.devkum{M0,EUR1}',
                        'frequency' => 'P1M',
                        'unit' => 'Cours à 11h en CHF',
                    ],
                    'values' => [
                        ['date' => '2024-12', 'value' => 0.93111],
                        ['date' => '2025-01', 'value' => 0.94444],
                        ['date' => '2026-06', 'value' => 0.92045],
                    ],
                ],
                [
                    'header' => [
                        ['dim' => 'Moyenne mensuelle/Fin de mois', 'dimItem' => 'Fin de mois'],
                        ['dim' => 'Monnaie', 'dimItem' => 'Europe - EUR 1.-'],
                    ],
                    'metadata' => [
                        'key' => 'EPB@SNB.devkum{M1,EUR1}',
                        'frequency' => 'P1M',
                        'unit' => 'Cours à 11h en CHF',
                    ],
                    'values' => [
                        ['date' => '2025-01', 'value' => 0.94777],
                        ['date' => '2026-06', 'value' => 0.92218],
                    ],
                ],
                [
                    'header' => [
                        ['dim' => 'Moyenne mensuelle/Fin de mois', 'dimItem' => 'Moyenne mensuelle'],
                        ['dim' => 'Monnaie', 'dimItem' => 'Amérique - États-Unis – USD 1.-'],
                    ],
                    'metadata' => [
                        'key' => 'EPB@SNB.devkum{M0,USD1}',
                        'frequency' => 'P1M',
                        'unit' => 'Cours à 11h en CHF',
                    ],
                    'values' => [
                        ['date' => '2026-06', 'value' => 0.7992],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $interestJson = json_encode([
            'timeseries' => [
                [
                    'header' => [[
                        'dim' => 'Taux',
                        'dimItem' => 'Suisse - CHF - SARON - 1 jour',
                    ]],
                    'metadata' => [
                        'key' => 'EPB@SNB.zimoma{SARON}',
                        'frequency' => 'P1M',
                        'unit' => 'En pour-cent',
                    ],
                    'values' => [
                        ['date' => '2025-01', 'value' => 0.44],
                        ['date' => '2026-06', 'value' => -0.043903],
                    ],
                ],
                [
                    'header' => [[
                        'dim' => 'Taux',
                        'dimItem' => 'Zone euro - EUR - ESTR - 1 jour',
                    ]],
                    'metadata' => [
                        'key' => 'EPB@SNB.zimoma{ESTR}',
                        'frequency' => 'P1M',
                        'unit' => 'En pour-cent',
                    ],
                    'values' => [
                        ['date' => '2025-01', 'value' => 2.9],
                        ['date' => '2026-06', 'value' => 2.182],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $dailyXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<wechselkurse xmlns="https://www.backend-rates.bazg.admin.ch/xmldaily">
  <datum>24.07.2026</datum>
  <zeit>07:00:04</zeit>
  <gueltigkeit>25.07.2026,26.07.2026,27.07.2026</gueltigkeit>
  <devise code="eur">
    <land_fr>Union monétaire européenne</land_fr>
    <waehrung>1 EUR</waehrung>
    <kurs>0.93883</kurs>
  </devise>
  <devise code="jpy">
    <land_fr>Japon</land_fr>
    <waehrung>100 JPY</waehrung>
    <kurs>0.54123</kurs>
  </devise>
</wechselkurse>
XML;
        $calls = ['devkum' => 0, 'zimoma' => 0, 'daily' => 0];
        $http = new PublicMarketHttpClient(
            function (string $url) use (
                &$calls,
                $exchangeJson,
                $interestJson,
                $dailyXml
            ): string {
                if (str_contains($url, '/devkum/')) {
                    $calls['devkum']++;
                    return $exchangeJson;
                }
                if (str_contains($url, '/zimoma/')) {
                    $calls['zimoma']++;
                    return $interestJson;
                }
                $calls['daily']++;
                return $dailyXml;
            }
        );
        $service = new PublicMarketDataService(
            $pdo,
            $http,
            new DateTimeImmutable('2026-07-26')
        );
        $exchange = $service->exchangeHistory(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exerciseA
        );
        $this->same(
            '2025-01|2026-12',
            $exchange['window']['start'] . '|' . $exchange['window']['end'],
            'fenêtre égale à l’exercice plus les douze mois précédents'
        );
        $this->same(
            ['CHF', 'EUR'],
            $exchange['currencies'],
            'monnaies actives du dossier uniquement'
        );
        $this->same(
            2,
            count($exchange['series']),
            'moyenne et fin de mois EUR disponibles'
        );
        $this->same(
            '0.93883|2026-07-24',
            $exchange['daily'][0]['per_unit']
                . '|' . $exchange['daily'][0]['publication_date'],
            'taux OFDF quotidien et date de publication conservés'
        );
        $this->same(
            ['2026-07-25', '2026-07-26', '2026-07-27'],
            $exchange['daily'][0]['validity'],
            'jours de validité OFDF conservés'
        );

        $shared = $service->exchangeHistory(
            $ids['organisation_b'],
            $ids['dossier_b'],
            $exerciseB
        );
        $sharedAverage = array_values(array_filter(
            $shared['series'],
            static fn (array $series): bool => $series['mode'] === 'moyenne'
        ))[0];
        $this->same(
            '0.92045',
            $sharedAverage['values'][1]['per_unit'],
            'cache de change partagé avec un autre dossier'
        );
        $this->same(
            ['devkum' => 1, 'zimoma' => 0, 'daily' => 1],
            $calls,
            'source externe appelée une seule fois pour le cache global'
        );

        $interest = $service->interestHistory(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exerciseA
        );
        $this->same(
            ['CHF', 'EUR'],
            array_values(array_unique(array_column($interest['series'], 'currency'))),
            'taux d’intérêt limités aux monnaies actives'
        );
        $saron = array_values(array_filter(
            $interest['series'],
            static fn (array $series): bool => $series['currency'] === 'CHF'
        ))[0];
        $this->same(
            '-0.043903',
            $saron['values'][1]['per_unit'],
            'taux négatif conservé sans perte de précision'
        );
        $this->same(
            1,
            $calls['zimoma'],
            'série de taux BNS synchronisée une seule fois'
        );
        $this->same(
            0,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM series_marche_publiques
                 WHERE devise NOT IN ('CHF', 'EUR')"
            )->fetchColumn(),
            'aucune série stockée pour une monnaie non définie'
        );
        $this->same(
            0,
            (int) $pdo->query(
                "SELECT COUNT(*)
                 FROM valeurs_marche_mensuelles
                 WHERE periode < '2025-01' OR periode > '2026-06'"
            )->fetchColumn(),
            'aucune valeur stockée hors exercice et douze mois précédents'
        );
        $this->same(
            ['EUR'],
            $pdo->query(
                'SELECT DISTINCT devise
                 FROM taux_change_publics_quotidiens ORDER BY devise'
            )->fetchAll(PDO::FETCH_COLUMN),
            'taux quotidien limité aux monnaies définies'
        );
        $pdo->prepare(
            'UPDATE devises_dossier SET actif = 0
             WHERE organisation_id = ? AND dossier_id = ? AND code = \'EUR\''
        )->execute([$ids['organisation_a'], $ids['dossier_a']]);
        $service->exchangeHistory(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exerciseA
        );
        $this->same(
            4,
            (int) $pdo->query(
                "SELECT COUNT(*)
                 FROM valeurs_marche_mensuelles v
                 JOIN series_marche_publiques s ON s.id = v.serie_id
                 WHERE s.jeu_donnees = 'devkum'"
            )->fetchColumn(),
            'cache EUR conservé tant qu’un autre dossier en a besoin'
        );
        $pdo->prepare(
            'UPDATE devises_dossier SET actif = 1
             WHERE organisation_id = ? AND dossier_id = ? AND code = \'EUR\''
        )->execute([$ids['organisation_a'], $ids['dossier_a']]);
        $columns = array_column(
            $pdo->query('PRAGMA table_info(series_marche_publiques)')->fetchAll(),
            'name'
        );
        $this->false(
            in_array('organisation_id', $columns, true)
                || in_array('dossier_id', $columns, true),
            'référentiel de marché réellement global'
        );
        $this->same(
            'integer',
            (string) $pdo->query(
                'SELECT typeof(valeur_echelle) FROM valeurs_marche_mensuelles LIMIT 1'
            )->fetchColumn(),
            'valeurs publiques persistées en entier à échelle fixe'
        );
        $this->throws(
            fn () => $service->exchangeHistory(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $exerciseB
            ),
            'exercice étranger refusé sans fuite de cache'
        );
        $pdo->exec(
            "DELETE FROM series_marche_publiques WHERE jeu_donnees = 'devkum'"
        );
        $pdo->exec('DELETE FROM taux_change_publics_quotidiens');
        $pdo->exec(
            "UPDATE actualisations_marche_publiques
             SET statut = 'echec', tente_le = '2026-07-26 08:00:00',
                 erreur = 'source indisponible'
             WHERE jeu_donnees IN ('devkum', 'bazg_daily')"
        );
        $repeatedFailure = $service->exchangeHistory(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exerciseA
        );
        $this->same(
            'Actualisation BNS impossible : les dernières données conservées sont affichées.',
            $repeatedFailure['refresh']['monthly']['warning'],
            'échec BNS du jour reste visible sans nouvelle tentative'
        );
        $this->same(
            'Actualisation OFDF impossible : le dernier taux quotidien conservé est affiché.',
            $repeatedFailure['refresh']['daily']['warning'],
            'échec OFDF du jour reste visible sans nouvelle tentative'
        );
        $this->same(
            ['devkum' => 1, 'zimoma' => 1, 'daily' => 1],
            $calls,
            'échec déjà tenté ne relance pas les sources le même jour'
        );
        $this->true(
            IntegrityChecker::check($pdo)['ok'],
            'intégrité après synchronisation des données de marché'
        );
    }

    private function treasuryTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $ids = $this->seedScopes($pdo);
        $audit = new AuditLogger($pdo);
        $scope = new ScopeManager($pdo, $audit);
        $exercise = $scope->createExercise(
            $ids['dossier_a'],
            'Exercice 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $setup = new AccountingSetupService($pdo, $audit);
        $setup->createPeriod(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exercise,
            'Année 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $journal = $setup->createJournal(
            $ids['organisation_a'],
            $ids['dossier_a'],
            'BQ',
            'Banque',
            'banque'
        );
        $seeder = new PlanSeeder($pdo, dirname(__DIR__) . '/database/seeds');
        $seeder->installForDossier(
            $ids['organisation_a'],
            $ids['dossier_a'],
            'personne_morale'
        );
        $seeder->installForDossier(
            $ids['organisation_b'],
            $ids['dossier_b'],
            'personne_morale'
        );
        $bankAccount = $this->accountId($pdo, $ids['dossier_a'], '1020');
        $cashAccount = $this->accountId($pdo, $ids['dossier_a'], '1000');
        $salesAccount = $this->accountId($pdo, $ids['dossier_a'], '3400');
        $expenseAccount = $this->accountId($pdo, $ids['dossier_a'], '6500');
        $foreignAccount = $this->accountId($pdo, $ids['dossier_b'], '1020');
        $accounts = new TreasuryAccountService($pdo, $audit);
        $bankTreasury = $accounts->create([
            'organisation_id' => $ids['organisation_a'],
            'dossier_id' => $ids['dossier_a'],
            'compte_comptable_id' => $bankAccount,
            'libelle' => 'PostFinance',
            'type' => 'poste',
            'iban' => 'CH9300762011623852957',
            'monnaie' => 'CHF',
        ]);
        $cashTreasury = $accounts->create([
            'organisation_id' => $ids['organisation_a'],
            'dossier_id' => $ids['dossier_a'],
            'compte_comptable_id' => $cashAccount,
            'libelle' => 'Caisse',
            'type' => 'caisse',
            'monnaie' => 'CHF',
        ]);
        $this->same(2, count($accounts->list(
            $ids['organisation_a'],
            $ids['dossier_a']
        )), 'comptes banque/poste/caisse rattachés au grand livre');
        $this->throws(
            fn () => $accounts->create([
                'organisation_id' => $ids['organisation_a'],
                'dossier_id' => $ids['dossier_a'],
                'compte_comptable_id' => $foreignAccount,
                'libelle' => 'Compte étranger',
                'type' => 'banque',
            ]),
            'compte de trésorerie inter-organisation refusé'
        );

        $csv = <<<'CSV'
Date de début:;="01.01.2026"
Date de fin:;="31.12.2026"
Genre:;="Compte commercial"
Compte:;="CH9300762011623852957"
Monnaie:;="CHF"

Date;Texte de notification;Crédit en CHF;Débit en CHF;Valeur;Solde en CHF

31.12.2026;"PRIX POUR LA GESTION DU COMPTE";;-5;31.12.2026;4474.67
31.12.2026;"CRÉDIT MARTIN COMMUNICATIONS: LOCAL";120;;31.12.2026;4594.67
31.12.2026;"CRÉDIT MARTIN COMMUNICATIONS: LOCAL";120;;31.12.2026;4714.67
05.12.2026;"DÉBIT ORDRE PERMANENT DUPONT REFERENCE: LOYER";;-470;05.12.2026;4594.67
03.12.2026;"DON PRIVÉ DURAND";100;;03.12.2026;5064.67
Disclaimer:
CSV;
        $imports = new BankImportService($pdo, $audit);
        $preview = $imports->preview(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $bankTreasury,
            'postfinance.csv',
            $csv
        );
        $this->same('postfinance_csv', $preview['format'], 'format PostFinance reconnu');
        $this->same(-500, $preview['transactions'][0]['amount_cents'], 'débit PostFinance signé');
        $this->same(12000, $preview['transactions'][1]['amount_cents'], 'crédit PostFinance signé');
        $this->same('2026-12-31', $preview['transactions'][1]['date_booking'], 'date PostFinance normalisée');
        $confirmed = $imports->confirm(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $preview['import_id']
        );
        $this->same(5, $confirmed['imported'], 'cinq mouvements importés');
        $this->same(
            5,
            (int) $pdo->query('SELECT COUNT(*) FROM lignes_bancaires')->fetchColumn(),
            'deux mouvements légitimement identiques conservés'
        );
        $secondPreview = $imports->preview(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $bankTreasury,
            'renomme.csv',
            $csv
        );
        $this->same(5, $secondPreview['duplicate_count'], 'réimport intégral détecté');
        $imports->confirm(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $secondPreview['import_id']
        );
        $this->same(
            5,
            (int) $pdo->query('SELECT COUNT(*) FROM lignes_bancaires')->fetchColumn(),
            'réimport sans création de doublon'
        );
        $stored = $pdo->prepare('SELECT contenu_source FROM imports_bancaires WHERE id = ?');
        $stored->execute([$preview['import_id']]);
        $this->same($csv, (string) $stored->fetchColumn(), 'source bancaire originale conservée');
        $lineId = (int) $pdo->query(
            'SELECT id FROM lignes_bancaires ORDER BY id LIMIT 1'
        )->fetchColumn();
        $this->throws(
            fn () => $pdo->exec(
                "UPDATE lignes_bancaires SET libelle = 'altéré' WHERE id = {$lineId}"
            ),
            'ligne bancaire confirmée immuable'
        );
        $this->throws(
            fn () => $imports->preview(
                $ids['organisation_b'],
                $ids['dossier_b'],
                $bankTreasury,
                'postfinance.csv',
                $csv
            ),
            'import bancaire strictement limité au scope'
        );

        $camt = $this->camtFixture('053', '08', 'Stmt');
        $parsed = (new Camt053Parser())->parse($camt);
        $this->same('camt053', $parsed->format, 'camt.053.001.08 reconnu');
        $this->same(2, count($parsed->transactions), 'écriture groupée CAMT détaillée');
        $this->same('SCOR', $parsed->transactions[0]['reference_type'], 'référence SCOR extraite');
        $this->same('RF18539007547034', $parsed->transactions[0]['reference'], 'référence structurée extraite');
        $this->same('QRR', $parsed->transactions[1]['reference_type'], 'référence QR extraite');
        $this->same(1, count($parsed->balances), 'solde CAMT extrait');
        $this->same('CH5604835012345678009', $parsed->transactions[0]['counterparty_iban'], 'IBAN contrepartie extrait');
        $this->same(125, $parsed->transactions[0]['fee_cents'], 'frais CAMT extraits');
        $this->same(
            'camt053',
            (new Camt053Parser())->parse($this->camtFixture('053', '04', 'Stmt'))->format,
            'ancienne version camt.053.001.04 reconnue'
        );
        $this->same(
            'camt054',
            (new Camt054Parser())->parse($this->camtFixture('054', '08', 'Ntfctn'))->format,
            'camt.054 reconnu par son type et namespace'
        );
        $this->throws(
            fn () => (new Camt053Parser())->parse(
                '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY ext SYSTEM "file:///etc/passwd">]>'
                . '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08">&ext;</Document>'
            ),
            'XXE et DTD refusées sans lecture externe'
        );

        $entries = new EntryService($pdo, $audit);
        $post = function (string $key, int $amount, int $counterpart) use (
            $entries,
            $ids,
            $exercise,
            $journal,
            $bankAccount
        ): int {
            $lines = $amount > 0
                ? [
                    ['compte_id' => $bankAccount, 'debit_centimes' => $amount],
                    ['compte_id' => $counterpart, 'credit_centimes' => $amount],
                ]
                : [
                    ['compte_id' => $counterpart, 'debit_centimes' => abs($amount)],
                    ['compte_id' => $bankAccount, 'credit_centimes' => abs($amount)],
                ];
            return $entries->postGenerated([
                'organisation_id' => $ids['organisation_a'],
                'dossier_id' => $ids['dossier_a'],
                'exercice_id' => $exercise,
                'journal_id' => $journal,
                'date_comptable' => '2026-12-31',
                'libelle' => 'Rapprochement test',
                'source_type' => 'test',
                'source_id' => $key,
                'source_action' => 'rapprochement',
                'lignes' => $lines,
            ], 'test-treasury:' . $key);
        };
        $bankLines = $pdo->query(
            'SELECT id, montant_centimes FROM lignes_bancaires ORDER BY id'
        )->fetchAll();
        $accountingLine = static function (PDO $pdo, int $entry, int $account): int {
            $stmt = $pdo->prepare(
                'SELECT id FROM lignes_ecriture WHERE ecriture_id = ? AND compte_id = ?'
            );
            $stmt->execute([$entry, $account]);
            return (int) $stmt->fetchColumn();
        };
        $reconciliations = new ReconciliationService($pdo, $audit);
        $entryMinus = $post('one-to-one', -500, $expenseAccount);
        $oneToOne = $reconciliations->reconcile(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $bankTreasury,
            [(int) $bankLines[0]['id']],
            [$accountingLine($pdo, $entryMinus, $bankAccount)]
        );
        $this->true($oneToOne > 0, 'rapprochement 1–1');
        $entry70 = $post('one-to-many-a', 6000, $salesAccount);
        $entry50 = $post('one-to-many-b', 4000, $salesAccount);
        $oneToMany = $reconciliations->reconcile(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $bankTreasury,
            [(int) $bankLines[4]['id']],
            [
                $accountingLine($pdo, $entry70, $bankAccount),
                $accountingLine($pdo, $entry50, $bankAccount),
            ]
        );
        $this->true($oneToMany > 0, 'rapprochement 1–N par somme exacte');
        $entry240 = $post('many-to-one', 24000, $salesAccount);
        $manyToOne = $reconciliations->reconcile(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $bankTreasury,
            [(int) $bankLines[1]['id'], (int) $bankLines[2]['id']],
            [$accountingLine($pdo, $entry240, $bankAccount)]
        );
        $this->true($manyToOne > 0, 'rapprochement N–1 par somme exacte');
        $this->throws(
            fn () => $reconciliations->reconcile(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $bankTreasury,
                [(int) $bankLines[0]['id']],
                [$accountingLine($pdo, $entryMinus, $bankAccount)]
            ),
            'double consommation d’une ligne refusée'
        );
        $this->throws(
            fn () => $reconciliations->reconcile(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $bankTreasury,
                [(int) $bankLines[3]['id']],
                [$accountingLine($pdo, $entry50, $bankAccount)]
            ),
            'rapprochement de sommes différentes refusé'
        );

        $suggestions = new SuggestionService($pdo, $audit, $entries);
        $suggestion = $suggestions->propose(
            $ids['organisation_a'],
            $ids['dossier_a'],
            (int) $bankLines[4]['id'],
            $salesAccount,
            'Don reçu',
            90,
            'Référence connue'
        );
        $this->same(
            0,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM ecritures WHERE source_type = 'ligne_bancaire'"
            )->fetchColumn(),
            'suggestion sans validation silencieuse'
        );
        $suggestionEntry = $suggestions->accept(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $suggestion,
            $exercise,
            $journal
        );
        $this->same(
            'validee',
            (string) $pdo->query(
                "SELECT statut FROM ecritures WHERE id = {$suggestionEntry}"
            )->fetchColumn(),
            'suggestion validée seulement après acceptation explicite'
        );

        $transfer = (new InternalTransferService($pdo, $entries))->post(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $bankTreasury,
            $cashTreasury,
            $exercise,
            $journal,
            '2026-12-30',
            5000,
            'Alimentation de la caisse',
            'caisse-2026-01'
        );
        $transferTypes = $pdo->query(
            "SELECT DISTINCT c.type
             FROM lignes_ecriture l JOIN comptes c ON c.id = l.compte_id
             WHERE l.ecriture_id = {$transfer} ORDER BY c.type"
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->same(['actif'], $transferTypes, 'transfert interne sans charge ni produit');
        $this->same(
            $transfer,
            (new InternalTransferService($pdo, $entries))->post(
                $ids['organisation_a'],
                $ids['dossier_a'],
                $bankTreasury,
                $cashTreasury,
                $exercise,
                $journal,
                '2026-12-30',
                5000,
                'Alimentation de la caisse',
                'caisse-2026-01'
            ),
            'transfert interne idempotent'
        );
        $state = (new TreasuryStateService($pdo))->state(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $bankTreasury,
            '2026-12-31'
        );
        $this->true(
            array_key_exists('difference_cents', $state),
            'état banque/comptabilité/écart calculé'
        );
        $this->true(IntegrityChecker::check($pdo)['ok'], 'intégrité après trésorerie');
    }

    private function treasuryPaymentTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $ids = $this->seedScopes($pdo);
        $organisationId = $ids['organisation_a'];
        $dossierId = $ids['dossier_a'];
        $audit = new AuditLogger($pdo);
        $scope = new ScopeManager($pdo, $audit);
        $exercise = $scope->createExercise(
            $dossierId,
            'Exercice 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $setup = new AccountingSetupService($pdo, $audit);
        $period = $setup->createPeriod(
            $organisationId,
            $dossierId,
            $exercise,
            '2026',
            '2026-01-01',
            '2026-12-31'
        );
        $journal = $setup->createJournal(
            $organisationId,
            $dossierId,
            'BQ',
            'Banque',
            'banque'
        );
        (new PlanSeeder($pdo, dirname(__DIR__) . '/database/seeds'))
            ->installForDossier($organisationId, $dossierId, 'personne_morale');
        $bankAccount = $this->accountId($pdo, $dossierId, '1020');
        $payable = $this->accountId($pdo, $dossierId, '2000');
        $expenseAccount = $this->accountId($pdo, $dossierId, '6500');
        $inputVat = $this->accountId($pdo, $dossierId, '1170');
        $treasuryId = (new TreasuryAccountService($pdo, $audit))->create([
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
            'compte_comptable_id' => $bankAccount,
            'libelle' => 'Banque paiements',
            'type' => 'banque',
            'iban' => 'CH9300762011623852957',
            'bic' => 'POFICHBEXXX',
            'monnaie' => 'CHF',
        ]);
        $vat = new VatConfigurationService($pdo, $audit);
        $vat->addRegime([
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
            'statut' => 'assujetti',
            'numero_tva' => 'CHE-123.456.789 TVA',
            'methode' => 'effective',
            'mode_decompte' => 'convenues',
            'periodicite' => 'trimestrielle',
            'date_debut' => '2026-01-01',
            'compte_impot_prealable_materiel_id' => $inputVat,
            'compte_impot_prealable_investissements_id' =>
                $this->accountId($pdo, $dossierId, '1171'),
            'compte_tva_due_id' => $this->accountId($pdo, $dossierId, '2200'),
            'compte_decompte_tva_id' => $this->accountId($pdo, $dossierId, '2201'),
            'compte_corrections_id' => $expenseAccount,
        ]);
        $rate = (int) $pdo->query(
            "SELECT id FROM tva_taux_legaux
             WHERE categorie = 'normal' AND date_debut = '2024-01-01'"
        )->fetchColumn();
        $purchaseVat = $vat->addCode([
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
            'code' => 'AM81-PAY',
            'libelle' => 'Achats 8,1 %',
            'traitement' => 'normal',
            'nature' => 'prealable',
            'taux_legal_id' => $rate,
            'droit_deduction' => true,
            'deduction_defaut_bp' => 10000,
            'compte_tva_id' => $inputVat,
            'date_debut' => '2024-01-01',
        ]);
        $supplier = (new ContactService($pdo, $audit))->create(
            $organisationId,
            $dossierId,
            [
                'type_personne' => 'entreprise',
                'raison_sociale' => 'Fournisseur Paiements SA',
                'iban_paiement' => 'CH5604835012345678009',
                'bic_paiement' => 'POFICHBEXXX',
            ],
            ['fournisseur'],
            [
                'ligne1' => 'Rue des Paiements 7',
                'code_postal' => '1200',
                'localite' => 'Genève',
                'pays' => 'CH',
            ]
        );
        $foreignSupplier = (new ContactService($pdo, $audit))->create(
            $ids['organisation_b'],
            $ids['dossier_b'],
            [
                'type_personne' => 'entreprise',
                'raison_sociale' => 'Fournisseur hors dossier SA',
            ],
            ['fournisseur']
        );
        $proof = (new AttachmentService($pdo, $audit))->store(
            $organisationId,
            $dossierId,
            'dette.pdf',
            "%PDF-1.4\n%%EOF"
        );
        $entries = new EntryService($pdo, $audit);
        $expenses = new ExpenseService($pdo, $audit, $entries);
        $line = [
            'libelle' => 'Services',
            'quantite_milli' => 1000,
            'prix_unitaire_centimes' => 10000,
            'mode_saisie' => 'net',
            'compte_id' => $expenseAccount,
            'code_tva_id' => $purchaseVat,
            'date_prestation' => '2026-03-01',
        ];
        $createExpense = function (string $external) use (
            $expenses,
            $organisationId,
            $dossierId,
            $supplier,
            $payable,
            $line,
            $proof,
            $exercise,
            $journal
        ): int {
            $id = $expenses->createDraft(
                $organisationId,
                $dossierId,
                $supplier,
                '2026-03-01',
                '2026-03-31',
                $external,
                $payable,
                [$line],
                $proof
            );
            $expenses->submit($organisationId, $dossierId, $id, 2);
            $expenses->approve($organisationId, $dossierId, $id, 3);
            $expenses->post(
                $organisationId,
                $dossierId,
                $id,
                $exercise,
                $journal
            );
            return $id;
        };
        $expenseId = $createExpense('PAY-001');
        $secondExpenseId = $createExpense('PAY-002');
        $pdo->prepare(
            'INSERT INTO imports_bancaires
             (organisation_id, dossier_id, compte_tresorerie_id, format,
              nom_fichier, empreinte_source, contenu_source, nb_total, statut,
              nb_importees)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, 1)'
        )->execute([
            $organisationId,
            $dossierId,
            $treasuryId,
            'camt053',
            'releve.xml',
            hash('sha256', '<camt>source</camt>'),
            '<camt>source</camt>',
            'confirme',
        ]);
        $importId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO lignes_bancaires
             (organisation_id, dossier_id, compte_tresorerie_id, import_id,
              empreinte, date_comptabilisation, libelle, montant_centimes,
              frais_centimes, monnaie)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $organisationId,
            $dossierId,
            $treasuryId,
            $importId,
            hash('sha256', 'lot-payment-line'),
            '2026-03-20',
            'LOT FOURNISSEUR',
            -11835,
            25,
            'CHF',
        ]);
        $bankLineId = (int) $pdo->lastInsertId();
        $payments = new PaymentService($pdo, $audit, $entries);
        $this->throws(
            fn () => $payments->create(
                $organisationId,
                $dossierId,
                $foreignSupplier,
                'decaissement',
                '2026-03-20',
                100,
                'HORS-SCOPE',
                $bankAccount
            ),
            'paiement inter-dossiers refusé avant toute allocation'
        );
        $reconciliations = new ReconciliationService($pdo, $audit);
        $outgoing = new OutgoingPaymentService(
            $pdo,
            $audit,
            $entries,
            $payments,
            $reconciliations,
            new Pain001Generator()
        );
        $batchId = $outgoing->prepare(
            $organisationId,
            $dossierId,
            $treasuryId,
            '2026-03-20',
            [
                ['document_id' => $expenseId, 'amount_cents' => 10810],
                ['document_id' => $secondExpenseId, 'amount_cents' => 1000],
            ],
            'lot-paiement-001'
        );
        $this->same(
            $batchId,
            $outgoing->prepare(
                $organisationId,
                $dossierId,
                $treasuryId,
                '2026-03-20',
                [
                    ['document_id' => $expenseId, 'amount_cents' => 10810],
                    ['document_id' => $secondExpenseId, 'amount_cents' => 1000],
                ],
                'lot-paiement-001'
            ),
            'préparation pain.001 idempotente'
        );
        $this->throws(
            fn () => $outgoing->prepare(
                $organisationId,
                $dossierId,
                $treasuryId,
                '2026-03-21',
                [['document_id' => $expenseId, 'amount_cents' => 10810]],
                'lot-paiement-001'
            ),
            'clé idempotente réutilisée avec un autre lot refusée'
        );
        $this->same(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM paiements')->fetchColumn(),
            'lot préparé sans marquer la dette payée'
        );
        $export = $outgoing->export(
            $organisationId,
            $dossierId,
            $batchId,
            1
        );
        $this->true(
            str_contains($export['content'], 'pain.001.001.09')
            && str_contains($export['content'], '<NbOfTxs>2</NbOfTxs>'),
            'pain.001 SPS 2026 généré avec le profil ISO courant'
        );
        $this->same(
            2,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM ordres_paiement_sortants
                 WHERE lot_id = {$batchId}"
            )->fetchColumn(),
            'paiement groupé conservé comme deux ordres distincts'
        );
        $this->same(
            hash('sha256', $export['content']),
            $export['hash'],
            'archive pain.001 conservée avec empreinte'
        );
        $this->same(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM paiements')->fetchColumn(),
            'export pain.001 toujours non transmis et non payé'
        );
        $reconciliationId = $outgoing->confirmFromStatement(
            $organisationId,
            $dossierId,
            $batchId,
            $bankLineId,
            $exercise,
            $journal,
            $expenseAccount
        );
        $this->same(
            'confirme',
            (string) $pdo->query(
                "SELECT statut FROM lots_paiements_sortants WHERE id = {$batchId}"
            )->fetchColumn(),
            'lot confirmé uniquement par le relevé'
        );
        $this->same(
            10810,
            (int) $pdo->query(
                "SELECT SUM(montant_centimes) FROM allocations
                 WHERE document_id = {$expenseId} AND statut = 'valide'"
            )->fetchColumn(),
            'dette fournisseur lettrée au centime'
        );
        $this->same(
            25,
            (int) $pdo->query(
                "SELECT frais_centimes FROM lots_paiements_sortants WHERE id = {$batchId}"
            )->fetchColumn(),
            'frais bancaires comptabilisés séparément'
        );
        $this->same(
            9810,
            (int) $pdo->query(
                "SELECT abs(total_brut_centimes) - COALESCE((
                    SELECT SUM(montant_centimes) FROM allocations
                    WHERE document_id = {$secondExpenseId} AND statut = 'valide'
                 ), 0)
                 FROM documents_financiers WHERE id = {$secondExpenseId}"
            )->fetchColumn(),
            'facture fournisseur partiellement réglée conserve son solde'
        );
        $this->throws(
            fn () => BankCoordinates::assertIban('CH00 0000 0000 0000 0000 0'),
            'IBAN invalide refusé avant préparation'
        );
        $this->throws(
            fn () => BankCoordinates::assertBic('12FICHBEXXX'),
            'BIC invalide refusé avant préparation'
        );
        $bankAccountingLines = $pdo->query(
            "SELECT rc.ligne_ecriture_id
             FROM rapprochement_lignes_comptables rc
             WHERE rc.rapprochement_id = {$reconciliationId} AND rc.actif = 1"
        )->fetchAll(PDO::FETCH_COLUMN);
        $reconciliations->cancel(
            $organisationId,
            $dossierId,
            $reconciliationId,
            1
        );
        $newReconciliation = $reconciliations->reconcile(
            $organisationId,
            $dossierId,
            $treasuryId,
            [$bankLineId],
            array_map('intval', $bankAccountingLines)
        );
        $this->true(
            $newReconciliation !== $reconciliationId,
            'annulation auditée libérant les lignes pour un nouveau rapprochement'
        );
        $manualPayment = $payments->create(
            $organisationId,
            $dossierId,
            $supplier,
            'decaissement',
            '2026-03-21',
            1000,
            'PARTIEL',
            $bankAccount
        );
        $allocation = $payments->allocatePayment(
            $organisationId,
            $dossierId,
            $manualPayment,
            $secondExpenseId,
            1000
        );
        $payments->unallocate(
            $organisationId,
            $dossierId,
            $allocation
        );
        $this->same(
            'annule',
            (string) $pdo->query(
                "SELECT statut FROM allocations WHERE id = {$allocation}"
            )->fetchColumn(),
            'délettrage autorisé avant clôture'
        );
        $secondAllocation = $payments->allocatePayment(
            $organisationId,
            $dossierId,
            $manualPayment,
            $secondExpenseId,
            1000
        );
        $setup->closePeriod($organisationId, $dossierId, $period);
        $this->throws(
            fn () => $payments->unallocate(
                $organisationId,
                $dossierId,
                $secondAllocation
            ),
            'délettrage refusé en période close'
        );
        $this->throws(
            fn () => $reconciliations->cancel(
                $organisationId,
                $dossierId,
                $newReconciliation,
                1
            ),
            'annulation de rapprochement refusée en période close'
        );
        $pdo->exec('BEGIN IMMEDIATE');
        $pdo->exec('ROLLBACK');
        $this->same(
            'confirme',
            (string) $pdo->query(
                "SELECT statut FROM rapprochements_bancaires
                 WHERE id = {$newReconciliation}"
            )->fetchColumn(),
            'refus d’annulation atomique sans transaction résiduelle'
        );
        $this->same(
            3,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM permissions
                 WHERE code IN ('paiements.prepare', 'paiements.export', 'paiements.confirm')"
            )->fetchColumn(),
            'séparation des permissions préparer, exporter et confirmer'
        );
        $this->true(
            IntegrityChecker::check($pdo)['ok'],
            'intégrité après paiements sortants et lettrage'
        );
    }

    private function camtFixture(string $message, string $version, string $container): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.{$message}.001.{$version}">
  <BkToCstmr>
    <{$container}>
      <Acct><Id><IBAN>CH9300762011623852957</IBAN></Id><Ccy>CHF</Ccy></Acct>
      <Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp><Amt Ccy="CHF">1000.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-01-31</Dt></Dt></Bal>
      <Ntry>
        <Amt Ccy="CHF">200.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
        <BookgDt><Dt>2026-01-31</Dt></BookgDt><ValDt><Dt>2026-01-30</Dt></ValDt>
        <NtryRef>GROUP-1</NtryRef>
        <BkTxCd><Domn><Cd>PMNT</Cd><Fmly><Cd>RCDT</Cd><SubFmlyCd>DMCT</SubFmlyCd></Fmly></Domn></BkTxCd>
        <NtryDtls>
          <TxDtls>
            <Refs><AcctSvcrRef>BANK-1</AcctSvcrRef><EndToEndId>E2E-1</EndToEndId></Refs>
            <AmtDtls><TxAmt><Amt Ccy="CHF">120.00</Amt></TxAmt></AmtDtls>
            <RltdPties><Dbtr><Nm>Client SA</Nm></Dbtr><DbtrAcct><Id><IBAN>CH5604835012345678009</IBAN></Id></DbtrAcct></RltdPties>
            <RmtInf><Ustrd>Facture 42</Ustrd><Strd><CdtrRefInf><Tp><CdOrPrtry><Cd>SCOR</Cd></CdOrPrtry></Tp><Ref>RF18539007547034</Ref></CdtrRefInf></Strd></RmtInf>
            <ChrgsInf><Amt Ccy="CHF">1.25</Amt></ChrgsInf>
          </TxDtls>
          <TxDtls>
            <Refs><AcctSvcrRef>BANK-2</AcctSvcrRef></Refs>
            <AmtDtls><TxAmt><Amt Ccy="CHF">80.00</Amt></TxAmt></AmtDtls>
            <RmtInf><Strd><CdtrRefInf><Tp><CdOrPrtry><Cd>QRR</Cd></CdOrPrtry></Tp><Ref>210000000003139471430009017</Ref></CdtrRefInf></Strd></RmtInf>
          </TxDtls>
        </NtryDtls>
      </Ntry>
    </{$container}>
  </BkToCstmr>
</Document>
XML;
    }

    private function vatTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $ids = $this->seedScopes($pdo);
        $audit = new AuditLogger($pdo);
        $scope = new ScopeManager($pdo, $audit);
        $exercise2026 = $scope->createExercise(
            $ids['dossier_a'],
            'Exercice 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $exerciseB = $scope->createExercise(
            $ids['dossier_b'],
            'Exercice 2026',
            '2026-01-01',
            '2026-12-31'
        );
        $setup = new AccountingSetupService($pdo, $audit);
        $setup->createPeriod(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $exercise2026,
            '2026',
            '2026-01-01',
            '2026-12-31'
        );
        $setup->createPeriod(
            $ids['organisation_b'],
            $ids['dossier_b'],
            $exerciseB,
            '2026',
            '2026-01-01',
            '2026-12-31'
        );
        $journal = $setup->createJournal(
            $ids['organisation_a'],
            $ids['dossier_a'],
            'TVA',
            'TVA',
            'general'
        );
        $setup->createJournal(
            $ids['organisation_b'],
            $ids['dossier_b'],
            'TVA',
            'TVA',
            'general'
        );
        $seeder = new PlanSeeder($pdo, dirname(__DIR__) . '/database/seeds');
        $seeder->installForDossier(
            $ids['organisation_a'],
            $ids['dossier_a'],
            'personne_morale'
        );
        $seeder->installForDossier(
            $ids['organisation_b'],
            $ids['dossier_b'],
            'personne_morale'
        );
        $account = fn (int $dossier, string $number): int =>
            $this->accountId($pdo, $dossier, $number);
        $bank = $account($ids['dossier_a'], '1020');
        $receivable = $account($ids['dossier_a'], '1100');
        $inputMaterial = $account($ids['dossier_a'], '1170');
        $inputInvestments = $account($ids['dossier_a'], '1171');
        $payable = $account($ids['dossier_a'], '2000');
        $vatDue = $account($ids['dossier_a'], '2200');
        $vatSettlement = $account($ids['dossier_a'], '2201');
        $revenue = $account($ids['dossier_a'], '3400');
        $expense = $account($ids['dossier_a'], '6500');
        $config = new VatConfigurationService($pdo, $audit);
        $regimeEffective = $config->addRegime([
            'organisation_id' => $ids['organisation_a'],
            'dossier_id' => $ids['dossier_a'],
            'statut' => 'assujetti',
            'numero_tva' => 'CHE-123.456.789 TVA',
            'methode' => 'effective',
            'mode_decompte' => 'convenues',
            'periodicite' => 'trimestrielle',
            'date_debut' => '2026-01-01',
            'compte_impot_prealable_materiel_id' => $inputMaterial,
            'compte_impot_prealable_investissements_id' => $inputInvestments,
            'compte_tva_due_id' => $vatDue,
            'compte_decompte_tva_id' => $vatSettlement,
            'compte_corrections_id' => $expense,
        ]);
        $config->addRegime([
            'organisation_id' => $ids['organisation_b'],
            'dossier_id' => $ids['dossier_b'],
            'statut' => 'non_assujetti',
            'methode' => 'effective',
            'mode_decompte' => 'convenues',
            'periodicite' => 'trimestrielle',
            'date_debut' => '2026-01-01',
        ]);
        $rates = [];
        foreach ($pdo->query(
            "SELECT id, categorie FROM tva_taux_legaux WHERE date_debut = '2024-01-01'"
        )->fetchAll() as $row) {
            $rates[$row['categorie']] = (int) $row['id'];
        }
        $addCode = function (
            string $code,
            string $label,
            string $treatment,
            string $nature,
            ?int $rate,
            string $box = '',
            ?int $vatAccount = null,
            bool $deductible = false,
            int $deduction = 0,
            ?int $organisation = null,
            ?int $dossier = null,
        ) use ($config, $ids): int {
            return $config->addCode([
                'organisation_id' => $organisation ?? $ids['organisation_a'],
                'dossier_id' => $dossier ?? $ids['dossier_a'],
                'code' => $code,
                'libelle' => $label,
                'traitement' => $treatment,
                'nature' => $nature,
                'taux_legal_id' => $rate,
                'droit_deduction' => $deductible,
                'deduction_defaut_bp' => $deduction,
                'chiffre_afc' => $box,
                'compte_tva_id' => $vatAccount,
                'date_debut' => '2024-01-01',
            ]);
        };
        $saleNormal = $addCode(
            'VN81', 'Ventes 8,1 %', 'normal', 'collectee', $rates['normal'], '', $vatDue
        );
        $saleReduced = $addCode(
            'VR26', 'Ventes 2,6 %', 'reduit', 'collectee', $rates['reduit'], '', $vatDue
        );
        $saleSpecial = $addCode(
            'VS38', 'Hébergement 3,8 %', 'special', 'collectee', $rates['special'], '', $vatDue
        );
        $inputCode = $addCode(
            'AM81', 'Achats matériel 8,1 %', 'normal', 'prealable',
            $rates['normal'], '400', $inputMaterial, true, 10000
        );
        $exempt = $addCode('EXO', 'Exonéré', 'exonere', 'non_taxable', null, '220');
        $excluded = $addCode('EXC', 'Exclu', 'exclu', 'non_taxable', null, '230');
        $outside = $addCode('HCH', 'Hors champ', 'hors_champ', 'non_taxable', null, '221');
        $foreignVatDue = $account($ids['dossier_b'], '2200');
        $foreignSale = $addCode(
            'VN81', 'Ventes 8,1 %', 'normal', 'collectee', $rates['normal'],
            '', $foreignVatDue, false, 0,
            $ids['organisation_b'], $ids['dossier_b']
        );

        $calculator = new VatCalculator();
        $this->same(810, $calculator->calculate(10000, 810, 'net')['vat_cents'], 'net → TVA au taux normal');
        $this->same(10000, $calculator->calculate(10810, 810, 'brut')['net_cents'], 'brut → net au taux normal');
        $this->same(260, $calculator->calculate(10000, 260, 'net')['vat_cents'], 'taux réduit en centimes');
        $this->same(380, $calculator->calculate(10000, 380, 'net')['vat_cents'], 'taux spécial en centimes');
        $this->same(729, $calculator->calculate(9000, 810, 'net')['vat_cents'], 'rabais avant TVA déterministe');
        $this->same(-810, $calculator->calculate(-10000, 810, 'net')['vat_cents'], 'avoir total négatif');
        $this->same(-405, $calculator->calculate(-5000, 810, 'net')['vat_cents'], 'avoir partiel négatif');
        $this->same(
            1070,
            $calculator->calculate(10000, 810, 'net')['vat_cents']
                + $calculator->calculate(10000, 260, 'net')['vat_cents'],
            'arrondi indépendant de plusieurs taux'
        );

        $vatLines = new VatLineService($pdo, $audit);
        $this->same(
            0,
            $vatLines->quote(
                $ids['organisation_b'],
                $ids['dossier_b'],
                $foreignSale,
                '2026-02-01',
                10000,
                'net'
            )['vat_cents'],
            'non-assujetti : aucune TVA malgré un code imposable'
        );
        $this->same(
            ['exonere', 'exclu', 'hors_champ'],
            [
                $vatLines->quote($ids['organisation_a'], $ids['dossier_a'], $exempt, '2026-02-01', 100, 'net')['treatment'],
                $vatLines->quote($ids['organisation_a'], $ids['dossier_a'], $excluded, '2026-02-01', 100, 'net')['treatment'],
                $vatLines->quote($ids['organisation_a'], $ids['dossier_a'], $outside, '2026-02-01', 100, 'net')['treatment'],
            ],
            'exonéré, exclu et hors champ restent distincts'
        );
        $halfDeduction = $vatLines->quote(
            $ids['organisation_a'],
            $ids['dossier_a'],
            $inputCode,
            '2026-02-01',
            5000,
            'net',
            5000,
            'Affectation mixte documentée'
        );
        $this->same(203, $halfDeduction['deductible_vat_cents'], 'impôt préalable partiellement déductible arrondi');
        $this->same(
            0,
            $vatLines->quote(
                $ids['organisation_a'], $ids['dossier_a'], $inputCode,
                '2026-02-01', 5000, 'net', 0, 'Aucune affectation entrepreneuriale'
            )['deductible_vat_cents'],
            'déduction nulle explicite'
        );
        $this->throws(
            fn () => $vatLines->quote(
                $ids['organisation_a'], $ids['dossier_a'], $inputCode,
                '2026-02-01', 5000, 'net', 5000
            ),
            'déduction dérogatoire sans motif refusée'
        );
        $this->throws(
            fn () => $vatLines->quote(
                $ids['organisation_b'], $ids['dossier_b'], $saleNormal,
                '2026-02-01', 10000, 'net'
            ),
            'code TVA d’une autre organisation refusé'
        );

        $entries = new EntryService($pdo, $audit);
        $posting = new VatPostingService($pdo, $audit);
        $postEntry = function (
            string $key,
            string $date,
            array $lines,
            int $exercise
        ) use ($entries, $ids, $journal): int {
            return $entries->postGenerated([
                'organisation_id' => $ids['organisation_a'],
                'dossier_id' => $ids['dossier_a'],
                'exercice_id' => $exercise,
                'journal_id' => $journal,
                'date_comptable' => $date,
                'libelle' => 'Test TVA ' . $key,
                'source_type' => 'test_tva',
                'source_id' => $key,
                'source_action' => 'comptabilisation',
                'lignes' => $lines,
            ], 'test-vat:' . $key);
        };
        $lineFor = static function (PDO $pdo, int $entry, int $accountId): int {
            $stmt = $pdo->prepare(
                'SELECT id FROM lignes_ecriture WHERE ecriture_id = ? AND compte_id = ?'
            );
            $stmt->execute([$entry, $accountId]);
            return (int) $stmt->fetchColumn();
        };
        $sale = $posting->sale(
            $ids['organisation_a'], $ids['dossier_a'], $saleNormal,
            '2026-02-10', 10000, 'net', $receivable, $revenue, $vatDue
        );
        $saleEntry = $postEntry('sale-q1', '2026-02-10', $sale['lines'], $exercise2026);
        $saleVatLine = $vatLines->attach(
            $ids['organisation_a'], $ids['dossier_a'],
            $lineFor($pdo, $saleEntry, $revenue), $saleNormal,
            '2026-02-10', 10000, 'net'
        );
        $purchase = $posting->purchase(
            $ids['organisation_a'], $ids['dossier_a'], $inputCode,
            '2026-02-20', 5000, 'net', $payable, $expense, $inputMaterial
        );
        $purchaseEntry = $postEntry('purchase-q1', '2026-02-20', $purchase['lines'], $exercise2026);
        $vatLines->attach(
            $ids['organisation_a'], $ids['dossier_a'],
            $lineFor($pdo, $purchaseEntry, $expense), $inputCode,
            '2026-02-20', 5000, 'net'
        );
        $statements = new VatStatementService($pdo, $audit);
        $q1 = $statements->createPeriod(
            $ids['organisation_a'], $ids['dossier_a'], '2026-01-01', '2026-03-31'
        );
        $q1Statement = $statements->prepare(
            $ids['organisation_a'], $ids['dossier_a'], $q1
        );
        $q1Row = $pdo->query(
            "SELECT * FROM tva_decomptes WHERE id = {$q1Statement}"
        )->fetch();
        $this->same(810, (int) $q1Row['tva_due_centimes'], 'TVA collectée du décompte effectif');
        $this->same(405, (int) $q1Row['impot_prealable_centimes'], 'impôt préalable du décompte effectif');
        $this->same(405, (int) $q1Row['solde_centimes'], 'solde du décompte effectif');
        $reconciliation = $statements->generalLedgerReconciliation(
            $ids['organisation_a'], $ids['dossier_a'], $q1Statement
        );
        $this->same(0, $reconciliation['vat_due_difference_cents'], 'décompte ↔ grand livre TVA due');
        $this->same(0, $reconciliation['input_tax_difference_cents'], 'décompte ↔ grand livre impôt préalable');
        $this->same(2, count($statements->drillDown(
            $ids['organisation_a'], $ids['dossier_a'], $q1Statement
        )), 'drill-down jusqu’aux écritures sources');
        $statements->control(
            $ids['organisation_a'], $ids['dossier_a'], $q1Statement
        );
        $validator = new Ech0217Validator(
            dirname(__DIR__) . '/resources/xsd/ech-0217-2-0-0-current-profile.xsd'
        );
        $exporter = new Ech0217ExportService(
            $pdo,
            $audit,
            $validator,
            trim((string) file_get_contents(dirname(__DIR__) . '/VERSION'))
        );
        $export = $exporter->export(
            $ids['organisation_a'], $ids['dossier_a'], $q1Statement
        );
        $this->true($export['schema_valid'], 'export eCH-0217 2.0.0 validé par le profil XSD');
        $this->true(
            str_contains($export['xml'], '<eCH-0217:effectiveReportingMethod>'),
            'méthode effective présente dans l’e-TVA'
        );
        $this->false($export['transmitted'], 'export jamais présenté comme transmis');
        $this->same([], $validator->validate($export['xml']), 'fixture eCH générée valide');
        $this->same(
            [],
            $validator->validate((string) file_get_contents(
                dirname(__DIR__) . '/tests/fixtures/ech0217-effective-valid.xml'
            )),
            'fixture eCH versionnée valide'
        );
        $this->true(
            $validator->validate(str_replace('CHE123456789', 'CHE000000000', $export['xml'])) !== [],
            'mutation UID rejetée par validation XSD'
        );
        $this->true(
            $validator->validate(str_replace(
                '<eCH-0217:payableTax>4.05</eCH-0217:payableTax>',
                '',
                $export['xml']
            )) !== [],
            'mutation supprimant un élément obligatoire rejetée'
        );
        $statements->markDeclared(
            $ids['organisation_a'], $ids['dossier_a'], $q1Statement
        );
        $originalHash = hash('sha256', json_encode($pdo->query(
            "SELECT * FROM tva_decomptes WHERE id = {$q1Statement}"
        )->fetch()));
        $rectification = $statements->prepare(
            $ids['organisation_a'], $ids['dossier_a'], $q1, $q1Statement
        );
        $this->same(
            1,
            (int) $pdo->query(
                "SELECT numero_correction FROM tva_decomptes WHERE id = {$rectification}"
            )->fetchColumn(),
            'décompte rectificatif distinct'
        );
        $this->same(
            $originalHash,
            hash('sha256', json_encode($pdo->query(
                "SELECT * FROM tva_decomptes WHERE id = {$q1Statement}"
            )->fetch())),
            'décompte déclaré non muté par le rectificatif'
        );
        $settlementEntry = (new VatSettlementService(
            $pdo,
            $entries,
            $statements
        ))->post(
            $ids['organisation_a'], $ids['dossier_a'], $q1Statement,
            $exercise2026, $journal, '2026-04-15'
        );
        $this->same(
            0,
            (int) $pdo->query(
                "SELECT SUM(debit_centimes-credit_centimes)
                 FROM lignes_ecriture WHERE ecriture_id = {$settlementEntry}"
            )->fetchColumn(),
            'comptabilisation du décompte équilibrée via 2201'
        );

        $receivedRegime = $config->addRegime([
            'organisation_id' => $ids['organisation_a'],
            'dossier_id' => $ids['dossier_a'],
            'statut' => 'assujetti',
            'numero_tva' => 'CHE123456789TVA',
            'methode' => 'effective',
            'mode_decompte' => 'recues',
            'periodicite' => 'trimestrielle',
            'date_debut' => '2026-07-01',
            'fermer_precedent' => true,
            'compte_impot_prealable_materiel_id' => $inputMaterial,
            'compte_impot_prealable_investissements_id' => $inputInvestments,
            'compte_tva_due_id' => $vatDue,
            'compte_decompte_tva_id' => $vatSettlement,
            'compte_corrections_id' => $expense,
        ]);
        $saleReceived = $posting->sale(
            $ids['organisation_a'], $ids['dossier_a'], $saleNormal,
            '2026-07-15', 10000, 'net', $receivable, $revenue, $vatDue
        );
        $receivedEntry = $postEntry(
            'sale-received', '2026-07-15', $saleReceived['lines'], $exercise2026
        );
        $receivedVatLine = $vatLines->attach(
            $ids['organisation_a'], $ids['dossier_a'],
            $lineFor($pdo, $receivedEntry, $revenue), $saleNormal,
            '2026-07-15', 10000, 'net'
        );
        $vatLines->recordPayment(
            $ids['organisation_a'], $ids['dossier_a'], $receivedVatLine,
            '2026-08-15', 5405, 'paiement', 'PAY-1'
        );
        $h2 = $statements->createPeriod(
            $ids['organisation_a'], $ids['dossier_a'], '2026-07-01', '2026-12-31'
        );
        $receivedStatement = $statements->prepare(
            $ids['organisation_a'], $ids['dossier_a'], $h2
        );
        $receivedRow = $pdo->query(
            "SELECT * FROM tva_decomptes WHERE id = {$receivedStatement}"
        )->fetch();
        $this->same(405, (int) $receivedRow['tva_due_centimes'], 'mode reçu : TVA du paiement partiel seulement');
        $this->same(5405, (int) $receivedRow['total_chiffre_affaires_centimes'], 'mode reçu : brut encaissé partiel');
        $this->same(
            'convenues',
            (string) $pdo->query(
                "SELECT mode_decompte_snapshot FROM tva_decomptes WHERE id = {$q1Statement}"
            )->fetchColumn(),
            'changement de mode sans effet rétroactif'
        );
        $this->throws(
            fn () => $vatLines->recordPayment(
                $ids['organisation_a'], $ids['dossier_a'], $receivedVatLine,
                '2026-09-01', 6000, 'paiement', 'PAY-OVER'
            ),
            'sur-allocation d’un paiement refusée'
        );

        $exercise2027 = $scope->createExercise(
            $ids['dossier_a'],
            'Exercice 2027',
            '2027-01-01',
            '2027-12-31'
        );
        $setup->createPeriod(
            $ids['organisation_a'], $ids['dossier_a'], $exercise2027,
            '2027', '2027-01-01', '2027-12-31'
        );
        $config->addRegime([
            'organisation_id' => $ids['organisation_a'],
            'dossier_id' => $ids['dossier_a'],
            'statut' => 'assujetti',
            'numero_tva' => 'CHE123456789TVA',
            'methode' => 'tdfn',
            'mode_decompte' => 'convenues',
            'periodicite' => 'semestrielle',
            'date_debut' => '2027-01-01',
            'fermer_precedent' => true,
            'compte_impot_prealable_materiel_id' => $inputMaterial,
            'compte_impot_prealable_investissements_id' => $inputInvestments,
            'compte_tva_due_id' => $vatDue,
            'compte_decompte_tva_id' => $vatSettlement,
            'compte_corrections_id' => $expense,
        ]);
        $tdfn = $config->addTdfn([
            'organisation_id' => $ids['organisation_a'],
            'dossier_id' => $ids['dossier_a'],
            'activite_id' => '00001',
            'activite' => 'Conseil',
            'taux_bp' => 620,
            'date_debut' => '2027-01-01',
            'autorisation_reference' => 'AFC-TDFN-TEST',
        ]);
        $tdfnQuote = $vatLines->quote(
            $ids['organisation_a'], $ids['dossier_a'], $saleNormal,
            '2027-02-01', 10000, 'net', tdfnId: $tdfn
        );
        $this->same(810, $tdfnQuote['vat_cents'], 'TDFN : taux légal maintenu sur facture');
        $this->same(
            0,
            $vatLines->quote(
                $ids['organisation_a'], $ids['dossier_a'], $inputCode,
                '2027-02-01', 5000, 'net'
            )['deductible_vat_cents'],
            'TDFN : aucun impôt préalable ordinaire déduit'
        );
        $tdfnPosting = $posting->sale(
            $ids['organisation_a'], $ids['dossier_a'], $saleNormal,
            '2027-02-01', 10000, 'net', $receivable, $revenue, $vatDue, $tdfn
        );
        $tdfnEntry = $postEntry(
            'sale-tdfn', '2027-02-01', $tdfnPosting['lines'], $exercise2027
        );
        $vatLines->attach(
            $ids['organisation_a'], $ids['dossier_a'],
            $lineFor($pdo, $tdfnEntry, $revenue), $saleNormal,
            '2027-02-01', 10000, 'net', tdfnId: $tdfn
        );
        $tdfnPeriod = $statements->createPeriod(
            $ids['organisation_a'], $ids['dossier_a'], '2027-01-01', '2027-06-30'
        );
        $tdfnStatement = $statements->prepare(
            $ids['organisation_a'], $ids['dossier_a'], $tdfnPeriod
        );
        $this->same(
            670,
            (int) $pdo->query(
                "SELECT tva_due_centimes FROM tva_decomptes WHERE id = {$tdfnStatement}"
            )->fetchColumn(),
            'TDFN accordé calculé sur chiffre d’affaires brut'
        );
        $statements->control(
            $ids['organisation_a'], $ids['dossier_a'], $tdfnStatement
        );
        $tdfnExport = $exporter->export(
            $ids['organisation_a'], $ids['dossier_a'], $tdfnStatement
        );
        $this->true(
            str_contains($tdfnExport['xml'], '<eCH-0217:simpleTaxRateMethod>'),
            'TDFN 2027 exportée via simpleTaxRateMethod'
        );
        $pdo->exec(
            "INSERT INTO tva_taux_legaux
             (categorie, libelle, taux_bp, date_debut, source_url, verifie_le)
             VALUES ('normal', 'Taux futur test', 900, '2028-01-01',
                     'test:transition', '2026-07-25')"
        );
        $this->same(
            900,
            $vatLines->quote(
                $ids['organisation_a'], $ids['dossier_a'], $saleNormal,
                '2028-02-01', 10000, 'net', tdfnId: $tdfn
            )['rate_bp'],
            'taux sélectionné selon la date de prestation'
        );
        $this->same(
            810,
            (int) $pdo->query(
                "SELECT taux_legal_snapshot_bp FROM tva_lignes WHERE id = {$saleVatLine}"
            )->fetchColumn(),
            'changement de taux sans réécriture du snapshot'
        );
        $this->true($regimeEffective > 0 && $receivedRegime > 0, 'historique des régimes daté');
        $this->true(IntegrityChecker::check($pdo)['ok'], 'intégrité après TVA');
    }

    /** @return array{0:PDO,1:MigrationRunner,2:string} */
    private function database(): array
    {
        $directory = $this->tempDir();
        $path = $directory . '/app.sqlite';
        $pdo = ConnectionFactory::sqlite($path);
        return [
            $pdo,
            new MigrationRunner($pdo, dirname(__DIR__) . '/database/migrations'),
            $path,
        ];
    }

    /** @return array{organisation_a:int,organisation_b:int,dossier_a:int,dossier_b:int} */
    private function seedScopes(PDO $pdo): array
    {
        $pdo->exec(
            "INSERT INTO organisations (nom, nature) VALUES
                ('Organisation A', 'pedagogique'),
                ('Organisation B', 'reelle')"
        );
        $organisationA = (int) $pdo->query(
            "SELECT id FROM organisations WHERE nom = 'Organisation A'"
        )->fetchColumn();
        $organisationB = (int) $pdo->query(
            "SELECT id FROM organisations WHERE nom = 'Organisation B'"
        )->fetchColumn();
        $stmt = $pdo->prepare(
            'INSERT INTO dossiers (organisation_id, nom, slug, type) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$organisationA, 'Exercice A', 'exercice-a', 'exercice']);
        $dossierA = (int) $pdo->lastInsertId();
        $stmt->execute([$organisationB, 'Comptabilité B', 'compta-b', 'reel']);
        $dossierB = (int) $pdo->lastInsertId();
        return [
            'organisation_a' => $organisationA,
            'organisation_b' => $organisationB,
            'dossier_a' => $dossierA,
            'dossier_b' => $dossierB,
        ];
    }

    private function accountId(PDO $pdo, int $dossierId, string $number): int
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM comptes WHERE dossier_id = ? AND numero = ?'
        );
        $stmt->execute([$dossierId, $number]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new RuntimeException("Compte {$number} absent du dossier de test.");
        }
        return (int) $id;
    }

    /** @param list<array<string,mixed>> $rows */
    private function balanceFor(array $rows, string $number): int
    {
        foreach ($rows as $row) {
            if ((string) $row['numero'] === $number) {
                return (int) $row['solde_centimes'];
            }
        }
        throw new RuntimeException("Compte {$number} absent de la balance.");
    }

    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . '/compta-test-' . bin2hex(random_bytes(6));
        mkdir($path, 0770, true);
        $this->temporaryDirectories[] = $path;
        return $path;
    }

    private function true(bool $actual, string $message): void
    {
        $this->assertions++;
        if (!$actual) {
            $this->failures++;
            echo "  ECHEC {$message}\n";
            return;
        }
        echo "  ok    {$message}\n";
    }

    private function false(bool $actual, string $message): void
    {
        $this->true(!$actual, $message);
    }

    private function same(mixed $expected, mixed $actual, string $message): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $this->failures++;
            echo '  ECHEC ' . $message . ' attendu=' . var_export($expected, true)
                . ' reçu=' . var_export($actual, true) . "\n";
            return;
        }
        echo "  ok    {$message}\n";
    }

    /** @return array<string, mixed> */
    private function responseJson(Response $response): array
    {
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    private function throws(callable $callback, string $message): void
    {
        try {
            $callback();
        } catch (Throwable) {
            $this->true(true, $message);
            return;
        }
        $this->true(false, $message);
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = scandir($directory) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}

$suite = 'all';
$caseFilter = '';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--suite=')) {
        $suite = substr($argument, strlen('--suite='));
    } elseif (str_starts_with($argument, '--case=')) {
        $caseFilter = substr($argument, strlen('--case='));
    }
}
exit((new Tests())->run($suite, $caseFilter));
