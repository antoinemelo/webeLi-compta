<?php
declare(strict_types=1);

namespace Compta\Modules\Dossiers;

use Compta\Core\Audit\AuditLogger;
use PDO;
use RuntimeException;
use Throwable;

final class ScopeManager
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
    ) {
    }

    public function createOrganisation(string $name, string $nature, ?int $actorId = null): int
    {
        $name = trim($name);
        if ($name === '' || !in_array($nature, ['reelle', 'pedagogique'], true)) {
            throw new RuntimeException('Nom ou nature d’organisation invalide.');
        }
        return $this->transaction(function () use ($name, $nature, $actorId): int {
            $stmt = $this->pdo->prepare(
                'INSERT INTO organisations (nom, nature) VALUES (:name, :nature)'
            );
            $stmt->execute(['name' => $name, 'nature' => $nature]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit->log(
                'organisation.creee',
                $actorId,
                $id,
                targetType: 'organisation',
                targetId: (string) $id,
                summary: ['nom' => $name, 'nature' => $nature]
            );
            return $id;
        });
    }

    public function createDossier(
        int $organisationId,
        string $name,
        string $slug,
        string $type,
        ?int $actorId = null,
    ): int {
        $name = trim($name);
        $slug = mb_strtolower(trim($slug));
        if (
            $organisationId < 1
            || $name === ''
            || !preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug)
            || !in_array($type, ['reel', 'demo', 'exercice'], true)
        ) {
            throw new RuntimeException('Paramètres de dossier invalides.');
        }
        $exists = $this->pdo->prepare('SELECT 1 FROM organisations WHERE id = ? AND actif = 1');
        $exists->execute([$organisationId]);
        if ($exists->fetchColumn() === false) {
            throw new RuntimeException('Organisation inexistante ou inactive.');
        }
        return $this->transaction(function () use (
            $organisationId,
            $name,
            $slug,
            $type,
            $actorId
        ): int {
            $stmt = $this->pdo->prepare(
                'INSERT INTO dossiers (organisation_id, nom, slug, type)
                 VALUES (:organisation, :name, :slug, :type)'
            );
            $stmt->execute([
                'organisation' => $organisationId,
                'name' => $name,
                'slug' => $slug,
                'type' => $type,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit->log(
                'dossier.cree',
                $actorId,
                $organisationId,
                $id,
                'dossier',
                (string) $id,
                ['nom' => $name, 'type' => $type]
            );
            return $id;
        });
    }

    public function createExercise(
        int $dossierId,
        string $label,
        string $start,
        string $end,
        ?int $actorId = null,
    ): int {
        if (
            $dossierId < 1
            || trim($label) === ''
            || !$this->validDate($start)
            || !$this->validDate($end)
            || $start > $end
        ) {
            throw new RuntimeException('Paramètres d’exercice invalides.');
        }
        $scope = $this->pdo->prepare(
            'SELECT organisation_id FROM dossiers WHERE id = ? AND actif = 1'
        );
        $scope->execute([$dossierId]);
        $organisationId = $scope->fetchColumn();
        if ($organisationId === false) {
            throw new RuntimeException('Dossier inexistant ou inactif.');
        }
        return $this->transaction(function () use (
            $dossierId,
            $label,
            $start,
            $end,
            $actorId,
            $organisationId
        ): int {
            $stmt = $this->pdo->prepare(
                'INSERT INTO exercices (dossier_id, libelle, date_debut, date_fin)
                 VALUES (:dossier, :label, :start, :end)'
            );
            $stmt->execute([
                'dossier' => $dossierId,
                'label' => trim($label),
                'start' => $start,
                'end' => $end,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit->log(
                'exercice.cree',
                $actorId,
                (int) $organisationId,
                $dossierId,
                'exercice',
                (string) $id,
                ['libelle' => trim($label), 'debut' => $start, 'fin' => $end]
            );
            return $id;
        });
    }

    public function grantRole(
        int $userId,
        string $roleCode,
        string $scope,
        ?int $scopeId = null,
        ?int $actorId = null,
    ): void {
        $role = $this->pdo->prepare('SELECT id FROM roles WHERE code = ?');
        $role->execute([$roleCode]);
        $roleId = $role->fetchColumn();
        if ($roleId === false) {
            throw new RuntimeException('Rôle inconnu.');
        }
        $user = $this->pdo->prepare('SELECT 1 FROM utilisateurs WHERE id = ? AND actif = 1');
        $user->execute([$userId]);
        if ($user->fetchColumn() === false) {
            throw new RuntimeException('Utilisateur inexistant ou inactif.');
        }

        [$table, $column, $organisationId, $dossierId] = match ($scope) {
            'installation' => ['utilisateur_roles_installation', null, null, null],
            'organisation' => $this->organisationGrantContext($scopeId),
            'dossier' => $this->dossierGrantContext($scopeId, $roleCode),
            default => throw new RuntimeException('Scope de rôle inconnu.'),
        };

        $this->transaction(function () use (
            $table,
            $column,
            $scopeId,
            $userId,
            $roleId,
            $roleCode,
            $scope,
            $organisationId,
            $dossierId,
            $actorId
        ): void {
            if ($column === null) {
                $sql = "INSERT OR IGNORE INTO {$table} (utilisateur_id, role_id) VALUES (?, ?)";
                $params = [$userId, $roleId];
            } else {
                $sql = "INSERT OR IGNORE INTO {$table} (utilisateur_id, {$column}, role_id)
                        VALUES (?, ?, ?)";
                $params = [$userId, $scopeId, $roleId];
            }
            $this->pdo->prepare($sql)->execute($params);
            $this->audit->log(
                'role.attribue',
                $actorId,
                $organisationId,
                $dossierId,
                'utilisateur',
                (string) $userId,
                ['role' => $roleCode, 'scope' => $scope]
            );
        });
    }

    /** @return array{string,string,int,null} */
    private function organisationGrantContext(?int $organisationId): array
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM organisations WHERE id = ? AND actif = 1');
        $stmt->execute([$organisationId]);
        if ($organisationId === null || $stmt->fetchColumn() === false) {
            throw new RuntimeException('Organisation de scope invalide.');
        }
        return ['utilisateur_roles_organisation', 'organisation_id', $organisationId, null];
    }

    /** @return array{string,string,int,int} */
    private function dossierGrantContext(?int $dossierId, string $roleCode): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.organisation_id, d.type, o.nature
             FROM dossiers d JOIN organisations o ON o.id = d.organisation_id
             WHERE d.id = ? AND d.actif = 1 AND o.actif = 1'
        );
        $stmt->execute([$dossierId]);
        $row = $stmt->fetch();
        if ($dossierId === null || $row === false) {
            throw new RuntimeException('Dossier de scope invalide.');
        }
        if (
            $roleCode === 'apprenant'
            && ($row['type'] === 'reel' || $row['nature'] === 'reelle')
        ) {
            throw new RuntimeException('Un apprenant ne peut pas recevoir un dossier réel.');
        }
        return [
            'utilisateur_roles_dossier',
            'dossier_id',
            (int) $row['organisation_id'],
            $dossierId,
        ];
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
