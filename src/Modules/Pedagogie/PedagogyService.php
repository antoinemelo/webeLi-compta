<?php
declare(strict_types=1);

namespace Compta\Modules\Pedagogie;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Compta\AccountingException;
use Compta\Modules\Compta\EntryService;
use PDO;
use Throwable;

final class PedagogyService
{
    private bool $inTransaction = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly EntryService $entries,
    ) {
    }

    public function createModel(
        int $organisationId,
        string $title,
        string $description = '',
        ?int $actorId = null,
    ): int {
        if (trim($title) === '') {
            throw new PedagogyException('Le titre du modèle est requis.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO modeles_exercice
             (organisation_id, titre, description, cree_par) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$organisationId, trim($title), trim($description), $actorId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'pedagogie.modele_cree', $actorId, $organisationId,
            targetType: 'modele_exercice', targetId: (string) $id
        );
        return $id;
    }

    /**
     * @param list<array{code:string,titre:string,consigne:string,indices?:list<string>,
     * regles?:list<array{type:string,configuration:array<string,mixed>}>}> $steps
     * @param list<array<string,mixed>> $opening
     * @param list<array<string,mixed>> $initial
     * @param array<string,mixed> $solution
     */
    public function createVersion(
        int $organisationId,
        int $modelId,
        int $sourceDossierId,
        string $instructions,
        array $steps,
        array $opening = [],
        array $initial = [],
        array $solution = [],
        string $correctionRule = 'manuelle',
        string $correctionValue = '',
        ?int $actorId = null,
    ): int {
        if (
            trim($instructions) === '' || $steps === []
            || !in_array($correctionRule, ['manuelle', 'apres_tentatives', 'date'], true)
        ) {
            throw new PedagogyException('Version pédagogique incomplète.');
        }
        $snapshot = $this->snapshot($organisationId, $sourceDossierId);
        return $this->transaction(function () use (
            $organisationId, $modelId, $instructions, $steps, $opening,
            $initial, $solution, $correctionRule, $correctionValue,
            $snapshot, $actorId
        ): int {
            $model = $this->one(
                'SELECT * FROM modeles_exercice WHERE id = ? AND organisation_id = ?',
                [$modelId, $organisationId],
                'Modèle absent de l’organisation.'
            );
            $number = (int) $model['version_courante'] + 1;
            $stmt = $this->pdo->prepare(
                'INSERT INTO versions_modeles_exercice
                 (modele_id, numero_version, plan_snapshot_json,
                  soldes_initiaux_json, donnees_initiales_json, consignes,
                  solution_json, regle_correction, valeur_correction, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $modelId, $number, $this->json($snapshot), $this->json($opening),
                $this->json($initial), trim($instructions), $this->json($solution),
                $correctionRule, trim($correctionValue), $actorId,
            ]);
            $versionId = (int) $this->pdo->lastInsertId();
            $this->insertSteps($versionId, $steps);
            $this->pdo->prepare(
                "UPDATE versions_modeles_exercice SET statut = 'publie',
                 publie_le = datetime('now') WHERE id = ?"
            )->execute([$versionId]);
            $this->pdo->prepare(
                "UPDATE modeles_exercice SET statut = 'publie',
                 version_courante = ?, version = version + 1,
                 modifie_le = datetime('now') WHERE id = ?"
            )->execute([$number, $modelId]);
            $this->audit->log(
                'pedagogie.version_publiee', $actorId, $organisationId,
                targetType: 'version_modele', targetId: (string) $versionId,
                summary: ['version' => $number]
            );
            return $versionId;
        });
    }

    public function createGroup(
        int $organisationId,
        string $name,
        ?int $actorId = null,
    ): int {
        if (trim($name) === '') {
            throw new PedagogyException('Le nom du groupe est requis.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO groupes_pedagogiques
             (organisation_id, nom, cree_par) VALUES (?, ?, ?)'
        );
        $stmt->execute([$organisationId, trim($name), $actorId]);
        return (int) $this->pdo->lastInsertId();
    }

    public function addMember(
        int $organisationId,
        int $groupId,
        int $userId,
        string $role = 'membre',
        ?int $actorId = null,
    ): void {
        if (!in_array($role, ['membre', 'coordinateur'], true)) {
            throw new PedagogyException('Rôle de groupe invalide.');
        }
        $group = $this->group($organisationId, $groupId);
        $this->assertUser($userId);
        $this->pdo->prepare(
            'INSERT INTO membres_groupes
             (groupe_id, utilisateur_id, role_groupe, ajoute_par)
             VALUES (?, ?, ?, ?)'
        )->execute([$groupId, $userId, $role, $actorId]);
        if ($group['dossier_partage_id'] !== null) {
            $this->grant((int) $group['dossier_partage_id'], $userId);
        }
    }

    public function removeMember(
        int $organisationId,
        int $groupId,
        int $userId,
        ?int $actorId = null,
    ): void {
        $group = $this->group($organisationId, $groupId);
        $stmt = $this->pdo->prepare(
            "UPDATE membres_groupes SET retrait_le = datetime('now'),
             version = version + 1
             WHERE groupe_id = ? AND utilisateur_id = ? AND retrait_le IS NULL"
        );
        $stmt->execute([$groupId, $userId]);
        if ($stmt->rowCount() !== 1) {
            throw new PedagogyException('Membre actif absent.');
        }
        if ($group['dossier_partage_id'] !== null) {
            $this->revoke((int) $group['dossier_partage_id'], $userId);
        }
        $this->audit->log(
            'pedagogie.membre_retire', $actorId, $organisationId,
            targetType: 'groupe', targetId: (string) $groupId,
            summary: ['utilisateur_id' => $userId]
        );
    }

    public function assignIndividual(
        int $organisationId,
        int $versionId,
        int $userId,
        string $name,
        ?int $actorId = null,
    ): int {
        $this->assertUser($userId);
        return $this->assign(
            $organisationId, $versionId, $userId, null, $name, $actorId
        );
    }

    public function assignGroup(
        int $organisationId,
        int $versionId,
        int $groupId,
        string $name,
        ?int $actorId = null,
    ): int {
        $this->group($organisationId, $groupId);
        return $this->assign(
            $organisationId, $versionId, null, $groupId, $name, $actorId
        );
    }

    /** @param array<string,mixed> $command */
    public function createDraft(
        int $organisationId,
        int $dossierId,
        int $userId,
        array $command,
    ): int {
        $assignment = $this->participant($organisationId, $dossierId, $userId);
        $id = $this->entries->createDraft($command + [
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
        ], $userId);
        $this->contribute($assignment, $userId, $id, 'creation');
        return $id;
    }

    /** @param array<string,mixed> $command */
    public function replaceDraft(
        int $organisationId,
        int $dossierId,
        int $userId,
        int $entryId,
        int $expectedVersion,
        array $command,
    ): void {
        $assignment = $this->participant($organisationId, $dossierId, $userId);
        try {
            $this->entries->replaceDraft(
                $organisationId, $dossierId, $entryId, $expectedVersion,
                $command + [
                    'organisation_id' => $organisationId,
                    'dossier_id' => $dossierId,
                ],
                $userId
            );
        } catch (AccountingException $e) {
            throw new PedagogyConflictException(
                'Conflit : le brouillon a changé. Rechargez la dernière version. '
                . $e->getMessage(),
                0,
                $e
            );
        }
        $this->contribute(
            $assignment, $userId, $entryId, 'modification',
            ['version_precedente' => $expectedVersion]
        );
    }

    public function validateDraft(
        int $organisationId,
        int $dossierId,
        int $userId,
        int $entryId,
    ): string {
        $assignment = $this->participant($organisationId, $dossierId, $userId);
        $number = $this->entries->validate(
            $organisationId, $dossierId, $entryId, $userId
        );
        $this->contribute($assignment, $userId, $entryId, 'validation');
        return $number;
    }

    /** @return array{reussie:bool,messages:list<string>,tentative_id:int} */
    public function attempt(
        int $organisationId,
        int $dossierId,
        int $userId,
        int $stepId,
        ?int $entryId,
    ): array {
        $assignment = $this->participant($organisationId, $dossierId, $userId);
        $this->assertStep((int) $assignment['id'], $stepId);
        $rules = $this->all(
            'SELECT * FROM regles_validation WHERE etape_id = ? ORDER BY ordre, id',
            [$stepId]
        );
        if ($rules === []) {
            throw new PedagogyException('Aucune règle pour cette étape.');
        }
        $success = true;
        $messages = [];
        foreach ($rules as $rule) {
            $config = json_decode(
                (string) $rule['configuration_json'], true, 64, JSON_THROW_ON_ERROR
            );
            $valid = $this->evaluate(
                (string) $rule['type'], (array) $config, $dossierId, $entryId
            );
            $success = $success && $valid;
            $messages[] = (string) (
                $valid ? $rule['message_succes'] : $rule['message_echec']
            );
        }
        return $this->transaction(function () use (
            $organisationId, $dossierId, $assignment, $stepId, $userId,
            $entryId, $success, $messages
        ): array {
            $stmt = $this->pdo->prepare(
                'INSERT INTO tentatives_pedagogiques
                 (organisation_id, dossier_id, assignation_id, etape_id,
                  utilisateur_id, ecriture_id, reussie, resultat_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId, $dossierId, $assignment['id'], $stepId,
                $userId, $entryId, $success ? 1 : 0,
                $this->json(['messages' => $messages]),
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $status = $success ? 'validee' : 'vue';
            $this->pdo->prepare(
                "UPDATE progressions_etapes SET statut = ?,
                 vue_le = COALESCE(vue_le, datetime('now')),
                 validee_le = CASE WHEN ? = 'validee' THEN datetime('now')
                                   ELSE validee_le END,
                 validee_par = CASE WHEN ? = 'validee' THEN ? ELSE validee_par END,
                 version = version + 1
                 WHERE assignation_id = ? AND etape_id = ?"
            )->execute([
                $status, $status, $status, $userId, $assignment['id'], $stepId,
            ]);
            $this->contribute(
                $assignment, $userId, $entryId, 'tentative',
                ['tentative_id' => $id, 'reussie' => $success]
            );
            return ['reussie' => $success, 'messages' => $messages, 'tentative_id' => $id];
        });
    }

    /** @return array{niveau:int,contenu:string} */
    public function nextHint(
        int $organisationId,
        int $dossierId,
        int $userId,
        int $stepId,
    ): array {
        $assignment = $this->participant($organisationId, $dossierId, $userId);
        $this->assertStep((int) $assignment['id'], $stepId);
        $stmt = $this->pdo->prepare(
            'SELECT i.* FROM indices_exercice i
             WHERE i.etape_id = ? AND NOT EXISTS (
               SELECT 1 FROM consultations_indices c
               WHERE c.assignation_id = ? AND c.indice_id = i.id
                 AND c.utilisateur_id = ?
             ) ORDER BY i.niveau LIMIT 1'
        );
        $stmt->execute([$stepId, $assignment['id'], $userId]);
        $hint = $stmt->fetch();
        if ($hint === false) {
            throw new PedagogyException('Aucun nouvel indice.');
        }
        $this->pdo->prepare(
            'INSERT INTO consultations_indices
             (assignation_id, indice_id, utilisateur_id) VALUES (?, ?, ?)'
        )->execute([$assignment['id'], $hint['id'], $userId]);
        $this->contribute(
            $assignment, $userId, null, 'indice', ['niveau' => $hint['niveau']]
        );
        return ['niveau' => (int) $hint['niveau'], 'contenu' => (string) $hint['contenu']];
    }

    /** @return array<string,mixed> */
    public function correction(
        int $organisationId,
        int $dossierId,
        int $userId,
    ): array {
        $assignment = $this->participant($organisationId, $dossierId, $userId);
        $version = $this->version((int) $assignment['version_modele_id']);
        $allowed = (int) $assignment['correction_autorisee'] === 1;
        if (!$allowed && $version['regle_correction'] === 'apres_tentatives') {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM tentatives_pedagogiques WHERE assignation_id = ?'
            );
            $stmt->execute([$assignment['id']]);
            $allowed = (int) $stmt->fetchColumn() >= (int) $version['valeur_correction'];
        }
        if (!$allowed && $version['regle_correction'] === 'date') {
            $allowed = $version['valeur_correction'] !== ''
                && date('Y-m-d H:i:s') >= $version['valeur_correction'];
        }
        if (!$allowed) {
            throw new PedagogyException('La correction n’est pas encore autorisée.');
        }
        return (array) json_decode(
            (string) $version['solution_json'], true, 64, JSON_THROW_ON_ERROR
        );
    }

    public function authorizeCorrection(
        int $organisationId,
        int $assignmentId,
        ?int $actorId = null,
    ): void {
        $stmt = $this->pdo->prepare(
            "UPDATE assignations_exercice SET correction_autorisee = 1,
             modifie_le = datetime('now'), version = version + 1
             WHERE id = ? AND organisation_id = ?"
        );
        $stmt->execute([$assignmentId, $organisationId]);
        if ($stmt->rowCount() !== 1) {
            throw new PedagogyException('Assignation absente.');
        }
    }

    public function reset(
        int $organisationId,
        int $assignmentId,
        ?int $actorId = null,
    ): int {
        return $this->transaction(function () use (
            $organisationId, $assignmentId, $actorId
        ): int {
            $assignment = $this->assignment($organisationId, $assignmentId);
            $old = $this->one(
                'SELECT * FROM dossiers WHERE id = ? AND organisation_id = ?',
                [$assignment['dossier_id'], $organisationId],
                'Dossier d’assignation absent.'
            );
            if ($old['type'] !== 'exercice') {
                throw new PedagogyException(
                    'La réinitialisation est réservée aux dossiers exercice.'
                );
            }
            $version = $this->version((int) $assignment['version_modele_id']);
            $new = $this->cloneVersion($organisationId, $version, $old['nom'], $actorId);
            $stmt = $this->pdo->prepare(
                "UPDATE assignations_exercice SET dossier_id = ?,
                 generation = generation + 1, correction_autorisee = 0,
                 modifie_le = datetime('now'), version = version + 1
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?"
            );
            $stmt->execute([$new, $assignmentId, $organisationId, $old['id']]);
            if ($stmt->rowCount() !== 1) {
                throw new PedagogyConflictException('Assignation modifiée simultanément.');
            }
            $this->pdo->prepare(
                'UPDATE dossiers SET actif = 0, version = version + 1 WHERE id = ?'
            )->execute([$old['id']]);
            foreach ($this->assignmentUsers($assignment) as $userId) {
                $this->revoke((int) $old['id'], $userId);
                $this->grant($new, $userId);
            }
            if ($assignment['groupe_id'] !== null) {
                $this->pdo->prepare(
                    'UPDATE groupes_pedagogiques SET dossier_partage_id = ?,
                     version = version + 1 WHERE id = ?'
                )->execute([$new, $assignment['groupe_id']]);
            }
            $this->pdo->prepare(
                'DELETE FROM progressions_etapes WHERE assignation_id = ?'
            )->execute([$assignmentId]);
            $this->seedProgress($assignmentId, (int) $version['id']);
            $this->audit->log(
                'pedagogie.assignation_reinitialisee', $actorId, $organisationId,
                $new, 'assignation', (string) $assignmentId,
                ['ancien_dossier_id' => (int) $old['id'], 'nouveau_dossier_id' => $new]
            );
            return $new;
        });
    }

    public function assertResetAllowed(int $organisationId, int $dossierId): void
    {
        $row = $this->one(
            'SELECT type FROM dossiers WHERE id = ? AND organisation_id = ?',
            [$dossierId, $organisationId],
            'Dossier absent.'
        );
        if ($row['type'] !== 'exercice') {
            throw new PedagogyException(
                'La réinitialisation est réservée aux dossiers exercice.'
            );
        }
    }

    /** @return list<array<string,mixed>> */
    public function assignmentsForUser(int $userId): array
    {
        return $this->all(
            "SELECT a.id, a.organisation_id, a.dossier_id, a.generation,
                    d.nom AS dossier_nom, m.titre AS modele_titre, v.consignes,
                    g.nom AS groupe_nom, COUNT(pe.id) AS nombre_etapes,
                    SUM(CASE WHEN pe.statut = 'validee' THEN 1 ELSE 0 END)
                      AS etapes_validees
             FROM assignations_exercice a
             JOIN dossiers d ON d.id = a.dossier_id AND d.actif = 1
               AND d.type = 'exercice'
             JOIN organisations o ON o.id = a.organisation_id
               AND o.nature = 'pedagogique'
             JOIN versions_modeles_exercice v ON v.id = a.version_modele_id
             JOIN modeles_exercice m ON m.id = v.modele_id
             LEFT JOIN groupes_pedagogiques g ON g.id = a.groupe_id
             LEFT JOIN membres_groupes mg ON mg.groupe_id = a.groupe_id
               AND mg.utilisateur_id = ? AND mg.retrait_le IS NULL
             LEFT JOIN progressions_etapes pe ON pe.assignation_id = a.id
             WHERE a.statut = 'en_cours'
               AND (a.utilisateur_id = ? OR mg.id IS NOT NULL)
             GROUP BY a.id ORDER BY a.assignee_le DESC",
            [$userId, $userId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function steps(int $assignmentId, int $userId): array
    {
        return $this->all(
            "SELECT e.id, e.titre, e.consigne, pe.statut,
                    (SELECT COUNT(*) FROM tentatives_pedagogiques t
                     WHERE t.assignation_id = ? AND t.etape_id = e.id) AS tentatives
             FROM etapes_exercice e
             JOIN assignations_exercice a ON a.version_modele_id = e.version_modele_id
               AND a.id = ?
             JOIN progressions_etapes pe ON pe.assignation_id = a.id
               AND pe.etape_id = e.id
             ORDER BY e.ordre, e.id",
            [$assignmentId, $assignmentId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function dashboard(int $organisationId): array
    {
        return $this->all(
            "SELECT a.id, a.dossier_id, d.nom AS dossier_nom, m.titre,
                    g.nom AS groupe_nom, u.email AS apprenant,
                    a.generation, COUNT(pe.id) AS etapes,
                    SUM(CASE WHEN pe.statut = 'validee' THEN 1 ELSE 0 END) AS validees,
                    COUNT(DISTINCT t.id) AS tentatives,
                    COUNT(DISTINCT c.utilisateur_id) AS contributeurs
             FROM assignations_exercice a
             JOIN organisations o ON o.id = a.organisation_id
               AND o.nature = 'pedagogique'
             JOIN dossiers d ON d.id = a.dossier_id AND d.type = 'exercice'
             JOIN versions_modeles_exercice v ON v.id = a.version_modele_id
             JOIN modeles_exercice m ON m.id = v.modele_id
             LEFT JOIN groupes_pedagogiques g ON g.id = a.groupe_id
             LEFT JOIN utilisateurs u ON u.id = a.utilisateur_id
             LEFT JOIN progressions_etapes pe ON pe.assignation_id = a.id
             LEFT JOIN tentatives_pedagogiques t ON t.assignation_id = a.id
             LEFT JOIN contributions_pedagogiques c ON c.assignation_id = a.id
             WHERE a.organisation_id = ?
             GROUP BY a.id ORDER BY m.titre, d.nom",
            [$organisationId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function models(int $organisationId): array
    {
        return $this->all(
            'SELECT m.*, v.id AS version_id FROM modeles_exercice m
             LEFT JOIN versions_modeles_exercice v ON v.modele_id = m.id
               AND v.numero_version = m.version_courante
             WHERE m.organisation_id = ? ORDER BY m.titre',
            [$organisationId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function groups(int $organisationId): array
    {
        return $this->all(
            'SELECT g.*, COUNT(m.id) AS membres FROM groupes_pedagogiques g
             LEFT JOIN membres_groupes m ON m.groupe_id = g.id
               AND m.retrait_le IS NULL
             WHERE g.organisation_id = ? AND g.actif = 1
             GROUP BY g.id ORDER BY g.nom',
            [$organisationId]
        );
    }

    /** @return array<string,mixed> */
    private function snapshot(int $organisationId, int $dossierId): array
    {
        $scope = $this->one(
            'SELECT d.*, o.nature FROM dossiers d
             JOIN organisations o ON o.id = d.organisation_id
             WHERE d.id = ? AND d.organisation_id = ?',
            [$dossierId, $organisationId],
            'Dossier source absent.'
        );
        if ($scope['nature'] !== 'pedagogique' || $scope['type'] === 'reel') {
            throw new PedagogyException('Un modèle ne capture jamais de données réelles.');
        }
        $exercises = $this->all(
            'SELECT id, libelle, date_debut, date_fin, statut FROM exercices
             WHERE dossier_id = ? ORDER BY date_debut',
            [$dossierId]
        );
        foreach ($exercises as &$exercise) {
            $exercise['periodes'] = $this->all(
                'SELECT libelle, date_debut, date_fin, statut FROM periodes
                 WHERE exercice_id = ? ORDER BY date_debut',
                [$exercise['id']]
            );
            unset($exercise['id']);
        }
        return [
            'monnaie' => $scope['monnaie'],
            'types' => $this->all(
                'SELECT code, libelle, ordre, actif FROM types_comptes
                 WHERE dossier_id = ? ORDER BY ordre', [$dossierId]
            ),
            'rubriques' => $this->all(
                'SELECT r.code, r.libelle, r.niveau_structure, r.type, r.ordre,
                        r.actif, r.source_modele, p.code AS parent_code
                 FROM rubriques_comptables r
                 LEFT JOIN rubriques_comptables p ON p.id = r.parent_id
                 WHERE r.dossier_id = ? ORDER BY r.ordre, r.id', [$dossierId]
            ),
            'comptes' => $this->all(
                'SELECT c.numero, c.libelle, c.type, c.sens_normal, c.sens_mode,
                        c.niveau, c.imputable, c.actif, c.marque, c.source_modele,
                        c.source_version, c.ordre, p.numero AS parent_numero,
                        r.code AS rubrique_code
                 FROM comptes c LEFT JOIN comptes p ON p.id = c.parent_id
                 LEFT JOIN rubriques_comptables r ON r.id = c.rubrique_id
                 WHERE c.dossier_id = ? ORDER BY c.niveau, c.ordre, c.id',
                [$dossierId]
            ),
            'regles_sens' => $this->all(
                'SELECT prefixe FROM regles_sens_comptes WHERE dossier_id = ?',
                [$dossierId]
            ),
            'exercices' => $exercises,
            'journaux' => $this->all(
                'SELECT code, libelle, type, actif FROM journaux
                 WHERE dossier_id = ? ORDER BY code', [$dossierId]
            ),
        ];
    }

    /** @param list<array<string,mixed>> $steps */
    private function insertSteps(int $versionId, array $steps): void
    {
        foreach (array_values($steps) as $order => $step) {
            foreach (['code', 'titre', 'consigne'] as $key) {
                if (trim((string) ($step[$key] ?? '')) === '') {
                    throw new PedagogyException('Étape incomplète.');
                }
            }
            $this->pdo->prepare(
                'INSERT INTO etapes_exercice
                 (version_modele_id, code, titre, consigne, ordre)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $versionId, $step['code'], $step['titre'], $step['consigne'], $order + 1,
            ]);
            $stepId = (int) $this->pdo->lastInsertId();
            foreach (array_values($step['indices'] ?? []) as $level => $hint) {
                $this->pdo->prepare(
                    'INSERT INTO indices_exercice (etape_id, niveau, contenu)
                     VALUES (?, ?, ?)'
                )->execute([$stepId, $level + 1, trim((string) $hint)]);
            }
            foreach (array_values($step['regles'] ?? []) as $rank => $rule) {
                $type = (string) ($rule['type'] ?? '');
                if (!in_array($type, [
                    'comptes', 'sens', 'montants', 'ecriture_equivalente',
                    'soldes', 'rapport',
                ], true)) {
                    throw new PedagogyException('Validateur inconnu.');
                }
                $this->pdo->prepare(
                    'INSERT INTO regles_validation
                     (etape_id, type, configuration_json, ordre) VALUES (?, ?, ?, ?)'
                )->execute([
                    $stepId, $type,
                    $this->json((array) ($rule['configuration'] ?? [])), $rank + 1,
                ]);
            }
        }
    }

    private function assign(
        int $organisationId,
        int $versionId,
        ?int $userId,
        ?int $groupId,
        string $name,
        ?int $actorId,
    ): int {
        return $this->transaction(function () use (
            $organisationId, $versionId, $userId, $groupId, $name, $actorId
        ): int {
            $version = $this->version($versionId);
            $owner = $this->one(
                'SELECT m.organisation_id FROM modeles_exercice m
                 JOIN versions_modeles_exercice v ON v.modele_id = m.id WHERE v.id = ?',
                [$versionId], 'Modèle de version absent.'
            );
            if ((int) $owner['organisation_id'] !== $organisationId
                || $version['statut'] !== 'publie') {
                throw new PedagogyException('Version publiée hors scope.');
            }
            $dossierId = $this->cloneVersion(
                $organisationId, $version, $name, $actorId
            );
            $this->pdo->prepare(
                'INSERT INTO assignations_exercice
                 (organisation_id, version_modele_id, dossier_id,
                  utilisateur_id, groupe_id, assignee_par)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $organisationId, $versionId, $dossierId,
                $userId, $groupId, $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->seedProgress($id, $versionId);
            $assignment = $this->assignment($organisationId, $id);
            foreach ($this->assignmentUsers($assignment) as $participant) {
                $this->grant($dossierId, $participant);
            }
            if ($groupId !== null) {
                $this->pdo->prepare(
                    'UPDATE groupes_pedagogiques SET dossier_partage_id = ?,
                     version = version + 1 WHERE id = ?'
                )->execute([$dossierId, $groupId]);
            }
            $this->audit->log(
                'pedagogie.exercice_assigne', $actorId, $organisationId,
                $dossierId, 'assignation', (string) $id
            );
            return $id;
        });
    }

    /** @param array<string,mixed> $version */
    private function cloneVersion(
        int $organisationId,
        array $version,
        string $name,
        ?int $actorId,
    ): int {
        $s = (array) json_decode(
            (string) $version['plan_snapshot_json'], true, 128, JSON_THROW_ON_ERROR
        );
        $slug = 'exercice-' . $version['id'] . '-' . bin2hex(random_bytes(5));
        $this->pdo->prepare(
            "INSERT INTO dossiers (organisation_id, nom, slug, type, monnaie)
             VALUES (?, ?, ?, 'exercice', ?)"
        )->execute([
            $organisationId, trim($name) ?: 'Exercice', $slug, $s['monnaie'] ?? 'CHF',
        ]);
        $dossier = (int) $this->pdo->lastInsertId();
        foreach (['regles_sens_comptes', 'types_comptes'] as $table) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE dossier_id = ?")
                ->execute([$dossier]);
        }
        foreach ($s['types'] ?? [] as $r) {
            $this->pdo->prepare(
                'INSERT INTO types_comptes
                 (organisation_id,dossier_id,code,libelle,ordre,actif,cree_par)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                $organisationId, $dossier, $r['code'], $r['libelle'],
                $r['ordre'], $r['actif'], $actorId,
            ]);
        }
        $rubrics = [];
        foreach ($s['rubriques'] ?? [] as $r) {
            $parent = (string) ($r['parent_code'] ?? '');
            $this->pdo->prepare(
                'INSERT INTO rubriques_comptables
                 (organisation_id,dossier_id,code,libelle,niveau_structure,type,
                  parent_id,ordre,actif,source_modele,cree_par)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $organisationId, $dossier, $r['code'], $r['libelle'],
                $r['niveau_structure'], $r['type'],
                $parent === '' ? null : ($rubrics[$parent] ?? null),
                $r['ordre'], $r['actif'], $r['source_modele'], $actorId,
            ]);
            $rubrics[(string) $r['code']] = (int) $this->pdo->lastInsertId();
        }
        $accounts = [];
        foreach ($s['comptes'] ?? [] as $r) {
            $parent = (string) ($r['parent_numero'] ?? '');
            $rubric = (string) ($r['rubrique_code'] ?? '');
            $this->pdo->prepare(
                'INSERT INTO comptes
                 (organisation_id,dossier_id,numero,libelle,type,sens_normal,
                  sens_mode,parent_id,rubrique_id,niveau,imputable,actif,marque,
                  source_modele,source_version,ordre,cree_par)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $organisationId, $dossier, $r['numero'], $r['libelle'], $r['type'],
                $r['sens_normal'], $r['sens_mode'],
                $parent === '' ? null : ($accounts[$parent] ?? null),
                $rubric === '' ? null : ($rubrics[$rubric] ?? null),
                $r['niveau'], $r['imputable'], $r['actif'], $r['marque'],
                $r['source_modele'], $r['source_version'], $r['ordre'], $actorId,
            ]);
            $accounts[(string) $r['numero']] = (int) $this->pdo->lastInsertId();
        }
        foreach ($s['regles_sens'] ?? [] as $r) {
            $this->pdo->prepare(
                'INSERT INTO regles_sens_comptes
                 (organisation_id,dossier_id,prefixe,cree_par) VALUES (?,?,?,?)'
            )->execute([$organisationId, $dossier, $r['prefixe'], $actorId]);
        }
        $exercises = [];
        foreach ($s['exercices'] ?? [] as $r) {
            $this->pdo->prepare(
                'INSERT INTO exercices
                 (dossier_id,libelle,date_debut,date_fin,statut) VALUES (?,?,?,?,?)'
            )->execute([
                $dossier, $r['libelle'], $r['date_debut'], $r['date_fin'], $r['statut'],
            ]);
            $exercise = (int) $this->pdo->lastInsertId();
            $exercises[] = $exercise;
            foreach ($r['periodes'] ?? [] as $p) {
                $this->pdo->prepare(
                    'INSERT INTO periodes
                     (organisation_id,dossier_id,exercice_id,libelle,date_debut,
                      date_fin,statut,cree_par) VALUES (?,?,?,?,?,?,?,?)'
                )->execute([
                    $organisationId, $dossier, $exercise, $p['libelle'],
                    $p['date_debut'], $p['date_fin'], $p['statut'], $actorId,
                ]);
            }
        }
        $journals = [];
        foreach ($s['journaux'] ?? [] as $r) {
            $this->pdo->prepare(
                'INSERT INTO journaux
                 (organisation_id,dossier_id,code,libelle,type,actif,cree_par)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                $organisationId, $dossier, $r['code'], $r['libelle'],
                $r['type'], $r['actif'], $actorId,
            ]);
            $journals[(string) $r['code']] = (int) $this->pdo->lastInsertId();
        }
        $this->initialEntries(
            $organisationId, $dossier, $version, $exercises, $journals,
            $accounts, $actorId
        );
        return $dossier;
    }

    /** @param list<int> $exercises @param array<string,int> $journals @param array<string,int> $accounts */
    private function initialEntries(
        int $organisationId,
        int $dossierId,
        array $version,
        array $exercises,
        array $journals,
        array $accounts,
        ?int $actorId,
    ): void {
        if ($exercises === []) {
            return;
        }
        $commands = [];
        foreach (['soldes_initiaux_json', 'donnees_initiales_json'] as $field) {
            $commands = [...$commands, ...(array) json_decode(
                (string) $version[$field], true, 64, JSON_THROW_ON_ERROR
            )];
        }
        foreach ($commands as $index => $command) {
            $lines = [];
            foreach ((array) ($command['lignes'] ?? []) as $line) {
                $number = (string) ($line['compte'] ?? '');
                if (!isset($accounts[$number])) {
                    throw new PedagogyException("Compte initial {$number} absent.");
                }
                $lines[] = [
                    'compte_id' => $accounts[$number],
                    'libelle' => (string) ($line['libelle'] ?? ''),
                    'debit_centimes' => (int) ($line['debit_centimes'] ?? 0),
                    'credit_centimes' => (int) ($line['credit_centimes'] ?? 0),
                ];
            }
            $journal = (string) ($command['journal'] ?? '');
            if (!isset($journals[$journal])) {
                throw new PedagogyException("Journal initial {$journal} absent.");
            }
            $this->entries->postGenerated([
                'organisation_id' => $organisationId,
                'dossier_id' => $dossierId,
                'exercice_id' => $exercises[0],
                'journal_id' => $journals[$journal],
                'date_comptable' => (string) ($command['date'] ?? ''),
                'libelle' => (string) ($command['libelle'] ?? 'Donnée initiale'),
                'source_type' => 'pedagogie_initiale',
                'source_id' => (string) $version['id'],
                'source_action' => 'initiale-' . ($index + 1),
                'lignes' => $lines,
            ], "pedagogie:{$dossierId}:initiale:" . ($index + 1), $actorId);
        }
    }

    private function seedProgress(int $assignmentId, int $versionId): void
    {
        $this->pdo->prepare(
            'INSERT INTO progressions_etapes (assignation_id, etape_id)
             SELECT ?, id FROM etapes_exercice WHERE version_modele_id = ?'
        )->execute([$assignmentId, $versionId]);
    }

    private function evaluate(
        string $type,
        array $config,
        int $dossierId,
        ?int $entryId,
    ): bool {
        if ($type === 'soldes') {
            foreach ((array) ($config['comptes'] ?? []) as $expected) {
                $stmt = $this->pdo->prepare(
                    "SELECT COALESCE(SUM(l.debit_centimes-l.credit_centimes),0)
                     FROM lignes_ecriture l JOIN ecritures e ON e.id=l.ecriture_id
                     JOIN comptes c ON c.id=l.compte_id
                     WHERE e.dossier_id=? AND e.statut='validee' AND c.numero=?"
                );
                $stmt->execute([$dossierId, $expected['compte'] ?? '']);
                if (abs(
                    (int) $stmt->fetchColumn()
                    - (int) ($expected['solde_centimes'] ?? 0)
                ) > (int) ($expected['tolerance_centimes'] ?? 0)) {
                    return false;
                }
            }
            return ($config['comptes'] ?? []) !== [];
        }
        if ($type === 'rapport') {
            $stmt = $this->pdo->prepare(
                "SELECT COALESCE(SUM(CASE
                 WHEN c.type='produit' THEN l.credit_centimes-l.debit_centimes
                 WHEN c.type='charge' THEN l.credit_centimes-l.debit_centimes
                 ELSE 0 END),0)
                 FROM lignes_ecriture l JOIN ecritures e ON e.id=l.ecriture_id
                 JOIN comptes c ON c.id=l.compte_id
                 WHERE e.dossier_id=? AND e.statut='validee'"
            );
            $stmt->execute([$dossierId]);
            return abs(
                (int) $stmt->fetchColumn()
                - (int) ($config['resultat_centimes'] ?? PHP_INT_MIN)
            ) <= (int) ($config['tolerance_centimes'] ?? 0);
        }
        $effects = $this->effects($dossierId, $entryId);
        if ($type === 'comptes') {
            return array_diff(
                array_map('strval', (array) ($config['comptes'] ?? [])),
                array_column($effects, 'compte')
            ) === [] && ($config['comptes'] ?? []) !== [];
        }
        $actual = [];
        foreach ($effects as $effect) {
            $key = $effect['compte'] . ':' . $effect['sens'];
            $actual[$key] = ($actual[$key] ?? 0) + $effect['montant'];
        }
        $expected = [];
        foreach ((array) ($config['lignes'] ?? []) as $line) {
            $key = ($line['compte'] ?? '') . ':' . ($line['sens'] ?? '');
            $expected[$key] = ($expected[$key] ?? 0)
                + (int) ($line['montant_centimes'] ?? 0);
        }
        if ($type === 'sens') {
            return array_diff_key($expected, $actual) === [] && $expected !== [];
        }
        if ($type === 'montants') {
            foreach ($expected as $key => $amount) {
                if (($actual[$key] ?? null) !== $amount) {
                    return false;
                }
            }
            return $expected !== [];
        }
        ksort($actual);
        ksort($expected);
        return $type === 'ecriture_equivalente' && $expected !== [] && $actual === $expected;
    }

    /** @return list<array{compte:string,sens:string,montant:int}> */
    private function effects(int $dossierId, ?int $entryId): array
    {
        if ($entryId === null) {
            return [];
        }
        $rows = $this->all(
            'SELECT c.numero,l.debit_centimes,l.credit_centimes
             FROM lignes_ecriture l JOIN ecritures e ON e.id=l.ecriture_id
             JOIN comptes c ON c.id=l.compte_id
             WHERE e.id=? AND e.dossier_id=?',
            [$entryId, $dossierId]
        );
        return array_map(static function (array $row): array {
            $debit = (int) $row['debit_centimes'];
            return [
                'compte' => (string) $row['numero'],
                'sens' => $debit > 0 ? 'debit' : 'credit',
                'montant' => $debit > 0 ? $debit : (int) $row['credit_centimes'],
            ];
        }, $rows);
    }

    /** @return array<string,mixed> */
    private function participant(int $organisationId, int $dossierId, int $userId): array
    {
        return $this->one(
            "SELECT a.* FROM assignations_exercice a
             JOIN dossiers d ON d.id=a.dossier_id AND d.actif=1 AND d.type='exercice'
             LEFT JOIN membres_groupes m ON m.groupe_id=a.groupe_id
               AND m.utilisateur_id=? AND m.retrait_le IS NULL
             WHERE a.organisation_id=? AND a.dossier_id=? AND a.statut='en_cours'
               AND (a.utilisateur_id=? OR m.id IS NOT NULL)",
            [$userId, $organisationId, $dossierId, $userId],
            'Accès à l’exercice refusé.'
        );
    }

    private function assertStep(int $assignmentId, int $stepId): void
    {
        $this->one(
            'SELECT 1 FROM progressions_etapes WHERE assignation_id=? AND etape_id=?',
            [$assignmentId, $stepId], 'Étape absente de cette assignation.'
        );
    }

    private function contribute(
        array $assignment,
        int $userId,
        ?int $entryId,
        string $action,
        array $summary = [],
    ): void {
        $this->pdo->prepare(
            'INSERT INTO contributions_pedagogiques
             (organisation_id,dossier_id,assignation_id,utilisateur_id,
              ecriture_id,action,resume_json) VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $assignment['organisation_id'], $assignment['dossier_id'],
            $assignment['id'], $userId, $entryId, $action, $this->json($summary),
        ]);
    }

    /** @return list<int> */
    private function assignmentUsers(array $assignment): array
    {
        if ($assignment['utilisateur_id'] !== null) {
            return [(int) $assignment['utilisateur_id']];
        }
        $stmt = $this->pdo->prepare(
            'SELECT utilisateur_id FROM membres_groupes
             WHERE groupe_id=? AND retrait_le IS NULL'
        );
        $stmt->execute([$assignment['groupe_id']]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function grant(int $dossierId, int $userId): void
    {
        $this->pdo->prepare(
            "INSERT OR IGNORE INTO utilisateur_roles_dossier
             (utilisateur_id,dossier_id,role_id)
             SELECT ?,?,id FROM roles WHERE code='apprenant'"
        )->execute([$userId, $dossierId]);
    }

    private function revoke(int $dossierId, int $userId): void
    {
        $this->pdo->prepare(
            "DELETE FROM utilisateur_roles_dossier
             WHERE utilisateur_id=? AND dossier_id=? AND role_id=
               (SELECT id FROM roles WHERE code='apprenant')"
        )->execute([$userId, $dossierId]);
    }

    private function assertUser(int $userId): void
    {
        $this->one(
            'SELECT 1 FROM utilisateurs WHERE id=? AND actif=1',
            [$userId], 'Utilisateur absent ou inactif.'
        );
    }

    /** @return array<string,mixed> */
    private function group(int $organisationId, int $groupId): array
    {
        return $this->one(
            'SELECT * FROM groupes_pedagogiques
             WHERE id=? AND organisation_id=? AND actif=1',
            [$groupId, $organisationId], 'Groupe absent.'
        );
    }

    /** @return array<string,mixed> */
    private function version(int $versionId): array
    {
        return $this->one(
            'SELECT * FROM versions_modeles_exercice WHERE id=?',
            [$versionId], 'Version de modèle absente.'
        );
    }

    /** @return array<string,mixed> */
    private function assignment(int $organisationId, int $assignmentId): array
    {
        return $this->one(
            "SELECT * FROM assignations_exercice
             WHERE id=? AND organisation_id=? AND statut='en_cours'",
            [$assignmentId, $organisationId], 'Assignation active absente.'
        );
    }

    /** @return array<string,mixed> */
    private function one(string $sql, array $params, string $error): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new PedagogyException($error);
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function all(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function json(array $value): string
    {
        return json_encode(
            $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    private function transaction(callable $callback): mixed
    {
        if ($this->inTransaction || $this->pdo->inTransaction()) {
            return $callback();
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->inTransaction = true;
        try {
            $result = $callback();
            $this->pdo->exec('COMMIT');
            $this->inTransaction = false;
            return $result;
        } catch (Throwable $e) {
            if ($this->inTransaction) {
                $this->pdo->exec('ROLLBACK');
                $this->inTransaction = false;
            }
            throw $e;
        }
    }
}
