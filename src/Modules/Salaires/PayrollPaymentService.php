<?php
declare(strict_types=1);

namespace Compta\Modules\Salaires;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Compta\EntryService;
use PDO;
use Throwable;

final class PayrollPaymentService
{
    public const BENEFICIARY_LABELS = [
        'net' => 'Employé',
        'ocas' => 'OCAS',
        'laa' => 'Assureur LAA',
        'lpp' => 'Institution LPP',
        'impot_source' => 'Administration fiscale',
        'organisme' => 'Organisme',
    ];

    private bool $transactionActive = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly EntryService $entries,
    ) {
    }

    public function create(
        int $organisationId,
        int $dossierId,
        string $beneficiaryType,
        ?int $employeeId,
        string $date,
        int $amountCents,
        int $treasuryAccountId,
        string $reference = '',
        ?int $actorId = null,
        ?int $treasuryOperationalAccountId = null,
        ?int $liabilityId = null,
    ): int {
        $beneficiaryCode = $beneficiaryType === 'employe' ? 'net' : 'organisme';
        if ($liabilityId !== null) {
            $referenceLiability = $this->liability(
                $organisationId,
                $dossierId,
                $liabilityId
            );
            $beneficiaryCode = (string) $referenceLiability['type'];
            $beneficiaryType = $beneficiaryCode === 'net' ? 'employe' : 'organisme';
            $employeeId = $beneficiaryCode === 'net'
                ? (int) $referenceLiability['employe_id']
                : null;
        }
        if (
            !in_array($beneficiaryType, ['employe', 'organisme'], true)
            || !array_key_exists($beneficiaryCode, self::BENEFICIARY_LABELS)
            || !$this->validDate($date)
            || $amountCents <= 0
            || $treasuryAccountId <= 0
            || ($beneficiaryType === 'employe') !== ($employeeId !== null)
        ) {
            throw new PayrollException('Paiement salarial invalide.');
        }
        $this->assertAccount($organisationId, $dossierId, $treasuryAccountId);
        if ($treasuryOperationalAccountId !== null) {
            $operational = $this->pdo->prepare(
                'SELECT 1 FROM comptes_tresorerie
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND compte_comptable_id = ? AND actif = 1'
            );
            $operational->execute([
                $treasuryOperationalAccountId,
                $organisationId,
                $dossierId,
                $treasuryAccountId,
            ]);
            if ($operational->fetchColumn() === false) {
                throw new PayrollException('Compte de trésorerie opérationnel invalide.');
            }
        }
        if ($employeeId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM employes
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
            );
            $stmt->execute([$employeeId, $organisationId, $dossierId]);
            if ($stmt->fetchColumn() === false) {
                throw new PayrollException('Employé du paiement absent du dossier.');
            }
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO paiements_salaires
             (organisation_id, dossier_id, beneficiaire_type, beneficiaire_code, employe_id,
              date_paiement, montant_centimes, reference,
              compte_tresorerie_id, compte_tresorerie_operationnel_id, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId, $dossierId, $beneficiaryType, $beneficiaryCode, $employeeId,
            $date, $amountCents, trim($reference), $treasuryAccountId,
            $treasuryOperationalAccountId, $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'salaires.paiement_saisi',
            $actorId,
            $organisationId,
            $dossierId,
            'paiement_salaire',
            (string) $id,
            [
                'montant_centimes' => $amountCents,
                'beneficiaire_code' => $beneficiaryCode,
                'dette_reference_id' => $liabilityId,
            ]
        );
        return $id;
    }

    public function allocate(
        int $organisationId,
        int $dossierId,
        int $paymentId,
        int $liabilityId,
        int $amountCents,
        ?int $actorId = null,
    ): int {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $paymentId,
            $liabilityId,
            $amountCents,
            $actorId
        ): int {
            if ($amountCents <= 0) {
                throw new PayrollException('Montant d’allocation invalide.');
            }
            $payment = $this->payment($organisationId, $dossierId, $paymentId);
            $liability = $this->liability(
                $organisationId,
                $dossierId,
                $liabilityId
            );
            if ($payment['statut'] !== 'valide') {
                throw new PayrollException('Le paiement salarial est annulé.');
            }
            $beneficiaryCode = (string) ($payment['beneficiaire_code'] ?? (
                $payment['beneficiaire_type'] === 'employe' ? 'net' : 'organisme'
            ));
            $employeePayment = $beneficiaryCode === 'net';
            if (
                ($employeePayment && (
                    $liability['type'] !== 'net'
                    || (int) $payment['employe_id'] !== (int) $liability['employe_id']
                ))
                || (!$employeePayment && (
                    $liability['type'] === 'net'
                    || (
                        $beneficiaryCode !== 'organisme'
                        && $liability['type'] !== $beneficiaryCode
                    )
                ))
            ) {
                throw new PayrollException('Paiement et dette salariale incompatibles.');
            }
            $paymentAllocated = $this->allocatedFor('paiement_salaire_id', $paymentId);
            $liabilityAllocated = $this->allocatedFor('dette_salaire_id', $liabilityId);
            if (
                $paymentAllocated + $amountCents > (int) $payment['montant_centimes']
                || $liabilityAllocated + $amountCents > (int) $liability['montant_centimes']
            ) {
                throw new PayrollException('Allocation supérieure au solde disponible.');
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO allocations_salaires
                 (organisation_id, dossier_id, paiement_salaire_id,
                  dette_salaire_id, montant_centimes, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId, $dossierId, $paymentId, $liabilityId,
                $amountCents, $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->refreshPayrollStatus((int) $liability['fiche_salaire_id']);
            $this->audit->log(
                'salaires.paiement_alloue',
                $actorId,
                $organisationId,
                $dossierId,
                'allocation_salaire',
                (string) $id,
                ['dette_id' => $liabilityId, 'montant_centimes' => $amountCents]
            );
            return $id;
        }, true);
    }

    public function post(
        int $organisationId,
        int $dossierId,
        int $paymentId,
        int $exerciseId,
        int $journalId,
        ?int $actorId = null,
    ): int {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $paymentId,
            $exerciseId,
            $journalId,
            $actorId
        ): int {
            $payment = $this->payment($organisationId, $dossierId, $paymentId);
            if ($payment['ecriture_id'] !== null) {
                return (int) $payment['ecriture_id'];
            }
            $allocations = $this->allocationRows($paymentId);
            $allocated = array_sum(array_map(
                static fn (array $row): int => (int) $row['montant_centimes'],
                $allocations
            ));
            if (
                $payment['statut'] !== 'valide'
                || $allocated !== (int) $payment['montant_centimes']
            ) {
                throw new PayrollException(
                    'Le paiement doit être entièrement alloué avant comptabilisation.'
                );
            }
            if (array_filter(
                $allocations,
                static fn (array $row): bool => $row['fiche_ecriture_id'] === null
            ) !== []) {
                throw new PayrollException(
                    'La fiche de salaire doit être comptabilisée avant son décaissement.'
                );
            }
            $lines = [];
            foreach ($allocations as $allocation) {
                $lines[] = [
                    'compte_id' => (int) $allocation['compte_dette_id'],
                    'libelle' => 'Règlement ' . (string) $allocation['type'],
                    'debit_centimes' => (int) $allocation['montant_centimes'],
                ];
            }
            $lines[] = [
                'compte_id' => (int) $payment['compte_tresorerie_id'],
                'compte_tresorerie_operationnel_id' =>
                    $payment['compte_tresorerie_operationnel_id'] === null
                        ? null : (int) $payment['compte_tresorerie_operationnel_id'],
                'libelle' => 'Décaissement salaires',
                'credit_centimes' => (int) $payment['montant_centimes'],
            ];
            $entryId = $this->entries->postGenerated([
                'organisation_id' => $organisationId,
                'dossier_id' => $dossierId,
                'exercice_id' => $exerciseId,
                'journal_id' => $journalId,
                'date_comptable' => (string) $payment['date_paiement'],
                'libelle' => 'Paiement salaires — '
                    . ((string) $payment['reference'] ?: (string) $paymentId),
                'reference' => (string) $payment['reference'],
                'source_type' => 'paiement_salaire',
                'source_id' => (string) $paymentId,
                'source_action' => 'comptabiliser',
                'lignes' => $lines,
            ], 'paiement-salaire:' . $paymentId . ':comptabiliser', $actorId);
            $this->pdo->prepare(
                'UPDATE paiements_salaires SET ecriture_id = ?
                 WHERE id = ? AND ecriture_id IS NULL'
            )->execute([$entryId, $paymentId]);
            $this->audit->log(
                'salaires.paiement_comptabilise',
                $actorId,
                $organisationId,
                $dossierId,
                'paiement_salaire',
                (string) $paymentId,
                ['ecriture_id' => $entryId]
            );
            return $entryId;
        });
    }

    /** @return list<array<string,mixed>> */
    public function payments(
        int $organisationId,
        int $dossierId,
        bool $revealPii = true,
    ): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.*, e.prenom, e.nom,
                    c.numero AS compte_tresorerie_numero,
                    c.libelle AS compte_tresorerie_libelle,
                    pe.numero AS ecriture_numero,
                    (
                      SELECT GROUP_CONCAT(DISTINCT fe.numero)
                      FROM allocations_salaires source_a
                      JOIN dettes_salaires source_d
                        ON source_d.id = source_a.dette_salaire_id
                      JOIN fiches_salaires source_f
                        ON source_f.id = source_d.fiche_salaire_id
                      JOIN ecritures fe ON fe.id = source_f.ecriture_id
                      WHERE source_a.paiement_salaire_id = p.id
                        AND source_a.statut = 'valide'
                    ) AS dette_ecriture_numeros,
                    COALESCE(t.monnaie, d.monnaie) AS monnaie,
                    COALESCE((
                      SELECT SUM(a.montant_centimes)
                      FROM allocations_salaires a
                      WHERE a.paiement_salaire_id = p.id AND a.statut = 'valide'
                    ), 0) AS alloue_centimes
             FROM paiements_salaires p
             LEFT JOIN employes e ON e.id = p.employe_id
             JOIN dossiers d ON d.id = p.dossier_id
             LEFT JOIN comptes c ON c.id = p.compte_tresorerie_id
             LEFT JOIN ecritures pe ON pe.id = p.ecriture_id
             LEFT JOIN comptes_tresorerie t
               ON t.id = p.compte_tresorerie_operationnel_id
             WHERE p.organisation_id = ? AND p.dossier_id = ?
             ORDER BY p.date_paiement DESC, p.id DESC"
        );
        $stmt->execute([$organisationId, $dossierId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['non_alloue_centimes'] = (int) $row['montant_centimes']
                - (int) $row['alloue_centimes'];
            $code = (string) ($row['beneficiaire_code'] ?? (
                $row['beneficiaire_type'] === 'employe' ? 'net' : 'organisme'
            ));
            $row['beneficiaire_code'] = $code;
            $row['beneficiaire_libelle'] = $code === 'net' && $revealPii
                ? trim((string) $row['prenom'] . ' ' . (string) $row['nom'])
                : (self::BENEFICIARY_LABELS[$code] ?? $code);
            if (!$revealPii) {
                unset($row['prenom'], $row['nom'], $row['employe_id']);
            }
        }
        unset($row);
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function liabilities(
        int $organisationId,
        int $dossierId,
        bool $revealPii = true,
    ): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT d.*, f.annee, f.mois, f.employe_id, e.prenom, e.nom,
                    COALESCE((
                      SELECT SUM(a.montant_centimes)
                      FROM allocations_salaires a
                      WHERE a.dette_salaire_id = d.id AND a.statut = 'valide'
                    ), 0) AS alloue_centimes
             FROM dettes_salaires d
             JOIN fiches_salaires f ON f.id = d.fiche_salaire_id
             JOIN employes e ON e.id = f.employe_id
             WHERE d.organisation_id = ? AND d.dossier_id = ?
               AND f.statut NOT IN ('brouillon', 'annulee')
             ORDER BY f.annee, f.mois, d.type"
        );
        $stmt->execute([$organisationId, $dossierId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['solde_centimes'] = (int) $row['montant_centimes']
                - (int) $row['alloue_centimes'];
            $code = (string) $row['type'];
            $row['beneficiaire_code'] = $code;
            $row['beneficiaire_libelle'] = $code === 'net' && $revealPii
                ? trim((string) $row['prenom'] . ' ' . (string) $row['nom'])
                : (self::BENEFICIARY_LABELS[$code] ?? $code);
            $row['periode_libelle'] = sprintf(
                '%02d/%04d',
                (int) $row['mois'],
                (int) $row['annee']
            );
            if (!$revealPii) {
                unset($row['prenom'], $row['nom'], $row['employe_id']);
            }
        }
        unset($row);
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function allocations(
        int $organisationId,
        int $dossierId,
        bool $revealPii = true,
    ): array {
        $stmt = $this->pdo->prepare(
            "SELECT a.*, p.reference AS paiement_reference, p.date_paiement,
                    p.beneficiaire_code, d.type AS dette_type,
                    f.annee, f.mois, f.employe_id, e.prenom, e.nom
             FROM allocations_salaires a
             JOIN paiements_salaires p ON p.id = a.paiement_salaire_id
             JOIN dettes_salaires d ON d.id = a.dette_salaire_id
             JOIN fiches_salaires f ON f.id = d.fiche_salaire_id
             JOIN employes e ON e.id = f.employe_id
             WHERE a.organisation_id = ? AND a.dossier_id = ?
             ORDER BY a.cree_le DESC, a.id DESC"
        );
        $stmt->execute([$organisationId, $dossierId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $code = (string) $row['dette_type'];
            $beneficiary = $code === 'net' && $revealPii
                ? trim((string) $row['prenom'] . ' ' . (string) $row['nom'])
                : (self::BENEFICIARY_LABELS[$code] ?? $code);
            $row['beneficiaire_libelle'] = $beneficiary;
            $row['dette_libelle'] = sprintf(
                '%s · %02d/%04d',
                $beneficiary,
                (int) $row['mois'],
                (int) $row['annee']
            );
            if (!$revealPii) {
                unset($row['prenom'], $row['nom'], $row['employe_id']);
            }
        }
        unset($row);
        return $rows;
    }

    public function unallocate(
        int $organisationId,
        int $dossierId,
        int $allocationId,
        ?int $actorId = null,
    ): void {
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $allocationId,
            $actorId
        ): void {
            $stmt = $this->pdo->prepare(
                "SELECT a.*, p.ecriture_id, d.fiche_salaire_id
                 FROM allocations_salaires a
                 JOIN paiements_salaires p ON p.id = a.paiement_salaire_id
                 JOIN dettes_salaires d ON d.id = a.dette_salaire_id
                 WHERE a.id = ? AND a.organisation_id = ? AND a.dossier_id = ?"
            );
            $stmt->execute([$allocationId, $organisationId, $dossierId]);
            $allocation = $stmt->fetch();
            if ($allocation === false) {
                throw new PayrollException('Allocation salariale absente du dossier.');
            }
            if ($allocation['statut'] === 'annule') {
                return;
            }
            if ($allocation['ecriture_id'] !== null) {
                throw new PayrollException(
                    'Le paiement comptabilisé ne peut plus être délettré.'
                );
            }
            $this->pdo->prepare(
                "UPDATE allocations_salaires SET statut = 'annule'
                 WHERE id = ? AND statut = 'valide'"
            )->execute([$allocationId]);
            $this->refreshPayrollStatus((int) $allocation['fiche_salaire_id']);
            $this->audit->log(
                'salaires.allocation_annulee',
                $actorId,
                $organisationId,
                $dossierId,
                'allocation_salaire',
                (string) $allocationId
            );
        }, true);
    }

    public function cancel(
        int $organisationId,
        int $dossierId,
        int $paymentId,
        ?int $actorId = null,
    ): void {
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $paymentId,
            $actorId
        ): void {
            $payment = $this->payment($organisationId, $dossierId, $paymentId);
            if ($payment['statut'] === 'annule') {
                return;
            }
            if ($payment['ecriture_id'] !== null) {
                throw new PayrollException(
                    'Un paiement comptabilisé doit être contre-passé avant annulation.'
                );
            }
            if ($this->allocatedFor('paiement_salaire_id', $paymentId) > 0) {
                throw new PayrollException(
                    'Délettrez le paiement avant de l’annuler.'
                );
            }
            $this->pdo->prepare(
                "UPDATE paiements_salaires SET statut = 'annule'
                 WHERE id = ? AND statut = 'valide'"
            )->execute([$paymentId]);
            $this->audit->log(
                'salaires.paiement_annule',
                $actorId,
                $organisationId,
                $dossierId,
                'paiement_salaire',
                (string) $paymentId
            );
        }, true);
    }

    /** @return array<string,mixed> */
    private function payment(int $organisationId, int $dossierId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM paiements_salaires
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$id, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new PayrollException('Paiement salarial absent du dossier.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function liability(int $organisationId, int $dossierId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.*, f.employe_id FROM dettes_salaires d
             JOIN fiches_salaires f ON f.id = d.fiche_salaire_id
             WHERE d.id = ? AND d.organisation_id = ? AND d.dossier_id = ?
               AND f.statut NOT IN (\'brouillon\', \'annulee\')'
        );
        $stmt->execute([$id, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new PayrollException('Dette salariale absente du dossier.');
        }
        return $row;
    }

    private function allocatedFor(string $field, int $id): int
    {
        if (!in_array($field, ['paiement_salaire_id', 'dette_salaire_id'], true)) {
            throw new PayrollException('Clé d’allocation invalide.');
        }
        return (int) $this->pdo->query(
            "SELECT COALESCE(SUM(montant_centimes), 0)
             FROM allocations_salaires
             WHERE {$field} = {$id} AND statut = 'valide'"
        )->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    private function allocationRows(int $paymentId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.montant_centimes, d.compte_dette_id, d.type,
                    f.ecriture_id AS fiche_ecriture_id
             FROM allocations_salaires a
             JOIN dettes_salaires d ON d.id = a.dette_salaire_id
             JOIN fiches_salaires f ON f.id = d.fiche_salaire_id
             WHERE a.paiement_salaire_id = ? AND a.statut = 'valide'
             ORDER BY a.id"
        );
        $stmt->execute([$paymentId]);
        return $stmt->fetchAll();
    }

    private function refreshPayrollStatus(int $payrollId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT f.statut,
                    COALESCE(SUM(d.montant_centimes), 0) AS total,
                    COALESCE((
                      SELECT SUM(a.montant_centimes)
                      FROM allocations_salaires a
                      JOIN dettes_salaires da ON da.id = a.dette_salaire_id
                      WHERE da.fiche_salaire_id = f.id AND a.statut = 'valide'
                    ), 0) AS alloue
             FROM fiches_salaires f
             LEFT JOIN dettes_salaires d ON d.fiche_salaire_id = f.id
             WHERE f.id = ? GROUP BY f.id"
        );
        $stmt->execute([$payrollId]);
        $row = $stmt->fetch();
        if ($row === false || (int) $row['total'] <= 0) {
            return;
        }
        if (
            $row['statut'] === 'comptabilisee'
            && (int) $row['total'] === (int) $row['alloue']
        ) {
            $this->pdo->prepare(
                "UPDATE fiches_salaires SET statut = 'payee',
                    payee_le = datetime('now'), version = version + 1 WHERE id = ?"
            )->execute([$payrollId]);
            return;
        }
        if (
            $row['statut'] === 'payee'
            && (int) $row['total'] !== (int) $row['alloue']
        ) {
            $this->pdo->prepare(
                "UPDATE fiches_salaires
                 SET statut = CASE
                       WHEN ecriture_id IS NULL THEN 'validee'
                       ELSE 'comptabilisee'
                     END,
                     payee_le = NULL,
                     version = version + 1
                 WHERE id = ?"
            )->execute([$payrollId]);
        }
    }

    private function assertAccount(int $organisationId, int $dossierId, int $id): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM comptes WHERE id = ? AND organisation_id = ?
             AND dossier_id = ? AND actif = 1'
        );
        $stmt->execute([$id, $organisationId, $dossierId]);
        if ($stmt->fetchColumn() === false) {
            throw new PayrollException('Compte de trésorerie absent du dossier.');
        }
    }

    private function validDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private function transaction(callable $callback, bool $immediate = false): mixed
    {
        if ($this->transactionActive || $this->pdo->inTransaction()) {
            return $callback();
        }
        $immediate ? $this->pdo->exec('BEGIN IMMEDIATE') : $this->pdo->beginTransaction();
        $this->transactionActive = true;
        try {
            $result = $callback();
            $immediate ? $this->pdo->exec('COMMIT') : $this->pdo->commit();
            $this->transactionActive = false;
            return $result;
        } catch (Throwable $exception) {
            if ($this->transactionActive) {
                $immediate ? $this->pdo->exec('ROLLBACK') : $this->pdo->rollBack();
                $this->transactionActive = false;
            }
            throw $exception;
        }
    }
}
