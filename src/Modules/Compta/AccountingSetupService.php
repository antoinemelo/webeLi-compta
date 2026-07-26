<?php
declare(strict_types=1);

namespace Compta\Modules\Compta;

use Compta\Core\Audit\AuditLogger;
use DateTimeImmutable;
use PDO;

final class AccountingSetupService
{
    public const JOURNAL_TYPES = [
        'general', 'achats', 'ventes', 'banque', 'caisse', 'salaires', 'ouverture',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
    ) {
    }

    public function createPeriod(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $label,
        string $start,
        string $end,
        ?int $actorId = null,
    ): int {
        if (
            trim($label) === ''
            || !$this->validDate($start)
            || !$this->validDate($end)
            || $start > $end
        ) {
            throw new AccountingException('Données de période invalides.');
        }
        $exercise = $this->pdo->prepare(
            'SELECT x.date_debut, x.date_fin
             FROM exercices x
             JOIN dossiers d ON d.id = x.dossier_id
             WHERE x.id = ? AND x.dossier_id = ? AND d.organisation_id = ?
               AND x.statut = \'ouvert\''
        );
        $exercise->execute([$exerciseId, $dossierId, $organisationId]);
        $bounds = $exercise->fetch();
        if (
            $bounds === false
            || $start < $bounds['date_debut']
            || $end > $bounds['date_fin']
        ) {
            throw new AccountingException('Période hors exercice ou mauvais dossier.');
        }
        $overlap = $this->pdo->prepare(
            'SELECT 1 FROM periodes
             WHERE exercice_id = ? AND date_debut <= ? AND date_fin >= ?'
        );
        $overlap->execute([$exerciseId, $end, $start]);
        if ($overlap->fetchColumn() !== false) {
            throw new AccountingException('Les périodes comptables ne peuvent pas se chevaucher.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO periodes
                (organisation_id, dossier_id, exercice_id, libelle,
                 date_debut, date_fin, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId,
            $dossierId,
            $exerciseId,
            trim($label),
            $start,
            $end,
            $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'compta.periode_creee',
            $actorId,
            $organisationId,
            $dossierId,
            'periode',
            (string) $id,
            ['debut' => $start, 'fin' => $end]
        );
        return $id;
    }

    public function closePeriod(
        int $organisationId,
        int $dossierId,
        int $periodId,
        ?int $actorId = null,
    ): void {
        $stmt = $this->pdo->prepare(
            "UPDATE periodes
             SET statut = 'fermee', modifie_le = datetime('now'), version = version + 1
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?"
        );
        $stmt->execute([$periodId, $organisationId, $dossierId]);
        if ($stmt->rowCount() !== 1) {
            throw new AccountingException('Période absente du dossier.');
        }
        $this->audit->log(
            'compta.periode_fermee',
            $actorId,
            $organisationId,
            $dossierId,
            'periode',
            (string) $periodId
        );
    }

    public function createJournal(
        int $organisationId,
        int $dossierId,
        string $code,
        string $label,
        string $type = 'general',
        ?int $actorId = null,
        bool $active = true,
    ): int {
        $code = mb_strtoupper(trim($code));
        if (
            !preg_match('/^[A-Z0-9_-]{1,12}$/', $code)
            || trim($label) === ''
            || !in_array($type, self::JOURNAL_TYPES, true)
        ) {
            throw new AccountingException('Données de journal invalides.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO journaux
                (organisation_id, dossier_id, code, libelle, type, actif, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId,
            $dossierId,
            $code,
            trim($label),
            $type,
            $active ? 1 : 0,
            $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'compta.journal_cree',
            $actorId,
            $organisationId,
            $dossierId,
            'journal',
            (string) $id,
            ['code' => $code, 'type' => $type]
        );
        return $id;
    }

    public function updateJournal(
        int $organisationId,
        int $dossierId,
        int $journalId,
        int $expectedVersion,
        string $code,
        string $label,
        string $type,
        bool $active,
        ?int $actorId = null,
    ): void {
        $code = mb_strtoupper(trim($code));
        if (
            $journalId < 1
            || $expectedVersion < 1
            || !preg_match('/^[A-Z0-9_-]{1,12}$/', $code)
            || trim($label) === ''
            || !in_array($type, self::JOURNAL_TYPES, true)
        ) {
            throw new AccountingException('Données de journal invalides.');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE journaux
             SET code = ?, libelle = ?, type = ?, actif = ?,
                 modifie_le = datetime(\'now\'), version = version + 1
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND version = ?'
        );
        $stmt->execute([
            $code,
            trim($label),
            $type,
            $active ? 1 : 0,
            $journalId,
            $organisationId,
            $dossierId,
            $expectedVersion,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new AccountingException(
                'Journal absent ou modifié par un autre utilisateur.'
            );
        }
        $this->audit->log(
            'compta.journal_modifie',
            $actorId,
            $organisationId,
            $dossierId,
            'journal',
            (string) $journalId,
            ['code' => $code, 'type' => $type, 'actif' => $active]
        );
    }

    public function createExercise(
        int $organisationId,
        int $dossierId,
        string $label,
        string $start,
        string $end,
        ?int $actorId = null,
    ): int {
        if (
            trim($label) === ''
            || !$this->validDate($start)
            || !$this->validDate($end)
            || $start > $end
        ) {
            throw new AccountingException('Données d’exercice invalides.');
        }
        $scope = $this->pdo->prepare(
            'SELECT 1 FROM dossiers WHERE id = ? AND organisation_id = ?'
        );
        $scope->execute([$dossierId, $organisationId]);
        if ($scope->fetchColumn() === false) {
            throw new AccountingException('Dossier comptable invalide.');
        }
        $overlap = $this->pdo->prepare(
            'SELECT 1 FROM exercices
             WHERE dossier_id = ? AND date_debut <= ? AND date_fin >= ?'
        );
        $overlap->execute([$dossierId, $end, $start]);
        if ($overlap->fetchColumn() !== false) {
            throw new AccountingException(
                'Les exercices comptables ne peuvent pas se chevaucher.'
            );
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO exercices (dossier_id, libelle, date_debut, date_fin)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$dossierId, trim($label), $start, $end]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'compta.exercice_cree',
            $actorId,
            $organisationId,
            $dossierId,
            'exercice',
            (string) $id,
            ['debut' => $start, 'fin' => $end]
        );
        return $id;
    }

    public function setExerciseStatus(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        int $expectedVersion,
        string $status,
        ?int $actorId = null,
    ): void {
        if (!in_array($status, ['ouvert', 'ferme'], true)) {
            throw new AccountingException('Statut d’exercice invalide.');
        }
        if ($status === 'ferme') {
            $openPeriods = $this->pdo->prepare(
                "SELECT COUNT(*) FROM periodes
                 WHERE exercice_id = ? AND organisation_id = ?
                   AND dossier_id = ? AND statut = 'ouverte'"
            );
            $openPeriods->execute([$exerciseId, $organisationId, $dossierId]);
            if ((int) $openPeriods->fetchColumn() > 0) {
                throw new AccountingException(
                    'Fermez d’abord toutes les périodes de cet exercice.'
                );
            }
            $draftEntries = $this->pdo->prepare(
                "SELECT COUNT(*) FROM ecritures
                 WHERE exercice_id = ? AND organisation_id = ?
                   AND dossier_id = ? AND statut = 'brouillon'"
            );
            $draftEntries->execute([$exerciseId, $organisationId, $dossierId]);
            if ((int) $draftEntries->fetchColumn() > 0) {
                throw new AccountingException(
                    'Validez ou retirez les écritures brouillon avant la clôture.'
                );
            }
        }
        $stmt = $this->pdo->prepare(
            'UPDATE exercices
             SET statut = ?, version = version + 1
             WHERE id = ? AND dossier_id = ? AND version = ?
               AND EXISTS (
                   SELECT 1 FROM dossiers d
                   WHERE d.id = exercices.dossier_id
                     AND d.organisation_id = ?
               )'
        );
        $stmt->execute([
            $status,
            $exerciseId,
            $dossierId,
            $expectedVersion,
            $organisationId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new AccountingException(
                'Exercice absent ou modifié par un autre utilisateur.'
            );
        }
        $this->audit->log(
            'compta.exercice_statut_modifie',
            $actorId,
            $organisationId,
            $dossierId,
            'exercice',
            (string) $exerciseId,
            ['statut' => $status]
        );
    }

    public function setPeriodStatus(
        int $organisationId,
        int $dossierId,
        int $periodId,
        int $expectedVersion,
        string $status,
        ?int $actorId = null,
    ): void {
        if (!in_array($status, ['ouverte', 'fermee'], true)) {
            throw new AccountingException('Statut de période invalide.');
        }
        if ($status === 'ouverte') {
            $exercise = $this->pdo->prepare(
                "SELECT 1 FROM periodes p
                 JOIN exercices x ON x.id = p.exercice_id
                 WHERE p.id = ? AND p.organisation_id = ?
                   AND p.dossier_id = ? AND x.statut = 'ouvert'"
            );
            $exercise->execute([$periodId, $organisationId, $dossierId]);
            if ($exercise->fetchColumn() === false) {
                throw new AccountingException(
                    'Une période ne peut pas être ouverte dans un exercice fermé.'
                );
            }
        } else {
            $drafts = $this->pdo->prepare(
                "SELECT COUNT(*)
                 FROM periodes p
                 JOIN ecritures e
                   ON e.exercice_id = p.exercice_id
                  AND e.organisation_id = p.organisation_id
                  AND e.dossier_id = p.dossier_id
                  AND e.date_comptable BETWEEN p.date_debut AND p.date_fin
                  AND e.statut = 'brouillon'
                 WHERE p.id = ? AND p.organisation_id = ?
                   AND p.dossier_id = ?"
            );
            $drafts->execute([$periodId, $organisationId, $dossierId]);
            if ((int) $drafts->fetchColumn() > 0) {
                throw new AccountingException(
                    'Validez ou retirez les écritures brouillon avant la clôture.'
                );
            }
        }
        $stmt = $this->pdo->prepare(
            'UPDATE periodes
             SET statut = ?, modifie_le = datetime(\'now\'), version = version + 1
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND version = ?'
        );
        $stmt->execute([
            $status,
            $periodId,
            $organisationId,
            $dossierId,
            $expectedVersion,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new AccountingException(
                'Période absente ou modifiée par un autre utilisateur.'
            );
        }
        $this->audit->log(
            'compta.periode_statut_modifie',
            $actorId,
            $organisationId,
            $dossierId,
            'periode',
            (string) $periodId,
            ['statut' => $status]
        );
    }

    private function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
