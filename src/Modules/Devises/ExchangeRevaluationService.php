<?php
declare(strict_types=1);

namespace Compta\Modules\Devises;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Compta\EntryService;
use PDO;
use Throwable;

final class ExchangeRevaluationService
{
    private bool $transactionActive = false;
    private ExchangeRateService $exchange;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly EntryService $entries,
    ) {
        $this->exchange = new ExchangeRateService($pdo, $audit);
    }

    /** @return list<array<string,mixed>> */
    public function history(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*, e.numero AS entry_number,
                    x.numero AS reversal_number,
                    (SELECT COUNT(*) FROM lignes_reevaluation_change l
                     WHERE l.reevaluation_id = r.id) AS item_count,
                    (SELECT COALESCE(SUM(l.ecart_latent_centimes), 0)
                     FROM lignes_reevaluation_change l
                     WHERE l.reevaluation_id = r.id) AS net_difference_cents
             FROM reevaluations_change r
             JOIN ecritures e ON e.id = r.ecriture_id
             LEFT JOIN ecritures x ON x.id = r.ecriture_contrepassation_id
             WHERE r.organisation_id = ? AND r.dossier_id = ?
             ORDER BY r.date_reevaluation DESC, r.id DESC'
        );
        $stmt->execute([$organisationId, $dossierId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'date' => (string) $row['date_reevaluation'],
            'status' => (string) $row['statut'],
            'entry_id' => (int) $row['ecriture_id'],
            'entry_number' => (string) $row['entry_number'],
            'reversal_entry_id' => $row['ecriture_contrepassation_id'] === null
                ? null : (int) $row['ecriture_contrepassation_id'],
            'reversal_number' => (string) ($row['reversal_number'] ?? ''),
            'item_count' => (int) $row['item_count'],
            'net_difference_cents' => (int) $row['net_difference_cents'],
        ], $stmt->fetchAll());
    }

    public function post(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        int $journalId,
        string $date,
        string $idempotencyKey,
        ?int $actorId = null,
    ): int {
        return $this->transaction(function () use (
            $organisationId, $dossierId, $exerciseId, $journalId,
            $date, $idempotencyKey, $actorId
        ): int {
            $existing = $this->pdo->prepare(
                'SELECT id FROM reevaluations_change
                 WHERE dossier_id = ? AND cle_idempotence = ?'
            );
            $existing->execute([$dossierId, trim($idempotencyKey)]);
            $found = $existing->fetchColumn();
            if ($found !== false) {
                return (int) $found;
            }
            if (trim($idempotencyKey) === '') {
                throw new ExchangeRateException('Clé idempotente de réévaluation requise.');
            }
            $active = $this->pdo->prepare(
                "SELECT COUNT(*) FROM reevaluations_change
                 WHERE organisation_id = ? AND dossier_id = ?
                   AND statut = 'comptabilisee'"
            );
            $active->execute([$organisationId, $dossierId]);
            if ((int) $active->fetchColumn() > 0) {
                throw new ExchangeRateException(
                    'Contre-passez la réévaluation active avant d’en créer une nouvelle.'
                );
            }
            $items = $this->openItems($organisationId, $dossierId, $date);
            $mapping = $this->exchange->mapping($organisationId, $dossierId);
            $lines = [];
            foreach ($items as &$item) {
                $rate = $this->exchange->snapshot(
                    $organisationId,
                    $dossierId,
                    (string) $item['currency'],
                    $date
                );
                $current = ExchangeRateService::convert(
                    (int) $item['open_cents'],
                    $rate['numerator'],
                    $rate['denominator']
                );
                $historic = ExchangeRateService::convert(
                    (int) $item['open_cents'],
                    (int) $item['historic_numerator'],
                    (int) $item['historic_denominator']
                );
                $gain = $item['client']
                    ? $current - $historic
                    : $historic - $current;
                if ($gain === 0) {
                    continue;
                }
                if ($gain > 0) {
                    $lines[] = [
                        'compte_id' => (int) $item['collective_account_id'],
                        'libelle' => 'Réévaluation ' . $item['number'],
                        'debit_centimes' => $gain,
                    ];
                    $lines[] = [
                        'compte_id' => $mapping['unrealized_gain'],
                        'libelle' => 'Gain de change latent ' . $item['number'],
                        'credit_centimes' => $gain,
                    ];
                } else {
                    $loss = abs($gain);
                    $lines[] = [
                        'compte_id' => $mapping['unrealized_loss'],
                        'libelle' => 'Perte de change latente ' . $item['number'],
                        'debit_centimes' => $loss,
                    ];
                    $lines[] = [
                        'compte_id' => (int) $item['collective_account_id'],
                        'libelle' => 'Réévaluation ' . $item['number'],
                        'credit_centimes' => $loss,
                    ];
                }
                $item['historic_base_cents'] = $historic;
                $item['current_base_cents'] = $current;
                $item['difference_cents'] = $gain;
                $item['rate'] = $rate;
            }
            unset($item);
            $items = array_values(array_filter(
                $items,
                static fn (array $item): bool => isset($item['difference_cents'])
            ));
            if ($items === []) {
                throw new ExchangeRateException(
                    'Aucun poste ouvert en devise ne produit d’écart à cette date.'
                );
            }
            $entryId = $this->entries->postGenerated([
                'organisation_id' => $organisationId,
                'dossier_id' => $dossierId,
                'exercice_id' => $exerciseId,
                'journal_id' => $journalId,
                'date_comptable' => $date,
                'libelle' => 'Réévaluation des postes en devises',
                'reference' => 'CHANGE-' . $date,
                'source_type' => 'reevaluation_change',
                'source_id' => trim($idempotencyKey),
                'source_action' => 'comptabiliser',
                'lignes' => $lines,
            ], 'change:reevaluation:' . trim($idempotencyKey), $actorId);
            $stmt = $this->pdo->prepare(
                'INSERT INTO reevaluations_change
                 (organisation_id, dossier_id, exercice_id, journal_id,
                  date_reevaluation, ecriture_id, cle_idempotence, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId, $dossierId, $exerciseId, $journalId,
                $date, $entryId, trim($idempotencyKey), $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $detail = $this->pdo->prepare(
                'INSERT INTO lignes_reevaluation_change
                 (reevaluation_id, document_id, devise, montant_ouvert_centimes,
                  valeur_historique_base_centimes, valeur_reevaluee_base_centimes,
                  ecart_latent_centimes, taux_change_numerateur,
                  taux_change_denominateur, taux_change_date, taux_change_source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($items as $item) {
                $detail->execute([
                    $id, $item['document_id'], $item['currency'], $item['open_cents'],
                    $item['historic_base_cents'], $item['current_base_cents'],
                    $item['difference_cents'], $item['rate']['numerator'],
                    $item['rate']['denominator'], $item['rate']['rate_date'],
                    $item['rate']['source'],
                ]);
            }
            $this->audit->log(
                'devises.reevaluation_comptabilisee',
                $actorId,
                $organisationId,
                $dossierId,
                'reevaluation_change',
                (string) $id,
                ['ecriture_id' => $entryId, 'date' => $date]
            );
            return $id;
        });
    }

    public function reverse(
        int $organisationId,
        int $dossierId,
        int $revaluationId,
        string $date,
        ?int $actorId = null,
    ): int {
        return $this->transaction(function () use (
            $organisationId, $dossierId, $revaluationId, $date, $actorId
        ): int {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM reevaluations_change
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
            );
            $stmt->execute([$revaluationId, $organisationId, $dossierId]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new ExchangeRateException('Réévaluation absente du dossier.');
            }
            if ($row['ecriture_contrepassation_id'] !== null) {
                return (int) $row['ecriture_contrepassation_id'];
            }
            $entryId = $this->entries->reverse(
                $organisationId,
                $dossierId,
                (int) $row['ecriture_id'],
                $date,
                'Contre-passation de la réévaluation de change',
                $actorId
            );
            $this->pdo->prepare(
                "UPDATE reevaluations_change
                 SET statut = 'contre_passee', ecriture_contrepassation_id = ?,
                     contrepassee_le = datetime('now'), contrepassee_par = ?
                 WHERE id = ?"
            )->execute([$entryId, $actorId, $revaluationId]);
            $this->audit->log(
                'devises.reevaluation_contrepassee',
                $actorId,
                $organisationId,
                $dossierId,
                'reevaluation_change',
                (string) $revaluationId,
                ['ecriture_id' => $entryId]
            );
            return $entryId;
        });
    }

    /** @return list<array<string,mixed>> */
    private function openItems(
        int $organisationId,
        int $dossierId,
        string $date,
    ): array {
        $stmt = $this->pdo->prepare(
            "SELECT d.id AS document_id, d.numero AS number, d.type,
                    d.monnaie AS currency, d.compte_collectif_id AS collective_account_id,
                    d.taux_change_numerateur AS historic_numerator,
                    d.taux_change_denominateur AS historic_denominator,
                    abs(d.total_brut_centimes) - COALESCE((
                      SELECT SUM(a.montant_centimes)
                      FROM allocations a
                      LEFT JOIN paiements p ON p.id = a.paiement_id
                      LEFT JOIN documents_financiers av ON av.id = a.avoir_id
                      WHERE a.document_id = d.id AND a.statut = 'valide'
                        AND COALESCE(p.date_paiement, av.date_document) <= ?
                    ), 0) AS open_cents
             FROM documents_financiers d
             JOIN dossiers s ON s.id = d.dossier_id
             WHERE d.organisation_id = ? AND d.dossier_id = ?
               AND d.statut = 'comptabilise' AND d.date_document <= ?
               AND d.monnaie <> s.monnaie
               AND d.type IN ('facture_client', 'facture_fournisseur')
               AND d.compte_collectif_id IS NOT NULL"
        );
        $stmt->execute([$date, $organisationId, $dossierId, $date]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            if ((int) $row['open_cents'] < 1) {
                continue;
            }
            $row['client'] = (string) $row['type'] === 'facture_client';
            $items[] = $row;
        }
        return $items;
    }

    private function transaction(callable $callback): mixed
    {
        if ($this->transactionActive || $this->pdo->inTransaction()) {
            return $callback();
        }
        $this->pdo->beginTransaction();
        $this->transactionActive = true;
        try {
            $result = $callback();
            $this->pdo->commit();
            $this->transactionActive = false;
            return $result;
        } catch (Throwable $exception) {
            if ($this->transactionActive) {
                $this->pdo->rollBack();
                $this->transactionActive = false;
            }
            throw $exception;
        }
    }
}
