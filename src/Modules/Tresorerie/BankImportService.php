<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie;

use Compta\Core\Audit\AuditLogger;
use Compta\Modules\Tresorerie\Parsing\ParsedStatement;
use Compta\Modules\Tresorerie\Parsing\ParserRegistry;
use PDO;
use Throwable;

final class BankImportService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly ParserRegistry $parsers = new ParserRegistry(),
    ) {
    }

    /** @return array<string,mixed> */
    public function preview(
        int $organisationId,
        int $dossierId,
        int $treasuryAccountId,
        string $filename,
        string $content,
        ?int $actorId = null,
    ): array {
        $account = $this->account($organisationId, $dossierId, $treasuryAccountId);
        $parsed = $this->parsers->parse($content, $filename);
        if (
            $parsed->iban !== ''
            && $account['iban'] !== ''
            && $parsed->iban !== $account['iban']
        ) {
            throw new TreasuryException('L’IBAN du relevé ne correspond pas au compte sélectionné.');
        }
        if ($parsed->currency !== '' && $parsed->currency !== $account['monnaie']) {
            throw new TreasuryException('La monnaie du relevé ne correspond pas au compte sélectionné.');
        }
        $sourceHash = hash('sha256', $content);
        $existing = $this->pdo->prepare(
            'SELECT id FROM imports_bancaires
             WHERE compte_tresorerie_id = ? AND empreinte_source = ?'
        );
        $existing->execute([$treasuryAccountId, $sourceHash]);
        $importId = $existing->fetchColumn();
        if ($importId === false) {
            $insert = $this->pdo->prepare(
                'INSERT INTO imports_bancaires
                 (organisation_id, dossier_id, compte_tresorerie_id, format,
                  namespace_xml, nom_fichier, empreinte_source, contenu_source,
                  iban_detecte, monnaie_detectee, date_debut, date_fin,
                  erreurs_json, nb_total, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $organisationId,
                $dossierId,
                $treasuryAccountId,
                $parsed->format,
                $parsed->namespace,
                basename($filename),
                $sourceHash,
                $content,
                $parsed->iban,
                $parsed->currency,
                $parsed->dateStart,
                $parsed->dateEnd,
                $this->json($parsed->errors),
                count($parsed->transactions),
                $actorId,
            ]);
            $importId = (int) $this->pdo->lastInsertId();
        } else {
            $importId = (int) $importId;
        }
        $lines = $this->decorateTransactions($treasuryAccountId, $parsed);
        $duplicates = count(array_filter(
            $lines,
            static fn (array $line): bool => $line['duplicate']
        ));
        return [
            'import_id' => $importId,
            'format' => $parsed->format,
            'namespace' => $parsed->namespace,
            'iban' => $parsed->iban,
            'currency' => $parsed->currency,
            'date_start' => $parsed->dateStart,
            'date_end' => $parsed->dateEnd,
            'errors' => $parsed->errors,
            'transactions' => $lines,
            'balances' => $parsed->balances,
            'duplicate_count' => $duplicates,
        ];
    }

    /** @return array{import_id:int,imported:int,duplicates:int,status:string} */
    public function confirm(
        int $organisationId,
        int $dossierId,
        int $importId,
        ?int $actorId = null,
    ): array {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM imports_bancaires
                 WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
            );
            $stmt->execute([$importId, $organisationId, $dossierId]);
            $import = $stmt->fetch();
            if ($import === false) {
                throw new TreasuryException('Import bancaire introuvable dans ce dossier.');
            }
            if ($import['statut'] === 'confirme') {
                $this->pdo->commit();
                return [
                    'import_id' => $importId,
                    'imported' => (int) $import['nb_importees'],
                    'duplicates' => (int) $import['nb_doublons'],
                    'status' => 'confirme',
                ];
            }
            $parsed = $this->parsers->parse(
                (string) $import['contenu_source'],
                (string) $import['nom_fichier']
            );
            $lines = $this->decorateTransactions((int) $import['compte_tresorerie_id'], $parsed);
            $inserted = 0;
            $duplicates = 0;
            foreach ($lines as $line) {
                if ($line['duplicate']) {
                    $duplicates++;
                    continue;
                }
                $insert = $this->pdo->prepare(
                    'INSERT INTO lignes_bancaires
                     (organisation_id, dossier_id, compte_tresorerie_id, import_id,
                      empreinte, rang_occurrence, identifiant_bancaire, groupe_id,
                      date_comptabilisation, date_valeur, libelle, tiers, communication,
                      type_reference, reference, iban_contrepartie, code_transaction,
                      montant_centimes, frais_centimes, monnaie, solde_apres_centimes,
                      donnees_brutes_json)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    $organisationId, $dossierId, $import['compte_tresorerie_id'], $importId,
                    $line['fingerprint'], $line['occurrence'], $line['bank_id'], $line['group_id'],
                    $line['date_booking'], $line['date_value'], $line['label'],
                    $line['counterparty'], $line['communication'], $line['reference_type'],
                    $line['reference'], $line['counterparty_iban'], $line['transaction_code'],
                    $line['amount_cents'], $line['fee_cents'], $line['currency'],
                    $line['balance_after_cents'], $this->json($line['raw']),
                ]);
                $inserted++;
            }
            foreach ($parsed->balances as $balance) {
                $fingerprint = hash('sha256', $this->json($balance));
                $balanceInsert = $this->pdo->prepare(
                    'INSERT OR IGNORE INTO soldes_bancaires
                     (organisation_id, dossier_id, compte_tresorerie_id, import_id,
                      type, date_solde, montant_centimes, monnaie, empreinte)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $balanceInsert->execute([
                    $organisationId, $dossierId, $import['compte_tresorerie_id'], $importId,
                    $balance['type'], $balance['date'], $balance['amount_cents'],
                    $balance['currency'], $fingerprint,
                ]);
            }
            $update = $this->pdo->prepare(
                "UPDATE imports_bancaires
                 SET statut = 'confirme', nb_importees = ?, nb_doublons = ?,
                     confirme_le = datetime('now'), confirme_par = ?, version = version + 1
                 WHERE id = ? AND statut = 'previsualise'"
            );
            $update->execute([$inserted, $duplicates, $actorId, $importId]);
            $this->audit->log(
                'tresorerie.import_confirme',
                $actorId,
                $organisationId,
                $dossierId,
                'import_bancaire',
                (string) $importId,
                ['importees' => $inserted, 'doublons' => $duplicates]
            );
            $this->pdo->commit();
            return [
                'import_id' => $importId,
                'imported' => $inserted,
                'duplicates' => $duplicates,
                'status' => 'confirme',
            ];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function account(int $organisationId, int $dossierId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM comptes_tresorerie
             WHERE id = ? AND organisation_id = ? AND dossier_id = ? AND actif = 1'
        );
        $stmt->execute([$id, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new TreasuryException('Compte de trésorerie introuvable dans ce dossier.');
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    private function decorateTransactions(int $accountId, ParsedStatement $statement): array
    {
        $occurrences = [];
        $result = [];
        $existing = $this->pdo->prepare(
            'SELECT 1 FROM lignes_bancaires
             WHERE compte_tresorerie_id = ? AND empreinte = ?'
        );
        foreach ($statement->transactions as $transaction) {
            $signature = $this->transactionSignature($transaction);
            $occurrence = $occurrences[$signature] ?? 0;
            $occurrences[$signature] = $occurrence + 1;
            $fingerprint = hash('sha256', $signature . "\noccurrence=" . $occurrence);
            $existing->execute([$accountId, $fingerprint]);
            $result[] = $transaction + [
                'occurrence' => $occurrence,
                'fingerprint' => $fingerprint,
                'duplicate' => $existing->fetchColumn() !== false,
            ];
        }
        return $result;
    }

    /** @param array<string,mixed> $transaction */
    private function transactionSignature(array $transaction): string
    {
        $keys = [
            'date_booking', 'date_value', 'label', 'counterparty', 'communication',
            'reference_type', 'reference', 'counterparty_iban', 'bank_id', 'group_id',
            'transaction_code', 'amount_cents', 'fee_cents', 'currency',
        ];
        $normalized = [];
        foreach ($keys as $key) {
            $normalized[$key] = $transaction[$key] ?? null;
        }
        return $this->json($normalized);
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
