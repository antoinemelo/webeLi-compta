<?php
declare(strict_types=1);

use Compta\Core\Audit\AuditLogger;
use Compta\Core\Auth\UserRepository;
use Compta\Core\Config\AppConfig;
use Compta\Core\Database\ConnectionFactory;
use Compta\Core\Database\MigrationRunner;
use Compta\Modules\Compta\AccountingSetupService;
use Compta\Modules\Compta\EntryService;
use Compta\Modules\Compta\PlanSeeder;
use Compta\Modules\Dossiers\ScopeManager;
use Compta\Modules\Tresorerie\TreasuryAccountService;

$root = dirname(__DIR__, 4);
require $root . '/bootstrap/autoload.php';

$config = AppConfig::load($root);
$pdo = ConnectionFactory::sqlite($config->string('database_path'));
(new MigrationRunner($pdo, $root . '/database/migrations'))->apply();
$audit = new AuditLogger($pdo);
$scopes = new ScopeManager($pdo, $audit);
$users = new UserRepository($pdo);

$userId = $users->create(
    'lecteur@example.test',
    'mot-de-passe-e2e',
    'Léa',
    'Lectrice'
);
$organisationA = $scopes->createOrganisation('Entreprise Alpha SA', 'reelle');
$dossierA = $scopes->createDossier(
    $organisationA,
    'Comptabilité principale',
    'comptabilite-principale',
    'reel'
);
$exerciseA = $scopes->createExercise(
    $dossierA,
    '2026',
    '2026-01-01',
    '2026-12-31'
);
(new PlanSeeder($pdo, $root . '/database/seeds'))->installForDossier(
    $organisationA,
    $dossierA,
    'personne_morale'
);
$setup = new AccountingSetupService($pdo, $audit);
$setup->createPeriod(
    $organisationA,
    $dossierA,
    $exerciseA,
    'Année 2026',
    '2026-01-01',
    '2026-12-31'
);
$journalA = $setup->createJournal(
    $organisationA,
    $dossierA,
    'TDB',
    'Tableau de bord'
);
$accountId = static function (string $number) use ($pdo, $dossierA): int {
    $stmt = $pdo->prepare(
        'SELECT id FROM comptes WHERE dossier_id = ? AND numero = ?'
    );
    $stmt->execute([$dossierA, $number]);
    return (int) $stmt->fetchColumn();
};
$bankAccount = $accountId('1020');
$entries = new EntryService($pdo, $audit);
$post = static function (
    string $key,
    string $label,
    array $lines,
) use (
    $entries,
    $organisationA,
    $dossierA,
    $exerciseA,
    $journalA
): void {
    $entries->postGenerated([
        'organisation_id' => $organisationA,
        'dossier_id' => $dossierA,
        'exercice_id' => $exerciseA,
        'journal_id' => $journalA,
        'date_comptable' => '2026-03-15',
        'libelle' => $label,
        'source_type' => 'test_e2e',
        'source_id' => $key,
        'source_action' => 'dashboard',
        'lignes' => $lines,
    ], 'e2e-dashboard:' . $key);
};
$post('capital', 'Apport initial', [
    ['compte_id' => $bankAccount, 'debit_centimes' => 50000],
    ['compte_id' => $accountId('2800'), 'credit_centimes' => 50000],
]);
$post('sale', 'Prestation facturée', [
    ['compte_id' => $accountId('1100'), 'debit_centimes' => 120000],
    ['compte_id' => $accountId('3400'), 'credit_centimes' => 120000],
]);
$post('expense', 'Charge administrative', [
    ['compte_id' => $accountId('6500'), 'debit_centimes' => 30000],
    ['compte_id' => $accountId('2000'), 'credit_centimes' => 30000],
]);
(new TreasuryAccountService($pdo, $audit))->create([
    'organisation_id' => $organisationA,
    'dossier_id' => $dossierA,
    'compte_comptable_id' => $bankAccount,
    'libelle' => 'Banque principale',
    'type' => 'banque',
    'monnaie' => 'CHF',
]);

$organisationB = $scopes->createOrganisation('Entreprise Confidentielle SA', 'reelle');
$dossierB = $scopes->createDossier(
    $organisationB,
    'Dossier inaccessible',
    'dossier-inaccessible',
    'reel'
);
$scopes->createExercise($dossierB, '2026 secret', '2026-01-01', '2026-12-31');

$organisationC = $scopes->createOrganisation('École WebeLi', 'pedagogique');
$dossierC = $scopes->createDossier(
    $organisationC,
    'Démonstration guidée',
    'demonstration-guidee',
    'demo'
);
$scopes->createExercise($dossierC, 'Atelier 2026', '2026-01-01', '2026-12-31');

$scopes->grantRole($userId, 'lecteur', 'dossier', $dossierA);
$scopes->grantRole($userId, 'lecteur', 'dossier', $dossierC);

echo json_encode([
    'user_id' => $userId,
    'allowed' => [$dossierA, $dossierC],
    'forbidden' => [
        'organisation_id' => $organisationB,
        'dossier_id' => $dossierB,
    ],
], JSON_THROW_ON_ERROR) . PHP_EOL;
