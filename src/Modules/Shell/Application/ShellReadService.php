<?php
declare(strict_types=1);

namespace Compta\Modules\Shell\Application;

use Compta\Core\Http\Api\ListQuery;
use PDO;

final class ShellReadService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id:int,email:string,first_name:string,last_name:string,name:string}|null */
    public function user(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, prenom, nom FROM utilisateurs WHERE id = ? AND actif = 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $firstName = (string) $row['prenom'];
        $lastName = (string) $row['nom'];
        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => trim($firstName . ' ' . $lastName),
        ];
    }

    /** @return list<string> */
    public function permissions(int $userId, int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT p.code
             FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             JOIN (
                 SELECT role_id
                 FROM utilisateur_roles_installation
                 WHERE utilisateur_id = :installation_user
                 UNION
                 SELECT role_id
                 FROM utilisateur_roles_organisation
                 WHERE utilisateur_id = :organisation_user
                   AND organisation_id = :organisation
                 UNION
                 SELECT role_id
                 FROM utilisateur_roles_dossier
                 WHERE utilisateur_id = :dossier_user
                   AND dossier_id = :dossier
             ) assigned ON assigned.role_id = rp.role_id
             ORDER BY p.code'
        );
        $stmt->execute([
            'installation_user' => $userId,
            'organisation_user' => $userId,
            'organisation' => $organisationId,
            'dossier_user' => $userId,
            'dossier' => $dossierId,
        ]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<array<string, mixed>> $visibleDossiers
     * @return array{items:list<array<string,mixed>>,pagination:array<string,int|bool>}
     */
    public function dossiers(array $visibleDossiers, ListQuery $query): array
    {
        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'organization_id' => (int) $row['organisation_id'],
            'organization_name' => (string) $row['organisation_nom'],
            'name' => (string) $row['nom'],
            'type' => (string) $row['type'],
        ], $visibleDossiers);
        $search = mb_strtolower($query->filters['search'] ?? '');
        $type = $query->filters['type'] ?? '';
        $items = array_values(array_filter(
            $items,
            static function (array $row) use ($search, $type): bool {
                if ($type !== '' && $row['type'] !== $type) {
                    return false;
                }
                if ($search === '') {
                    return true;
                }
                return str_contains(
                    mb_strtolower($row['organization_name'] . ' ' . $row['name']),
                    $search
                );
            }
        ));
        $sortKeys = [
            'id' => 'id',
            'name' => 'name',
            'organization_name' => 'organization_name',
            'type' => 'type',
        ];
        $sortKey = $sortKeys[$query->sort];
        usort($items, static function (array $left, array $right) use ($sortKey, $query): int {
            $comparison = is_int($left[$sortKey])
                ? $left[$sortKey] <=> $right[$sortKey]
                : strnatcasecmp((string) $left[$sortKey], (string) $right[$sortKey]);
            if ($comparison === 0) {
                $comparison = $left['id'] <=> $right['id'];
            }
            return $query->order === 'desc' ? -$comparison : $comparison;
        });
        return $this->page($items, $query);
    }

    /** @return array{items:list<array<string,mixed>>,pagination:array<string,int|bool>} */
    public function exercises(
        int $organisationId,
        int $dossierId,
        ListQuery $query,
    ): array {
        $where = [
            'e.dossier_id = :dossier',
            'd.organisation_id = :organisation',
        ];
        $params = [
            'dossier' => $dossierId,
            'organisation' => $organisationId,
        ];
        if (isset($query->filters['status'])) {
            $where[] = 'e.statut = :status';
            $params['status'] = $query->filters['status'];
        }
        if (isset($query->filters['search'])) {
            $where[] = 'LOWER(e.libelle) LIKE :search ESCAPE \'\\\'';
            $params['search'] = '%' . $this->escapeLike(
                mb_strtolower($query->filters['search'])
            ) . '%';
        }
        $whereSql = implode(' AND ', $where);
        $count = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM exercices e
             JOIN dossiers d ON d.id = e.dossier_id
             WHERE {$whereSql}"
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $sorts = [
            'id' => 'e.id',
            'label' => 'e.libelle',
            'start_date' => 'e.date_debut',
            'end_date' => 'e.date_fin',
            'status' => 'e.statut',
        ];
        $order = $query->order === 'desc' ? 'DESC' : 'ASC';
        $stmt = $this->pdo->prepare(
            'SELECT e.id, e.libelle, e.date_debut, e.date_fin, e.statut, e.version
             FROM exercices e
             JOIN dossiers d ON d.id = e.dossier_id
             WHERE ' . $whereSql . '
             ORDER BY ' . $sorts[$query->sort] . ' ' . $order . ', e.id ' . $order . '
             LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $query->perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $query->offset(), PDO::PARAM_INT);
        $stmt->execute();
        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'label' => (string) $row['libelle'],
            'start_date' => (string) $row['date_debut'],
            'end_date' => (string) $row['date_fin'],
            'status' => (string) $row['statut'],
            'version' => (int) $row['version'],
        ], $stmt->fetchAll());
        return [
            'items' => $items,
            'pagination' => $this->pagination($total, $query),
        ];
    }

    /** @return array<string, mixed>|null */
    public function currentExercise(int $organisationId, int $dossierId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.id, e.libelle, e.date_debut, e.date_fin, e.statut
             FROM exercices e
             JOIN dossiers d ON d.id = e.dossier_id
             WHERE e.dossier_id = ? AND d.organisation_id = ?
             ORDER BY CASE e.statut WHEN 'ouvert' THEN 0 ELSE 1 END,
                      e.date_debut DESC, e.id DESC
             LIMIT 1"
        );
        $stmt->execute([$dossierId, $organisationId]);
        $row = $stmt->fetch();
        return $row === false ? null : [
            'id' => (int) $row['id'],
            'label' => (string) $row['libelle'],
            'start_date' => (string) $row['date_debut'],
            'end_date' => (string) $row['date_fin'],
            'status' => (string) $row['statut'],
        ];
    }

    public function dossierCurrency(int $organisationId, int $dossierId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT monnaie
             FROM dossiers
             WHERE id = ? AND organisation_id = ? AND actif = 1'
        );
        $stmt->execute([$dossierId, $organisationId]);
        $currency = $stmt->fetchColumn();
        return is_string($currency) && $currency !== '' ? $currency : 'CHF';
    }

    /** @param list<string> $permissions @return list<array<string,mixed>> */
    public function navigation(array $permissions, bool $hasDossier): array
    {
        $definitions = [
            ['key' => 'dashboard', 'label' => 'Tableau de bord', 'path' => '/', 'permission' => null],
            ['key' => 'learning', 'label' => 'Apprentissage', 'path' => '/pedagogie', 'permission' => 'pedagogie.view'],
            ['key' => 'liquidity', 'label' => 'Liquidités', 'path' => '/liquidites', 'permission' => 'tresorerie.view'],
            ['key' => 'billing', 'label' => 'Facturation', 'path' => '/facturation', 'permission' => 'facturation.view'],
            ['key' => 'accounting', 'label' => 'Comptabilité', 'path' => '/compta', 'permission' => 'compta.view'],
            ['key' => 'payroll', 'label' => 'Salaires', 'path' => '/salaires', 'permission' => 'salaires.view'],
            ['key' => 'settings', 'label' => 'Configuration', 'path' => '/configuration', 'permission' => 'dossier.manage'],
        ];
        return array_values(array_filter(
            $definitions,
            static fn (array $item): bool => $item['permission'] === null
                || ($hasDossier && in_array($item['permission'], $permissions, true))
        ));
    }

    /** @return array<string, mixed> */
    public function references(string $currency): array
    {
        return [
            'dossier_types' => [
                ['value' => 'reel', 'label' => 'Réel'],
                ['value' => 'demo', 'label' => 'Démonstration'],
                ['value' => 'exercice', 'label' => 'Exercice'],
            ],
            'exercise_statuses' => [
                ['value' => 'ouvert', 'label' => 'Ouvert'],
                ['value' => 'ferme', 'label' => 'Fermé'],
            ],
            'currencies' => [
                ['code' => $currency, 'is_base' => true],
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array{items:list<array<string,mixed>>,pagination:array<string,int|bool>}
     */
    private function page(array $items, ListQuery $query): array
    {
        $total = count($items);
        return [
            'items' => array_values(array_slice($items, $query->offset(), $query->perPage)),
            'pagination' => $this->pagination($total, $query),
        ];
    }

    /** @return array<string, int|bool> */
    private function pagination(int $total, ListQuery $query): array
    {
        $pages = max(1, (int) ceil($total / $query->perPage));
        return [
            'page' => $query->page,
            'per_page' => $query->perPage,
            'total' => $total,
            'pages' => $pages,
            'has_more' => $query->page < $pages,
        ];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
