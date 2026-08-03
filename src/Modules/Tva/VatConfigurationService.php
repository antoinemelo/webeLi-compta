<?php
declare(strict_types=1);

namespace Compta\Modules\Tva;

use Compta\Core\Audit\AuditLogger;
use DateTimeImmutable;
use PDO;
use Throwable;

final class VatConfigurationService
{
    public const VERIFIED_ON = '2026-07-25';
    public const ESTV_RATES_URL = 'https://www.estv.admin.ch/fr/taux-de-la-tva-suisse';
    public const ESTV_TDFN_URL = 'https://www.estv.admin.ch/fr/tva-taux-de-la-dette-fiscale-nette-et-taux-forfaitaires';

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function addRegime(array $data, ?int $actorId = null): int
    {
        $status = (string) ($data['statut'] ?? '');
        $method = (string) ($data['methode'] ?? '');
        $reporting = (string) ($data['mode_decompte'] ?? '');
        $periodicity = (string) ($data['periodicite'] ?? '');
        $start = (string) ($data['date_debut'] ?? '');
        $end = ($data['date_fin'] ?? null) ?: null;
        if (
            !in_array($status, ['non_assujetti', 'assujetti', 'volontaire'], true)
            || !in_array($method, ['effective', 'tdfn'], true)
            || !in_array($reporting, ['convenues', 'recues'], true)
            || !in_array($periodicity, ['mensuelle', 'trimestrielle', 'semestrielle', 'annuelle'], true)
            || !$this->validDate($start)
            || ($end !== null && (!$this->validDate((string) $end) || $end < $start))
        ) {
            throw new VatException('Configuration TVA invalide.');
        }
        $vatNumber = strtoupper(str_replace([' ', '.', '-'], '', (string) ($data['numero_tva'] ?? '')));
        if ($status === 'non_assujetti') {
            $vatNumber = '';
        } elseif (preg_match('/^CHE[1-9][0-9]{8}(?:TVA|MWST|IVA)?$/', $vatNumber) !== 1) {
            throw new VatException('Numéro IDE/TVA invalide.');
        }
        $organisationId = (int) $data['organisation_id'];
        $dossierId = (int) $data['dossier_id'];
        $accountFields = [
            'compte_impot_prealable_materiel_id',
            'compte_impot_prealable_investissements_id',
            'compte_tva_due_id',
            'compte_decompte_tva_id',
            'compte_corrections_id',
        ];
        foreach ($accountFields as $field) {
            if (($data[$field] ?? null) !== null) {
                $this->assertAccount($organisationId, $dossierId, (int) $data[$field]);
            }
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $replacedInitialRegimeId = null;
            if (($data['remplacer_regime_initial'] ?? false) === true) {
                $replacedInitialRegimeId = $this->replaceableInitialRegime(
                    $organisationId,
                    $dossierId,
                    $start
                );
                if ($replacedInitialRegimeId !== null) {
                    $this->pdo->prepare(
                        'DELETE FROM tva_regimes WHERE id = ?'
                    )->execute([$replacedInitialRegimeId]);
                }
            }
            if (($data['fermer_precedent'] ?? false) === true) {
                $previousDay = (new DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d');
                $close = $this->pdo->prepare(
                    'UPDATE tva_regimes SET date_fin = ?
                     WHERE organisation_id = ? AND dossier_id = ?
                       AND date_fin IS NULL AND date_debut < ?'
                );
                $close->execute([$previousDay, $organisationId, $dossierId, $start]);
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO tva_regimes
                 (organisation_id, dossier_id, statut, numero_tva, methode,
                  mode_decompte, periodicite, date_debut, date_fin,
                  compte_impot_prealable_materiel_id,
                  compte_impot_prealable_investissements_id,
                  compte_tva_due_id, compte_decompte_tva_id, compte_corrections_id,
                  source_reglementaire, verifie_le, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $organisationId, $dossierId, $status, $vatNumber, $method,
                $reporting, $periodicity, $start, $end,
                $data['compte_impot_prealable_materiel_id'] ?? null,
                $data['compte_impot_prealable_investissements_id'] ?? null,
                $data['compte_tva_due_id'] ?? null,
                $data['compte_decompte_tva_id'] ?? null,
                $data['compte_corrections_id'] ?? null,
                self::ESTV_RATES_URL, self::VERIFIED_ON, $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit->log(
                'tva.regime_ajoute',
                $actorId,
                $organisationId,
                $dossierId,
                'regime_tva',
                (string) $id,
                [
                    'statut' => $status,
                    'methode' => $method,
                    'date_debut' => $start,
                    'regime_initial_remplace_id' => $replacedInitialRegimeId,
                ]
            );
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $id;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function replaceableInitialRegime(
        int $organisationId,
        int $dossierId,
        string $start,
    ): ?int {
        $stmt = $this->pdo->prepare(
            "SELECT r.id
             FROM tva_regimes r
             WHERE r.organisation_id = ? AND r.dossier_id = ?
               AND r.date_debut = ? AND r.date_fin IS NULL
               AND r.statut = 'non_assujetti' AND r.numero_tva = ''
               AND r.methode = 'effective'
               AND r.mode_decompte = 'convenues'
               AND r.periodicite = 'annuelle'
               AND (SELECT COUNT(*) FROM tva_regimes all_r
                    WHERE all_r.organisation_id = r.organisation_id
                      AND all_r.dossier_id = r.dossier_id) = 1
               AND NOT EXISTS (
                    SELECT 1 FROM tva_periodes p
                    WHERE p.regime_tva_id = r.id
               )
               AND NOT EXISTS (
                    SELECT 1 FROM documents_financiers d
                    WHERE d.organisation_id = r.organisation_id
                      AND d.dossier_id = r.dossier_id
               )
               AND NOT EXISTS (
                    SELECT 1 FROM parametres_dossier p
                    WHERE p.dossier_id = r.dossier_id
                      AND p.cle IN ('setup_guide.vat', 'setup_guide.finished')
               )
             LIMIT 1"
        );
        $stmt->execute([$organisationId, $dossierId, $start]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @param array<string,mixed> $data */
    public function addCode(array $data, ?int $actorId = null): int
    {
        $organisationId = (int) $data['organisation_id'];
        $dossierId = (int) $data['dossier_id'];
        if (($data['compte_tva_id'] ?? null) !== null) {
            $this->assertAccount($organisationId, $dossierId, (int) $data['compte_tva_id']);
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO tva_codes
             (organisation_id, dossier_id, code, libelle, traitement, nature,
              taux_legal_id, droit_deduction, deduction_defaut_bp, chiffre_afc,
              compte_tva_id, date_debut, date_fin, actif, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId,
            $dossierId,
            strtoupper(trim((string) $data['code'])),
            trim((string) $data['libelle']),
            (string) $data['traitement'],
            (string) $data['nature'],
            $data['taux_legal_id'] ?? null,
            !empty($data['droit_deduction']) ? 1 : 0,
            (int) ($data['deduction_defaut_bp'] ?? 0),
            trim((string) ($data['chiffre_afc'] ?? '')),
            $data['compte_tva_id'] ?? null,
            (string) $data['date_debut'],
            ($data['date_fin'] ?? null) ?: null,
            !array_key_exists('actif', $data) || !empty($data['actif']) ? 1 : 0,
            $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'tva.code_ajoute',
            $actorId,
            $organisationId,
            $dossierId,
            'code_tva',
            (string) $id,
            [
                'code' => strtoupper(trim((string) $data['code'])),
                'date_debut' => (string) $data['date_debut'],
            ]
        );
        return $id;
    }

    /** @param array<string,mixed> $data */
    public function updateCode(
        int $organisationId,
        int $dossierId,
        int $codeId,
        array $data,
        ?int $actorId = null,
    ): void {
        $this->assertCode($organisationId, $dossierId, $codeId);
        if (($data['compte_tva_id'] ?? null) !== null) {
            $this->assertAccount(
                $organisationId,
                $dossierId,
                (int) $data['compte_tva_id']
            );
        }
        $stmt = $this->pdo->prepare(
            'UPDATE tva_codes
             SET code = ?, libelle = ?, traitement = ?, nature = ?,
                 taux_legal_id = ?, droit_deduction = ?,
                 deduction_defaut_bp = ?, chiffre_afc = ?,
                 compte_tva_id = ?, date_debut = ?, date_fin = ?, actif = ?
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([
            strtoupper(trim((string) $data['code'])),
            trim((string) $data['libelle']),
            (string) $data['traitement'],
            (string) $data['nature'],
            $data['taux_legal_id'] ?? null,
            !empty($data['droit_deduction']) ? 1 : 0,
            (int) ($data['deduction_defaut_bp'] ?? 0),
            trim((string) ($data['chiffre_afc'] ?? '')),
            $data['compte_tva_id'] ?? null,
            (string) $data['date_debut'],
            ($data['date_fin'] ?? null) ?: null,
            !empty($data['actif']) ? 1 : 0,
            $codeId,
            $organisationId,
            $dossierId,
        ]);
        $this->audit->log(
            'tva.code_modifie',
            $actorId,
            $organisationId,
            $dossierId,
            'code_tva',
            (string) $codeId,
            [
                'code' => strtoupper(trim((string) $data['code'])),
                'actif' => !empty($data['actif']),
            ]
        );
    }

    public function deleteCode(
        int $organisationId,
        int $dossierId,
        int $codeId,
        ?int $actorId = null,
    ): void {
        $this->assertCode($organisationId, $dossierId, $codeId);
        if ($this->codeUsageCount($organisationId, $dossierId, $codeId) > 0) {
            throw new VatException(
                'Ce code TVA est déjà utilisé. Désactivez-le pour préserver l’historique.'
            );
        }
        $stmt = $this->pdo->prepare(
            'DELETE FROM tva_codes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$codeId, $organisationId, $dossierId]);
        $this->audit->log(
            'tva.code_supprime',
            $actorId,
            $organisationId,
            $dossierId,
            'code_tva',
            (string) $codeId
        );
    }

    /** @param array<string,mixed> $data */
    public function addTdfn(array $data, ?int $actorId = null): int
    {
        $activityId = trim((string) ($data['activite_id'] ?? ''));
        if (preg_match('/^[0-9A-Za-z]{5}$/', $activityId) !== 1) {
            throw new VatException('Identifiant technique d’activité TDFN invalide.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO tva_tdfn
             (organisation_id, dossier_id, activite_id, activite, taux_bp,
              date_debut, date_fin, seuil_chiffre_affaires_centimes,
              autorisation_reference, source_url, cree_par)
             SELECT ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
             WHERE EXISTS (
                 SELECT 1 FROM dossiers WHERE id = ? AND organisation_id = ?
             )'
        );
        $stmt->execute([
            (int) $data['organisation_id'], (int) $data['dossier_id'],
            $activityId, trim((string) $data['activite']), (int) $data['taux_bp'],
            (string) $data['date_debut'], ($data['date_fin'] ?? null) ?: null,
            $data['seuil_chiffre_affaires_centimes'] ?? null,
            trim((string) $data['autorisation_reference']),
            self::ESTV_TDFN_URL, $actorId,
            (int) $data['dossier_id'], (int) $data['organisation_id'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new VatException('Dossier TVA introuvable.');
        }
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    public function regimeAt(int $organisationId, int $dossierId, string $date): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tva_regimes
             WHERE organisation_id = ? AND dossier_id = ?
               AND date_debut <= ? AND COALESCE(date_fin, \'9999-12-31\') >= ?
             ORDER BY date_debut DESC LIMIT 1'
        );
        $stmt->execute([$organisationId, $dossierId, $date, $date]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new VatException('Aucun régime TVA applicable à cette date.');
        }
        return $row;
    }

    private function assertCode(
        int $organisationId,
        int $dossierId,
        int $codeId,
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM tva_codes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$codeId, $organisationId, $dossierId]);
        if ($stmt->fetchColumn() === false) {
            throw new VatException('Code TVA absent ou hors du dossier.');
        }
    }

    private function codeUsageCount(
        int $organisationId,
        int $dossierId,
        int $codeId,
    ): int {
        $stmt = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*)
                 FROM lignes_document l
                 JOIN documents_financiers d ON d.id = l.document_id
                 WHERE l.code_tva_id = ?
                   AND d.organisation_id = ? AND d.dossier_id = ?)
              + (SELECT COUNT(*) FROM tva_lignes
                 WHERE code_tva_id = ?
                   AND organisation_id = ? AND dossier_id = ?)
              + (SELECT COUNT(*) FROM modeles_factures_recurrentes m
                 WHERE m.organisation_id = ? AND m.dossier_id = ?
                   AND EXISTS (
                       SELECT 1 FROM json_each(m.lignes_json) j
                       WHERE CAST(json_extract(j.value, \'$.code_tva_id\') AS INTEGER) = ?
                   ))
              + (SELECT COUNT(*) FROM modeles_depenses_recurrentes m
                 WHERE m.organisation_id = ? AND m.dossier_id = ?
                   AND EXISTS (
                       SELECT 1 FROM json_each(m.lignes_json) j
                       WHERE CAST(json_extract(j.value, \'$.code_tva_id\') AS INTEGER) = ?
                   ))'
        );
        $stmt->execute([
            $codeId, $organisationId, $dossierId,
            $codeId, $organisationId, $dossierId,
            $organisationId, $dossierId, $codeId,
            $organisationId, $dossierId, $codeId,
        ]);
        return (int) $stmt->fetchColumn();
    }

    private function assertAccount(int $organisationId, int $dossierId, int $accountId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM comptes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ? AND imputable = 1'
        );
        $stmt->execute([$accountId, $organisationId, $dossierId]);
        if ($stmt->fetchColumn() === false) {
            throw new VatException('Compte TVA absent ou hors du dossier.');
        }
    }

    private function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
