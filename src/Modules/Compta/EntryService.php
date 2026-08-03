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
     * Importe un lot indivisible de brouillons et/ou d'écritures validées.
     * La source identifie le fichier complet et interdit tout rejeu.
     *
     * @param list<array{command:array<string,mixed>,validate:bool}> $items
     * @return list<int>
     */
    public function importBatch(
        int $organisationId,
        int $dossierId,
        string $sourceId,
        array $items,
        ?int $actorId = null,
    ): array {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $sourceId,
            $items,
            $actorId
        ): array {
            $existing = $this->pdo->prepare(
                "SELECT 1 FROM ecritures
                 WHERE organisation_id = ? AND dossier_id = ?
                   AND source_type = 'import_journal' AND source_id = ?
                 LIMIT 1"
            );
            $existing->execute([$organisationId, $dossierId, $sourceId]);
            if ($existing->fetchColumn() !== false) {
                throw new AccountingException('Ce fichier journal a déjà été importé.');
            }
            $ids = [];
            foreach ($items as $item) {
                $id = $this->insertDraft($item['command'], $actorId);
                if ($item['validate']) {
                    $this->validateInside(
                        $organisationId,
                        $dossierId,
                        $id,
                        $actorId
                    );
                }
                $ids[] = $id;
            }
            return $ids;
        });
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
            "SELECT c.id, c.numero, c.libelle, c.type, c.sens_normal, c.marque,
                    CASE WHEN c.marque IN ('client_collectif', 'fournisseur_collectif')
                           OR EXISTS (
                             SELECT 1 FROM documents_financiers d
                             WHERE d.organisation_id = c.organisation_id
                               AND d.dossier_id = c.dossier_id
                               AND d.compte_collectif_id = c.id
                           )
                         THEN 1 ELSE 0 END AS lettrable
             FROM comptes c
             WHERE c.organisation_id = ? AND c.dossier_id = ?
               AND c.actif = 1 AND c.imputable = 1
             ORDER BY length(c.numero), c.numero COLLATE NOCASE"
        );
        $accounts->execute([$organisationId, $dossierId]);

        $treasuryAccounts = $this->pdo->prepare(
            'SELECT id, compte_comptable_id, libelle, type, monnaie
             FROM comptes_tresorerie
             WHERE organisation_id = ? AND dossier_id = ? AND actif = 1
             ORDER BY libelle COLLATE NOCASE, id'
        );
        $treasuryAccounts->execute([$organisationId, $dossierId]);

        return [
            'exercises' => $exercises->fetchAll(),
            'journals' => $journals->fetchAll(),
            'accounts' => $accounts->fetchAll(),
            'treasury_accounts' => $treasuryAccounts->fetchAll(),
        ];
    }

    /**
     * Relit un brouillon manuel dans son périmètre afin de poursuivre sa saisie.
     *
     * @return array{
     *   id:int,version:int,exercise_id:int,journal_id:int,date:string,label:string,
     *   reference:string,attachment_reference:string,
     *   lines:list<array{account_id:int,label:string,debit_cents:int,credit_cents:int}>
     * }
     */
    public function draft(
        int $organisationId,
        int $dossierId,
        int $entryId,
    ): array {
        $header = $this->pdo->prepare(
            "SELECT id, exercice_id, journal_id, date_comptable, libelle,
                    reference, piece, version
             FROM ecritures
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND statut = 'brouillon'
               AND source_type IN ('manuel', 'import_journal')"
        );
        $header->execute([$entryId, $organisationId, $dossierId]);
        $row = $header->fetch();
        if ($row === false) {
            throw new AccountingException(
                'Brouillon absent, déjà validé ou non modifiable.'
            );
        }

        $lines = $this->pdo->prepare(
            'SELECT l.compte_id, l.compte_tresorerie_operationnel_id,
                    l.libelle, l.debit_centimes, l.credit_centimes
             FROM lignes_ecriture l
             JOIN comptes c ON c.id = l.compte_id
             WHERE l.ecriture_id = ?
               AND c.organisation_id = ? AND c.dossier_id = ?
             ORDER BY l.ordre, l.id'
        );
        $lines->execute([$entryId, $organisationId, $dossierId]);

        return [
            'id' => (int) $row['id'],
            'version' => (int) $row['version'],
            'exercise_id' => (int) $row['exercice_id'],
            'journal_id' => (int) $row['journal_id'],
            'date' => (string) $row['date_comptable'],
            'label' => (string) $row['libelle'],
            'reference' => (string) $row['reference'],
            'attachment_reference' => (string) $row['piece'],
            'lines' => array_map(
                static fn (array $line): array => [
                    'account_id' => (int) $line['compte_id'],
                    'treasury_account_id' => $line['compte_tresorerie_operationnel_id'] === null
                        ? 0 : (int) $line['compte_tresorerie_operationnel_id'],
                    'label' => (string) $line['libelle'],
                    'debit_cents' => (int) $line['debit_centimes'],
                    'credit_cents' => (int) $line['credit_centimes'],
                ],
                $lines->fetchAll()
            ),
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

    /**
     * Supprime définitivement un brouillon manuel ou importé avec verrou
     * optimiste. Les lignes sont supprimées par la contrainte en cascade.
     */
    public function deleteDraft(
        int $organisationId,
        int $dossierId,
        int $entryId,
        int $expectedVersion,
        ?int $actorId = null,
    ): void {
        $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $entryId,
            $expectedVersion,
            $actorId
        ): void {
            $draft = $this->pdo->prepare(
                "SELECT exercice_id, libelle, reference, source_type
                 FROM ecritures
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND statut = 'brouillon'
                   AND source_type IN ('manuel', 'import_journal')
                   AND version = ?"
            );
            $draft->execute([
                $entryId,
                $organisationId,
                $dossierId,
                $expectedVersion,
            ]);
            $row = $draft->fetch();
            if ($row === false) {
                throw new AccountingException(
                    'Brouillon absent, déjà validé ou modifié par un autre utilisateur.'
                );
            }

            $delete = $this->pdo->prepare(
                "DELETE FROM ecritures
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?
                   AND statut = 'brouillon'
                   AND source_type IN ('manuel', 'import_journal')
                   AND version = ?"
            );
            $delete->execute([
                $entryId,
                $organisationId,
                $dossierId,
                $expectedVersion,
            ]);
            if ($delete->rowCount() !== 1) {
                throw new AccountingException(
                    'Brouillon absent, déjà validé ou modifié par un autre utilisateur.'
                );
            }

            $this->audit->log(
                'compta.brouillon_supprime',
                $actorId,
                $organisationId,
                $dossierId,
                'ecriture',
                (string) $entryId,
                [
                    'exercice_id' => (int) $row['exercice_id'],
                    'libelle' => (string) $row['libelle'],
                    'reference' => (string) $row['reference'],
                    'source_type' => (string) $row['source_type'],
                    'version' => $expectedVersion,
                ]
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
                'SELECT compte_id, compte_tresorerie_operationnel_id,
                        libelle, debit_centimes, credit_centimes,
                        devise_origine, montant_origine_centimes, devise_base,
                        taux_change_numerateur, taux_change_denominateur,
                        taux_change_date, taux_change_source,
                        montant_base_centimes, ecart_arrondi_centimes
                 FROM lignes_ecriture WHERE ecriture_id = ? ORDER BY ordre'
            );
            $lines->execute([$entryId]);
            $reversedLines = [];
            foreach ($lines->fetchAll() as $line) {
                $reversedLines[] = [
                    'compte_id' => (int) $line['compte_id'],
                    'compte_tresorerie_operationnel_id' =>
                        $line['compte_tresorerie_operationnel_id'] === null
                            ? null : (int) $line['compte_tresorerie_operationnel_id'],
                    'libelle' => (string) $line['libelle'],
                    'debit_centimes' => (int) $line['credit_centimes'],
                    'credit_centimes' => (int) $line['debit_centimes'],
                    'devise_origine' => (string) $line['devise_origine'],
                    'montant_origine_centimes' =>
                        $line['montant_origine_centimes'] === null
                            ? null : -(int) $line['montant_origine_centimes'],
                    'devise_base' => (string) $line['devise_base'],
                    'taux_change_numerateur' => $line['taux_change_numerateur'],
                    'taux_change_denominateur' => $line['taux_change_denominateur'],
                    'taux_change_date' => (string) $line['taux_change_date'],
                    'taux_change_source' => (string) $line['taux_change_source'],
                    'montant_base_centimes' =>
                        $line['montant_base_centimes'] === null
                            ? null : -(int) $line['montant_base_centimes'],
                    'ecart_arrondi_centimes' => -(int) $line['ecart_arrondi_centimes'],
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
     * @param list<array<string,mixed>> $lines
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
     *   soldes:array<int,int>,soldes_tresorerie:array<int,int>,
     *   total_debit_centimes:int,total_credit_centimes:int
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
                'soldes_tresorerie' => [],
                'total_debit_centimes' => 0,
                'total_credit_centimes' => 0,
            ];
        }
        $lines = $this->pdo->prepare(
            "SELECT l.compte_id, l.compte_tresorerie_operationnel_id,
                    l.debit_centimes, l.credit_centimes,
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
        $treasuryBalances = [];
        $debit = 0;
        $credit = 0;
        foreach ($lines->fetchAll() as $line) {
            $accountId = (int) $line['compte_id'];
            $balance = (int) $line['solde_naturel_centimes'];
            $balances[$accountId] = ($balances[$accountId] ?? 0) + $balance;
            if ($line['compte_tresorerie_operationnel_id'] !== null) {
                $treasuryAccountId = (int) $line['compte_tresorerie_operationnel_id'];
                $treasuryBalances[$treasuryAccountId]
                    = ($treasuryBalances[$treasuryAccountId] ?? 0) + $balance;
            }
            $debit += (int) $line['debit_centimes'];
            $credit += (int) $line['credit_centimes'];
        }
        return [
            'id' => (int) $entry['id'],
            'status' => (string) $entry['statut'],
            'numero' => (string) $entry['numero'],
            'version' => (int) $entry['version'],
            'soldes' => $balances,
            'soldes_tresorerie' => $treasuryBalances,
            'total_debit_centimes' => $debit,
            'total_credit_centimes' => $credit,
        ];
    }

    /**
     * @param array<int,int> $balancesCents compte_id => solde naturel signé
     * @param array<int,int> $treasuryBalancesCents compte_tresorerie_id => solde naturel signé
     */
    public function saveOpeningDraft(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        int $journalId,
        array $balancesCents,
        ?int $actorId = null,
        array $treasuryBalancesCents = [],
    ): int {
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $exerciseId,
            $journalId,
            $balancesCents,
            $actorId,
            $treasuryBalancesCents
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
            $treasuryLedgerAccounts = [];
            if ($treasuryBalancesCents !== []) {
                $treasuryAccount = $this->pdo->prepare(
                    'SELECT t.compte_comptable_id, c.sens_normal
                     FROM comptes_tresorerie t
                     JOIN comptes c ON c.id = t.compte_comptable_id
                     WHERE t.id = ? AND t.organisation_id = ? AND t.dossier_id = ?
                       AND t.actif = 1 AND c.actif = 1 AND c.imputable = 1
                       AND c.type IN (\'actif\', \'passif\')'
                );
                foreach ($treasuryBalancesCents as $treasuryAccountId => $balance) {
                    $treasuryAccountId = (int) $treasuryAccountId;
                    $balance = (int) $balance;
                    $treasuryAccount->execute([
                        $treasuryAccountId,
                        $organisationId,
                        $dossierId,
                    ]);
                    $mapped = $treasuryAccount->fetch();
                    if ($mapped === false) {
                        throw new AccountingException(
                            "Le compte de trésorerie {$treasuryAccountId} est invalide."
                        );
                    }
                    $ledgerAccountId = (int) $mapped['compte_comptable_id'];
                    $treasuryLedgerAccounts[$ledgerAccountId] = true;
                    if ($balance === 0) {
                        continue;
                    }
                    $normalDebit = $mapped['sens_normal'] === 'debit';
                    $onDebit = ($balance > 0) === $normalDebit;
                    $lines[] = [
                        'compte_id' => $ledgerAccountId,
                        'compte_tresorerie_operationnel_id' => $treasuryAccountId,
                        'libelle' => 'Solde d’ouverture',
                        'debit_centimes' => $onDebit ? abs($balance) : 0,
                        'credit_centimes' => $onDebit ? 0 : abs($balance),
                    ];
                }
            }
            foreach ($balancesCents as $accountId => $balance) {
                $accountId = (int) $accountId;
                $balance = (int) $balance;
                if ($balance === 0) {
                    continue;
                }
                if (isset($treasuryLedgerAccounts[$accountId])) {
                    throw new AccountingException(
                        "Le compte {$accountId} est déjà détaillé par compte de trésorerie."
                    );
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
                (ecriture_id, compte_id, compte_tresorerie_operationnel_id,
                 libelle, debit_centimes, credit_centimes,
                 devise_origine, montant_origine_centimes, devise_base,
                 taux_change_numerateur, taux_change_denominateur,
                 taux_change_date, taux_change_source, montant_base_centimes,
                 ecart_arrondi_centimes, ordre)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
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
                $this->resolveTreasuryAccount(
                    $entryId,
                    $accountId,
                    isset($line['compte_tresorerie_operationnel_id'])
                        ? (int) $line['compte_tresorerie_operationnel_id']
                        : null
                ),
                trim((string) ($line['libelle'] ?? '')),
                $debit,
                $credit,
                trim((string) ($line['devise_origine'] ?? '')),
                array_key_exists('montant_origine_centimes', $line)
                    && $line['montant_origine_centimes'] !== null
                    ? (int) $line['montant_origine_centimes'] : null,
                trim((string) ($line['devise_base'] ?? '')),
                array_key_exists('taux_change_numerateur', $line)
                    && $line['taux_change_numerateur'] !== null
                    ? (int) $line['taux_change_numerateur'] : null,
                array_key_exists('taux_change_denominateur', $line)
                    && $line['taux_change_denominateur'] !== null
                    ? (int) $line['taux_change_denominateur'] : null,
                trim((string) ($line['taux_change_date'] ?? '')),
                trim((string) ($line['taux_change_source'] ?? '')),
                array_key_exists('montant_base_centimes', $line)
                    && $line['montant_base_centimes'] !== null
                    ? (int) $line['montant_base_centimes'] : null,
                (int) ($line['ecart_arrondi_centimes'] ?? 0),
                $position + 1,
            ]);
        }
    }

    private function resolveTreasuryAccount(
        int $entryId,
        int $ledgerAccountId,
        ?int $requestedId,
    ): ?int {
        if ($requestedId !== null && $requestedId > 0) {
            return $requestedId;
        }
        $stmt = $this->pdo->prepare(
            'SELECT t.id
             FROM ecritures e
             JOIN comptes_tresorerie t
               ON t.organisation_id = e.organisation_id
              AND t.dossier_id = e.dossier_id
              AND t.compte_comptable_id = ?
              AND t.actif = 1
             WHERE e.id = ?
             ORDER BY t.id'
        );
        $stmt->execute([$ledgerAccountId, $entryId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        return count($ids) === 1 ? $ids[0] : null;
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
        $unallocatedTreasury = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM lignes_ecriture l
             WHERE l.ecriture_id = ?
               AND l.compte_tresorerie_operationnel_id IS NULL
               AND EXISTS (
                 SELECT 1 FROM comptes_tresorerie t
                 WHERE t.organisation_id = ? AND t.dossier_id = ?
                   AND t.compte_comptable_id = l.compte_id
                   AND t.actif = 1
               )'
        );
        $unallocatedTreasury->execute([$entryId, $organisationId, $dossierId]);
        if ((int) $unallocatedTreasury->fetchColumn() > 0) {
            throw new AccountingException(
                'Précisez le compte de trésorerie pour chaque ligne concernée.'
            );
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
        $this->materializeJournalPayment(
            $organisationId,
            $dossierId,
            $entryId,
            $entry,
            $number,
            $actorId
        );
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

    /**
     * Une écriture manuelle ou importée devient un paiement à lettrer lorsqu’elle
     * oppose exactement un compte de trésorerie à un véritable compte collectif.
     * Le contact appartient aux allocations et reste donc volontairement absent
     * du paiement créé depuis le journal.
     *
     * @param array<string,mixed> $entry
     */
    private function materializeJournalPayment(
        int $organisationId,
        int $dossierId,
        int $entryId,
        array $entry,
        string $number,
        ?int $actorId,
    ): void {
        if (!in_array((string) $entry['source_type'], ['manuel', 'import_journal'], true)) {
            return;
        }
        $existing = $this->pdo->prepare(
            'SELECT 1 FROM paiements WHERE ecriture_id = ?'
        );
        $existing->execute([$entryId]);
        if ($existing->fetchColumn() !== false) {
            return;
        }
        $lines = $this->pdo->prepare(
            "SELECT l.compte_id, l.debit_centimes, l.credit_centimes,
                    c.type AS compte_type, c.marque AS compte_marque,
                    t.id AS compte_tresorerie_reference,
                    COALESCE(t.multiplicateur_comptable, 1) AS multiplicateur
             FROM lignes_ecriture l
             JOIN comptes c ON c.id = l.compte_id
             LEFT JOIN comptes_tresorerie t
               ON t.id = l.compte_tresorerie_operationnel_id
              AND t.organisation_id = ? AND t.dossier_id = ?
             WHERE l.ecriture_id = ?
             ORDER BY l.ordre, l.id"
        );
        $lines->execute([$organisationId, $dossierId, $entryId]);
        $rows = $lines->fetchAll();
        if (count($rows) !== 2) {
            return;
        }
        $treasury = array_values(array_filter(
            $rows,
            static fn (array $line): bool =>
                $line['compte_tresorerie_reference'] !== null
        ));
        if (count($treasury) !== 1) {
            return;
        }
        $treasuryLine = $treasury[0];
        $counterpart = $rows[0]['compte_id'] === $treasuryLine['compte_id']
            ? $rows[1] : $rows[0];
        $signedTreasury = (
            (int) $treasuryLine['debit_centimes']
            - (int) $treasuryLine['credit_centimes']
        ) * (int) $treasuryLine['multiplicateur'];
        $amount = abs($signedTreasury);
        if ($amount < 1) {
            return;
        }
        $expectedType = $signedTreasury > 0 ? 'actif' : 'passif';
        $expectedTag = $signedTreasury > 0
            ? 'client_collectif' : 'fournisseur_collectif';
        if (
            $counterpart['compte_tresorerie_reference'] !== null
            || (string) $counterpart['compte_type'] !== $expectedType
            || (
                (string) $counterpart['compte_marque'] !== $expectedTag
                && !$this->accountUsedAsCollective(
                    $organisationId,
                    $dossierId,
                    (int) $counterpart['compte_id'],
                    $signedTreasury > 0 ? 'facture_client' : 'facture_fournisseur'
                )
            )
        ) {
            return;
        }
        $currency = $this->pdo->prepare(
            'SELECT monnaie FROM dossiers WHERE id = ? AND organisation_id = ?'
        );
        $currency->execute([$dossierId, $organisationId]);
        $baseCurrency = strtoupper((string) $currency->fetchColumn());
        if (preg_match('/^[A-Z]{3}$/', $baseCurrency) !== 1) {
            throw new AccountingException('Devise de base du dossier invalide.');
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO paiements
             (organisation_id, dossier_id, contact_id, sens, date_paiement,
              montant_centimes, monnaie, devise_base, montant_base_centimes,
              reference, compte_tresorerie_id,
              compte_tresorerie_operationnel_id, compte_collectif_id,
              ecriture_id, origine, cree_par)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $organisationId,
            $dossierId,
            $signedTreasury > 0 ? 'encaissement' : 'decaissement',
            (string) $entry['date_comptable'],
            $amount,
            $baseCurrency,
            $baseCurrency,
            $amount,
            trim((string) $entry['reference']) ?: $number,
            (int) $treasuryLine['compte_id'],
            (int) $treasuryLine['compte_tresorerie_reference'],
            (int) $counterpart['compte_id'],
            $entryId,
            'journal',
            $actorId,
        ]);
        $paymentId = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'facturation.paiement_detecte_journal',
            $actorId,
            $organisationId,
            $dossierId,
            'paiement',
            (string) $paymentId,
            [
                'ecriture_id' => $entryId,
                'compte_tresorerie_id' => (int) $treasuryLine['compte_id'],
                'compte_collectif_id' => (int) $counterpart['compte_id'],
                'montant_centimes' => $amount,
            ]
        );
    }

    private function accountUsedAsCollective(
        int $organisationId,
        int $dossierId,
        int $accountId,
        string $documentType,
    ): bool {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM documents_financiers
             WHERE organisation_id = ? AND dossier_id = ?
               AND compte_collectif_id = ? AND type = ? LIMIT 1'
        );
        $stmt->execute([$organisationId, $dossierId, $accountId, $documentType]);
        return $stmt->fetchColumn() !== false;
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
