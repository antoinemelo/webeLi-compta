<?php
declare(strict_types=1);

namespace Compta\Modules\Configuration\Application;

use PDO;

final class ModuleAccessService
{
    private const PATHS = [
        'apprentissage' => ['/pedagogie', '/app/apprentissage', '/api/v1/pedagogie'],
        'liquidites' => [
            '/liquidites', '/tresorerie', '/app/liquidites',
            '/api/v1/liquidites', '/api/v1/tresorerie',
        ],
        'facturation' => ['/facturation', '/app/facturation', '/api/v1/facturation'],
        'comptabilite' => ['/compta', '/app/compta', '/api/v1/compta'],
        'salaires' => ['/salaires', '/app/salaires', '/api/v1/salaires'],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function modules(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.code, m.libelle, m.description, m.ordre,
                    md.actif, md.version, md.modifie_le
             FROM modules_application m
             JOIN modules_dossier md ON md.module_code = m.code
             WHERE md.organisation_id = ? AND md.dossier_id = ?
               AND m.actif_global = 1
             ORDER BY m.ordre, m.code'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(static fn (array $row): array => [
            'code' => (string) $row['code'],
            'label' => (string) $row['libelle'],
            'description' => (string) $row['description'],
            'enabled' => (int) $row['actif'] === 1,
            'version' => (int) $row['version'],
            'updated_at' => $row['modifie_le'] === null
                ? null
                : (string) $row['modifie_le'],
        ], $stmt->fetchAll());
    }

    /** @return list<string> */
    public function enabledCodes(int $organisationId, int $dossierId): array
    {
        return array_values(array_map(
            static fn (array $module): string => (string) $module['code'],
            array_filter(
                $this->modules($organisationId, $dossierId),
                static fn (array $module): bool => (bool) $module['enabled']
            )
        ));
    }

    public function isEnabled(
        int $organisationId,
        int $dossierId,
        string $moduleCode,
    ): bool {
        $stmt = $this->pdo->prepare(
            'SELECT md.actif
             FROM modules_dossier md
             JOIN modules_application m ON m.code = md.module_code
             WHERE md.organisation_id = ? AND md.dossier_id = ?
               AND md.module_code = ? AND m.actif_global = 1'
        );
        $stmt->execute([$organisationId, $dossierId, $moduleCode]);
        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * Configure en une passe le périmètre fonctionnel d'un nouveau dossier.
     *
     * @param list<string> $enabledCodes
     */
    public function configureSelection(
        int $organisationId,
        int $dossierId,
        array $enabledCodes,
        ?int $actorId = null,
    ): void {
        $available = array_map(
            static fn (array $module): string => (string) $module['code'],
            $this->modules($organisationId, $dossierId)
        );
        $enabledCodes = array_values(array_unique($enabledCodes));
        if (array_diff($enabledCodes, $available) !== []) {
            throw new \RuntimeException('Sélection de modules invalide.');
        }
        $update = $this->pdo->prepare(
            'UPDATE modules_dossier
             SET actif = ?, modifie_le = datetime(\'now\'), modifie_par = ?,
                 version = version + 1
             WHERE organisation_id = ? AND dossier_id = ? AND module_code = ?'
        );
        foreach ($available as $code) {
            $update->execute([
                in_array($code, $enabledCodes, true) ? 1 : 0,
                $actorId,
                $organisationId,
                $dossierId,
                $code,
            ]);
        }
    }

    public function moduleForPath(string $path): ?string
    {
        foreach (self::PATHS as $module => $prefixes) {
            foreach ($prefixes as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                    return $module;
                }
            }
        }
        return null;
    }
}
