<?php
declare(strict_types=1);

namespace Compta\Modules\Dossiers;

use Compta\Core\Audit\AuditLogger;
use PDO;
use Throwable;

final class StructureAccessService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function matrix(
        int $actorId,
        string $scope,
        ?int $organisationId,
        ?int $dossierId,
        bool $installationAdmin,
    ): array {
        $this->assertScope($scope, $organisationId, $dossierId);
        $users = $this->visibleUsers(
            $actorId,
            $organisationId,
            $installationAdmin
        );
        return [
            'scope' => $scope,
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
            'version' => $this->version($scope, $organisationId, $dossierId),
            'roles' => $this->roles(),
            'users' => array_map(
                fn (array $user): array => $this->userAccess(
                    (int) $user['id'],
                    $user,
                    $scope,
                    $organisationId,
                    $dossierId
                ),
                $users
            ),
        ];
    }

    /**
     * @param list<int> $roleIds
     * @return array<string,mixed>
     */
    public function preview(
        int $actorId,
        string $scope,
        ?int $organisationId,
        ?int $dossierId,
        int $userId,
        array $roleIds,
        string $expectedVersion,
        bool $installationAdmin,
        ?int $successorUserId = null,
    ): array {
        $this->assertScope($scope, $organisationId, $dossierId);
        $roleIds = $this->normaliseRoleIds($roleIds);
        $this->assertVersion(
            $scope,
            $organisationId,
            $dossierId,
            $expectedVersion,
            $userId,
            $roleIds
        );
        $this->assertUserVisible(
            $actorId,
            $userId,
            $organisationId,
            $installationAdmin
        );
        $this->assertRoles($roleIds);
        if ($scope === 'installation' && !$installationAdmin) {
            throw new StructureAccessException(
                'Seul un administrateur d’installation peut gérer ces rôles.',
                'STRUCTURE_ACCESS_FORBIDDEN'
            );
        }
        $before = $this->directRoleIds(
            $scope,
            $organisationId,
            $dossierId,
            $userId
        );
        if (
            !$installationAdmin
            && $actorId === $userId
            && array_diff($roleIds, $before) !== []
        ) {
            throw new StructureAccessException(
                'Vous ne pouvez pas vous attribuer de droits supplémentaires.',
                'STRUCTURE_ACCESS_SELF_ESCALATION'
            );
        }
        $transfer = $this->transferPreview(
            $scope,
            $organisationId,
            $dossierId,
            $userId,
            $roleIds,
            $successorUserId,
            $actorId,
            $installationAdmin
        );
        $beforePermissions = $this->effectivePermissions(
            $userId,
            $organisationId,
            $dossierId
        );
        $afterPermissions = $this->effectivePermissions(
            $userId,
            $organisationId,
            $dossierId,
            [$scope => $roleIds]
        );
        $tokenData = [
            'scope' => $scope,
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
            'user_id' => $userId,
            'before' => $before,
            'after' => $roleIds,
            'version' => $expectedVersion,
            'successor_user_id' => $transfer['user_id'] ?? null,
            'successor_role_ids' => $transfer['role_ids'] ?? [],
        ];
        return [
            ...$tokenData,
            'before_permissions' => $beforePermissions,
            'after_permissions' => $afterPermissions,
            'added_permissions' => array_values(array_diff(
                $afterPermissions,
                $beforePermissions
            )),
            'removed_permissions' => array_values(array_diff(
                $beforePermissions,
                $afterPermissions
            )),
            'transfer' => $transfer,
            'confirmation_token' => $this->hash($tokenData),
        ];
    }

    /**
     * @param list<int> $roleIds
     * @return array<string,mixed>
     */
    public function apply(
        int $actorId,
        string $scope,
        ?int $organisationId,
        ?int $dossierId,
        int $userId,
        array $roleIds,
        string $expectedVersion,
        string $confirmationToken,
        bool $installationAdmin,
        ?int $successorUserId = null,
    ): array {
        return $this->transaction(function () use (
            $actorId,
            $scope,
            $organisationId,
            $dossierId,
            $userId,
            $roleIds,
            $expectedVersion,
            $confirmationToken,
            $installationAdmin,
            $successorUserId
        ): array {
            $normalisedRoleIds = $this->normaliseRoleIds($roleIds);
            $currentVersion = $this->version(
                $scope,
                $organisationId,
                $dossierId
            );
            if (
                !hash_equals($currentVersion, $expectedVersion)
                && $this->directRoleIds(
                    $scope,
                    $organisationId,
                    $dossierId,
                    $userId
                ) === $normalisedRoleIds
            ) {
                return [
                    'changed' => false,
                    'version' => $currentVersion,
                    'user_id' => $userId,
                    'direct_role_ids' => $normalisedRoleIds,
                ];
            }
            $preview = $this->preview(
                $actorId,
                $scope,
                $organisationId,
                $dossierId,
                $userId,
                $roleIds,
                $expectedVersion,
                $installationAdmin,
                $successorUserId
            );
            if (!hash_equals(
                (string) $preview['confirmation_token'],
                $confirmationToken
            )) {
                throw new StructureAccessException(
                    'La prévisualisation a expiré. Rechargez les accès.',
                    'STRUCTURE_ACCESS_PREVIEW_CONFLICT'
                );
            }
            $before = $preview['before'];
            $changed = $before !== $preview['after'];
            if ($changed) {
                $this->replaceDirectRoles(
                    $scope,
                    $organisationId,
                    $dossierId,
                    $userId,
                    $preview['after']
                );
            }
            $transfer = $preview['transfer'];
            if (is_array($transfer) && $transfer !== []) {
                $this->replaceDirectRoles(
                    $scope,
                    $organisationId,
                    $dossierId,
                    (int) $transfer['user_id'],
                    $transfer['role_ids']
                );
                $changed = true;
            }
            if ($changed) {
                $this->audit->log(
                    'structure.acces_modifie',
                    $actorId,
                    $organisationId,
                    $dossierId,
                    'utilisateur',
                    (string) $userId,
                    [
                        'scope' => $scope,
                        'before' => $before,
                        'after' => $preview['after'],
                        'before_permissions' => $preview['before_permissions'],
                        'after_permissions' => $preview['after_permissions'],
                        'transfer' => $transfer,
                    ]
                );
            }
            return [
                'changed' => $changed,
                'version' => $this->version(
                    $scope,
                    $organisationId,
                    $dossierId
                ),
                'user_id' => $userId,
                'direct_role_ids' => $preview['after'],
            ];
        });
    }

    /** @return array<string,mixed> */
    public function previewDossierCopy(
        int $organisationId,
        int $sourceDossierId,
    ): array {
        $this->assertDossierInOrganisation(
            $organisationId,
            $sourceDossierId,
            true
        );
        $assignments = $this->dossierAssignments($sourceDossierId);
        $data = [
            'organisation_id' => $organisationId,
            'source_dossier_id' => $sourceDossierId,
            'assignments' => $assignments,
        ];
        return [
            ...$data,
            'assignment_count' => count($assignments),
            'preview_hash' => $this->hash($data),
        ];
    }

    public function copyDossierMatrix(
        int $organisationId,
        int $sourceDossierId,
        int $targetDossierId,
        string $expectedHash,
        int $actorId,
    ): int {
        if ($sourceDossierId === $targetDossierId) {
            throw new StructureAccessException(
                'Le dossier source doit être différent de la cible.'
            );
        }
        $preview = $this->previewDossierCopy(
            $organisationId,
            $sourceDossierId
        );
        $this->assertDossierInOrganisation(
            $organisationId,
            $targetDossierId,
            true
        );
        if (!hash_equals((string) $preview['preview_hash'], $expectedHash)) {
            throw new StructureAccessException(
                'La matrice source a changé. Une nouvelle prévisualisation est requise.',
                'STRUCTURE_ACCESS_COPY_CONFLICT'
            );
        }
        $targetBefore = $this->dossierAssignments($targetDossierId);
        if ($targetBefore !== []) {
            throw new StructureAccessException(
                'La cible possède déjà des rôles directs.'
            );
        }
        $insert = $this->pdo->prepare(
            'INSERT OR IGNORE INTO utilisateur_roles_dossier
             (utilisateur_id, dossier_id, role_id) VALUES (?, ?, ?)'
        );
        foreach ($preview['assignments'] as $assignment) {
            $insert->execute([
                $assignment['user_id'],
                $targetDossierId,
                $assignment['role_id'],
            ]);
        }
        $this->audit->log(
            'structure.acces_dossier_copies',
            $actorId,
            $organisationId,
            $targetDossierId,
            'dossier',
            (string) $targetDossierId,
            [
                'source_dossier_id' => $sourceDossierId,
                'before' => $targetBefore,
                'after' => $preview['assignments'],
                'preview_hash' => $expectedHash,
            ]
        );
        return count($preview['assignments']);
    }

    /** @return list<array<string,mixed>> */
    private function visibleUsers(
        int $actorId,
        ?int $organisationId,
        bool $installationAdmin,
    ): array {
        if ($installationAdmin) {
            $stmt = $this->pdo->query(
                'SELECT id, email, prenom, nom, actif
                 FROM utilisateurs ORDER BY actif DESC, email COLLATE NOCASE'
            );
        } else {
            if ($organisationId === null) {
                return [];
            }
            $stmt = $this->pdo->prepare(
                'SELECT DISTINCT u.id, u.email, u.prenom, u.nom, u.actif
                 FROM utilisateurs u
                 WHERE u.id = :actor
                    OR EXISTS (
                        SELECT 1 FROM utilisateur_roles_organisation uro
                        WHERE uro.utilisateur_id = u.id
                          AND uro.organisation_id = :organisation
                    )
                    OR EXISTS (
                        SELECT 1 FROM utilisateur_roles_dossier urd
                        JOIN dossiers d ON d.id = urd.dossier_id
                        WHERE urd.utilisateur_id = u.id
                          AND d.organisation_id = :organisation
                    )
                 ORDER BY u.actif DESC, u.email COLLATE NOCASE'
            );
            $stmt->execute([
                'actor' => $actorId,
                'organisation' => $organisationId,
            ]);
        }
        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    private function userAccess(
        int $userId,
        array $user,
        string $scope,
        ?int $organisationId,
        ?int $dossierId,
    ): array {
        $installation = $this->roleRows(
            'utilisateur_roles_installation',
            'utilisateur_id = ?',
            [$userId]
        );
        $organisation = $organisationId === null ? [] : $this->roleRows(
            'utilisateur_roles_organisation',
            'utilisateur_id = ? AND organisation_id = ?',
            [$userId, $organisationId]
        );
        $dossier = $dossierId === null ? [] : $this->roleRows(
            'utilisateur_roles_dossier',
            'utilisateur_id = ? AND dossier_id = ?',
            [$userId, $dossierId]
        );
        return [
            'id' => $userId,
            'email' => (string) $user['email'],
            'name' => trim((string) $user['prenom'] . ' ' . (string) $user['nom']),
            'active' => (int) $user['actif'] === 1,
            'installation_roles' => $installation,
            'organisation_roles' => $organisation,
            'dossier_roles' => $dossier,
            'direct_role_ids' => match ($scope) {
                'installation' => array_column($installation, 'id'),
                'organisation' => array_column($organisation, 'id'),
                default => array_column($dossier, 'id'),
            },
            'effective_permissions' => $this->effectivePermissions(
                $userId,
                $organisationId,
                $dossierId
            ),
        ];
    }

    /** @return list<array{id:int,code:string,label:string,permissions:list<string>}> */
    private function roles(): array
    {
        $rows = $this->pdo->query(
            'SELECT r.id, r.code, r.libelle, p.code AS permission
             FROM roles r
             LEFT JOIN role_permissions rp ON rp.role_id = r.id
             LEFT JOIN permissions p ON p.id = rp.permission_id
             ORDER BY r.id, p.code'
        )->fetchAll();
        $roles = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $roles[$id] ??= [
                'id' => $id,
                'code' => (string) $row['code'],
                'label' => (string) $row['libelle'],
                'permissions' => [],
            ];
            if ($row['permission'] !== null) {
                $roles[$id]['permissions'][] = (string) $row['permission'];
            }
        }
        return array_values($roles);
    }

    /**
     * @param array<string,list<int>> $overrides
     * @return list<string>
     */
    private function effectivePermissions(
        int $userId,
        ?int $organisationId,
        ?int $dossierId,
        array $overrides = [],
    ): array {
        $sources = [
            'installation' => $overrides['installation']
                ?? $this->directRoleIds('installation', null, null, $userId),
            'organisation' => $organisationId === null ? [] : (
                $overrides['organisation']
                ?? $this->directRoleIds(
                    'organisation',
                    $organisationId,
                    null,
                    $userId
                )
            ),
            'dossier' => $dossierId === null ? [] : (
                $overrides['dossier']
                ?? $this->directRoleIds(
                    'dossier',
                    $organisationId,
                    $dossierId,
                    $userId
                )
            ),
        ];
        $permissions = [];
        foreach ($sources as $source => $roleIds) {
            if ($roleIds === []) {
                continue;
            }
            $marks = implode(',', array_fill(0, count($roleIds), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT DISTINCT p.code FROM role_permissions rp
                 JOIN permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id IN ({$marks})"
            );
            $stmt->execute($roleIds);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $permission) {
                $permission = (string) $permission;
                if (
                    $source === 'organisation'
                    && $permission === 'installation.admin'
                ) {
                    continue;
                }
                if (
                    $source === 'dossier'
                    && in_array($permission, [
                        'installation.admin',
                        'organisation.view',
                        'organisation.manage',
                    ], true)
                ) {
                    continue;
                }
                $permissions[$permission] = true;
            }
        }
        $result = array_keys($permissions);
        sort($result);
        return $result;
    }

    /**
     * @param list<int> $roleIds
     * @return array<string,mixed>
     */
    private function transferPreview(
        string $scope,
        ?int $organisationId,
        ?int $dossierId,
        int $userId,
        array $roleIds,
        ?int $successorUserId,
        int $actorId,
        bool $installationAdmin,
    ): array {
        if (!$this->structureActive($scope, $organisationId, $dossierId)) {
            return [];
        }
        if ($this->effectiveAdminCount(
            $scope,
            $organisationId,
            $dossierId,
            $userId,
            $roleIds
        ) > 0) {
            return [];
        }
        if ($successorUserId === null || $successorUserId === $userId) {
            throw new StructureAccessException(
                'Le dernier administrateur effectif doit transférer ses droits à un successeur.',
                'STRUCTURE_ACCESS_LAST_ADMIN'
            );
        }
        $this->assertUserVisible(
            $actorId,
            $successorUserId,
            $organisationId,
            $installationAdmin
        );
        $adminRoleId = (int) $this->pdo->query(
            "SELECT id FROM roles WHERE code = 'administrateur'"
        )->fetchColumn();
        $successorRoles = $this->directRoleIds(
            $scope,
            $organisationId,
            $dossierId,
            $successorUserId
        );
        $successorRoles[] = $adminRoleId;
        $successorRoles = $this->normaliseRoleIds($successorRoles);
        return [
            'user_id' => $successorUserId,
            'role_ids' => $successorRoles,
            'role_code' => 'administrateur',
        ];
    }

    /** @param list<int> $replacement */
    private function effectiveAdminCount(
        string $scope,
        ?int $organisationId,
        ?int $dossierId,
        int $replacedUserId,
        array $replacement,
    ): int {
        $users = $this->pdo->query(
            'SELECT id FROM utilisateurs WHERE actif = 1'
        )->fetchAll(PDO::FETCH_COLUMN);
        $permission = $scope === 'installation'
            ? 'installation.admin'
            : ($scope === 'organisation' ? 'organisation.manage' : 'dossier.manage');
        $count = 0;
        foreach ($users as $candidate) {
            $candidate = (int) $candidate;
            $overrides = $candidate === $replacedUserId
                ? [$scope => $replacement]
                : [];
            if (in_array(
                $permission,
                $this->effectivePermissions(
                    $candidate,
                    $organisationId,
                    $dossierId,
                    $overrides
                ),
                true
            )) {
                $count++;
            }
        }
        return $count;
    }

    private function structureActive(
        string $scope,
        ?int $organisationId,
        ?int $dossierId,
    ): bool {
        if ($scope === 'installation') {
            return true;
        }
        if ($scope === 'organisation') {
            $stmt = $this->pdo->prepare(
                'SELECT actif FROM organisations WHERE id = ?'
            );
            $stmt->execute([$organisationId]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT d.actif AND o.actif FROM dossiers d
                 JOIN organisations o ON o.id = d.organisation_id
                 WHERE d.id = ? AND d.organisation_id = ?'
            );
            $stmt->execute([$dossierId, $organisationId]);
        }
        return (int) $stmt->fetchColumn() === 1;
    }

    /** @return list<array{id:int,code:string,label:string}> */
    private function roleRows(string $table, string $where, array $values): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.code, r.libelle
             FROM {$table} ur JOIN roles r ON r.id = ur.role_id
             WHERE {$where} ORDER BY r.id"
        );
        $stmt->execute($values);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'label' => (string) $row['libelle'],
        ], $stmt->fetchAll());
    }

    /** @return list<int> */
    private function directRoleIds(
        string $scope,
        ?int $organisationId,
        ?int $dossierId,
        int $userId,
    ): array {
        [$table, $column, $scopeId] = match ($scope) {
            'installation' => [
                'utilisateur_roles_installation',
                null,
                null,
            ],
            'organisation' => [
                'utilisateur_roles_organisation',
                'organisation_id',
                $organisationId,
            ],
            default => [
                'utilisateur_roles_dossier',
                'dossier_id',
                $dossierId,
            ],
        };
        $sql = "SELECT role_id FROM {$table} WHERE utilisateur_id = ?";
        $values = [$userId];
        if ($column !== null) {
            $sql .= " AND {$column} = ?";
            $values[] = $scopeId;
        }
        $sql .= ' ORDER BY role_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param list<int> $roleIds */
    private function replaceDirectRoles(
        string $scope,
        ?int $organisationId,
        ?int $dossierId,
        int $userId,
        array $roleIds,
    ): void {
        [$table, $column, $scopeId] = match ($scope) {
            'installation' => [
                'utilisateur_roles_installation', null, null,
            ],
            'organisation' => [
                'utilisateur_roles_organisation',
                'organisation_id',
                $organisationId,
            ],
            default => [
                'utilisateur_roles_dossier', 'dossier_id', $dossierId,
            ],
        };
        $delete = "DELETE FROM {$table} WHERE utilisateur_id = ?";
        $values = [$userId];
        if ($column !== null) {
            $delete .= " AND {$column} = ?";
            $values[] = $scopeId;
        }
        $this->pdo->prepare($delete)->execute($values);
        foreach ($roleIds as $roleId) {
            if ($scope === 'installation') {
                $this->pdo->prepare(
                    'INSERT INTO utilisateur_roles_installation
                     (utilisateur_id, role_id) VALUES (?, ?)'
                )->execute([$userId, $roleId]);
            } elseif ($scope === 'organisation') {
                $this->pdo->prepare(
                    'INSERT INTO utilisateur_roles_organisation
                     (utilisateur_id, organisation_id, role_id)
                     VALUES (?, ?, ?)'
                )->execute([$userId, $organisationId, $roleId]);
            } else {
                $this->pdo->prepare(
                    'INSERT INTO utilisateur_roles_dossier
                     (utilisateur_id, dossier_id, role_id) VALUES (?, ?, ?)'
                )->execute([$userId, $dossierId, $roleId]);
            }
        }
    }

    private function version(
        string $scope,
        ?int $organisationId,
        ?int $dossierId,
    ): string {
        [$table, $column, $scopeId] = match ($scope) {
            'installation' => [
                'utilisateur_roles_installation', null, null,
            ],
            'organisation' => [
                'utilisateur_roles_organisation',
                'organisation_id',
                $organisationId,
            ],
            default => [
                'utilisateur_roles_dossier', 'dossier_id', $dossierId,
            ],
        };
        $sql = "SELECT utilisateur_id, role_id FROM {$table}";
        $values = [];
        if ($column !== null) {
            $sql .= " WHERE {$column} = ?";
            $values[] = $scopeId;
        }
        $sql .= ' ORDER BY utilisateur_id, role_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        return $this->hash([
            'scope' => $scope,
            'scope_id' => $scopeId,
            'assignments' => $stmt->fetchAll(PDO::FETCH_NUM),
        ]);
    }

    /**
     * @param list<int> $roleIds
     */
    private function assertVersion(
        string $scope,
        ?int $organisationId,
        ?int $dossierId,
        string $expected,
        int $userId,
        array $roleIds,
    ): void {
        if (hash_equals(
            $this->version($scope, $organisationId, $dossierId),
            $expected
        )) {
            return;
        }
        if ($this->directRoleIds(
            $scope,
            $organisationId,
            $dossierId,
            $userId
        ) === $roleIds) {
            return;
        }
        throw new StructureAccessException(
            'La matrice d’accès a changé. Rechargez-la avant de confirmer.',
            'STRUCTURE_ACCESS_VERSION_CONFLICT'
        );
    }

    private function assertUserVisible(
        int $actorId,
        int $userId,
        ?int $organisationId,
        bool $installationAdmin,
    ): void {
        foreach (
            $this->visibleUsers($actorId, $organisationId, $installationAdmin)
            as $user
        ) {
            if ((int) $user['id'] === $userId && (int) $user['actif'] === 1) {
                return;
            }
        }
        throw new StructureAccessException(
            'Utilisateur absent du périmètre administrable.',
            'STRUCTURE_ACCESS_USER_NOT_FOUND'
        );
    }

    /** @param list<int> $roleIds */
    private function assertRoles(array $roleIds): void
    {
        if ($roleIds === []) {
            return;
        }
        $marks = implode(',', array_fill(0, count($roleIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM roles WHERE id IN ({$marks})"
        );
        $stmt->execute($roleIds);
        if ((int) $stmt->fetchColumn() !== count($roleIds)) {
            throw new StructureAccessException('Un rôle sélectionné est invalide.');
        }
    }

    private function assertScope(
        string $scope,
        ?int $organisationId,
        ?int $dossierId,
    ): void {
        if (!in_array($scope, ['installation', 'organisation', 'dossier'], true)) {
            throw new StructureAccessException('Périmètre d’accès invalide.');
        }
        if ($scope === 'installation') {
            return;
        }
        if ($organisationId === null) {
            throw new StructureAccessException('Organisation requise.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM organisations WHERE id = ?'
        );
        $stmt->execute([$organisationId]);
        if ($stmt->fetchColumn() === false) {
            throw new StructureAccessException(
                'Organisation introuvable.',
                'STRUCTURE_ACCESS_NOT_FOUND'
            );
        }
        if ($scope === 'dossier') {
            if ($dossierId === null) {
                throw new StructureAccessException('Dossier requis.');
            }
            $this->assertDossierInOrganisation(
                $organisationId,
                $dossierId,
                false
            );
        }
    }

    private function assertDossierInOrganisation(
        int $organisationId,
        int $dossierId,
        bool $active,
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM dossiers
             WHERE id = ? AND organisation_id = ?'
            . ($active ? ' AND actif = 1' : '')
        );
        $stmt->execute([$dossierId, $organisationId]);
        if ($stmt->fetchColumn() === false) {
            throw new StructureAccessException(
                'Dossier introuvable.',
                'STRUCTURE_ACCESS_NOT_FOUND'
            );
        }
    }

    /** @return list<array<string,mixed>> */
    private function dossierAssignments(int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT urd.utilisateur_id, urd.role_id, u.email,
                    u.prenom, u.nom, r.code, r.libelle
             FROM utilisateur_roles_dossier urd
             JOIN utilisateurs u ON u.id = urd.utilisateur_id
             JOIN roles r ON r.id = urd.role_id
             WHERE urd.dossier_id = ?
             ORDER BY urd.utilisateur_id, urd.role_id'
        );
        $stmt->execute([$dossierId]);
        return array_map(static fn (array $row): array => [
            'user_id' => (int) $row['utilisateur_id'],
            'user_email' => (string) $row['email'],
            'user_name' => trim(
                (string) $row['prenom'] . ' ' . (string) $row['nom']
            ),
            'role_id' => (int) $row['role_id'],
            'role_code' => (string) $row['code'],
            'role_label' => (string) $row['libelle'],
        ], $stmt->fetchAll());
    }

    /** @param list<int> $roleIds @return list<int> */
    private function normaliseRoleIds(array $roleIds): array
    {
        $result = array_values(array_unique(array_map('intval', $roleIds)));
        sort($result);
        return $result;
    }

    /** @param array<string,mixed> $data */
    private function hash(array $data): string
    {
        return hash('sha256', json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ));
    }

    /** @template T @param callable():T $callback @return T */
    private function transaction(callable $callback): mixed
    {
        $alreadyOpen = $this->pdo->inTransaction();
        if (!$alreadyOpen) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if (!$alreadyOpen) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if (!$alreadyOpen && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
