<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Compta\EntryService;
use Compta\Modules\Facturation\BillingException;
use Compta\Modules\Facturation\BillingService;
use DateTimeImmutable;
use PDO;
use PDOException;

final class ExpenseService
{
    private BillingService $billing;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly EntryService $entries,
    ) {
        $this->billing = new BillingService($pdo, $audit, $entries);
    }

    /**
     * @param list<array<string,mixed>> $lines
     */
    public function createDraft(
        int $organisationId,
        int $dossierId,
        int $contactId,
        string $documentDate,
        string $dueDate,
        string $externalNumber,
        int $collectiveAccountId,
        array $lines,
        ?int $attachmentId = null,
        ?int $actorId = null,
        string $generationKey = '',
    ): int {
        $this->assertReferences(
            $organisationId,
            $dossierId,
            $contactId,
            $collectiveAccountId
        );
        $this->assertLineAccounts($organisationId, $dossierId, $lines);
        if ($attachmentId !== null) {
            $piece = $this->pdo->prepare(
                'SELECT 1 FROM pieces_jointes
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
            );
            $piece->execute([$attachmentId, $organisationId, $dossierId]);
            if ($piece->fetchColumn() === false) {
                throw new ExpenseException('Justificatif absent du dossier.');
            }
        }
        try {
            return $this->billing->createDraft(
                $organisationId,
                $dossierId,
                'facture_fournisseur',
                $contactId,
                $documentDate,
                $dueDate,
                $lines,
                $collectiveAccountId,
                $externalNumber,
                attachmentId: $attachmentId,
                actorId: $actorId,
                workflow: 'depense',
                generationKey: $generationKey
            );
        } catch (BillingException $exception) {
            throw new ExpenseException($exception->getMessage(), previous: $exception);
        }
    }

    public function attachProof(
        int $organisationId,
        int $dossierId,
        int $documentId,
        int $attachmentId,
        int $expectedVersion,
        ?int $actorId = null,
    ): void {
        $stmt = $this->pdo->prepare(
            "UPDATE documents_financiers
             SET justificatif_id = ?, version = version + 1,
                 modifie_le = datetime('now')
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND workflow = 'depense' AND statut = 'brouillon'
               AND version = ?
               AND EXISTS (
                 SELECT 1 FROM pieces_jointes p
                 WHERE p.id = ? AND p.organisation_id = ?
                   AND p.dossier_id = ?
               )"
        );
        $stmt->execute([
            $attachmentId, $documentId, $organisationId, $dossierId,
            $expectedVersion, $attachmentId, $organisationId, $dossierId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new ExpenseException(
                'Brouillon absent, modifié ou justificatif hors du dossier.'
            );
        }
        $this->audit->log(
            'depenses.justificatif_associe',
            $actorId,
            $organisationId,
            $dossierId,
            'document_financier',
            (string) $documentId,
            ['piece_jointe_id' => $attachmentId]
        );
    }

    public function submit(
        int $organisationId,
        int $dossierId,
        int $documentId,
        int $expectedVersion,
        ?int $actorId = null,
    ): string {
        $document = $this->expense($organisationId, $dossierId, $documentId);
        if (
            $document['statut'] !== 'brouillon'
            || (int) $document['version'] !== $expectedVersion
            || $document['justificatif_id'] === null
            || (int) $document['total_brut_centimes'] <= 0
        ) {
            throw new ExpenseException(
                'La dépense doit être un brouillon complet avec justificatif.'
            );
        }
        $this->pdo->beginTransaction();
        try {
            $year = (int) substr((string) $document['date_document'], 0, 4);
            $this->pdo->prepare(
                "INSERT OR IGNORE INTO sequences_documents
                 (dossier_id, annee, prefixe, dernier_numero)
                 VALUES (?, ?, 'DEP', 0)"
            )->execute([$dossierId, $year]);
            $this->pdo->prepare(
                "UPDATE sequences_documents
                 SET dernier_numero = dernier_numero + 1
                 WHERE dossier_id = ? AND annee = ? AND prefixe = 'DEP'"
            )->execute([$dossierId, $year]);
            $sequence = $this->pdo->prepare(
                "SELECT dernier_numero FROM sequences_documents
                 WHERE dossier_id = ? AND annee = ? AND prefixe = 'DEP'"
            );
            $sequence->execute([$dossierId, $year]);
            $number = sprintf('DEP-%04d-%03d', $year, (int) $sequence->fetchColumn());
            $update = $this->pdo->prepare(
                "UPDATE documents_financiers
                 SET numero = ?, statut = 'a_approuver',
                     soumis_le = datetime('now'), soumis_par = ?,
                     modifie_le = datetime('now'), version = version + 1
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND workflow = 'depense' AND statut = 'brouillon'
                   AND version = ?"
            );
            $update->execute([
                $number, $actorId, $documentId, $organisationId,
                $dossierId, $expectedVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new ExpenseException('Conflit lors de la soumission.');
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        $this->audit->log(
            'depenses.soumise',
            $actorId,
            $organisationId,
            $dossierId,
            'document_financier',
            (string) $documentId,
            ['numero' => $number]
        );
        return $number;
    }

    public function approve(
        int $organisationId,
        int $dossierId,
        int $documentId,
        int $expectedVersion,
        ?int $actorId = null,
    ): void {
        $stmt = $this->pdo->prepare(
            "UPDATE documents_financiers
             SET statut = 'approuve', approuve_le = datetime('now'),
                 approuve_par = ?, modifie_le = datetime('now'),
                 version = version + 1
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND workflow = 'depense' AND statut = 'a_approuver'
               AND version = ?"
        );
        $stmt->execute([
            $actorId, $documentId, $organisationId, $dossierId, $expectedVersion,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new ExpenseException('Dépense absente, déjà traitée ou modifiée.');
        }
        $this->audit->log(
            'depenses.approuvee',
            $actorId,
            $organisationId,
            $dossierId,
            'document_financier',
            (string) $documentId
        );
    }

    public function post(
        int $organisationId,
        int $dossierId,
        int $documentId,
        int $exerciseId,
        int $journalId,
        ?int $actorId = null,
    ): int {
        $document = $this->expense($organisationId, $dossierId, $documentId);
        if ($document['statut'] !== 'approuve' && $document['ecriture_id'] === null) {
            throw new ExpenseException('Seule une dépense approuvée peut être comptabilisée.');
        }
        try {
            return $this->billing->post(
                $organisationId,
                $dossierId,
                $documentId,
                $exerciseId,
                $journalId,
                $actorId,
                'depense'
            );
        } catch (BillingException $exception) {
            throw new ExpenseException($exception->getMessage(), previous: $exception);
        }
    }

    public function cancel(
        int $organisationId,
        int $dossierId,
        int $documentId,
        int $expectedVersion,
        string $date,
        ?int $actorId = null,
    ): ?int {
        $this->assertDate($date);
        $document = $this->expense($organisationId, $dossierId, $documentId);
        if (
            (int) $document['version'] !== $expectedVersion
            || $document['statut'] === 'annule'
        ) {
            throw new ExpenseException('Dépense déjà annulée ou modifiée.');
        }
        $allocated = $this->pdo->prepare(
            "SELECT COALESCE(SUM(montant_centimes), 0)
             FROM allocations WHERE document_id = ? AND statut = 'valide'"
        );
        $allocated->execute([$documentId]);
        if ((int) $allocated->fetchColumn() > 0) {
            throw new ExpenseException(
                'Annulez d’abord les allocations de paiement de cette dépense.'
            );
        }
        $reversalId = null;
        if ($document['ecriture_id'] !== null) {
            $reversalId = $this->entries->reverse(
                $organisationId,
                $dossierId,
                (int) $document['ecriture_id'],
                $date,
                'Annulation de la dépense ' . (string) $document['numero'],
                $actorId
            );
        }
        $stmt = $this->pdo->prepare(
            "UPDATE documents_financiers
             SET statut = 'annule', ecriture_annulation_id = ?,
                 annule_le = datetime('now'), annule_par = ?,
                 modifie_le = datetime('now'), version = version + 1
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND workflow = 'depense' AND version = ?"
        );
        $stmt->execute([
            $reversalId, $actorId, $documentId, $organisationId,
            $dossierId, $expectedVersion,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new ExpenseException('Conflit lors de l’annulation.');
        }
        $this->audit->log(
            'depenses.annulee',
            $actorId,
            $organisationId,
            $dossierId,
            'document_financier',
            (string) $documentId,
            ['contrepassation_id' => $reversalId]
        );
        return $reversalId;
    }

    /**
     * @param list<array<string,mixed>> $lines
     */
    public function createRecurrence(
        int $organisationId,
        int $dossierId,
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
            trim($label) === ''
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
            || $lines === []
        ) {
            throw new ExpenseException('Modèle de dépense récurrente invalide.');
        }
        $this->assertReferences(
            $organisationId,
            $dossierId,
            $contactId,
            $collectiveAccountId
        );
        $this->assertLineAccounts($organisationId, $dossierId, $lines);
        $stmt = $this->pdo->prepare(
            'INSERT INTO modeles_depenses_recurrentes
             (organisation_id, dossier_id, contact_id, libelle, periodicite,
              intervalle, prochaine_echeance, jour_reference, date_fin,
              jours_echeance, compte_collectif_id, numero_externe_prefixe,
              lignes_json, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId, $dossierId, $contactId, trim($label), $frequency,
            $interval, $nextDate, (int) substr($nextDate, 8, 2), $endDate,
            $dueDays, $collectiveAccountId,
            trim($externalPrefix) ?: 'REC',
            json_encode($lines, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'depenses.recurrence_creee',
            $actorId,
            $organisationId,
            $dossierId,
            'modele_depense_recurrente',
            (string) $id
        );
        return $id;
    }

    public function setRecurrencePaused(
        int $organisationId,
        int $dossierId,
        int $recurrenceId,
        bool $paused,
        int $expectedVersion,
        ?int $actorId = null,
    ): void {
        $stmt = $this->pdo->prepare(
            "UPDATE modeles_depenses_recurrentes
             SET statut = ?, modifie_le = datetime('now'), version = version + 1
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND statut IN ('actif', 'pause') AND version = ?"
        );
        $stmt->execute([
            $paused ? 'pause' : 'actif',
            $recurrenceId, $organisationId, $dossierId, $expectedVersion,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new ExpenseException('Récurrence absente, terminée ou modifiée.');
        }
        $this->audit->log(
            $paused ? 'depenses.recurrence_suspendue' : 'depenses.recurrence_reprise',
            $actorId,
            $organisationId,
            $dossierId,
            'modele_depense_recurrente',
            (string) $recurrenceId
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
        $stmt = $this->pdo->prepare(
            "SELECT * FROM modeles_depenses_recurrentes
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
                    $this->finishRecurrence((int) $model['id']);
                    break;
                }
                $documentId = $this->generateOne($model, $date, $actorId);
                $generated[] = $documentId;
                $next = $this->nextDate(
                    $date,
                    (string) $model['periodicite'],
                    (int) $model['intervalle'],
                    (int) $model['jour_reference']
                );
                $finished = $model['date_fin'] !== null
                    && $next > (string) $model['date_fin'];
                $advance = $this->pdo->prepare(
                    "UPDATE modeles_depenses_recurrentes
                     SET prochaine_echeance = ?, statut = ?,
                         derniere_generation_le = datetime('now'),
                         modifie_le = datetime('now'), version = version + 1
                     WHERE id = ? AND prochaine_echeance = ? AND statut = 'actif'"
                );
                $advance->execute([
                    $next, $finished ? 'termine' : 'actif',
                    (int) $model['id'], $date,
                ]);
                if ($advance->rowCount() !== 1) {
                    break;
                }
                $model['prochaine_echeance'] = $next;
                $model['statut'] = $finished ? 'termine' : 'actif';
            }
        }
        return array_values(array_unique($generated));
    }

    /** @return array<string,mixed> */
    public function read(int $organisationId, int $dossierId): array
    {
        $expenses = $this->pdo->prepare(
            "SELECT d.*, c.raison_sociale, c.prenom, c.nom,
                    p.nom_fichier AS justificatif_nom,
                    p.type_mime AS justificatif_type,
                    p.taille_octets AS justificatif_taille,
                    COALESCE((
                      SELECT SUM(a.montant_centimes) FROM allocations a
                      WHERE a.document_id = d.id AND a.statut = 'valide'
                    ), 0) AS alloue_centimes
             FROM documents_financiers d
             JOIN contacts c ON c.id = d.contact_id
             LEFT JOIN pieces_jointes p ON p.id = d.justificatif_id
             WHERE d.organisation_id = ? AND d.dossier_id = ?
               AND d.workflow = 'depense'
             ORDER BY d.date_document DESC, d.id DESC
             LIMIT 100"
        );
        $expenses->execute([$organisationId, $dossierId]);
        $items = [];
        foreach ($expenses->fetchAll() as $row) {
            $lines = $this->billing->lines((int) $row['id']);
            $items[] = [
                'id' => (int) $row['id'],
                'number' => (string) $row['numero'],
                'external_number' => (string) $row['numero_externe'],
                'status' => (string) $row['statut'],
                'version' => (int) $row['version'],
                'contact_id' => (int) $row['contact_id'],
                'supplier' => trim(
                    (string) $row['raison_sociale'] . ' '
                    . (string) $row['prenom'] . ' ' . (string) $row['nom']
                ),
                'document_date' => (string) $row['date_document'],
                'due_date' => (string) $row['date_echeance'],
                'currency' => (string) $row['monnaie'],
                'net_cents' => (int) $row['total_net_centimes'],
                'vat_cents' => (int) $row['total_tva_centimes'],
                'gross_cents' => (int) $row['total_brut_centimes'],
                'allocated_cents' => (int) $row['alloue_centimes'],
                'open_cents' => max(
                    0,
                    (int) $row['total_brut_centimes'] - (int) $row['alloue_centimes']
                ),
                'attachment' => $row['justificatif_id'] === null ? null : [
                    'id' => (int) $row['justificatif_id'],
                    'name' => (string) $row['justificatif_nom'],
                    'type' => (string) $row['justificatif_type'],
                    'size' => (int) $row['justificatif_taille'],
                ],
                'entry_id' => $row['ecriture_id'] === null
                    ? null : (int) $row['ecriture_id'],
                'reversal_entry_id' => $row['ecriture_annulation_id'] === null
                    ? null : (int) $row['ecriture_annulation_id'],
                'lines' => array_map(static fn (array $line): array => [
                    'id' => (int) $line['id'],
                    'label' => (string) $line['libelle'],
                    'quantity_milli' => (int) $line['quantite_milli'],
                    'unit_price_cents' => (int) $line['prix_unitaire_centimes'],
                    'input_mode' => (string) $line['mode_saisie'],
                    'account_id' => (int) $line['compte_id'],
                    'vat_code_id' => (int) $line['code_tva_id'],
                    'net_cents' => (int) $line['base_nette_centimes'],
                    'vat_cents' => (int) $line['tva_centimes'],
                    'gross_cents' => (int) $line['total_brut_centimes'],
                ], $lines),
            ];
        }
        $recurrences = $this->pdo->prepare(
            "SELECT r.*, c.raison_sociale, c.prenom, c.nom,
                    (SELECT COUNT(*) FROM generations_depenses_recurrentes g
                     WHERE g.modele_id = r.id) AS generations
             FROM modeles_depenses_recurrentes r
             JOIN contacts c ON c.id = r.contact_id
             WHERE r.organisation_id = ? AND r.dossier_id = ?
             ORDER BY r.prochaine_echeance, r.id"
        );
        $recurrences->execute([$organisationId, $dossierId]);
        return [
            'expenses' => $items,
            'recurrences' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => (string) $row['libelle'],
                'supplier' => trim(
                    (string) $row['raison_sociale'] . ' '
                    . (string) $row['prenom'] . ' ' . (string) $row['nom']
                ),
                'frequency' => (string) $row['periodicite'],
                'interval' => (int) $row['intervalle'],
                'next_date' => (string) $row['prochaine_echeance'],
                'end_date' => $row['date_fin'] === null
                    ? null : (string) $row['date_fin'],
                'status' => (string) $row['statut'],
                'generations' => (int) $row['generations'],
                'version' => (int) $row['version'],
            ], $recurrences->fetchAll()),
            'catalog' => $this->catalog($organisationId, $dossierId),
        ];
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function catalog(int $organisationId, int $dossierId): array
    {
        $contacts = $this->pdo->prepare(
            "SELECT DISTINCT c.id, c.raison_sociale, c.prenom, c.nom
             FROM contacts c
             JOIN contact_roles rc ON rc.contact_id = c.id
             WHERE c.organisation_id = ? AND c.dossier_id = ?
               AND c.actif = 1 AND rc.role = 'fournisseur'
             ORDER BY c.raison_sociale, c.nom, c.prenom"
        );
        $contacts->execute([$organisationId, $dossierId]);
        $catalog = $this->billing->catalog($organisationId, $dossierId);
        return [
            'suppliers' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => trim(
                    (string) $row['raison_sociale'] . ' '
                    . (string) $row['prenom'] . ' ' . (string) $row['nom']
                ),
            ], $contacts->fetchAll()),
            'accounts' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'number' => (string) $row['numero'],
                'label' => (string) $row['libelle'],
            ], $catalog['accounts']),
            'vat_codes' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'label' => (string) $row['libelle'],
            ], $catalog['vat_codes']),
            'exercises' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'label' => (string) $row['libelle'],
            ], $catalog['exercises']),
            'journals' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'label' => (string) $row['libelle'],
            ], $catalog['journals']),
        ];
    }

    /** @param array<string,mixed> $model */
    private function generateOne(array $model, string $date, ?int $actorId): int
    {
        $key = 'depense-recurrente:' . $model['id'] . ':' . $date;
        $existing = $this->pdo->prepare(
            "SELECT id FROM documents_financiers
             WHERE dossier_id = ? AND cle_generation = ?"
        );
        $existing->execute([(int) $model['dossier_id'], $key]);
        $documentId = $existing->fetchColumn();
        if ($documentId === false) {
            $lines = json_decode((string) $model['lignes_json'], true);
            if (!is_array($lines) || $lines === []) {
                throw new ExpenseException('Lignes de récurrence illisibles.');
            }
            $lines = array_map(
                static function (mixed $line) use ($date): mixed {
                    if (is_array($line)) {
                        $line['date_prestation'] = $date;
                    }
                    return $line;
                },
                array_values($lines)
            );
            $dueDate = (new DateTimeImmutable($date))
                ->modify('+' . (int) $model['jours_echeance'] . ' days')
                ->format('Y-m-d');
            try {
                $documentId = $this->createDraft(
                    (int) $model['organisation_id'],
                    (int) $model['dossier_id'],
                    (int) $model['contact_id'],
                    $date,
                    $dueDate,
                    (string) $model['numero_externe_prefixe'] . '-' . $date,
                    (int) $model['compte_collectif_id'],
                    array_values($lines),
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
            'INSERT OR IGNORE INTO generations_depenses_recurrentes
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
            default => throw new ExpenseException('Périodicité inconnue.'),
        };
        $first = $current->modify('first day of this month')
            ->modify('+' . $months . ' months');
        $lastDay = (int) $first->format('t');
        return $first->setDate(
            (int) $first->format('Y'),
            (int) $first->format('m'),
            min($referenceDay, $lastDay)
        )->format('Y-m-d');
    }

    private function finishRecurrence(int $recurrenceId): void
    {
        $this->pdo->prepare(
            "UPDATE modeles_depenses_recurrentes
             SET statut = 'termine', modifie_le = datetime('now'),
                 version = version + 1 WHERE id = ?"
        )->execute([$recurrenceId]);
    }

    /** @return array<string,mixed> */
    private function expense(
        int $organisationId,
        int $dossierId,
        int $documentId,
    ): array {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM documents_financiers
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND workflow = 'depense'"
        );
        $stmt->execute([$documentId, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new ExpenseException('Dépense absente du dossier.');
        }
        return $row;
    }

    private function assertReferences(
        int $organisationId,
        int $dossierId,
        int $contactId,
        int $collectiveAccountId,
    ): void {
        $stmt = $this->pdo->prepare(
            "SELECT
               EXISTS(
                 SELECT 1 FROM contacts c
                 JOIN contact_roles r ON r.contact_id = c.id
                 WHERE c.id = ? AND c.organisation_id = ?
                   AND c.dossier_id = ? AND r.role = 'fournisseur'
               )
               AND EXISTS(
                 SELECT 1 FROM comptes
                 WHERE id = ? AND organisation_id = ?
                   AND dossier_id = ? AND actif = 1
               )"
        );
        $stmt->execute([
            $contactId, $organisationId, $dossierId,
            $collectiveAccountId, $organisationId, $dossierId,
        ]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new ExpenseException('Fournisseur ou compte collectif hors du dossier.');
        }
    }

    /** @param list<array<string,mixed>> $lines */
    private function assertLineAccounts(
        int $organisationId,
        int $dossierId,
        array $lines,
    ): void {
        $account = $this->pdo->prepare(
            'SELECT 1 FROM comptes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND imputable = 1'
        );
        foreach ($lines as $line) {
            $account->execute([
                (int) ($line['compte_id'] ?? 0),
                $organisationId,
                $dossierId,
            ]);
            if ($account->fetchColumn() === false) {
                throw new ExpenseException('Compte de ligne absent du dossier.');
            }
        }
    }

    private function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new ExpenseException('Date invalide.');
        }
    }
}
