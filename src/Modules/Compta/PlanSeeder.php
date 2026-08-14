<?php
declare(strict_types=1);

namespace Compta\Modules\Compta;

use PDO;
use Throwable;

final class PlanSeeder
{
    private const VEB_CODE = 'veb-pme-fr';
    private const VEB_VERSION = '2026-07-28-base';
    private const VEB_URL = 'https://www.kmu.admin.ch/dam/fr/sd-web/ddOMnlBEN93Z/'
        . '240812%20Schulkontenrahmen%20VEB%20-%20FR.pdf';
    private const VEB_ATTRIBUTION = 'Plan comptable de base WebeLi, adapté du '
        . 'Plan comptable suisse PME (Mattle/Helbling/Pfaff), '
        . 'référence du 12 août 2024 — © veb.ch, Zürich';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $seedDirectory,
    ) {
    }

    /** @return array{veb:int,association:int} */
    public function seedCatalog(): array
    {
        return $this->transaction(function (): array {
            $veb = $this->upsertModel(
                self::VEB_CODE,
                self::VEB_VERSION,
                'Plan comptable suisse PME VEB — français',
                self::VEB_URL,
                self::VEB_ATTRIBUTION,
                false,
            );
            $association = $this->upsertModel(
                'association-ch',
                '2024-v1',
                'Overlay associations suisses',
                'interne:SPECS',
                'Overlay WebeLi Compta 2024-v1, basé sur la structure VEB',
                true,
            );
            $this->replaceRows($veb, $this->seedDirectory . '/veb_pme_2024_fr.csv');
            $this->replaceRows(
                $association,
                $this->seedDirectory . '/association_2024_v1.csv'
            );
            return ['veb' => $veb, 'association' => $association];
        });
    }

    /**
     * @param array{projets?:bool,fonds_affectes?:bool} $associationOptions
     */
    public function installForDossier(
        int $organisationId,
        int $dossierId,
        string $variant = 'personne_morale',
        bool $withAssociation = false,
        array $associationOptions = [],
        ?int $actorId = null,
    ): int {
        if (!in_array($variant, ['personne_morale', 'raison_individuelle', 'societe_personnes'], true)) {
            throw new AccountingException('Variante de plan comptable inconnue.');
        }
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $variant,
            $withAssociation,
            $associationOptions,
            $actorId
        ): int {
            $this->assertDossierScope($organisationId, $dossierId);
            $this->ensureConfigurationDefaults(
                $organisationId,
                $dossierId,
                $actorId
            );
            $models = $this->seedCatalog();
            $inserted = $this->installModel(
                $models['veb'],
                $organisationId,
                $dossierId,
                $variant,
                [],
                $actorId
            );
            if ($withAssociation) {
                $inserted += $this->installModel(
                    $models['association'],
                    $organisationId,
                    $dossierId,
                    'commun',
                    $associationOptions,
                    $actorId
                );
            }
            return $inserted;
        });
    }

    /** @return list<array{code:string,version:string,source_url:string,attribution:string}> */
    public function attributions(): array
    {
        $rows = $this->pdo->query(
            'SELECT code, version, source_url, attribution
             FROM modeles_plan_comptable ORDER BY est_overlay, code'
        )->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    private function upsertModel(
        string $code,
        string $version,
        string $label,
        string $url,
        string $attribution,
        bool $overlay,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO modeles_plan_comptable
                (code, version, libelle, source_url, attribution, est_overlay)
             VALUES (:code, :version, :label, :url, :attribution, :overlay)
             ON CONFLICT(code, version) DO UPDATE SET
                libelle = excluded.libelle,
                source_url = excluded.source_url,
                attribution = excluded.attribution,
                est_overlay = excluded.est_overlay'
        );
        $stmt->execute([
            'code' => $code,
            'version' => $version,
            'label' => $label,
            'url' => $url,
            'attribution' => $attribution,
            'overlay' => $overlay ? 1 : 0,
        ]);
        $find = $this->pdo->prepare(
            'SELECT id FROM modeles_plan_comptable WHERE code = ? AND version = ?'
        );
        $find->execute([$code, $version]);
        return (int) $find->fetchColumn();
    }

    private function replaceRows(int $modelId, string $path): void
    {
        if (!is_file($path) || ($handle = fopen($path, 'rb')) === false) {
            throw new AccountingException("Seed de plan comptable introuvable : {$path}");
        }
        try {
            $headers = fgetcsv($handle, 0, ';');
            if (!is_array($headers)) {
                throw new AccountingException("En-tête CSV invalide : {$path}");
            }
            $this->pdo->prepare('DELETE FROM modele_comptes WHERE modele_id = ?')
                ->execute([$modelId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO modele_comptes
                    (modele_id, variante, numero, libelle, type, sens_normal,
                     parent_numero, niveau, imputable, marque, parametre_requis, ordre)
                 VALUES
                    (:model, :variant, :number, :label, :type, :side,
                     :parent, :level, :postable, :tag, :required, :position)'
            );
            $position = 0;
            while (($values = fgetcsv($handle, 0, ';')) !== false) {
                if ($values === [null] || count($values) !== count($headers)) {
                    throw new AccountingException("Ligne CSV invalide dans {$path}.");
                }
                $row = array_combine($headers, $values);
                if (!is_array($row)) {
                    throw new AccountingException("Colonnes CSV invalides dans {$path}.");
                }
                $insert->execute([
                    'model' => $modelId,
                    'variant' => trim((string) $row['variante']),
                    'number' => trim((string) $row['numero']),
                    'label' => trim((string) $row['libelle']),
                    'type' => $this->normalizeType((string) $row['type']),
                    'side' => trim((string) $row['sens_normal']),
                    'parent' => trim((string) $row['parent_numero']) ?: null,
                    'level' => (int) $row['niveau'],
                    'postable' => (int) $row['imputable'],
                    'tag' => trim((string) $row['marque']),
                    'required' => trim((string) $row['parametre_requis']),
                    'position' => ++$position,
                ]);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,bool> $options */
    private function installModel(
        int $modelId,
        int $organisationId,
        int $dossierId,
        string $variant,
        array $options,
        ?int $actorId,
    ): int {
        $model = $this->pdo->prepare(
            'SELECT code, version FROM modeles_plan_comptable WHERE id = ?'
        );
        $model->execute([$modelId]);
        $metadata = $model->fetch();
        if ($metadata === false) {
            throw new AccountingException('Modèle de plan comptable absent.');
        }
        $rows = $this->pdo->prepare(
            "SELECT * FROM modele_comptes
             WHERE modele_id = :model AND variante IN ('commun', :variant)
             ORDER BY niveau, ordre, numero"
        );
        $rows->execute(['model' => $modelId, 'variant' => $variant]);
        $parentIds = [];
        $rubricIds = [];
        $existing = $this->pdo->prepare(
            'SELECT id FROM comptes WHERE dossier_id = ? AND numero = ?'
        );
        $insert = $this->pdo->prepare(
            'INSERT INTO comptes
                (organisation_id, dossier_id, numero, libelle, type, sens_normal,
                 sens_mode, parent_id, rubrique_id, niveau, imputable, marque,
                 ordre, source_modele, source_version, cree_par)
             VALUES
                (:organisation, :dossier, :number, :label, :type, :side,
                 :sense_mode, :parent, :rubric, :level, :postable, :tag,
                 :position, :source, :version, :actor)'
        );
        $rubric = $this->pdo->prepare(
            'INSERT INTO rubriques_comptables
                (organisation_id, dossier_id, code, libelle, niveau_structure,
                 type, parent_id, ordre, source_modele, cree_par)
             VALUES
                (:organisation, :dossier, :code, :label, :structure_level,
                 :type, :parent, :position, :source, :actor)
             ON CONFLICT(dossier_id, code) WHERE code <> \'\' DO NOTHING'
        );
        $existingRubric = $this->pdo->prepare(
            'SELECT id FROM rubriques_comptables
             WHERE dossier_id = ? AND code = ? AND actif = 1'
        );
        $count = 0;
        foreach ($rows->fetchAll() as $row) {
            $required = (string) $row['parametre_requis'];
            if ($required !== '' && !($options[$required] ?? false)) {
                continue;
            }
            $number = (string) $row['numero'];
            $type = $this->normalizeType((string) $row['type']);
            $parentNumber = (string) ($row['parent_numero'] ?? '');
            if ((int) $row['imputable'] === 0) {
                $rubricParentId = $parentNumber === ''
                    ? null
                    : ($rubricIds[$parentNumber] ?? null);
                if ($parentNumber !== '' && $rubricParentId === null) {
                    $existingRubric->execute([$dossierId, $parentNumber]);
                    $foundRubric = $existingRubric->fetchColumn();
                    $rubricParentId = $foundRubric === false
                        ? null
                        : (int) $foundRubric;
                }
                $rubric->execute([
                    'organisation' => $organisationId,
                    'dossier' => $dossierId,
                    'code' => $number,
                    'label' => $row['libelle'],
                    'structure_level' => $this->structureLevel($number),
                    'type' => $type,
                    'parent' => $rubricParentId,
                    'position' => (int) $row['ordre'],
                    'source' => $metadata['code'],
                    'actor' => $actorId,
                ]);
                $existingRubric->execute([$dossierId, $number]);
                $rubricId = $existingRubric->fetchColumn();
                if ($rubricId === false) {
                    throw new AccountingException(
                        "Rubrique {$number} absente après installation."
                    );
                }
                $rubricIds[$number] = (int) $rubricId;
            }
            $existing->execute([$dossierId, $number]);
            $id = $existing->fetchColumn();
            if ($id !== false) {
                $parentIds[$number] = (int) $id;
                continue;
            }
            $parentId = $parentNumber === '' ? null : ($parentIds[$parentNumber] ?? null);
            if ($parentNumber !== '' && $parentId === null) {
                $existing->execute([$dossierId, $parentNumber]);
                $found = $existing->fetchColumn();
                $parentId = $found === false ? null : (int) $found;
            }
            if ($parentNumber !== '' && $parentId === null) {
                throw new AccountingException(
                    "Parent {$parentNumber} absent pour le compte {$number}."
                );
            }
            $accountRubricId = null;
            if ((int) $row['imputable'] === 1) {
                $directRubricId = $rubricIds[$parentNumber] ?? null;
                if ($directRubricId === null && $parentNumber !== '') {
                    $existingRubric->execute([$dossierId, $parentNumber]);
                    $foundRubric = $existingRubric->fetchColumn();
                    $directRubricId = $foundRubric === false
                        ? null
                        : (int) $foundRubric;
                }
                $accountRubricId = $this->accountParent(
                    $organisationId,
                    $dossierId,
                    $number,
                    $directRubricId
                );
                $rubricType = $this->pdo->prepare(
                    'SELECT type FROM rubriques_comptables WHERE id = ?'
                );
                $rubricType->execute([$accountRubricId]);
                $type = (string) $rubricType->fetchColumn();
            }
            try {
                $insert->execute([
                    'organisation' => $organisationId,
                    'dossier' => $dossierId,
                    'number' => $number,
                    'label' => $row['libelle'],
                    'type' => $type,
                    'side' => $row['sens_normal'],
                    'sense_mode' => $row['sens_normal'] === $this->automaticSide(
                        $organisationId,
                        $dossierId,
                        $number
                    ) ? 'automatique' : $row['sens_normal'],
                    'parent' => $parentId,
                    'rubric' => $accountRubricId,
                    'level' => $row['niveau'],
                    'postable' => $row['imputable'],
                    'tag' => $row['marque'],
                    'position' => (int) $row['ordre'] * 10,
                    'source' => $metadata['code'],
                    'version' => $metadata['version'],
                    'actor' => $actorId,
                ]);
            } catch (\PDOException $e) {
                throw new AccountingException(
                    "Impossible d’installer le compte {$number} : {$e->getMessage()}",
                    0,
                    $e
                );
            }
            $parentIds[$number] = (int) $this->pdo->lastInsertId();
            $count++;
        }
        return $count;
    }

    private function normalizeType(string $type): string
    {
        $type = trim($type);
        return $type === 'fonds_propres' ? 'passif' : $type;
    }

    private function structureLevel(string $number): string
    {
        return match (strlen($number)) {
            1 => 'classe',
            2 => 'groupe_principal',
            3 => 'groupe',
            default => throw new AccountingException(
                "Le numéro structurel {$number} doit contenir de un à trois chiffres."
            ),
        };
    }

    private function ensureConfigurationDefaults(
        int $organisationId,
        int $dossierId,
        ?int $actorId,
    ): void {
        $this->ensureAccountTypes($organisationId, $dossierId, $actorId);
        $initialized = $this->pdo->prepare(
            "SELECT 1 FROM parametres_dossier
             WHERE dossier_id = ? AND cle = 'plan_sens_initialise'"
        );
        $initialized->execute([$dossierId]);
        if ($initialized->fetchColumn() !== false) {
            return;
        }
        $insert = $this->pdo->prepare(
            'INSERT OR IGNORE INTO regles_sens_comptes
                (organisation_id, dossier_id, prefixe, cree_par)
             VALUES (?, ?, ?, ?)'
        );
        foreach (['2', '3'] as $prefix) {
            $insert->execute([
                $organisationId,
                $dossierId,
                $prefix,
                $actorId,
            ]);
        }
        $this->pdo->prepare(
            "INSERT INTO parametres_dossier (dossier_id, cle, valeur)
             VALUES (?, 'plan_sens_initialise', '1')"
        )->execute([$dossierId]);
    }

    private function ensureAccountTypes(
        int $organisationId,
        int $dossierId,
        ?int $actorId,
    ): void {
        $insert = $this->pdo->prepare(
            'INSERT OR IGNORE INTO types_comptes
                (organisation_id, dossier_id, code, libelle, ordre, cree_par)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $labels = [
            'actif' => 'Actif',
            'passif' => 'Passif',
            'produit' => 'Produit',
            'charge' => 'Charge',
            'hors_bilan' => 'Hors bilan',
        ];
        foreach ($labels as $code => $label) {
            $insert->execute([
                $organisationId,
                $dossierId,
                $code,
                $label,
                (array_search($code, array_keys($labels), true) + 1) * 10,
                $actorId,
            ]);
        }
    }

    private function accountParent(
        int $organisationId,
        int $dossierId,
        string $accountNumber,
        ?int $directRubricId,
    ): int {
        if ($directRubricId === null) {
            throw new AccountingException(
                "Le compte {$accountNumber} ne possède pas de parent structurel."
            );
        }
        $findById = $this->pdo->prepare(
            'SELECT id, niveau_structure, type, parent_id
             FROM rubriques_comptables
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $findById->execute([$directRubricId, $organisationId, $dossierId]);
        $direct = $findById->fetch();
        if ($direct === false) {
            throw new AccountingException('Parent structurel absent du dossier.');
        }
        if (in_array($direct['niveau_structure'], ['groupe_principal', 'groupe'], true)) {
            return (int) $direct['id'];
        }
        if ($direct['niveau_structure'] === 'sous_groupe') {
            return (int) $direct['parent_id'];
        }
        if ($direct['niveau_structure'] === 'classe') {
            $principal = $this->pdo->prepare(
                "SELECT id
                 FROM rubriques_comptables
                 WHERE organisation_id = ? AND dossier_id = ?
                   AND niveau_structure = 'groupe_principal'
                   AND parent_id = ?
                   AND CAST(code AS INTEGER) <= ?
                 ORDER BY CAST(code AS INTEGER) DESC
                 LIMIT 1"
            );
            $principal->execute([
                $organisationId,
                $dossierId,
                (int) $direct['id'],
                (int) substr($accountNumber, 0, 2),
            ]);
            $principalId = $principal->fetchColumn();
            if ($principalId !== false) {
                return (int) $principalId;
            }
        }
        throw new AccountingException(
            "Parent structurel invalide pour le compte {$accountNumber}."
        );
    }

    private function automaticSide(
        int $organisationId,
        int $dossierId,
        string $number,
    ): string {
        $stmt = $this->pdo->prepare(
            "SELECT EXISTS(
                SELECT 1 FROM regles_sens_comptes
                WHERE organisation_id = ? AND dossier_id = ?
                  AND ? LIKE prefixe || '%'
            )"
        );
        $stmt->execute([$organisationId, $dossierId, $number]);
        return (int) $stmt->fetchColumn() === 1 ? 'credit' : 'debit';
    }

    private function assertDossierScope(int $organisationId, int $dossierId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM dossiers
             WHERE id = ? AND organisation_id = ? AND actif = 1'
        );
        $stmt->execute([$dossierId, $organisationId]);
        if ($stmt->fetchColumn() === false) {
            throw new AccountingException('Organisation ou dossier invalide.');
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
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
