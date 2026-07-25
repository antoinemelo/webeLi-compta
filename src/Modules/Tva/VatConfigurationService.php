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
        $this->pdo->beginTransaction();
        try {
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
                ['statut' => $status, 'methode' => $method, 'date_debut' => $start]
            );
            $this->pdo->commit();
            return $id;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
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
              compte_tva_id, date_debut, date_fin, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
            $actorId,
        ]);
        return (int) $this->pdo->lastInsertId();
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
