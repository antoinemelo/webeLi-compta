<?php
declare(strict_types=1);

namespace Compta\Modules\Compta;

use Compta\Core\Audit\AuditLogger;
use DateTimeImmutable;
use PDO;

final class AccountingSetupService
{
    private const JOURNAL_TYPES = [
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
             WHERE x.id = ? AND x.dossier_id = ? AND d.organisation_id = ?'
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
                (organisation_id, dossier_id, code, libelle, type, cree_par)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId,
            $dossierId,
            $code,
            trim($label),
            $type,
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

    private function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
