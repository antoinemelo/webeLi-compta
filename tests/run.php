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
use Compta\Modules\Compta\AccountingSetupService;
use Compta\Modules\Compta\ChartOfAccountsService;
use Compta\Modules\Compta\EntryService;
use Compta\Modules\Compta\PlanSeeder;
use Compta\Modules\Compta\ReportingService;
use Compta\Modules\Dashboard\Application\DashboardReadService;
use Compta\Modules\Dashboard\Http\DashboardApiController;
use Compta\Modules\Dashboard\Http\DashboardInputValidator;
use Compta\Modules\Dossiers\ScopeManager;
use Compta\Modules\Facturation\BillingService;
use Compta\Modules\Facturation\ContactService;
use Compta\Modules\Facturation\AttachmentService;
use Compta\Modules\Facturation\InvoicePdfService;
use Compta\Modules\Facturation\PaymentService;
use Compta\Modules\Facturation\ScorReference;
use Compta\Modules\Facturation\SwissQrService;
use Compta\Modules\Tresorerie\BankImportService;
use Compta\Modules\Tresorerie\InternalTransferService;
use Compta\Modules\Tresorerie\Parsing\Camt053Parser;
use Compta\Modules\Tresorerie\Parsing\Camt054Parser;
use Compta\Modules\Tresorerie\ReconciliationService;
use Compta\Modules\Tresorerie\SuggestionService;
use Compta\Modules\Tresorerie\TreasuryAccountService;
use Compta\Modules\Tresorerie\TreasuryStateService;
use Compta\Modules\Tva\Ech0217ExportService;
use Compta\Modules\Tva\Ech0217Validator;
use Compta\Modules\Tva\VatCalculator;
use Compta\Modules\Tva\VatConfigurationService;
use Compta\Modules\Tva\VatLineService;
use Compta\Modules\Tva\VatPostingService;
use Compta\Modules\Tva\VatSettlementService;
use Compta\Modules\Tva\VatStatementService;
use Compta\Modules\Salaires\PayrollCalculator;
use Compta\Modules\Salaires\PayrollCertificateService;
use Compta\Modules\Salaires\PayrollConfigurationService;
use Compta\Modules\Salaires\PayrollImportService;
use Compta\Modules\Salaires\PayrollPaymentService;
use Compta\Modules\Salaires\PayrollService;
use Compta\Modules\Pedagogie\PedagogyConflictException;
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

    public function run(string $suite = 'all'): int
    {
        if (!in_array($suite, ['quick', 'integration', 'all'], true)) {
            fwrite(STDERR, "Suite inconnue : {$suite}\n");
            return 2;
        }
        $cases = [
            'unités configuration et sécurité' => ['quick', fn () => $this->unitTests()],
            'parité des 32 calculs salaires Lasso' => [
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
            'comptabilité générale et rapports' => [
                'integration',
                fn () => $this->accountingTests(),
            ],
            'trésorerie, CAMT et rapprochements' => [
                'integration',
                fn () => $this->treasuryTests(),
            ],
            'TVA suisse effective, TDFN et eCH-0217' => [
                'integration',
                fn () => $this->vatTests(),
            ],
            'débiteurs, créanciers, paiements et QR-facture' => [
                'integration',
                fn () => $this->billingTests(),
            ],
            'projection du tableau de bord' => [
                'integration',
                fn () => $this->dashboardTests(),
            ],
            'HTTP et CSRF' => ['integration', fn () => $this->httpTests()],
            'diagnostic, sauvegarde et multi-instance' => [
                'integration',
                fn () => $this->operationsTests(),
            ],
        ];
        foreach ($cases as $name => [$caseSuite, $case]) {
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

        $session = new ArraySessionStore();
        $csrf = new Csrf($session);
        $token = $csrf->token();
        $this->true(strlen($token) === 64, 'jeton CSRF aléatoire');
        $this->true($csrf->validate($token), 'jeton CSRF accepté');
        $this->false($csrf->validate('incorrect'), 'jeton CSRF incorrect refusé');
        $_SERVER['REQUEST_URI'] = '/education/login';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = Request::fromGlobals('/edu');
        $this->same('/education/login', $request->path, 'base path ne coupe pas un préfixe voisin');
    }

    private function databaseTests(): void
    {
        [$pdo, $runner] = $this->database();
        $applied = $runner->apply();
        $this->same(
            ['001', '002', '003', '004', '005', '006', '007', '008', '009', '010'],
            $applied,
            'migrations initiales appliquées'
        );
        $this->same([], $runner->apply(), 'rejeu idempotent');
        $this->true(IntegrityChecker::check($pdo)['ok'], 'intégrité SQLite');
        $this->same('1', (string) $pdo->query('PRAGMA foreign_keys')->fetchColumn(), 'clés étrangères actives');
        $this->same('5000', (string) $pdo->query('PRAGMA busy_timeout')->fetchColumn(), 'busy timeout');
        $this->same('wal', mb_strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()), 'WAL actif');

        $upgradeDirectory = $this->tempDir() . '/migrations';
        mkdir($upgradeDirectory, 0770, true);
        foreach (range(1, 5) as $version) {
            $prefix = str_pad((string) $version, 3, '0', STR_PAD_LEFT);
            $source = glob(dirname(__DIR__) . "/database/migrations/{$prefix}_*.sql")[0];
            copy($source, $upgradeDirectory . '/' . basename($source));
        }
        $upgradePdo = ConnectionFactory::sqlite(
            $this->tempDir() . '/upgrade-plan.sqlite'
        );
        (new MigrationRunner($upgradePdo, $upgradeDirectory))->apply();
        $upgradePdo->exec(
            "INSERT INTO organisations (id, nom, nature)
                VALUES (1, 'Test reprise', 'pedagogique');
             INSERT INTO dossiers (id, organisation_id, nom, slug, type)
                VALUES (1, 1, 'Dossier', 'dossier', 'exercice');
             INSERT INTO comptes
                (id, organisation_id, dossier_id, numero, libelle, type,
                 sens_normal, sens_mode, parent_id, niveau, imputable)
                VALUES
                (1, 1, 1, '1', 'ACTIFS', 'actif', 'debit', 'automatique', NULL, 1, 0),
                (2, 1, 1, '10', 'Actif circulant', 'actif', 'debit', 'automatique', 1, 2, 0),
                (3, 1, 1, '100', 'Trésorerie', 'actif', 'debit', 'automatique', 2, 3, 0),
                (4, 1, 1, '1020', 'Avoirs en banque', 'actif', 'debit', 'automatique', 3, 4, 1),
                (5, 1, 1, '3', 'PRODUITS', 'produit', 'credit', 'automatique', NULL, 1, 0),
                (6, 1, 1, '3000', 'Ventes', 'produit', 'credit', 'automatique', 5, 4, 1);
             INSERT INTO rubriques_comptables
                (id, organisation_id, dossier_id, prefixe, libelle, type, ordre)
                VALUES
                (1, 1, 1, '1', 'ACTIFS', 'actif', 1),
                (2, 1, 1, '10', 'Actif circulant', 'actif', 2),
                (3, 1, 1, '100', 'Trésorerie', 'actif', 3),
                (4, 1, 1, '3', 'PRODUITS', 'produit', 4);"
        );
        foreach ([6, 7, 8, 9, 10] as $version) {
            $prefix = str_pad((string) $version, 3, '0', STR_PAD_LEFT);
            $source = glob(dirname(__DIR__) . "/database/migrations/{$prefix}_*.sql")[0];
            copy($source, $upgradeDirectory . '/' . basename($source));
        }
        (new MigrationRunner($upgradePdo, $upgradeDirectory))->apply();
        $this->same(
            '100',
            (string) $upgradePdo->query(
                "SELECT r.code
                 FROM comptes c JOIN rubriques_comptables r ON r.id = c.rubrique_id
                 WHERE c.numero = '1020'"
            )->fetchColumn(),
            'reprise 1020 vers son parent structurel 100'
        );
        $this->same(
            '30|groupe_principal|produit',
            (string) $upgradePdo->query(
                "SELECT r.code || '|' || r.niveau_structure || '|' || c.type
                 FROM comptes c JOIN rubriques_comptables r ON r.id = c.rubrique_id
                 WHERE c.numero = '3000'"
            )->fetchColumn(),
            'parent structurel réel et type hérités lors de la reprise'
        );

        $migrationDirectory = $this->tempDir() . '/migrations';
        mkdir($migrationDirectory, 0770, true);
        copy(dirname(__DIR__) . '/database/migrations/001_core.sql', $migrationDirectory . '/001_core.sql');
        $dbPath = $this->tempDir() . '/checksum.sqlite';
        $checksumPdo = ConnectionFactory::sqlite($dbPath);
        $checksumRunner = new MigrationRunner($checksumPdo, $migrationDirectory);
        $checksumRunner->apply();
        file_put_contents($migrationDirectory . '/001_core.sql', "\n-- mutation interdite\n", FILE_APPEND);
        $statuses = array_column($checksumRunner->plan(), 'status');
        $this->true(in_array('mismatch', $statuses, true), 'checksum modifié détecté');
        $this->throws(fn () => $checksumRunner->apply(), 'migration modifiée bloquée');
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
        $this->same(192000, $result['salaire_travail_centimes'], 'Lasso salaire_travail');
        $this->same(15994, $result['supplement_centimes'], 'Lasso supplément vacances');
        $this->same(207994, $result['brut_centimes'], 'Lasso salaire brut');
        $this->same(11024, $result['ded_avs_centimes'], 'Lasso AVS employé');
        $this->same(2205, $result['ded_ac_centimes'], 'Lasso AC employé');
        $this->same(60, $result['ded_amat_centimes'], 'Lasso A.mat employé');
        $this->same(2205, $result['ded_laa_centimes'], 'Lasso LAA employé');
        $this->same(14560, $result['ded_lpp_centimes'], 'Lasso LPP employé');
        $this->same(0, $result['ded_impot_source_centimes'], 'Lasso sans impôt source');
        $this->same(0, $result['ded_caf_centimes'], 'Lasso CAF supprimée');
        $this->same(30054, $result['total_deductions_centimes'], 'Lasso total déductions');
        $this->same(177940, $result['net_centimes'], 'Lasso salaire net');

        $this->same(11024, $result['emp_avs_centimes'], 'Lasso AVS employeur');
        $this->same(4867, $result['emp_af_centimes'], 'Lasso AF employeur');
        $this->same(14560, $result['emp_lpp_centimes'], 'Lasso LPP employeur');
        $this->same(32799, $result['total_charges_employeur_centimes'], 'Lasso charges employeur');
        $this->same(240793, $result['cout_total_centimes'], 'Lasso coût employeur');

        $source = $calculator->calculate([
            'supplement_vacances_ppm' => 0,
            'procedure' => 'ordinaire_impot_source',
            'impot_source_ppm' => 100000,
        ], 100000, $rates);
        $this->same(100000, $source['brut_centimes'], 'Lasso brut sans supplément');
        $this->same(10000, $source['ded_impot_source_centimes'], 'Lasso impôt source 10 %');

        $withoutVacation = $calculator->calculate([
            'supplement_vacances_ppm' => 0,
            'procedure' => 'ordinaire',
            'impot_source_ppm' => 0,
        ], 50000, $rates);
        $this->same(0, $withoutVacation['supplement_centimes'], 'Lasso supplément nul');
        $this->same(50000, $withoutVacation['brut_centimes'], 'Lasso brut égal au travail');
        $this->same(0, $withoutVacation['ded_caf_centimes'], 'Lasso aucune CAF cantonale');

        $withCantonalEmployerRates = $calculator->calculate([
            'supplement_vacances_ppm' => 0,
            'procedure' => 'ordinaire',
            'impot_source_ppm' => 0,
        ], 100000, $rates + [
            'emp_cpe_ppm' => 700,
            'emp_lfp_ppm' => 820,
        ]);
        $this->same(70, $withCantonalEmployerRates['emp_cpe_centimes'], 'Lasso CPE employeur');
        $this->same(82, $withCantonalEmployerRates['emp_lfp_centimes'], 'Lasso LFP employeur');
        $this->same(
            15921,
            $withCantonalEmployerRates['total_charges_employeur_centimes'],
            'Lasso total employeur avec CPE/LFP'
        );

        $this->same(35.43, round($calculator->monthlyHourThreshold(2026, 1), 2), 'Lasso seuil janvier');
        $this->same(32.0, round($calculator->monthlyHourThreshold(2026, 2), 2), 'Lasso seuil février');
        $accidentRates = [
            'laa_reduit_ppm' => 5300,
            'laa_plein_ppm' => 9600,
            'emp_laa_reduit_ppm' => 5300,
            'emp_laa_plein_ppm' => 9600,
        ];
        $reduced = $calculator->effectiveAccidentRates($accidentRates, 30.0, 2026, 1);
        $this->same(5300, $reduced['laa_ppm'], 'Lasso 30 h LAA employé réduit');
        $this->same(5300, $reduced['emp_laa_ppm'], 'Lasso 30 h LAA employeur réduit');
        $full = $calculator->effectiveAccidentRates($accidentRates, 40.0, 2026, 1);
        $this->same(9600, $full['laa_ppm'], 'Lasso 40 h LAA plein');
        $threshold = $calculator->effectiveAccidentRates($accidentRates, 35.43, 2026, 1);
        $this->same(5300, $threshold['laa_ppm'], 'Lasso seuil arrondi LAA réduit');

        $lppExample = $calculator->calculate([
            'supplement_vacances_ppm' => 0,
            'procedure' => 'ordinaire',
            'impot_source_ppm' => 0,
        ], 100000, $rates);
        $this->same(7000, $lppExample['ded_lpp_centimes'], 'Lasso LPP 7 %');
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
            'vue_shell_enabled' => true,
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
        $apiRoutes = new ApiRouteRegistry(
            new ShellApiController(
                $config,
                $session,
                $csrf,
                $httpAuth,
                $httpAccess,
                $httpAudit,
                new ShellReadService($pdo),
                new ShellInputValidator()
            ),
            new DashboardApiController(
                $session,
                $httpAuth,
                $httpAccess,
                new DashboardReadService($pdo, new ReportingService($pdo)),
                new DashboardInputValidator()
            ),
            $csrf
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
            new ChartOfAccountsService($pdo, $httpAudit),
            $httpEntries,
            new ContactService($pdo, $httpAudit),
            new BillingService($pdo, $httpAudit, $httpEntries),
            new PaymentService($pdo, $httpAudit, $httpEntries),
            new InvoicePdfService($pdo, $httpAudit),
            new AttachmentService($pdo, $httpAudit),
            new PayrollConfigurationService($pdo, $httpAudit),
            $httpPayrolls,
            new PayrollPaymentService($pdo, $httpAudit, $httpEntries),
            new PayrollCertificateService($pdo, $httpAudit),
            new PayrollImportService($pdo, $httpAudit, $httpPayrolls),
            $httpPedagogy,
            $apiRoutes,
            $shellPage
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
        $this->same('/edu/', $allowed->headers['Location'], 'redirection avec base path');
        $this->same($ids['dossier_a'], $session->get('dossier_id'), 'contexte stocké');

        $dashboard = $app->handle(new Request('GET', '/', query: ['legacy' => '1']));
        $this->same(200, $dashboard->status, 'tableau de bord accessible');
        $this->true(
            isset($dashboard->headers['Content-Security-Policy']),
            'en-têtes de sécurité présents'
        );
        $this->true(
            str_contains($dashboard->body, 'EXERCICE — DONNÉES FICTIVES'),
            'bandeau fictif permanent sur un dossier exercice'
        );
        $this->true(
            str_contains($dashboard->body, 'Organisation A')
            && str_contains($dashboard->body, 'Exercice A')
            && str_contains($dashboard->body, 'Exercice HTTP 2026')
            && str_contains($dashboard->body, 'Tableau de bord')
            && str_contains($dashboard->body, 'aria-label="Contexte de travail"'),
            'instance, organisation, dossier, exercice et module restent identifiables'
        );
        $this->true(
            str_contains($dashboard->body, 'href="#contenu"')
            && str_contains($dashboard->body, 'aria-current="page"')
            && str_contains($dashboard->body, '/edu/assets/app.js'),
            'navigation commune progressive, clavier et sous-répertoire'
        );
        $this->false(str_contains($dashboard->body, 'Organisation B'), 'autre organisation absente du HTML');
        $this->true(
            str_contains($dashboard->body, '/edu/compta/plan'),
            'accès au plan comptable depuis le tableau de bord'
        );
        $this->true(
            str_contains($dashboard->body, '/edu/facturation'),
            'accès aux débiteurs et créanciers depuis le tableau de bord'
        );
        $this->true(
            str_contains($dashboard->body, '/edu/salaires'),
            'accès aux salaires genevois depuis le tableau de bord'
        );
        $this->true(
            str_contains($dashboard->body, '/edu/pedagogie'),
            'accès à l’enseignement depuis le tableau de bord'
        );
        $accountingHome = $app->handle(new Request('GET', '/compta'));
        $this->same(200, $accountingHome->status, 'espace de travail comptable accessible');
        $this->true(
            str_contains($accountingHome->body, 'Journalisation')
            && str_contains($accountingHome->body, 'Extrait de compte')
            && str_contains($accountingHome->body, 'Grand livre')
            && str_contains($accountingHome->body, 'Soldes initiaux')
            && str_contains($accountingHome->body, 'Bilan et résultat'),
            'gestes comptables historiques remis au premier plan'
        );
        $entryScreen = $app->handle(new Request('GET', '/compta/saisie'));
        $this->same(200, $entryScreen->status, 'écran de saisie comptable accessible');
        $this->true(
            str_contains($entryScreen->body, 'data-entry-form')
            && str_contains($entryScreen->body, 'aria-live="polite"')
            && str_contains($entryScreen->body, 'Lignes de l’écriture comptable')
            && str_contains($entryScreen->body, 'data-entry-difference')
            && str_contains($entryScreen->body, 'name="compte_debit"')
            && str_contains($entryScreen->body, 'name="compte_credit"')
            && str_contains($entryScreen->body, 'name="montant"')
            && str_contains($entryScreen->body, 'Écriture composée')
            && str_contains($entryScreen->body, '/edu/assets/app.js'),
            'journalisation simple et saisie composée disponibles'
        );
        $entryNoCsrf = $app->handle(new Request('POST', '/compta/saisie', post: [
            'exercice_id' => (string) $exerciseId,
        ]));
        $this->same(419, $entryNoCsrf->status, 'saisie comptable sans CSRF refusée');
        $entryCash = $this->accountId($pdo, $ids['dossier_a'], '1000');
        $entrySales = $this->accountId($pdo, $ids['dossier_a'], '3400');
        $quickEntry = $app->handle(new Request('POST', '/compta/saisie', post: [
            '_csrf' => $csrf->token(),
            'action' => 'quick_validate',
            'exercice_id' => (string) $exerciseId,
            'journal_id' => (string) $httpJournal,
            'date_comptable' => '2026-05-10',
            'libelle' => 'Journalisation historique',
            'reference' => 'JOURNAL-HIST',
            'compte_debit' => (string) $entryCash,
            'compte_credit' => (string) $entrySales,
            'montant' => '25,30',
        ]));
        $this->same(303, $quickEntry->status, 'journalisation simple validée');
        $this->same(
            2530,
            (int) $pdo->query(
                "SELECT l.debit_centimes FROM lignes_ecriture l
                 JOIN ecritures e ON e.id = l.ecriture_id
                 WHERE e.reference = 'JOURNAL-HIST' AND l.debit_centimes > 0"
            )->fetchColumn(),
            'montant simple enregistré rigoureusement en centimes'
        );
        $sameAccountEntry = $app->handle(new Request('POST', '/compta/saisie', post: [
            '_csrf' => $csrf->token(),
            'action' => 'quick_validate',
            'exercice_id' => (string) $exerciseId,
            'journal_id' => (string) $httpJournal,
            'date_comptable' => '2026-05-11',
            'libelle' => 'Même compte interdit',
            'reference' => 'SAME-ACCOUNT',
            'compte_debit' => (string) $entryCash,
            'compte_credit' => (string) $entryCash,
            'montant' => '10.00',
        ]));
        $this->true(
            $sameAccountEntry->status === 303
            && str_contains($sameAccountEntry->headers['Location'], 'erreur='),
            'journalisation simple refuse le même compte des deux côtés'
        );
        $this->same(
            0,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM ecritures WHERE reference = 'SAME-ACCOUNT'"
            )->fetchColumn(),
            'saisie simple invalide sans écriture partielle'
        );
        $accountList = $app->handle(new Request('GET', '/compta/compte', query: [
            'compte' => (string) $entryCash,
            'exercice' => (string) $exerciseId,
            'vue' => 'liste',
        ]));
        $this->same(200, $accountList->status, 'extrait de compte en liste accessible');
        $this->true(
            str_contains($accountList->body, '1000')
            && str_contains($accountList->body, 'Journalisation historique')
            && str_contains($accountList->body, '25,30 CHF')
            && str_contains($accountList->body, 'Nouvelle opération liée à ce compte'),
            'extrait lié au compte avec mouvements et action contextuelle'
        );
        $accountT = $app->handle(new Request('GET', '/compta/compte', query: [
            'compte' => (string) $entryCash,
            'exercice' => (string) $exerciseId,
            'vue' => 't',
        ]));
        $this->true(
            str_contains($accountT->body, 'Compte en T')
            && str_contains($accountT->body, 'id="t-debit"')
            && str_contains($accountT->body, 'id="t-credit"'),
            'présentation historique en compte T disponible'
        );
        $grandLivreGateway = $app->handle(new Request('GET', '/compta/grand-livre'));
        $this->same(200, $grandLivreGateway->status, 'grand livre synthétique accessible sans choisir un compte');
        $this->true(
            str_contains($grandLivreGateway->body, 'Solde initial CHF')
            && str_contains($grandLivreGateway->body, 'Solde final CHF'),
            'grand livre reprend soldes initiaux, mouvements et soldes finaux'
        );
        $entryPost = $app->handle(new Request('POST', '/compta/saisie', post: [
            '_csrf' => $csrf->token(),
            'action' => 'validate',
            'exercice_id' => (string) $exerciseId,
            'journal_id' => (string) $httpJournal,
            'date_comptable' => '2026-06-15',
            'libelle' => 'Vente saisie au clavier',
            'reference' => 'UI-08',
            'compte_1' => (string) $entryCash,
            'debit_1' => '125.50',
            'compte_2' => (string) $entrySales,
            'credit_2' => '125,50',
        ]));
        $this->same(303, $entryPost->status, 'écriture équilibrée validée depuis l’interface');
        $this->same(
            'validee',
            (string) $pdo->query(
                "SELECT statut FROM ecritures WHERE reference = 'UI-08'"
            )->fetchColumn(),
            'contrôle serveur conservé derrière la saisie progressive'
        );
        $journalScreen = $app->handle(new Request('GET', '/compta/journal', query: [
            'exercice' => (string) $exerciseId,
        ]));
        $this->true(
            str_contains($journalScreen->body, 'Compte(s) au débit')
            && str_contains($journalScreen->body, 'Compte(s) au crédit')
            && str_contains($journalScreen->body, '125,50 CHF')
            && !str_contains($journalScreen->body, 'Débit (ct)'),
            'journal lisible en comptes et francs comme le programme historique'
        );
        $billingScreen = $app->handle(new Request('GET', '/facturation'));
        $this->same(200, $billingScreen->status, 'écran débiteurs et créanciers accessible');
        $this->true(
            str_contains($billingScreen->body, 'Documents')
            && str_contains($billingScreen->body, 'Contacts')
            && str_contains($billingScreen->body, 'Paiements'),
            'onglets compacts de facturation présents'
        );
        $salaryScreen = $app->handle(new Request('GET', '/salaires'));
        $this->same(200, $salaryScreen->status, 'écran des salaires genevois accessible');
        $this->true(
            str_contains($salaryScreen->body, 'Fiches')
            && str_contains($salaryScreen->body, 'Employés')
            && str_contains($salaryScreen->body, 'Paiements')
            && str_contains($salaryScreen->body, 'Paramètres')
            && str_contains($salaryScreen->body, 'canton de Genève')
            && !str_contains($salaryScreen->body, '<option value="VD">'),
            'quatre onglets compacts et périmètre Genève explicite'
        );
        $this->false(
            str_contains($salaryScreen->body, 'Swissdec'),
            'aucun écran salarial ne prétend transmettre via Swissdec'
        );
        $pedagogyScreen = $app->handle(new Request('GET', '/pedagogie'));
        $this->same(200, $pedagogyScreen->status, 'écran d’enseignement accessible');
        $this->true(
            str_contains($pedagogyScreen->body, 'Mon travail')
            && str_contains($pedagogyScreen->body, 'Suivi formateur')
            && str_contains($pedagogyScreen->body, 'Modèles et groupes'),
            'onglets pédagogiques compacts présents'
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
            ]]
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
        $staleHttp = $app->handle(new Request('POST', '/pedagogie/action', post: [
            '_csrf' => $csrf->token(),
            'action' => 'replace_draft',
            'ecriture_id' => (string) $httpDraft,
            'version' => (string) $httpDraftVersion,
            'exercice_id' => (string) $httpExercise,
            'journal_id' => (string) $httpCopiedJournal,
            'date_comptable' => '2026-03-01',
            'libelle' => 'Version périmée',
            'lignes_json' => json_encode($httpCommand['lignes'], JSON_THROW_ON_ERROR),
        ]));
        $this->same(409, $staleHttp->status, 'conflit collaboratif HTTP 409');

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
        $realDashboard = $app->handle(new Request('GET', '/', query: ['legacy' => '1']));
        $this->true(
            str_contains($realDashboard->body, 'DOSSIER RÉEL — DONNÉES DE PRODUCTION')
            && str_contains($realDashboard->body, 'context-real'),
            'dossier réel distingué par texte et présentation'
        );
        $resetReal = $app->handle(new Request('POST', '/pedagogie/action', post: [
            '_csrf' => $csrf->token(),
            'action' => 'reset',
            'assignation_id' => (string) $httpAssignment,
        ]));
        $this->same(403, $resetReal->status, 'route HTTP refuse le reset d’un dossier réel');
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
        $demoDashboard = $app->handle(new Request('GET', '/', query: ['legacy' => '1']));
        $this->true(
            str_contains($demoDashboard->body, 'DÉMONSTRATION — DONNÉES FICTIVES')
            && str_contains($demoDashboard->body, 'context-demo'),
            'dossier de démonstration distingué par texte et présentation'
        );
        $session->set('dossier_id', $ids['dossier_a']);
        $this->true(
            str_contains(
                $dashboard->body,
                '/edu/assets/vendor/bootstrap/bootstrap.min.css'
            ),
            'Bootstrap local chargé avec le base path'
        );
        $this->true(
            str_contains(
                $dashboard->body,
                '/edu/assets/vendor/bootstrap/bootstrap.bundle.min.js'
            ),
            'bundle Bootstrap local chargé'
        );
        $this->false(
            str_contains($dashboard->body, 'cdn.jsdelivr.net'),
            'aucune dépendance Bootstrap au CDN'
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
        $this->same(200, $balance->status, 'vue imprimable de balance accessible');
        $this->true(
            str_contains($balance->body, 'veb.ch'),
            'attribution du plan visible dans le rapport'
        );
        $csv = $app->handle(new Request('GET', '/compta/balance', query: [
            'exercice' => (string) $exerciseId,
            'format' => 'csv',
        ]));
        $this->same(
            'text/csv; charset=UTF-8',
            $csv->headers['Content-Type'],
            'export CSV HTTP'
        );
        $plan = $app->handle(new Request('GET', '/compta/plan', query: [
            'exercice' => (string) $exerciseId,
        ]));
        $this->same(200, $plan->status, 'écran du plan comptable accessible');
        $this->true(
            str_contains($plan->body, 'Comptes fonctionnant par défaut en --/++')
            && str_contains($plan->body, '1. Types de comptes')
            && str_contains($plan->body, 'Rubriques et structure de bouclement')
            && str_contains($plan->body, '3. Moins / plus standards')
            && str_contains($plan->body, '4. Comptes')
            && str_contains($plan->body, '<th>Code</th><th>Libellé</th>')
            && !str_contains($plan->body, 'Code fonctionnel')
            && !str_contains($plan->body, '<th>Classes</th>')
            && str_contains($plan->body, 'Groupes principaux')
            && str_contains($plan->body, 'Sous-groupes')
            && str_contains($plan->body, '>⋮</button>')
            && str_contains($plan->body, '>++/--<')
            && str_contains($plan->body, 'data-dirty-panel')
            && str_contains($plan->body, 'data-panel-submit')
            && substr_count($plan->body, 'data-panel-submit') === 6
            && str_contains($plan->body, '<th>Fonctionnement</th>')
            && !str_contains($plan->body, 'value="save">Modifier</button>')
            && !str_contains($plan->body, 'Automatique selon les préfixes')
            && str_contains($plan->body, 'data-bs-toggle="tab"')
            && str_contains($plan->body, '/assets/plan.js'),
            'ordre des onglets, libellés compacts et niveaux structurels présents'
        );
        $accountParentStart = strpos($plan->body, 'id="new-account-rubric"');
        $accountParentHtml = $accountParentStart === false
            ? ''
            : substr(
                $plan->body,
                $accountParentStart,
                (int) strpos($plan->body, '</select>', $accountParentStart) - $accountParentStart
            );
        $this->true(
            strpos($accountParentHtml, '28 Capitaux propres')
                < strpos($accountParentHtml, '280 Capital social')
            && strpos($accountParentHtml, '280 Capital social')
                < strpos($accountParentHtml, '290 Réserves')
            && strpos($accountParentHtml, '290 Réserves')
                < strpos($accountParentHtml, '30 Produits bruts'),
            'parents de comptes triés dans l’ordre numérique spécial'
        );
        $groupParentStart = strpos($plan->body, 'id="new-parent-groupe"');
        $groupParentHtml = $groupParentStart === false
            ? ''
            : substr(
                $plan->body,
                $groupParentStart,
                (int) strpos($plan->body, '</select>', $groupParentStart) - $groupParentStart
            );
        $groupParentText = preg_replace('/\s+/', ' ', strip_tags($groupParentHtml));
        $this->true(
            is_string($groupParentText)
            && str_contains($groupParentText, 'Actifs circulants')
            && !str_contains($groupParentText, '10 Actifs circulants'),
            'parents des groupes affichés sans numéro'
        );
        $planScript = file_get_contents(dirname(__DIR__) . '/public/assets/plan.js');
        $this->true(
            is_string($planScript)
            && str_contains(
                $planScript,
                'Des modifications de ce panneau ne sont pas encore enregistrées.'
            )
            && str_contains($planScript, 'beforeunload'),
            'modifications et ordres non enregistrés signalés'
        );
        $interfaceScript = file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
        $interfaceCss = file_get_contents(dirname(__DIR__) . '/public/assets/app.css');
        $this->true(
            is_string($planScript)
            && str_contains($planScript, "['ArrowUp', 'ArrowDown']")
            && str_contains($plan->body, 'aria-keyshortcuts="ArrowUp ArrowDown"'),
            'ordre du plan modifiable au clavier sans dépendre du glisser-déposer'
        );
        $this->true(
            is_string($interfaceScript)
            && str_contains($interfaceScript, 'data-entry-debit')
            && str_contains($interfaceScript, 'beforeunload')
            && is_string($interfaceCss)
            && str_contains($interfaceCss, '@media (max-width: 360px)')
            && str_contains($interfaceCss, '@media print')
            && str_contains($interfaceCss, ':focus-visible')
            && str_contains($interfaceCss, 'prefers-reduced-motion'),
            'contrôles automatisés 360 px, impression, focus et mouvement réduit'
        );
        $openingPanel = strstr($plan->body, 'id="panel-ouverture"');
        $this->true(
            is_string($openingPanel)
            && str_contains($openingPanel, 'name="solde_')
            && !str_contains($openingPanel, '>3000<')
            && !str_contains($openingPanel, '>9200<'),
            'ouverture limitée aux comptes actifs et passifs'
        );
        $planNoCsrf = $app->handle(new Request('POST', '/compta/plan/sens', post: [
            'prefixes' => '2, 3',
            'exercice_id' => (string) $exerciseId,
        ]));
        $this->same(419, $planNoCsrf->status, 'configuration sans CSRF refusée');
        $planRules = $app->handle(new Request('POST', '/compta/plan/sens', post: [
            '_csrf' => $csrf->token(),
            'prefixes' => '2, 3, 7',
            'exercice_id' => (string) $exerciseId,
        ]));
        $this->same(303, $planRules->status, 'règles de sens modifiables par HTTP');
        $this->same(
            3,
            (int) $pdo->query(
                "SELECT COUNT(*) FROM regles_sens_comptes
                 WHERE dossier_id = {$ids['dossier_a']}"
            )->fetchColumn(),
            'règles HTTP persistées'
        );
    }

    private function accountingTests(): void
    {
        [$pdo, $runner] = $this->database();
        $runner->apply();
        $ids = $this->seedScopes($pdo);
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
        $setup->createPeriod(
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
        $directory = $this->tempDir() . '/backups';
        $backup = BackupService::create($pdo, $directory, 'test-instance');
        $this->true(is_file($backup), 'sauvegarde créée');
        $copy = ConnectionFactory::sqlite($backup);
        $this->true(IntegrityChecker::check($copy)['ok'], 'sauvegarde restaurable/intègre');

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

        $payrolls = new PayrollService(
            $pdo,
            $audit,
            new EntryService($pdo, $audit)
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
        $configuration->saveRates(
            $organisationId,
            $dossierId,
            2026,
            $changedRates
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
            'taux de la fiche validée immuables après changement annuel'
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
        $this->true(IntegrityChecker::check($pdo)['ok'], 'intégrité après facturation');
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
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--suite=')) {
        $suite = substr($argument, strlen('--suite='));
    }
}
exit((new Tests())->run($suite));
