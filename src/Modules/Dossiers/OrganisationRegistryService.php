<?php
declare(strict_types=1);

namespace Compta\Modules\Dossiers;

use Compta\Core\Audit\AuditLogger;
use DateTimeImmutable;
use PDO;
use Throwable;

final class OrganisationRegistryService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param list<int>|null $allowedIds Null means every organisation.
     * @return array<string,mixed>
     */
    public function list(
        string $search = '',
        string $status = 'active',
        int $page = 1,
        int $perPage = 20,
        ?array $allowedIds = null,
    ): array {
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $where = [];
        $params = [];
        if ($status === 'active') {
            $where[] = 'o.actif = 1';
        } elseif ($status === 'archived') {
            $where[] = 'o.actif = 0';
        } elseif ($status !== 'all') {
            throw new OrganisationRegistryException('Filtre de statut invalide.');
        }
        $search = trim($search);
        if ($search !== '') {
            $where[] = '(o.nom LIKE :search OR o.raison_sociale LIKE :search
                         OR o.numero_ide LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        if ($allowedIds !== null) {
            $allowedIds = array_values(array_unique(array_filter(
                array_map('intval', $allowedIds),
                static fn (int $id): bool => $id > 0
            )));
            if ($allowedIds === []) {
                return [
                    'items' => [],
                    'pagination' => [
                        'page' => $page, 'per_page' => $perPage,
                        'total' => 0, 'pages' => 0,
                    ],
                ];
            }
            $placeholders = [];
            foreach ($allowedIds as $index => $id) {
                $key = 'scope_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $id;
            }
            $where[] = 'o.id IN (' . implode(',', $placeholders) . ')';
        }
        $filter = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $count = $this->pdo->prepare(
            'SELECT COUNT(*) FROM organisations o' . $filter
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $sql = 'SELECT o.id, o.nom, o.nature, o.actif, o.version,
                       o.raison_sociale, o.forme_juridique, o.numero_ide,
                       o.cree_le, o.modifie_le,
                       COUNT(d.id) AS dossier_count,
                       SUM(CASE WHEN d.actif = 1 THEN 1 ELSE 0 END) AS active_dossier_count
                FROM organisations o
                LEFT JOIN dossiers d ON d.organisation_id = o.id'
            . $filter
            . ' GROUP BY o.id
                ORDER BY o.actif DESC, o.nom COLLATE NOCASE, o.id
                LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();
        $items = array_map([$this, 'normaliseOrganisation'], $stmt->fetchAll());
        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function detail(int $organisationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.*,
                    COUNT(d.id) AS dossier_count,
                    SUM(CASE WHEN d.actif = 1 THEN 1 ELSE 0 END) AS active_dossier_count
             FROM organisations o
             LEFT JOIN dossiers d ON d.organisation_id = o.id
             WHERE o.id = ? GROUP BY o.id'
        );
        $stmt->execute([$organisationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new OrganisationRegistryException(
                'Organisation introuvable.', 'ORGANISATION_NOT_FOUND'
            );
        }
        $history = $this->pdo->prepare(
            'SELECT id, date_debut, date_fin, raison_sociale, forme_juridique,
                    numero_ide, adresse_json, source, cree_le, cree_par
             FROM attributs_juridiques_organisation
             WHERE organisation_id = ?
             ORDER BY date_debut DESC, id DESC'
        );
        $history->execute([$organisationId]);
        $legal = array_map(static function (array $item): array {
            $item['id'] = (int) $item['id'];
            $item['cree_par'] = $item['cree_par'] === null
                ? null : (int) $item['cree_par'];
            $item['adresse'] = json_decode((string) $item['adresse_json'], true) ?: [];
            unset($item['adresse_json']);
            return $item;
        }, $history->fetchAll());
        $data = $this->normaliseOrganisation($row);
        $data['legal_history'] = $legal;
        $data['deletion_dependencies'] = $this->deletionDependencies($organisationId);
        return $data;
    }

    /** @param array<string,mixed> $identity */
    public function create(
        string $name,
        string $nature,
        ?array $identity,
        int $actorId,
    ): int {
        $name = trim($name);
        if ($name === '' || !in_array($nature, ['reelle', 'pedagogique'], true)) {
            throw new OrganisationRegistryException('Nom ou nature invalide.');
        }
        if ($nature === 'reelle') {
            $this->validateIdentity($identity ?? []);
        }
        return $this->transaction(function () use (
            $name, $nature, $identity, $actorId
        ): int {
            $legal = $identity ?? [];
            $address = is_array($legal['address'] ?? null)
                ? $legal['address'] : [];
            $this->assertUidAvailable(
                trim((string) ($legal['uid'] ?? '')), 0
            );
            $this->pdo->prepare(
                'INSERT INTO organisations
                 (nom, nature, raison_sociale, forme_juridique, numero_ide,
                  adresse_ligne1, adresse_ligne2, code_postal, localite,
                  canton, pays)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $name, $nature,
                trim((string) ($legal['legal_name'] ?? '')),
                trim((string) ($legal['legal_form'] ?? '')),
                trim((string) ($legal['uid'] ?? '')),
                trim((string) ($address['line1'] ?? '')),
                trim((string) ($address['line2'] ?? '')),
                trim((string) ($address['postal_code'] ?? '')),
                trim((string) ($address['city'] ?? '')),
                trim((string) ($address['canton'] ?? '')),
                trim((string) ($address['country'] ?? 'CH')) ?: 'CH',
            ]);
            $id = (int) $this->pdo->lastInsertId();
            if (trim((string) ($legal['legal_name'] ?? '')) !== '') {
                $this->insertLegalIdentity($id, $legal, $actorId);
            }
            $after = $this->organisationSnapshot($id);
            $this->audit->log(
                'organisation.creee', $actorId, $id, null,
                'organisation', (string) $id,
                ['before' => null, 'after' => $after]
            );
            return $id;
        });
    }

    public function updateName(
        int $organisationId,
        string $name,
        int $expectedVersion,
        int $actorId,
    ): void {
        $name = trim($name);
        if ($name === '') {
            throw new OrganisationRegistryException('Le nom usuel est requis.');
        }
        $this->transaction(function () use (
            $organisationId, $name, $expectedVersion, $actorId
        ): void {
            $before = $this->organisationSnapshot($organisationId);
            $stmt = $this->pdo->prepare(
                'UPDATE organisations
                 SET nom = ?, version = version + 1, modifie_le = datetime(\'now\')
                 WHERE id = ? AND version = ?'
            );
            $stmt->execute([$name, $organisationId, $expectedVersion]);
            $this->assertUpdated($stmt->rowCount(), $organisationId);
            $this->auditMutation('organisation.modifiee', $actorId, $organisationId, $before);
        });
    }

    /** @param array<string,mixed> $identity */
    public function saveLegalIdentity(
        int $organisationId,
        int $expectedVersion,
        array $identity,
        int $actorId,
        ?int $expectedLegalIdentityId = null,
    ): int {
        $this->validateIdentity($identity);
        return $this->transaction(function () use (
            $organisationId, $expectedVersion, $identity, $actorId,
            $expectedLegalIdentityId
        ): int {
            $before = $this->organisationSnapshot($organisationId);
            $this->assertUidAvailable(trim((string) $identity['uid']), $organisationId);
            $previous = $this->pdo->prepare(
                'SELECT id, date_debut FROM attributs_juridiques_organisation
                 WHERE organisation_id = ? AND date_fin IS NULL
                 ORDER BY date_debut DESC LIMIT 1'
            );
            $previous->execute([$organisationId]);
            $open = $previous->fetch();
            $currentLegalIdentityId = $open === false ? 0 : (int) $open['id'];
            if (
                $expectedLegalIdentityId !== null
                && $currentLegalIdentityId !== $expectedLegalIdentityId
            ) {
                $this->conflict();
            }
            if (
                (int) $before['version'] !== $expectedVersion
                && $expectedLegalIdentityId === null
            ) {
                $this->conflict();
            }
            $writeVersion = (int) $before['version'];
            if ($open !== false) {
                if ((string) $open['date_debut'] >= (string) $identity['valid_from']) {
                    throw new OrganisationRegistryException(
                        'La nouvelle identité doit commencer après la version courante.'
                    );
                }
                $end = (new DateTimeImmutable((string) $identity['valid_from']))
                    ->modify('-1 day')->format('Y-m-d');
                $this->pdo->prepare(
                    'UPDATE attributs_juridiques_organisation SET date_fin = ? WHERE id = ?'
                )->execute([$end, (int) $open['id']]);
            }
            $id = $this->insertLegalIdentity($organisationId, $identity, $actorId);
            $address = $identity['address'];
            $stmt = $this->pdo->prepare(
                'UPDATE organisations
                 SET raison_sociale = ?, forme_juridique = ?, numero_ide = ?,
                     adresse_ligne1 = ?, adresse_ligne2 = ?, code_postal = ?,
                     localite = ?, canton = ?, pays = ?,
                     version = version + 1, modifie_le = datetime(\'now\')
                 WHERE id = ? AND version = ?'
            );
            $stmt->execute([
                trim((string) $identity['legal_name']),
                trim((string) $identity['legal_form']),
                trim((string) $identity['uid']),
                trim((string) ($address['line1'] ?? '')),
                trim((string) ($address['line2'] ?? '')),
                trim((string) ($address['postal_code'] ?? '')),
                trim((string) ($address['city'] ?? '')),
                trim((string) ($address['canton'] ?? '')),
                trim((string) ($address['country'] ?? 'CH')) ?: 'CH',
                $organisationId, $writeVersion,
            ]);
            $this->assertUpdated($stmt->rowCount(), $organisationId);
            $this->auditMutation(
                'organisation.identite_juridique_datee',
                $actorId,
                $organisationId,
                $before,
                ['identity_id' => $id, 'source' => (string) $identity['source']]
            );
            return $id;
        });
    }

    public function archive(
        int $organisationId,
        int $expectedVersion,
        int $actorId,
    ): void {
        $this->changeStatus($organisationId, $expectedVersion, false, $actorId);
    }

    public function reactivate(
        int $organisationId,
        int $expectedVersion,
        int $actorId,
    ): void {
        $this->changeStatus($organisationId, $expectedVersion, true, $actorId);
    }

    public function delete(
        int $organisationId,
        int $expectedVersion,
        int $actorId,
    ): void {
        $this->transaction(function () use (
            $organisationId, $expectedVersion, $actorId
        ): void {
            $before = $this->organisationSnapshot($organisationId);
            if ((int) $before['version'] !== $expectedVersion) {
                $this->conflict();
            }
            $dependencies = $this->deletionDependencies($organisationId);
            if ($dependencies !== []) {
                throw new OrganisationRegistryException(
                    'Suppression impossible : des dépendances métier subsistent.',
                    'ORGANISATION_HAS_DEPENDENCIES',
                    $dependencies
                );
            }
            $this->audit->log(
                'organisation.supprimee', $actorId, $organisationId, null,
                'organisation', (string) $organisationId,
                ['before' => $before, 'after' => null]
            );
            $stmt = $this->pdo->prepare(
                'DELETE FROM organisations WHERE id = ? AND version = ?'
            );
            $stmt->execute([$organisationId, $expectedVersion]);
            $this->assertUpdated($stmt->rowCount(), $organisationId);
        });
    }

    /** @return array<string,int> */
    public function deletionDependencies(int $organisationId): array
    {
        $ignored = [
            'audit_events',
            'parametres_organisation',
            'utilisateur_roles_organisation',
        ];
        $tables = $this->pdo->query(
            "SELECT name FROM sqlite_master
             WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
             ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);
        $dependencies = [];
        foreach ($tables as $table) {
            $table = (string) $table;
            if (in_array($table, $ignored, true)) {
                continue;
            }
            $quoted = '"' . str_replace('"', '""', $table) . '"';
            foreach ($this->pdo->query('PRAGMA foreign_key_list(' . $quoted . ')')->fetchAll() as $fk) {
                if ((string) $fk['table'] !== 'organisations') {
                    continue;
                }
                $column = (string) $fk['from'];
                $quotedColumn = '"' . str_replace('"', '""', $column) . '"';
                $stmt = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM ' . $quoted . ' WHERE '
                    . $quotedColumn . ' = ?'
                );
                $stmt->execute([$organisationId]);
                $count = (int) $stmt->fetchColumn();
                if ($count > 0) {
                    $dependencies[$table] = ($dependencies[$table] ?? 0) + $count;
                }
            }
        }
        return $dependencies;
    }

    private function changeStatus(
        int $organisationId,
        int $expectedVersion,
        bool $active,
        int $actorId,
    ): void {
        $this->transaction(function () use (
            $organisationId, $expectedVersion, $active, $actorId
        ): void {
            $before = $this->organisationSnapshot($organisationId);
            if ((bool) $before['active'] === $active) {
                if ((int) $before['version'] !== $expectedVersion) {
                    $this->conflict();
                }
                return;
            }
            if (!$active) {
                $activeDossiers = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM dossiers
                     WHERE organisation_id = ? AND actif = 1'
                );
                $activeDossiers->execute([$organisationId]);
                if ((int) $activeDossiers->fetchColumn() > 0) {
                    throw new OrganisationRegistryException(
                        'Archivez d’abord tous les dossiers actifs de cette organisation.',
                        'ORGANISATION_HAS_ACTIVE_DOSSIERS'
                    );
                }
            }
            $stmt = $this->pdo->prepare(
                'UPDATE organisations
                 SET actif = ?, version = version + 1, modifie_le = datetime(\'now\')
                 WHERE id = ? AND version = ?'
            );
            $stmt->execute([$active ? 1 : 0, $organisationId, $expectedVersion]);
            $this->assertUpdated($stmt->rowCount(), $organisationId);
            $this->auditMutation(
                $active ? 'organisation.reactivee' : 'organisation.archivee',
                $actorId,
                $organisationId,
                $before
            );
        });
    }

    /** @param array<string,mixed> $identity */
    private function validateIdentity(array $identity): void
    {
        foreach (['valid_from', 'legal_name', 'source'] as $field) {
            if (trim((string) ($identity[$field] ?? '')) === '') {
                throw new OrganisationRegistryException(
                    'Date de début, raison sociale et source sont requises.'
                );
            }
        }
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d', (string) $identity['valid_from']
        );
        if ($date === false || $date->format('Y-m-d') !== $identity['valid_from']) {
            throw new OrganisationRegistryException('Date de début invalide.');
        }
        if (!is_array($identity['address'] ?? null)) {
            throw new OrganisationRegistryException('Adresse juridique invalide.');
        }
    }

    private function assertUidAvailable(string $uid, int $organisationId): void
    {
        if ($uid === '') {
            return;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM organisations
             WHERE numero_ide = ? AND id <> ?'
        );
        $stmt->execute([$uid, $organisationId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new OrganisationRegistryException(
                'Ce numéro IDE est déjà attribué à une autre organisation.'
            );
        }
    }

    /** @param array<string,mixed> $identity */
    private function insertLegalIdentity(
        int $organisationId,
        array $identity,
        int $actorId,
    ): int {
        $address = json_encode(
            $identity['address'] ?? [],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
        $this->pdo->prepare(
            'INSERT INTO attributs_juridiques_organisation
             (organisation_id, date_debut, raison_sociale, forme_juridique,
              numero_ide, adresse_json, source, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $organisationId,
            (string) $identity['valid_from'],
            trim((string) $identity['legal_name']),
            trim((string) ($identity['legal_form'] ?? '')),
            trim((string) ($identity['uid'] ?? '')),
            $address,
            trim((string) $identity['source']),
            $actorId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function organisationSnapshot(int $organisationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, nom, nature, actif, version, raison_sociale,
                    forme_juridique, numero_ide
             FROM organisations WHERE id = ?'
        );
        $stmt->execute([$organisationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new OrganisationRegistryException(
                'Organisation introuvable.', 'ORGANISATION_NOT_FOUND'
            );
        }
        return $this->normaliseOrganisation($row);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normaliseOrganisation(array $row): array
    {
        foreach (['id', 'version', 'dossier_count', 'active_dossier_count'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = (int) ($row[$key] ?? 0);
            }
        }
        if (array_key_exists('actif', $row)) {
            $row['active'] = (bool) $row['actif'];
            unset($row['actif']);
        }
        return $row;
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $extra */
    private function auditMutation(
        string $action,
        int $actorId,
        int $organisationId,
        array $before,
        array $extra = [],
    ): void {
        $this->audit->log(
            $action, $actorId, $organisationId, null,
            'organisation', (string) $organisationId,
            ['before' => $before, 'after' => $this->organisationSnapshot($organisationId)]
                + $extra
        );
    }

    private function assertUpdated(int $count, int $organisationId): void
    {
        if ($count > 0) {
            return;
        }
        $exists = $this->pdo->prepare('SELECT 1 FROM organisations WHERE id = ?');
        $exists->execute([$organisationId]);
        if ($exists->fetchColumn() === false) {
            throw new OrganisationRegistryException(
                'Organisation introuvable.', 'ORGANISATION_NOT_FOUND'
            );
        }
        $this->conflict();
    }

    private function conflict(): never
    {
        throw new OrganisationRegistryException(
            'Cette organisation a été modifiée par un autre utilisateur.',
            'ORGANISATION_VERSION_CONFLICT'
        );
    }

    private function transaction(callable $callback): mixed
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
