<?php
declare(strict_types=1);

namespace Compta\Modules\Compta;

use Compta\Core\Audit\AuditLogger;
use DateTimeImmutable;
use PDO;
use Throwable;

final class EntryService
{
    private bool $transactionActive = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array{
     *   organisation_id:int,dossier_id:int,exercice_id:int,journal_id:int,
     *   date_comptable:string,libelle:string,reference?:string,piece?:string,
     *   lignes:list<array{compte_id:int,libelle?:string,debit_centimes?:int,credit_centimes?:int}>
     * } $command
     */
    public function createDraft(array $command, ?int $actorId = null): int
    {
        return $this->transaction(
            fn (): int => $this->insertDraft($command, $actorId)
        );
    }

    /**
     * Données strictement limitées au dossier pour l’écran de saisie manuelle.
     *
     * @return array{
     *   exercises:list<array<string,mixed>>,
     *   journals:list<array<string,mixed>>,
     *   accounts:list<array<string,mixed>>
     * }
     */
    public function entryCatalog(int $organisationId, int $dossierId): array
    {
        $exercises = $this->pdo->prepare(
            'SELECT x.id, x.libelle, x.date_debut, x.date_fin, x.statut
             FROM exercices x
             JOIN dossiers d ON d.id = x.dossier_id
             WHERE d.organisation_id = ? AND x.dossier_id = ?
             ORDER BY CASE x.statut WHEN \'ouvert\' THEN 0 ELSE 1 END,
                      x.date_debut DESC'
        );
        $exercises->execute([$organisationId, $dossierId]);

        $journals = $this->pdo->prepare(
            'SELECT id, code, libelle, type
             FROM journaux
             WHERE organisation_id = ? AND dossier_id = ? AND actif = 1
             ORDER BY code COLLATE NOCASE'
        );
        $journals->execute([$organisationId, $dossierId]);

        $accounts = $this->pdo->prepare(
            'SELECT id, numero, libelle, sens_normal
             FROM comptes
             WHERE organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND imputable = 1
             ORDER BY length(numero), numero COLLATE NOCASE'
        );
        $accounts->execute([$organisationId, $dossierId]);

        return [
            'exercises' => $exercises->fetchAll(),
            'journals' => $journals->fetchAll(),
            'accounts' => $accounts->fetchAll(),
        ];
    }

