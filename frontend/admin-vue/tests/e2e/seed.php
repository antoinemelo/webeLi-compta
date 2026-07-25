<?php
declare(strict_types=1);

use Compta\Core\Audit\AuditLogger;
use Compta\Core\Auth\UserRepository;
use Compta\Core\Config\AppConfig;
use Compta\Core\Database\ConnectionFactory;
use Compta\Core\Database\MigrationRunner;
use Compta\Modules\Dossiers\ScopeManager;

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
$scopes->createExercise($dossierA, '2026', '2026-01-01', '2026-12-31');

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
