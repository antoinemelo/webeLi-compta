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
use Compta\Modules\Facturation\ContactService;
use Compta\Modules\Salaires\PayrollConfigurationService;
use Compta\Modules\Salaires\PayrollService;
use Compta\Modules\Tresorerie\TreasuryAccountService;
use Compta\Modules\Tva\VatConfigurationService;

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
$administratorId = $users->create(
    'admin@example.test',
    'mot-de-passe-e2e',
    'Alex',
    'Administrateur'
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
(new ContactService($pdo, $audit))->create(
    $organisationA,
    $dossierA,
    [
        'type_personne' => 'entreprise',
        'raison_sociale' => 'Fournitures E2E SA',
    ],
    ['fournisseur'],
    [
        'ligne1' => 'Rue du Test 6',
        'code_postal' => '1200',
        'localite' => 'Genève',
        'pays' => 'CH',
    ]
);
$vat = new VatConfigurationService($pdo, $audit);
$vat->addRegime([
    'organisation_id' => $organisationA,
    'dossier_id' => $dossierA,
    'statut' => 'assujetti',
    'numero_tva' => 'CHE-123.456.789 TVA',
    'methode' => 'effective',
    'mode_decompte' => 'convenues',
    'periodicite' => 'trimestrielle',
    'date_debut' => '2026-01-01',
    'compte_impot_prealable_materiel_id' => $accountId('1170'),
    'compte_impot_prealable_investissements_id' => $accountId('1171'),
    'compte_tva_due_id' => $accountId('2200'),
    'compte_decompte_tva_id' => $accountId('2201'),
    'compte_corrections_id' => $accountId('6500'),
]);
$normalRateId = (int) $pdo->query(
    "SELECT id FROM tva_taux_legaux
     WHERE categorie = 'normal' ORDER BY date_debut DESC LIMIT 1"
)->fetchColumn();
$vat->addCode([
    'organisation_id' => $organisationA,
    'dossier_id' => $dossierA,
    'code' => 'AM81',
    'libelle' => 'Achats 8,1 %',
    'traitement' => 'normal',
    'nature' => 'prealable',
    'taux_legal_id' => $normalRateId,
    'droit_deduction' => true,
    'deduction_defaut_bp' => 10000,
    'compte_tva_id' => $accountId('1170'),
    'date_debut' => '2024-01-01',
]);
$vat->addCode([
    'organisation_id' => $organisationA,
    'dossier_id' => $dossierA,
    'code' => 'VE81',
    'libelle' => 'Ventes 8,1 %',
    'traitement' => 'normal',
    'nature' => 'collectee',
    'taux_legal_id' => $normalRateId,
    'droit_deduction' => false,
    'compte_tva_id' => $accountId('2200'),
    'date_debut' => '2024-01-01',
]);

$payrollConfiguration = new PayrollConfigurationService($pdo, $audit);
$payrollConfiguration->saveEmployer(
    $organisationA,
    $dossierA,
    [
        'nom' => 'Entreprise Alpha SA',
        'rue' => 'Rue du Test 1',
        'npa' => '1200',
        'localite' => 'Genève',
        'heures_hebdo_milli' => 40000,
    ],
    $administratorId
);
$payrollConfiguration->saveRates(
    $organisationA,
    $dossierA,
    2026,
    [
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
        'source' => 'OCAS Genève 2026 — fixture E2E',
        'verifie_le' => '2026-01-01',
    ],
    $administratorId
);
$payrollConfiguration->saveMapping(
    $organisationA,
    $dossierA,
    [
        'charge_salaires_id' => $accountId('5000'),
        'charge_ocas_id' => $accountId('5700'),
        'charge_laa_id' => $accountId('5700'),
        'charge_lpp_id' => $accountId('5700'),
        'dette_net_id' => $accountId('2000'),
        'dette_ocas_id' => $accountId('2270'),
        'dette_laa_id' => $accountId('2270'),
        'dette_lpp_id' => $accountId('2270'),
        'dette_impot_id' => $accountId('2270'),
    ],
    $administratorId
);
$payrollEmployee = $payrollConfiguration->createEmployee(
    $organisationA,
    $dossierA,
    [
        'prenom' => 'Ada',
        'nom' => 'Martin',
        'email' => 'ada@example.test',
        'numero_avs' => '756.1234.5678.90',
        'date_naissance' => '1990-05-12',
        'procedure' => 'ordinaire',
        'supplement_vacances_ppm' => 0,
        'impot_source_ppm' => 0,
    ],
    $administratorId
);
$payrollConfiguration->saveContract(
    $organisationA,
    $dossierA,
    [
        'employe_id' => $payrollEmployee,
        'type' => 'mensuel',
        'date_debut' => '2026-01-01',
        'date_fin' => '',
        'taux_horaire_centimes' => 0,
        'salaire_mensuel_centimes' => 500000,
        'heures_hebdo_milli' => 40000,
        'taux_activite_ppm' => 1_000_000,
        'source' => 'Contrat mensuel E2E',
    ],
    $administratorId
);
(new PayrollService($pdo, $audit, $entries))->createPeriodDraft(
    $organisationA,
    $dossierA,
    $payrollEmployee,
    2026,
    7,
    [
        [
            'type' => 'absence',
            'libelle' => 'Absence non rémunérée',
            'montant_centimes' => 10000,
            'note' => 'Correction de période',
        ],
        [
            'type' => 'prime',
            'libelle' => 'Prime exceptionnelle',
            'montant_centimes' => 50000,
            'note' => 'Décision employeur',
        ],
    ],
    $administratorId
);

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
$scopes->grantRole($administratorId, 'administrateur', 'dossier', $dossierA);

echo json_encode([
    'user_id' => $userId,
    'administrator_id' => $administratorId,
    'allowed' => [$dossierA, $dossierC],
    'forbidden' => [
        'organisation_id' => $organisationB,
        'dossier_id' => $dossierB,
    ],
], JSON_THROW_ON_ERROR) . PHP_EOL;
