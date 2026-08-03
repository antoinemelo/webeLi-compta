<?php
declare(strict_types=1);

namespace Compta\Modules\Dossiers;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Compta\AccountingSetupService;
use Compta\Modules\Compta\PlanSeeder;
use Compta\Modules\Configuration\Application\ModuleAccessService;
use Compta\Modules\Tva\DefaultVatCodeInstaller;
use DateTimeImmutable;
use PDO;
use PDOException;
use Throwable;

final class DossierRegistryService
{
    private const MODULE_CODES = [
        'apprentissage',
        'liquidites',
        'facturation',
        'comptabilite',
        'salaires',
    ];

    private const PLAN_VARIANTS = [
        'personne_morale',
        'raison_individuelle',
        'societe_personnes',
    ];

    /** Tables créées par l'assistant et supprimables tant qu'aucune donnée métier n'existe. */
    private const TECHNICAL_TABLES = [
        'audit_events',
        'comptes',
        'devises_dossier',
        'exercices',
        'journaux',
        'modules_dossier',
        'parametres_dossier',
        'periodes',
        'regles_sens_comptes',
        'rubriques_comptables',
        'tva_codes',
        'tva_regimes',
        'types_comptes',
        'utilisateur_roles_dossier',
    ];

    /** @var null|\Closure(string):void */
    private readonly ?\Closure $checkpoint;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly ScopeManager $scopes,
        private readonly PlanSeeder $plans,
        private readonly AccountingSetupService $accounting,
        private readonly DefaultVatCodeInstaller $vat,
        private readonly ModuleAccessService $modules,
        ?callable $checkpoint = null,
    ) {
        $this->checkpoint = $checkpoint === null
            ? null
            : \Closure::fromCallable($checkpoint);
    }

    /** @return list<array<string,mixed>> */
    public function listForOrganisation(
        int $organisationId,
        string $status = 'all',
    ): array {
        $where = match ($status) {
            'active' => ' AND d.actif = 1',
            'archived' => ' AND d.actif = 0',
            'all' => '',
            default => throw new DossierRegistryException('Filtre de statut invalide.'),
        };
        $stmt = $this->pdo->prepare(
            'SELECT d.id, d.organisation_id, d.nom, d.slug, d.type, d.monnaie,
                    d.actif, d.cree_le, d.version,
                    COUNT(DISTINCT c.id) AS account_count,
                    COUNT(DISTINCT x.id) AS exercise_count,
                    COUNT(DISTINCT p.id) AS period_count,
                    COUNT(DISTINCT j.id) AS journal_count
             FROM dossiers d
             LEFT JOIN comptes c ON c.dossier_id = d.id
             LEFT JOIN exercices x ON x.dossier_id = d.id
             LEFT JOIN periodes p ON p.dossier_id = d.id
             LEFT JOIN journaux j ON j.dossier_id = d.id
             WHERE d.organisation_id = ?' . $where . '
             GROUP BY d.id
             ORDER BY d.actif DESC, d.nom COLLATE NOCASE, d.id'
        );
        $stmt->execute([$organisationId]);
        return array_map([$this, 'normalise'], $stmt->fetchAll());
    }

    /** @return array<string,mixed> */
    public function detail(int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.id, d.organisation_id, d.nom, d.slug, d.type, d.monnaie,
                    d.actif, d.cree_le, d.version
             FROM dossiers d WHERE d.id = ?'
        );
        $stmt->execute([$dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new DossierRegistryException(
                'Dossier introuvable.', 'DOSSIER_NOT_FOUND'
            );
        }
        $data = $this->normalise($row);
        $data['summary'] = $this->summary(
            (int) $row['organisation_id'],
            (int) $row['id']
        );
        $data['deletion_dependencies'] = $this->businessDependencies($dossierId);
        $data['historical_data'] = $data['deletion_dependencies'] !== [];
        return $data;
    }

    /**
     * @param list<string> $moduleCodes
     * @param array{projets?:bool,fonds_affectes?:bool} $associationOptions
     * @param null|array{source_dossier_id:int,preview_hash:string} $accessCopy
     * @return array<string,mixed>
     */
    public function createInitialized(
        int $organisationId,
        string $name,
        string $slug,
        string $type,
        string $currency,
        array $moduleCodes,
        string $planVariant,
        bool $withAssociation,
        array $associationOptions,
        string $exerciseLabel,
        string $exerciseStart,
        string $exerciseEnd,
        string $journalCode,
        string $journalLabel,
        int $actorId,
        ?array $accessCopy = null,
    ): array {
        $name = trim($name);
        $slug = mb_strtolower(trim($slug));
        $currency = mb_strtoupper(trim($currency));
        $journalCode = mb_strtoupper(trim($journalCode));
        $this->assertDates($exerciseStart, $exerciseEnd);
        if ($name === '') {
            throw new DossierRegistryException('Le nom du dossier est requis.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,62}$/', $slug) !== 1) {
            throw new DossierRegistryException(
                'Le slug doit contenir de 2 à 63 lettres minuscules, chiffres, tirets ou tirets bas.'
            );
        }
        if (!in_array($type, ['reel', 'demo', 'exercice'], true)) {
            throw new DossierRegistryException('Le type de dossier est invalide.');
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new DossierRegistryException('La devise doit être un code ISO à trois lettres.');
        }
        if (array_diff($moduleCodes, self::MODULE_CODES) !== []) {
            throw new DossierRegistryException('La sélection de modules est invalide.');
        }
        if (!in_array($planVariant, self::PLAN_VARIANTS, true)) {
            throw new DossierRegistryException('La variante du plan comptable est invalide.');
        }
        if (trim($exerciseLabel) === '' || trim($journalLabel) === '') {
            throw new DossierRegistryException('Exercice et journal initial requis.');
        }
        if (preg_match('/^[A-Z0-9_-]{1,12}$/', $journalCode) !== 1) {
            throw new DossierRegistryException(
                'Le code du journal doit contenir au maximum 12 lettres, chiffres, tirets ou tirets bas.'
            );
        }
        return $this->transaction(function () use (
            $organisationId, $name, $slug, $type, $currency, $moduleCodes,
            $planVariant, $withAssociation, $associationOptions, $exerciseLabel,
            $exerciseStart, $exerciseEnd, $journalCode, $journalLabel, $actorId,
            $accessCopy
        ): array {
            try {
                $dossierId = $this->scopes->createDossier(
                    $organisationId, $name, $slug, $type, $actorId
                );
            } catch (PDOException $exception) {
                if (str_contains($exception->getMessage(), 'dossiers.organisation_id, dossiers.slug')) {
                    throw new DossierRegistryException(
                        'Ce slug est déjà utilisé dans cette organisation.',
                        'DOSSIER_SLUG_CONFLICT'
                    );
                }
                throw $exception;
            }
            $this->pdo->prepare(
                'UPDATE dossiers SET monnaie = ? WHERE id = ?'
            )->execute([$currency, $dossierId]);
            $this->modules->configureSelection(
                $organisationId, $dossierId, $moduleCodes, $actorId
            );
            $this->mark('modules');
            $this->plans->installForDossier(
                $organisationId,
                $dossierId,
                $planVariant,
                $withAssociation,
                $associationOptions,
                $actorId
            );
            $this->mark('plan');
            $exerciseId = $this->scopes->createExercise(
                $dossierId,
                $exerciseLabel,
                $exerciseStart,
                $exerciseEnd,
                $actorId
            );
            $periodId = $this->accounting->createPeriod(
                $organisationId,
                $dossierId,
                $exerciseId,
                $exerciseLabel,
                $exerciseStart,
                $exerciseEnd,
                $actorId
            );
            $journalId = $this->accounting->createJournal(
                $organisationId,
                $dossierId,
                $journalCode,
                $journalLabel,
                'general',
                $actorId
            );
            $vatCount = in_array('comptabilite', $moduleCodes, true)
                ? $this->vat->install($organisationId, $dossierId, $actorId)
                : 0;
            $vatRegimeId = in_array('comptabilite', $moduleCodes, true)
                ? $this->vat->installDefaultRegime(
                    $organisationId,
                    $dossierId,
                    $exerciseStart,
                    $actorId
                )
                : null;
            $this->mark('references');
            $copiedAccessCount = 0;
            if ($accessCopy !== null) {
                try {
                    $copiedAccessCount = (new StructureAccessService(
                        $this->pdo,
                        $this->audit
                    ))->copyDossierMatrix(
                        $organisationId,
                        (int) $accessCopy['source_dossier_id'],
                        $dossierId,
                        (string) $accessCopy['preview_hash'],
                        $actorId
                    );
                } catch (StructureAccessException $exception) {
                    throw new DossierRegistryException(
                        $exception->getMessage(),
                        $exception->errorCode
                    );
                }
            }
            $summary = $this->summary($organisationId, $dossierId);
            $summary['exercise_id'] = $exerciseId;
            $summary['period_id'] = $periodId;
            $summary['journal_id'] = $journalId;
            $summary['vat_code_count'] = $vatCount;
            $summary['vat_regime_id'] = $vatRegimeId;
            $summary['copied_access_count'] = $copiedAccessCount;
            $this->audit->log(
                'dossier.initialise',
                $actorId,
                $organisationId,
                $dossierId,
                'dossier',
                (string) $dossierId,
                $summary
            );
            return ['id' => $dossierId, 'summary' => $summary];
        });
    }

    public function update(
        int $dossierId,
        int $expectedVersion,
        string $name,
        string $type,
        string $currency,
        int $actorId,
    ): void {
        $name = trim($name);
        $currency = mb_strtoupper(trim($currency));
        if (
            $name === ''
            || !in_array($type, ['reel', 'demo', 'exercice'], true)
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1
        ) {
            throw new DossierRegistryException('Paramètres de dossier invalides.');
        }
        $this->transaction(function () use (
            $dossierId, $expectedVersion, $name, $type, $currency, $actorId
        ): void {
            $before = $this->snapshot($dossierId);
            if ((int) $before['version'] !== $expectedVersion) {
                $this->conflict();
            }
            if ((int) $before['actif'] !== 1) {
                throw new DossierRegistryException(
                    'Un dossier archivé doit être réactivé avant modification.',
                    'DOSSIER_ARCHIVED'
                );
            }
            if (
                ($before['type'] !== $type || $before['monnaie'] !== $currency)
                && $this->businessDependencies($dossierId) !== []
            ) {
                throw new DossierRegistryException(
                    'Le type et la devise sont immuables car des données métier '
                    . 'existent déjà. Le nom reste modifiable.',
                    'DOSSIER_HISTORICAL_FIELDS_LOCKED',
                    $this->businessDependencies($dossierId)
                );
            }
            $stmt = $this->pdo->prepare(
                'UPDATE dossiers
                 SET nom = ?, type = ?, monnaie = ?, version = version + 1
                 WHERE id = ? AND version = ?'
            );
            $stmt->execute([$name, $type, $currency, $dossierId, $expectedVersion]);
            if ($stmt->rowCount() !== 1) {
                $this->conflict();
            }
            $after = $this->snapshot($dossierId);
            $this->audit->log(
                'dossier.modifie',
                $actorId,
                (int) $after['organisation_id'],
                $dossierId,
                'dossier',
                (string) $dossierId,
                ['before' => $before, 'after' => $after]
            );
        });
    }

    public function archive(int $dossierId, int $version, int $actorId): void
    {
        $this->changeStatus($dossierId, $version, false, $actorId);
    }

    public function reactivate(int $dossierId, int $version, int $actorId): void
    {
        $this->changeStatus($dossierId, $version, true, $actorId);
    }

    public function delete(int $dossierId, int $version, int $actorId): void
    {
        $this->transaction(function () use ($dossierId, $version, $actorId): void {
            $before = $this->snapshot($dossierId);
            if ((int) $before['version'] !== $version) {
                $this->conflict();
            }
            $dependencies = $this->businessDependencies($dossierId);
            if ($dependencies !== []) {
                throw new DossierRegistryException(
                    'Suppression impossible : des données métier ou historiques subsistent. '
                    . 'Archivez le dossier.',
                    'DOSSIER_HAS_DEPENDENCIES',
                    $dependencies
                );
            }
            $this->audit->log(
                'dossier.supprime',
                $actorId,
                (int) $before['organisation_id'],
                $dossierId,
                'dossier',
                (string) $dossierId,
                ['before' => $before, 'after' => null]
            );
            $this->deleteTechnicalRows($dossierId);
            $stmt = $this->pdo->prepare(
                'DELETE FROM dossiers WHERE id = ? AND version = ?'
            );
            $stmt->execute([$dossierId, $version]);
            if ($stmt->rowCount() !== 1) {
                $this->conflict();
            }
        });
    }

    /** @return array<string,int> */
    public function businessDependencies(int $dossierId): array
    {
        $tables = $this->pdo->query(
            "SELECT name FROM sqlite_master
             WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);
        $dependencies = [];
        foreach ($tables as $tableName) {
            $table = (string) $tableName;
            if (in_array($table, self::TECHNICAL_TABLES, true)) {
                continue;
            }
            $columns = [];
            foreach ($this->pdo->query(
                'PRAGMA foreign_key_list(' . $this->quoteIdentifier($table) . ')'
            )->fetchAll() as $foreignKey) {
                if ((string) $foreignKey['table'] === 'dossiers') {
                    $columns[] = (string) $foreignKey['from'];
                }
            }
            $columns = array_values(array_unique($columns));
            if ($columns === []) {
                continue;
            }
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table)
                . ' WHERE ' . implode(' OR ', array_map(
                    fn (string $column): string => $this->quoteIdentifier($column) . ' = ?',
                    $columns
                ))
            );
            $stmt->execute(array_fill(0, count($columns), $dossierId));
            $count = (int) $stmt->fetchColumn();
            if ($count > 0) {
                $dependencies[$table] = $count;
            }
        }
        return $dependencies;
    }

    private function changeStatus(
        int $dossierId,
        int $version,
        bool $active,
        int $actorId,
    ): void {
        $this->transaction(function () use (
            $dossierId, $version, $active, $actorId
        ): void {
            $before = $this->snapshot($dossierId);
            if ((int) $before['version'] !== $version) {
                $this->conflict();
            }
            if ($active) {
                $organisation = $this->pdo->prepare(
                    'SELECT actif FROM organisations WHERE id = ?'
                );
                $organisation->execute([(int) $before['organisation_id']]);
                if ((int) $organisation->fetchColumn() !== 1) {
                    throw new DossierRegistryException(
                        'Réactivez d’abord l’organisation.',
                        'DOSSIER_ORGANISATION_INACTIVE'
                    );
                }
            }
            $stmt = $this->pdo->prepare(
                'UPDATE dossiers SET actif = ?, version = version + 1
                 WHERE id = ? AND version = ?'
            );
            $stmt->execute([$active ? 1 : 0, $dossierId, $version]);
            if ($stmt->rowCount() !== 1) {
                $this->conflict();
            }
            $this->audit->log(
                $active ? 'dossier.reactive' : 'dossier.archive',
                $actorId,
                (int) $before['organisation_id'],
                $dossierId,
                'dossier',
                (string) $dossierId,
                ['before' => $before, 'active' => $active]
            );
        });
    }

    /** @return array<string,mixed> */
    private function summary(int $organisationId, int $dossierId): array
    {
        $counts = [];
        foreach ([
            'comptes' => 'account_count',
            'exercices' => 'exercise_count',
            'periodes' => 'period_count',
            'journaux' => 'journal_count',
        ] as $table => $key) {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . $table . ' WHERE dossier_id = ?'
            );
            $stmt->execute([$dossierId]);
            $counts[$key] = (int) $stmt->fetchColumn();
        }
        $currency = $this->pdo->prepare(
            'SELECT monnaie FROM dossiers WHERE id = ? AND organisation_id = ?'
        );
        $currency->execute([$dossierId, $organisationId]);
        return $counts + [
            'currency' => (string) $currency->fetchColumn(),
            'modules' => $this->modules->enabledCodes($organisationId, $dossierId),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalise(array $row): array
    {
        foreach ([
            'id', 'organisation_id', 'version', 'account_count',
            'exercise_count', 'period_count', 'journal_count',
        ] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = (int) $row[$key];
            }
        }
        $row['active'] = (int) $row['actif'] === 1;
        unset($row['actif']);
        return $row;
    }

    /** @return array<string,mixed> */
    private function snapshot(int $dossierId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM dossiers WHERE id = ?');
        $stmt->execute([$dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new DossierRegistryException(
                'Dossier introuvable.', 'DOSSIER_NOT_FOUND'
            );
        }
        return $row;
    }

    private function deleteTechnicalRows(int $dossierId): void
    {
        $simple = [
            'tva_regimes', 'tva_codes', 'periodes', 'journaux', 'types_comptes',
            'regles_sens_comptes',
        ];
        foreach ($simple as $table) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE dossier_id = ?")
                ->execute([$dossierId]);
        }
        $this->pdo->prepare(
            'UPDATE comptes SET rubrique_id = NULL WHERE dossier_id = ?'
        )->execute([$dossierId]);
        foreach (['comptes', 'rubriques_comptables'] as $table) {
            do {
                $stmt = $this->pdo->prepare(
                    "DELETE FROM {$table}
                     WHERE dossier_id = ?
                       AND id NOT IN (
                         SELECT parent_id FROM {$table}
                         WHERE dossier_id = ? AND parent_id IS NOT NULL
                       )"
                );
                $stmt->execute([$dossierId, $dossierId]);
                $deleted = $stmt->rowCount();
            } while ($deleted > 0);
        }
        $this->pdo->prepare('DELETE FROM exercices WHERE dossier_id = ?')
            ->execute([$dossierId]);
    }

    private function assertDates(string $start, string $end): void
    {
        foreach ([$start, $end] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new DossierRegistryException('Dates d’exercice invalides.');
            }
        }
        if ($start > $end) {
            throw new DossierRegistryException('La fin précède le début de l’exercice.');
        }
    }

    private function conflict(): never
    {
        throw new DossierRegistryException(
            'Le dossier a été modifié par un autre utilisateur. Rechargez-le.',
            'DOSSIER_VERSION_CONFLICT'
        );
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function mark(string $step): void
    {
        if ($this->checkpoint !== null) {
            ($this->checkpoint)($step);
        }
    }

    private function transaction(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $callback();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