    /**
     * Remplace un brouillon avec verrou optimiste.
     *
     * @param array{
     *   exercice_id:int,journal_id:int,date_comptable:string,libelle:string,
     *   reference?:string,piece?:string,
     *   lignes:list<array{compte_id:int,libelle?:string,debit_centimes?:int,credit_centimes?:int}>
     * } $command
     */
    public function replaceDraft(
        int $organisationId,
        int $dossierId,
        int $entryId,
        int $expectedVersion,
        array $command,
        ?int $actorId = null,
    ): void {
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $entryId,
            $expectedVersion,
            $command,
            $actorId
        ): void {
            $this->assertHeaderInput($command);
            $stmt = $this->pdo->prepare(
                "UPDATE ecritures
                 SET exercice_id = :exercise, journal_id = :journal,
                     date_comptable = :date, libelle = :label,
                     reference = :reference, piece = :piece,
                     modifie_le = datetime('now'), version = version + 1
                 WHERE id = :id AND organisation_id = :organisation
                   AND dossier_id = :dossier AND statut = 'brouillon'
                   AND version = :version"
            );
            $stmt->execute([
                'exercise' => $command['exercice_id'],
                'journal' => $command['journal_id'],
                'date' => $command['date_comptable'],
                'label' => trim($command['libelle']),
                'reference' => trim((string) ($command['reference'] ?? '')),
                'piece' => trim((string) ($command['piece'] ?? '')),
                'id' => $entryId,
                'organisation' => $organisationId,
                'dossier' => $dossierId,
                'version' => $expectedVersion,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new AccountingException(
                    'Brouillon absent, déjà validé ou modifié par un autre utilisateur.'
                );
            }
            $this->pdo->prepare('DELETE FROM lignes_ecriture WHERE ecriture_id = ?')
                ->execute([$entryId]);
            $this->insertLines($entryId, $command['lignes']);
            $this->audit->log(
                'compta.brouillon_modifie',
                $actorId,
                $organisationId,
                $dossierId,
                'ecriture',
                (string) $entryId,
                ['version_precedente' => $expectedVersion]
            );
        });
    }

    public function validate(
        int $organisationId,
        int $dossierId,
        int $entryId,
        ?int $actorId = null,
    ): string {
        return $this->transaction(
            fn (): string => $this->validateInside(
                $organisationId,
                $dossierId,
                $entryId,
                $actorId
            )
        );
    }

    /**
     * Point d'entrée interne commun à la facturation, la trésorerie, la paie et
     * la TVA. Le même couple dossier/clé retourne toujours la même écriture.
     *
     * @param array{
     *   organisation_id:int,dossier_id:int,exercice_id:int,journal_id:int,
     *   date_comptable:string,libelle:string,reference?:string,piece?:string,
     *   source_type:string,source_id:string,source_action:string,
     *   lignes:list<array{compte_id:int,libelle?:string,debit_centimes?:int,credit_centimes?:int}>
     * } $command
     */
    public function postGenerated(
        array $command,
        string $idempotencyKey,
        ?int $actorId = null,
    ): int {
        $key = trim($idempotencyKey);
        if ($key === '' || strlen($key) > 190) {
            throw new AccountingException('Clé d’idempotence invalide.');
        }
        foreach (['source_type', 'source_id', 'source_action'] as $field) {
            if (trim((string) ($command[$field] ?? '')) === '') {
                throw new AccountingException("Champ {$field} requis pour une écriture générée.");
            }
        }
        $hash = hash('sha256', $this->canonicalJson($command));
        return $this->transaction(function () use ($command, $key, $hash, $actorId): int {
            $existing = $this->pdo->prepare(
                'SELECT id, empreinte_commande FROM ecritures
                 WHERE dossier_id = ? AND cle_idempotence = ?'
            );
            $existing->execute([$command['dossier_id'], $key]);
            $row = $existing->fetch();
            if ($row !== false) {
                if (!hash_equals((string) $row['empreinte_commande'], $hash)) {
                    throw new AccountingException(
                        'La clé d’idempotence existe avec une commande différente.'
                    );
                }
                return (int) $row['id'];
            }
            $entryId = $this->insertDraft(
                $command + [
                    'cle_idempotence' => $key,
                    'empreinte_commande' => $hash,
                ],
                $actorId
            );
            $this->validateInside(
                (int) $command['organisation_id'],
                (int) $command['dossier_id'],
                $entryId,
                $actorId
            );
            return $entryId;
        });
    }

    public function reverse(
        int $organisationId,
        int $dossierId,
        int $entryId,
        string $date,
        string $label = '',
        ?int $actorId = null,
    ): int {
        if (!$this->validDate($date)) {
            throw new AccountingException('Date de contre-passation invalide.');
        }
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $entryId,
            $date,
            $label,
            $actorId
        ): int {
            $already = $this->pdo->prepare(
                'SELECT id FROM ecritures
                 WHERE contrepassation_de_id = ?
                   AND organisation_id = ? AND dossier_id = ?'
            );
            $already->execute([$entryId, $organisationId, $dossierId]);
            $existing = $already->fetchColumn();
            if ($existing !== false) {
                return (int) $existing;
            }
            $source = $this->pdo->prepare(
                "SELECT * FROM ecritures
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND statut = 'validee'"
            );
            $source->execute([$entryId, $organisationId, $dossierId]);
            $entry = $source->fetch();
            if ($entry === false) {
                throw new AccountingException(
                    'Seule une écriture validée et non contre-passée peut être inversée.'
                );
            }
            $lines = $this->pdo->prepare(
                'SELECT compte_id, libelle, debit_centimes, credit_centimes
                 FROM lignes_ecriture WHERE ecriture_id = ? ORDER BY ordre'
            );
            $lines->execute([$entryId]);
            $reversedLines = [];
            foreach ($lines->fetchAll() as $line) {
                $reversedLines[] = [
                    'compte_id' => (int) $line['compte_id'],
                    'libelle' => (string) $line['libelle'],
                    'debit_centimes' => (int) $line['credit_centimes'],
                    'credit_centimes' => (int) $line['debit_centimes'],
                ];
            }
            $newId = $this->insertDraft([
                'organisation_id' => $organisationId,
                'dossier_id' => $dossierId,
                'exercice_id' => (int) $entry['exercice_id'],
                'journal_id' => (int) $entry['journal_id'],
                'date_comptable' => $date,
                'libelle' => trim($label) ?: 'Contre-passation de ' . $entry['numero'],
                'reference' => (string) $entry['numero'],
                'piece' => (string) $entry['piece'],
                'source_type' => 'contrepassation',
                'source_id' => (string) $entryId,
                'source_action' => 'inverse',
                'cle_idempotence' => "contrepassation:{$entryId}",
                'empreinte_commande' => hash('sha256', $entryId . '|' . $date),
                'contrepassation_de_id' => $entryId,
                'lignes' => $reversedLines,
            ], $actorId);
            $this->validateInside(
                $organisationId,
                $dossierId,
                $newId,
                $actorId
            );
            $this->pdo->prepare(
                "UPDATE ecritures
                 SET statut = 'contre_passee', modifie_le = datetime('now'),
                     version = version + 1
                 WHERE id = ?"
            )->execute([$entryId]);
            $this->audit->log(
                'compta.ecriture_contre_passee',
                $actorId,
                $organisationId,
                $dossierId,
                'ecriture',
                (string) $entryId,
                ['contrepassation_id' => $newId]
            );
            return $newId;
        });
    }

    /**
     * @param list<array{compte_id:int,libelle?:string,debit_centimes?:int,credit_centimes?:int}> $lines
     */
    public function postOpeningBalances(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        int $journalId,
        array $lines,
        string $sourceReference,
        ?int $actorId = null,
    ): int {
        $exercise = $this->pdo->prepare(
            'SELECT date_debut FROM exercices
             WHERE id = ? AND dossier_id = ?'
        );
        $exercise->execute([$exerciseId, $dossierId]);
        $date = $exercise->fetchColumn();
        if ($date === false) {
            throw new AccountingException('Exercice d’ouverture absent du dossier.');
        }
        return $this->postGenerated([
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
            'exercice_id' => $exerciseId,
            'journal_id' => $journalId,
            'date_comptable' => (string) $date,
            'libelle' => 'Soldes d’ouverture',
            'reference' => $sourceReference,
            'source_type' => 'ouverture',
            'source_id' => $sourceReference,
            'source_action' => 'comptabiliser',
            'lignes' => $lines,
        ], "ouverture:{$exerciseId}:{$sourceReference}", $actorId);
    }

    /**
     * Retourne le brouillon ou l'écriture d'ouverture préparée depuis l'écran
     * du plan comptable. Les soldes sont exprimés selon le sens naturel actuel
     * du compte : positif dans le sens normal, négatif dans le sens inverse.
     *
     * @return array{
     *   id:int,status:string,numero:string,version:int,
     *   soldes:array<int,int>,total_debit_centimes:int,total_credit_centimes:int
     * }
     */
    public function openingState(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
    ): array {
        $stmt = $this->pdo->prepare(
            "SELECT e.id, e.statut, e.numero, e.version
             FROM ecritures e
             WHERE e.organisation_id = ? AND e.dossier_id = ?
               AND e.exercice_id = ? AND e.source_type = 'ouverture'
             ORDER BY CASE e.source_action WHEN 'preparer' THEN 0 ELSE 1 END, e.id
             LIMIT 1"
        );
        $stmt->execute([$organisationId, $dossierId, $exerciseId]);
        $entry = $stmt->fetch();
        if ($entry === false) {
            return [
                'id' => 0,
                'status' => 'absent',
                'numero' => '',
                'version' => 0,
                'soldes' => [],
                'total_debit_centimes' => 0,
                'total_credit_centimes' => 0,
            ];
        }
        $lines = $this->pdo->prepare(
            "SELECT l.compte_id, l.debit_centimes, l.credit_centimes,
                    CASE c.sens_normal
                      WHEN 'debit' THEN l.debit_centimes - l.credit_centimes
                      ELSE l.credit_centimes - l.debit_centimes
                    END AS solde_naturel_centimes
             FROM lignes_ecriture l
             JOIN comptes c ON c.id = l.compte_id
             WHERE l.ecriture_id = ?
             ORDER BY l.ordre"
        );
        $lines->execute([(int) $entry['id']]);
        $balances = [];
        $debit = 0;
        $credit = 0;
        foreach ($lines->fetchAll() as $line) {
            $balances[(int) $line['compte_id']] = (int) $line['solde_naturel_centimes'];
            $debit += (int) $line['debit_centimes'];
            $credit += (int) $line['credit_centimes'];
        }
        return [
            'id' => (int) $entry['id'],
            'status' => (string) $entry['statut'],
            'numero' => (string) $entry['numero'],
            'version' => (int) $entry['version'],
            'soldes' => $balances,
            'total_debit_centimes' => $debit,
            'total_credit_centimes' => $credit,
        ];
    }

    /**
     * @param array<int,int> $balancesCents compte_id => solde naturel signé
     */
    public function saveOpeningDraft(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        int $journalId,
        array $balancesCents,
        ?int $actorId = null,
    ): int {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $exerciseId,
            $journalId,
            $balancesCents,
            $actorId
        ): int {
            $exercise = $this->pdo->prepare(
                'SELECT x.date_debut
                 FROM exercices x
                 JOIN dossiers d ON d.id = x.dossier_id
                 WHERE x.id = ? AND x.dossier_id = ?
                   AND d.organisation_id = ?'
            );
            $exercise->execute([$exerciseId, $dossierId, $organisationId]);
            $date = $exercise->fetchColumn();
            if ($date === false) {
                throw new AccountingException('Exercice d’ouverture absent du dossier.');
            }
            $journal = $this->pdo->prepare(
                "SELECT 1 FROM journaux
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND type = 'ouverture' AND actif = 1"
            );
            $journal->execute([$journalId, $organisationId, $dossierId]);
            if ($journal->fetchColumn() === false) {
                throw new AccountingException('Journal d’ouverture invalide.');
            }
            $sourceId = 'exercice:' . $exerciseId;
            $existing = $this->pdo->prepare(
                "SELECT id, statut, version FROM ecritures
                 WHERE organisation_id = ? AND dossier_id = ?
                   AND exercice_id = ? AND source_type = 'ouverture'
                   AND source_id = ? AND source_action = 'preparer'"
            );
            $existing->execute([
                $organisationId,
                $dossierId,
                $exerciseId,
                $sourceId,
            ]);
            $entry = $existing->fetch();
            if ($entry !== false && $entry['statut'] !== 'brouillon') {
                throw new AccountingException(
                    'Les soldes d’ouverture sont validés et ne sont plus modifiables.'
                );
            }
            if ($entry === false) {
                $other = $this->pdo->prepare(
                    "SELECT 1 FROM ecritures
                     WHERE organisation_id = ? AND dossier_id = ?
                       AND exercice_id = ? AND source_type = 'ouverture'
                       AND statut IN ('validee', 'contre_passee')"
                );
                $other->execute([$organisationId, $dossierId, $exerciseId]);
                if ($other->fetchColumn() !== false) {
                    throw new AccountingException(
                        'Une écriture d’ouverture est déjà comptabilisée pour cet exercice.'
                    );
                }
            }
            $account = $this->pdo->prepare(
                'SELECT sens_normal FROM comptes
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND actif = 1 AND imputable = 1
                   AND type IN (\'actif\', \'passif\')'
            );
            $lines = [];
            foreach ($balancesCents as $accountId => $balance) {
                $accountId = (int) $accountId;
                $balance = (int) $balance;
                if ($balance === 0) {
                    continue;
                }
                $account->execute([$accountId, $organisationId, $dossierId]);
                $side = $account->fetchColumn();
                if ($side === false) {
                    throw new AccountingException(
                        "Le compte d’ouverture {$accountId} doit être un compte actif ou passif."
                    );
                }
                $normalDebit = $side === 'debit';
                $onDebit = ($balance > 0) === $normalDebit;
                $lines[] = [
                    'compte_id' => $accountId,
                    'libelle' => 'Solde d’ouverture',
                    'debit_centimes' => $onDebit ? abs($balance) : 0,
                    'credit_centimes' => $onDebit ? 0 : abs($balance),
                ];
            }
            $command = [
                'organisation_id' => $organisationId,
                'dossier_id' => $dossierId,
                'exercice_id' => $exerciseId,
                'journal_id' => $journalId,
                'date_comptable' => (string) $date,
                'libelle' => 'Soldes d’ouverture',
                'reference' => 'OUV-' . substr((string) $date, 0, 4),
                'source_type' => 'ouverture',
                'source_id' => $sourceId,
                'source_action' => 'preparer',
                'lignes' => $lines,
            ];
            if ($entry === false) {
                return $this->insertDraft($command, $actorId);
            }
            $this->replaceDraft(
                $organisationId,
                $dossierId,
                (int) $entry['id'],
                (int) $entry['version'],
                $command,
                $actorId
            );
            return (int) $entry['id'];
        });
    }

    public function validateOpeningDraft(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        ?int $actorId = null,
    ): string {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM ecritures
             WHERE organisation_id = ? AND dossier_id = ? AND exercice_id = ?
               AND source_type = 'ouverture'
               AND source_id = ? AND source_action = 'preparer'
               AND statut = 'brouillon'"
        );
        $stmt->execute([
            $organisationId,
            $dossierId,
            $exerciseId,
            'exercice:' . $exerciseId,
        ]);
        $entryId = $stmt->fetchColumn();
        if ($entryId === false) {
            throw new AccountingException('Aucun brouillon d’ouverture à valider.');
        }
        return $this->validate(
            $organisationId,
            $dossierId,
            (int) $entryId,
            $actorId
        );
    }

    /** @param array<string,mixed> $command */
    private function insertDraft(array $command, ?int $actorId): int
    {
        $this->assertHeaderInput($command);
        $stmt = $this->pdo->prepare(
            'INSERT INTO ecritures
                (organisation_id, dossier_id, exercice_id, journal_id,
                 date_comptable, libelle, reference, piece,
                 source_type, source_id, source_action,
                 cle_idempotence, empreinte_commande, contrepassation_de_id, cree_par)
             VALUES
                (:organisation, :dossier, :exercise, :journal,
                 :date, :label, :reference, :piece,
                 :source_type, :source_id, :source_action,
                 :idempotency, :fingerprint, :reversal_of, :actor)'
        );
        $stmt->execute([
            'organisation' => $command['organisation_id'],
            'dossier' => $command['dossier_id'],
            'exercise' => $command['exercice_id'],
            'journal' => $command['journal_id'],
            'date' => $command['date_comptable'],
            'label' => trim((string) $command['libelle']),
            'reference' => trim((string) ($command['reference'] ?? '')),
            'piece' => trim((string) ($command['piece'] ?? '')),
            'source_type' => trim((string) ($command['source_type'] ?? 'manuel')),
            'source_id' => trim((string) ($command['source_id'] ?? '')),
            'source_action' => trim((string) ($command['source_action'] ?? '')),
            'idempotency' => $command['cle_idempotence'] ?? null,
            'fingerprint' => $command['empreinte_commande'] ?? null,
            'reversal_of' => $command['contrepassation_de_id'] ?? null,
            'actor' => $actorId,
        ]);
        $entryId = (int) $this->pdo->lastInsertId();
        $this->insertLines($entryId, $command['lignes']);
        $this->audit->log(
            'compta.brouillon_cree',
            $actorId,
            (int) $command['organisation_id'],
            (int) $command['dossier_id'],
            'ecriture',
            (string) $entryId,
            ['source_type' => $command['source_type'] ?? 'manuel']
        );
        return $entryId;
    }

    /**
     * @param list<array{compte_id:int,libelle?:string,debit_centimes?:int,credit_centimes?:int}> $lines
     */
    private function insertLines(int $entryId, array $lines): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO lignes_ecriture
                (ecriture_id, compte_id, libelle, debit_centimes, credit_centimes, ordre)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach (array_values($lines) as $position => $line) {
            $accountId = (int) ($line['compte_id'] ?? 0);
            $debit = (int) ($line['debit_centimes'] ?? 0);
            $credit = (int) ($line['credit_centimes'] ?? 0);
            if (
                $accountId < 1
                || $debit < 0
                || $credit < 0
                || (($debit > 0) === ($credit > 0))
            ) {
                throw new AccountingException(
                    'Chaque ligne exige un compte et un montant positif sur un seul côté.'
                );
            }
            $stmt->execute([
                $entryId,
                $accountId,
                trim((string) ($line['libelle'] ?? '')),
                $debit,
                $credit,
                $position + 1,
            ]);
        }
    }

    private function validateInside(
        int $organisationId,
        int $dossierId,
        int $entryId,
        ?int $actorId,
    ): string {
        $stmt = $this->pdo->prepare(
            "SELECT e.*, j.code AS journal_code, j.actif AS journal_actif,
                    x.date_debut AS exercice_debut, x.date_fin AS exercice_fin,
                    x.statut AS exercice_statut
             FROM ecritures e
             JOIN journaux j ON j.id = e.journal_id
             JOIN exercices x ON x.id = e.exercice_id
             WHERE e.id = ? AND e.organisation_id = ? AND e.dossier_id = ?"
        );
        $stmt->execute([$entryId, $organisationId, $dossierId]);
        $entry = $stmt->fetch();
        if ($entry === false) {
            throw new AccountingException('Écriture absente du dossier.');
        }
        if ($entry['statut'] !== 'brouillon') {
            throw new AccountingException('Seul un brouillon peut être validé.');
        }
        if (
            $entry['exercice_statut'] !== 'ouvert'
            || $entry['date_comptable'] < $entry['exercice_debut']
            || $entry['date_comptable'] > $entry['exercice_fin']
        ) {
            throw new AccountingException('Date hors exercice ouvert.');
        }
        if ((int) $entry['journal_actif'] !== 1) {
            throw new AccountingException('Journal inactif.');
        }
        $period = $this->pdo->prepare(
            "SELECT 1 FROM periodes
             WHERE organisation_id = ? AND dossier_id = ? AND exercice_id = ?
               AND statut = 'ouverte' AND date_debut <= ? AND date_fin >= ?"
        );
        $period->execute([
            $organisationId,
            $dossierId,
            $entry['exercice_id'],
            $entry['date_comptable'],
            $entry['date_comptable'],
        ]);
        if ($period->fetchColumn() === false) {
            throw new AccountingException('Aucune période ouverte pour cette date.');
        }
        $totals = $this->pdo->prepare(
            'SELECT COUNT(*) AS nombre,
                    COALESCE(SUM(l.debit_centimes), 0) AS debit,
                    COALESCE(SUM(l.credit_centimes), 0) AS credit,
                    COALESCE(SUM(CASE
                        WHEN c.id IS NULL OR c.actif <> 1 OR c.imputable <> 1
                          OR c.organisation_id <> :organisation
                          OR c.dossier_id <> :dossier
                        THEN 1 ELSE 0 END), 0) AS invalides
             FROM lignes_ecriture l
             LEFT JOIN comptes c ON c.id = l.compte_id
             WHERE l.ecriture_id = :entry'
        );
        $totals->execute([
            'organisation' => $organisationId,
            'dossier' => $dossierId,
            'entry' => $entryId,
        ]);
        $sum = $totals->fetch();
        if ($sum === false || (int) $sum['nombre'] < 2) {
            throw new AccountingException('Une écriture exige au moins deux lignes.');
        }
        if ((int) $sum['invalides'] !== 0) {
            throw new AccountingException('Compte inactif, non imputable ou hors dossier.');
        }
        if ((int) $sum['debit'] < 1 || (int) $sum['debit'] !== (int) $sum['credit']) {
            $difference = (int) $sum['debit'] - (int) $sum['credit'];
            throw new AccountingException(
                "Écriture déséquilibrée de {$difference} centime(s)."
            );
        }
        $this->pdo->prepare(
            'INSERT OR IGNORE INTO sequences_journaux
                (exercice_id, journal_id, dernier_numero) VALUES (?, ?, 0)'
        )->execute([$entry['exercice_id'], $entry['journal_id']]);
        $this->pdo->prepare(
            'UPDATE sequences_journaux
             SET dernier_numero = dernier_numero + 1
             WHERE exercice_id = ? AND journal_id = ?'
        )->execute([$entry['exercice_id'], $entry['journal_id']]);
        $sequence = $this->pdo->prepare(
            'SELECT dernier_numero FROM sequences_journaux
             WHERE exercice_id = ? AND journal_id = ?'
        );
        $sequence->execute([$entry['exercice_id'], $entry['journal_id']]);
        $year = substr((string) $entry['exercice_debut'], 0, 4);
        $number = sprintf(
            '%s-%s-%06d',
            $entry['journal_code'],
            $year,
            (int) $sequence->fetchColumn()
        );
        $update = $this->pdo->prepare(
            "UPDATE ecritures
             SET numero = ?, statut = 'validee',
                 validee_le = datetime('now'), validee_par = ?,
                 modifie_le = datetime('now'), version = version + 1
             WHERE id = ? AND statut = 'brouillon'"
        );
        $update->execute([$number, $actorId, $entryId]);
        if ($update->rowCount() !== 1) {
            throw new AccountingException('Validation concurrente détectée.');
        }
        $this->audit->log(
            'compta.ecriture_validee',
            $actorId,
            $organisationId,
            $dossierId,
            'ecriture',
            (string) $entryId,
            ['numero' => $number, 'total_centimes' => (int) $sum['debit']]
        );
        return $number;
    }

    /** @param array<string,mixed> $command */
    private function assertHeaderInput(array $command): void
    {
        foreach (['organisation_id', 'dossier_id', 'exercice_id', 'journal_id'] as $field) {
            if ((int) ($command[$field] ?? 0) < 1) {
                throw new AccountingException("Champ {$field} invalide.");
            }
        }
        if (
            !$this->validDate((string) ($command['date_comptable'] ?? ''))
            || trim((string) ($command['libelle'] ?? '')) === ''
            || !isset($command['lignes'])
            || !is_array($command['lignes'])
        ) {
            throw new AccountingException('En-tête ou lignes d’écriture invalides.');
        }
    }

    private function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /** @param array<string,mixed> $data */
    private function canonicalJson(array $data): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $normalize($item);
            }
            return $value;
        };
        $json = json_encode(
            $normalize($data),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        return $json;
    }

    private function transaction(callable $callback): mixed
    {
        if ($this->transactionActive || $this->pdo->inTransaction()) {
            return $callback();
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->transactionActive = true;
        try {
            $result = $callback();
            $this->pdo->exec('COMMIT');
            $this->transactionActive = false;
            return $result;
        } catch (Throwable $e) {
            if ($this->transactionActive) {
                $this->pdo->exec('ROLLBACK');
                $this->transactionActive = false;
            }
            throw $e;
        }
    }
}
