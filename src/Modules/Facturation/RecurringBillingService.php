<?php
declare(strict_types=1);

namespace Compta\Modules\Facturation;

use Compta\Core\Audit\AuditLogger;
use DateTimeImmutable;
use PDO;
use PDOException;
use Throwable;

final class RecurringBillingService
{
    private bool $transactionActive = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly BillingService $billing,
    ) {
    }

    /** @param list<array<string,mixed>> $lines */
    public function create(
        int $organisationId,
        int $dossierId,
        string $type,
        int $contactId,
        string $label,
        string $frequency,
        int $interval,
        string $nextDate,
        ?string $endDate,
        int $dueDays,
        int $collectiveAccountId,
        string $externalPrefix,
        array $lines,
        ?int $actorId = null,
    ): int {
        $this->assertDate($nextDate);
        if ($endDate !== null) {
            $this->assertDate($endDate);
        }
        if (
            !in_array($type, ['facture_client', 'facture_fournisseur'], true)
            || trim($label) === ''
            || !in_array(
                $frequency,
                ['hebdomadaire', 'mensuelle', 'trimestrielle', 'annuelle'],
                true
            )
            || $interval < 1
            || $interval > 120
            || $dueDays < 0
            || $dueDays > 365
            || ($endDate !== null && $endDate < $nextDate)
            || ($type === 'facture_fournisseur' && trim($externalPrefix) === '')
            || $lines === []
        ) {
            throw new BillingException('Modèle de facture récurrente invalide.');
        }
        $role = $type === 'facture_client' ? 'client' : 'fournisseur';
        $contact = $this->pdo->prepare(
            'SELECT 1 FROM contacts c
             JOIN contact_roles r ON r.contact_id = c.id AND r.role = ?
             WHERE c.id = ? AND c.organisation_id = ? AND c.dossier_id = ?
               AND c.actif = 1'
        );
        $contact->execute([$role, $contactId, $organisationId, $dossierId]);
        $account = $this->pdo->prepare(
            'SELECT 1 FROM comptes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND imputable = 1'
        );
        $account->execute([
            $collectiveAccountId,
            $organisationId,
            $dossierId,
        ]);
        if ($contact->fetchColumn() === false || $account->fetchColumn() === false) {
            throw new BillingException('Contact ou compte collectif hors du dossier.');
        }
        $lineAccount = $this->pdo->prepare(
            'SELECT 1 FROM comptes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND imputable = 1'
        );
        $vatCode = $this->pdo->prepare(
            'SELECT 1 FROM tva_codes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND actif = 1'
        );
        foreach ($lines as $line) {
            if (
                trim((string) ($line['libelle'] ?? '')) === ''
                || (int) ($line['quantite_milli'] ?? 0) <= 0
                || (int) ($line['prix_unitaire_centimes'] ?? -1) < 0
                || !in_array($line['mode_saisie'] ?? null, ['net', 'brut'], true)
            ) {
                throw new BillingException('Ligne de récurrence invalide.');
            }
            $lineAccount->execute([
                (int) ($line['compte_id'] ?? 0),
                $organisationId,
                $dossierId,
            ]);
            $vatCode->execute([
                (int) ($line['code_tva_id'] ?? 0),
                $organisationId,
                $dossierId,
            ]);
            if (
                $lineAccount->fetchColumn() === false
                || $vatCode->fetchColumn() === false
            ) {
                throw new BillingException(
                    'Compte ou code TVA de récurrence hors du dossier.'
                );
            }
        }
        $encoded = json_encode(
            array_values($lines),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        );
        $stmt = $this->pdo->prepare(
            'INSERT INTO modeles_factures_recurrentes
             (organisation_id, dossier_id, contact_id, type, libelle,
              periodicite, intervalle, prochaine_echeance, jour_reference,
              date_fin, jours_echeance, compte_collectif_id,
              numero_externe_prefixe, lignes_json, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId,
            $dossierId,
            $contactId,
            $type,
            trim($label),
            $frequency,
            $interval,
            $nextDate,
            (int) substr($nextDate, 8, 2),
            $endDate,
            $dueDays,
            $collectiveAccountId,
            trim($externalPrefix),
            $encoded,
            $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'facturation.recurrence_creee',
            $actorId,
            $organisationId,
            $dossierId,
            'modele_facture_recurrente',
            (string) $id,
            ['type' => $type, 'prochaine_echeance' => $nextDate]
        );
        return $id;
    }

    public function setPaused(
        int $organisationId,
        int $dossierId,
        int $recurrenceId,
        bool $paused,
        int $expectedVersion,
        ?int $actorId = null,
    ): void {
        $status = $paused ? 'pause' : 'actif';
        $stmt = $this->pdo->prepare(
            "UPDATE modeles_factures_recurrentes
             SET statut = ?, modifie_le = datetime('now'), version = version + 1
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND statut <> 'termine' AND version = ?"
        );
        $stmt->execute([
            $status,
            $recurrenceId,
            $organisationId,
            $dossierId,
            $expectedVersion,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new BillingException('Récurrence absente, terminée ou modifiée.');
        }
        $this->audit->log(
            'facturation.recurrence_statut_modifie',
            $actorId,
            $organisationId,
            $dossierId,
            'modele_facture_recurrente',
            (string) $recurrenceId,
            ['statut' => $status]
        );
    }

    /** @return list<int> */
    public function generateDue(
        int $organisationId,
        int $dossierId,
        string $throughDate,
        ?int $actorId = null,
    ): array {
        $this->assertDate($throughDate);
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $throughDate,
            $actorId
        ): array {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM modeles_factures_recurrentes
                 WHERE organisation_id = ? AND dossier_id = ?
                   AND statut = 'actif' AND prochaine_echeance <= ?
                 ORDER BY prochaine_echeance, id"
            );
            $stmt->execute([$organisationId, $dossierId, $throughDate]);
            $generated = [];
            foreach ($stmt->fetchAll() as $model) {
                $guard = 0;
                while (
                    $model['statut'] === 'actif'
                    && (string) $model['prochaine_echeance'] <= $throughDate
                    && $guard++ < 500
                ) {
                    $date = (string) $model['prochaine_echeance'];
                    if ($model['date_fin'] !== null && $date > (string) $model['date_fin']) {
                        $this->finish((int) $model['id']);
                        break;
                    }
                    $generated[] = $this->generateOne($model, $date, $actorId);
                    $next = $this->nextDate(
                        $date,
                        (string) $model['periodicite'],
                        (int) $model['intervalle'],
                        (int) $model['jour_reference']
                    );
                    $finished = $model['date_fin'] !== null
                        && $next > (string) $model['date_fin'];
                    $advance = $this->pdo->prepare(
                        "UPDATE modeles_factures_recurrentes
                         SET prochaine_echeance = ?, statut = ?,
                             derniere_generation_le = datetime('now'),
                             modifie_le = datetime('now'), version = version + 1
                         WHERE id = ? AND prochaine_echeance = ? AND statut = 'actif'"
                    );
                    $advance->execute([
                        $next,
                        $finished ? 'termine' : 'actif',
                        (int) $model['id'],
                        $date,
                    ]);
                    if ($advance->rowCount() !== 1) {
                        break;
                    }
                    $model['prochaine_echeance'] = $next;
                    $model['statut'] = $finished ? 'termine' : 'actif';
                }
            }
            return array_values(array_unique($generated));
        });
    }

    /** @param array<string,mixed> $model */
    private function generateOne(array $model, string $date, ?int $actorId): int
    {
        $key = 'facturation-recurrente:' . $model['id'] . ':' . $date;
        $existing = $this->pdo->prepare(
            'SELECT id FROM documents_financiers
             WHERE dossier_id = ? AND cle_generation = ?'
        );
        $existing->execute([(int) $model['dossier_id'], $key]);
        $documentId = $existing->fetchColumn();
        if ($documentId === false) {
            $lines = json_decode((string) $model['lignes_json'], true);
            if (!is_array($lines) || $lines === []) {
                throw new BillingException('Lignes de récurrence illisibles.');
            }
            foreach ($lines as &$line) {
                if (is_array($line)) {
                    $line['date_prestation'] = $date;
                }
            }
            unset($line);
            $dueDate = (new DateTimeImmutable($date))
                ->modify('+' . (int) $model['jours_echeance'] . ' days')
                ->format('Y-m-d');
            $externalNumber = $model['type'] === 'facture_fournisseur'
                ? (string) $model['numero_externe_prefixe'] . '-' . $date
                : '';
            try {
                $documentId = $this->billing->createDraft(
                    (int) $model['organisation_id'],
                    (int) $model['dossier_id'],
                    (string) $model['type'],
                    (int) $model['contact_id'],
                    $date,
                    $dueDate,
                    array_values($lines),
                    (int) $model['compte_collectif_id'],
                    $externalNumber,
                    actorId: $actorId,
                    generationKey: $key
                );
            } catch (PDOException $exception) {
                $existing->execute([(int) $model['dossier_id'], $key]);
                $documentId = $existing->fetchColumn();
                if ($documentId === false) {
                    throw $exception;
                }
            }
        }
        $this->pdo->prepare(
            'INSERT OR IGNORE INTO generations_factures_recurrentes
             (modele_id, date_generation, document_id) VALUES (?, ?, ?)'
        )->execute([(int) $model['id'], $date, (int) $documentId]);
        return (int) $documentId;
    }

    private function nextDate(
        string $date,
        string $frequency,
        int $interval,
        int $referenceDay,
    ): string {
        $current = new DateTimeImmutable($date);
        if ($frequency === 'hebdomadaire') {
            return $current->modify('+' . (7 * $interval) . ' days')->format('Y-m-d');
        }
        $months = match ($frequency) {
            'mensuelle' => $interval,
            'trimestrielle' => 3 * $interval,
            'annuelle' => 12 * $interval,
            default => throw new BillingException('Périodicité inconnue.'),
        };
        $first = $current->modify('first day of this month')
            ->modify('+' . $months . ' months');
        return $first->setDate(
            (int) $first->format('Y'),
            (int) $first->format('m'),
            min($referenceDay, (int) $first->format('t'))
        )->format('Y-m-d');
    }

    private function finish(int $recurrenceId): void
    {
        $this->pdo->prepare(
            "UPDATE modeles_factures_recurrentes
             SET statut = 'termine', modifie_le = datetime('now'), version = version + 1
             WHERE id = ? AND statut = 'actif'"
        )->execute([$recurrenceId]);
    }

    private function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new BillingException('Date de récurrence invalide.');
        }
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
