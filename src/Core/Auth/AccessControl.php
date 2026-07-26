<?php
declare(strict_types=1);

namespace Compta\Core\Auth;

use PDO;

final class AccessControl
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function canViewDossier(int $userId, int $organisationId, int $dossierId): bool
    {
        if ($this->learnerOnly($userId)) {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM dossiers d
                 JOIN organisations o ON o.id = d.organisation_id
                 JOIN utilisateur_roles_dossier urd
                   ON urd.dossier_id = d.id AND urd.utilisateur_id = ?
                 JOIN roles r ON r.id = urd.role_id AND r.code = 'apprenant'
                 WHERE d.id = ? AND d.organisation_id = ? AND d.actif = 1
                   AND d.type <> 'reel' AND o.nature = 'pedagogique'"
            );
            $stmt->execute([$userId, $dossierId, $organisationId]);
            return $stmt->fetchColumn() !== false;
        }
        $stmt = $this->pdo->prepare(
            "SELECT 1
             FROM dossiers d
             WHERE d.id = :dossier AND d.organisation_id = :organisation AND d.actif = 1
               AND (
                 EXISTS (
                   SELECT 1 FROM utilisateur_roles_installation uri
                   JOIN roles r ON r.id = uri.role_id
                   JOIN role_permissions rp ON rp.role_id = r.id
                   JOIN permissions p ON p.id = rp.permission_id
                   WHERE uri.utilisateur_id = :user AND p.code = 'dossier.view'
                 )
                 OR EXISTS (
                   SELECT 1 FROM utilisateur_roles_organisation uro
                   JOIN role_permissions rp ON rp.role_id = uro.role_id
                   JOIN permissions p ON p.id = rp.permission_id
                   WHERE uro.utilisateur_id = :user
                     AND uro.organisation_id = d.organisation_id
                     AND p.code = 'dossier.view'
                 )
                 OR EXISTS (
                   SELECT 1 FROM utilisateur_roles_dossier urd
                   JOIN role_permissions rp ON rp.role_id = urd.role_id
                   JOIN permissions p ON p.id = rp.permission_id
                   WHERE urd.utilisateur_id = :user
                     AND urd.dossier_id = d.id
                     AND p.code = 'dossier.view'
                 )
               )
             LIMIT 1"
        );
        $stmt->execute([
            'user' => $userId,
            'organisation' => $organisationId,
            'dossier' => $dossierId,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    public function hasDossierPermission(
        int $userId,
        int $organisationId,
        int $dossierId,
        string $permission,
    ): bool {
        if (
            $this->learnerOnly($userId)
            && !$this->canViewDossier($userId, $organisationId, $dossierId)
        ) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM dossiers d
             WHERE d.id = :dossier AND d.organisation_id = :organisation AND d.actif = 1
               AND (
                 EXISTS (
                   SELECT 1 FROM utilisateur_roles_installation ur
                   JOIN role_permissions rp ON rp.role_id = ur.role_id
                   JOIN permissions p ON p.id = rp.permission_id
                   WHERE ur.utilisateur_id = :user AND p.code = :permission
                 )
                 OR EXISTS (
                   SELECT 1 FROM utilisateur_roles_organisation ur
                   JOIN role_permissions rp ON rp.role_id = ur.role_id
                   JOIN permissions p ON p.id = rp.permission_id
                   WHERE ur.utilisateur_id = :user
                     AND ur.organisation_id = d.organisation_id
                     AND p.code = :permission
                 )
                 OR EXISTS (
                   SELECT 1 FROM utilisateur_roles_dossier ur
                   JOIN role_permissions rp ON rp.role_id = ur.role_id
                   JOIN permissions p ON p.id = rp.permission_id
                   WHERE ur.utilisateur_id = :user
                     AND ur.dossier_id = d.id
                     AND p.code = :permission
                 )
               )
             LIMIT 1'
        );
        $stmt->execute([
            'user' => $userId,
            'organisation' => $organisationId,
            'dossier' => $dossierId,
            'permission' => $permission,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Vérifie une permission d'administration même sur un dossier archivé.
     * L'organisation, le dossier et leur couple restent strictement contrôlés.
     */
    public function hasDossierPermissionIncludingArchived(
        int $userId,
        int $organisationId,
        int $dossierId,
        string $permission,
    ): bool {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM dossiers d
             WHERE d.id = :dossier AND d.organisation_id = :organisation
               AND (
                 EXISTS (
                   SELECT 1 FROM utilisateur_roles_installation ur
                   JOIN role_permissions rp ON rp.role_id = ur.role_id
                   JOIN permissions p ON p.id = rp.permission_id
                   WHERE ur.utilisateur_id = :user AND p.code = :permission
                 )
                 OR EXISTS (
                   SELECT 1 FROM utilisateur_roles_organisation ur
                   JOIN role_permissions rp ON rp.role_id = ur.role_id
                   JOIN permissions p ON p.id = rp.permission_id
                   WHERE ur.utilisateur_id = :user
                     AND ur.organisation_id = d.organisation_id
                     AND p.code = :permission
                 )
                 OR EXISTS (
                   SELECT 1 FROM utilisateur_roles_dossier ur
                   JOIN role_permissions rp ON rp.role_id = ur.role_id
                   JOIN permissions p ON p.id = rp.permission_id
                   WHERE ur.utilisateur_id = :user
                     AND ur.dossier_id = d.id
                     AND p.code = :permission
                 )
               )
             LIMIT 1'
        );
        $stmt->execute([
            'user' => $userId,
            'organisation' => $organisationId,
            'dossier' => $dossierId,
            'permission' => $permission,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    public function hasInstallationPermission(
        int $userId,
        string $permission,
    ): bool {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM utilisateur_roles_installation ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.utilisateur_id = ? AND p.code = ?
             LIMIT 1'
        );
        $stmt->execute([$userId, $permission]);
        return $stmt->fetchColumn() !== false;
    }

    public function hasOrganisationPermission(
        int $userId,
        int $organisationId,
        string $permission,
    ): bool {
        if ($this->hasInstallationPermission($userId, $permission)) {
            return true;
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM utilisateur_roles_organisation ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.utilisateur_id = ?
               AND ur.organisation_id = ?
               AND p.code = ?
             LIMIT 1'
        );
        $stmt->execute([$userId, $organisationId, $permission]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return list<int> */
    public function organisationIdsForPermission(
        int $userId,
        string $permission,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT ur.organisation_id
             FROM utilisateur_roles_organisation ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.utilisateur_id = ? AND p.code = ?
             ORDER BY ur.organisation_id'
        );
        $stmt->execute([$userId, $permission]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array<string, mixed>> */
    public function dossiersForUser(int $userId): array
    {
        if ($this->learnerOnly($userId)) {
            $stmt = $this->pdo->prepare(
                "SELECT DISTINCT d.id, d.nom, d.type, d.organisation_id,
                        o.nom AS organisation_nom
                 FROM dossiers d
                 JOIN organisations o ON o.id = d.organisation_id
                   AND o.nature = 'pedagogique'
                 JOIN utilisateur_roles_dossier urd
                   ON urd.dossier_id = d.id AND urd.utilisateur_id = ?
                 JOIN roles r ON r.id = urd.role_id AND r.code = 'apprenant'
                 WHERE d.actif = 1 AND d.type <> 'reel' AND o.actif = 1
                 ORDER BY o.nom, d.nom"
            );
            $stmt->execute([$userId]);
            return $stmt->fetchAll();
        }
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT d.id, d.nom, d.type, d.organisation_id, o.nom AS organisation_nom
             FROM dossiers d
             JOIN organisations o ON o.id = d.organisation_id
             LEFT JOIN utilisateur_roles_installation uri ON uri.utilisateur_id = :user
             LEFT JOIN utilisateur_roles_organisation uro
               ON uro.utilisateur_id = :user AND uro.organisation_id = o.id
             LEFT JOIN utilisateur_roles_dossier urd
               ON urd.utilisateur_id = :user AND urd.dossier_id = d.id
             WHERE d.actif = 1 AND o.actif = 1
               AND (uri.role_id IS NOT NULL OR uro.role_id IS NOT NULL OR urd.role_id IS NOT NULL)
               AND EXISTS (
                 SELECT 1 FROM role_permissions rp
                 JOIN permissions p ON p.id = rp.permission_id
                 WHERE p.code = 'dossier.view'
                   AND rp.role_id IN (uri.role_id, uro.role_id, urd.role_id)
               )
             ORDER BY o.nom, d.nom"
        );
        $stmt->execute(['user' => $userId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function visibleDossier(
        int $userId,
        int $organisationId,
        int $dossierId,
    ): ?array {
        if (!$this->canViewDossier($userId, $organisationId, $dossierId)) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT d.id, d.nom, d.type, d.organisation_id,
                    o.nom AS organisation_nom, o.nature,
                    (
                      SELECT x.libelle
                      FROM exercices x
                      WHERE x.dossier_id = d.id
                      ORDER BY CASE x.statut WHEN \'ouvert\' THEN 0 ELSE 1 END,
                               x.date_debut DESC
                      LIMIT 1
                    ) AS exercice_nom
             FROM dossiers d JOIN organisations o ON o.id = d.organisation_id
             WHERE d.id = ? AND d.organisation_id = ?'
        );
        $stmt->execute([$dossierId, $organisationId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    private function learnerOnly(int $userId): bool
    {
        $roles = $this->pdo->prepare(
            "SELECT DISTINCT r.code FROM roles r JOIN (
               SELECT role_id FROM utilisateur_roles_installation WHERE utilisateur_id = ?
               UNION ALL
               SELECT role_id FROM utilisateur_roles_organisation WHERE utilisateur_id = ?
               UNION ALL
               SELECT role_id FROM utilisateur_roles_dossier WHERE utilisateur_id = ?
             ) ur ON ur.role_id = r.id"
        );
        $roles->execute([$userId, $userId, $userId]);
        $codes = $roles->fetchAll(PDO::FETCH_COLUMN);
        return in_array('apprenant', $codes, true)
            && array_diff($codes, ['apprenant']) === [];
    }
}
