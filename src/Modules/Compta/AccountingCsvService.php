<?php
declare(strict_types=1);

namespace Compta\Modules\Compta;

use PDO;

/**
 * Imports et exports CSV propres aux données comptables transactionnelles.
 *
 * Le plan comptable, les soldes d'ouverture et le journal ont volontairement
 * des formats distincts afin qu'un fichier ne puisse pas être appliqué au
 * mauvais écran.
 */
final class AccountingCsvService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ChartOfAccountsService $chart,
        private readonly EntryService $entries,
    ) {
    }

    /** @return array{filename:string,content:string} */
    public function exportOpening(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
    ): array {
        $state = $this->entries->openingState(
            $organisationId,
            $dossierId,
            $exerciseId
        );
        $rows = [['numero', 'libelle', 'sens', 'solde']];
        foreach ($this->openingAccounts($organisationId, $dossierId) as $account) {
            $cents = (int) ($state['soldes'][(int) $account['id']] ?? 0);
            $rows[] = [
                (string) $account['numero'],
                (string) $account['libelle'],
                (string) $account['sens_normal'],
                $this->decimal($cents),
            ];
        }
        return [
            'filename' => "soldes-ouverture-{$exerciseId}.csv",
            'content' => $this->csv($rows),
        ];
    }

    /** @return array<string,mixed> */
    public function previewOpening(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $csv,
    ): array {
        $state = $this->entries->openingState(
            $organisationId,
            $dossierId,
            $exerciseId
        );
        if (!in_array($state['status'], ['absent', 'brouillon'], true)) {
            throw new AccountingException(
                'Les soldes d’ouverture sont validés et ne peuvent plus être importés.'
            );
        }
        $parsed = $this->parseOpening(
            $organisationId,
            $dossierId,
            $csv
        );
        $debit = 0;
        $credit = 0;
        foreach ($parsed['balances'] as $accountId => $balance) {
            $side = $parsed['sides'][$accountId];
            $onDebit = ($balance > 0) === ($side === 'debit');
            $debit += $onDebit ? abs($balance) : 0;
            $credit += $onDebit ? 0 : abs($balance);
        }
        if ($debit !== $credit) {
            throw new AccountingException(
                'Les soldes d’ouverture importés ne sont pas équilibrés : '
                . $this->decimal($debit) . ' au débit et '
                . $this->decimal($credit) . ' au crédit.'
            );
        }
        return [
            'fingerprint' => $this->openingFingerprint($csv, $state),
            'summary' => [
                'accounts' => count($parsed['balances']),
                'non_zero' => count(array_filter(
                    $parsed['balances'],
                    static fn (int $value): bool => $value !== 0
                )),
                'debit_cents' => $debit,
                'credit_cents' => $credit,
            ],
            'balances' => $parsed['balances'],
        ];
    }

    /** @return array{id:int,number:string} */
    public function importOpening(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $csv,
        string $fingerprint,
        int $actorId,
    ): array {
        $preview = $this->previewOpening(
            $organisationId,
            $dossierId,
            $exerciseId,
            $csv
        );
        if (!hash_equals((string) $preview['fingerprint'], $fingerprint)) {
            throw new AccountingException(
                'Le brouillon d’ouverture a changé depuis la prévisualisation.'
            );
        }
        $journalId = $this->chart->ensureOpeningJournal(
            $organisationId,
            $dossierId,
            $actorId
        );
        $id = $this->entries->saveOpeningDraft(
            $organisationId,
            $dossierId,
            $exerciseId,
            $journalId,
            $preview['balances'],
            $actorId
        );
        return ['id' => $id, 'number' => ''];
    }

    /** @return array{items:list<array<string,mixed>>,total_lines:int,total_entries:int,total_debit_cents:int,total_credit_cents:int} */
    public function journalDetails(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT e.id AS entry_id, e.numero, e.date_comptable, j.code AS journal,
                    e.reference, e.piece, e.libelle AS entry_label, e.statut,
                    l.ordre AS line_order, c.numero AS account_number,
                    c.libelle AS account_label,
                    COALESCE(NULLIF(l.libelle, \'\'), e.libelle) AS line_label,
                    l.debit_centimes, l.credit_centimes
             FROM ecritures e
             JOIN journaux j ON j.id = e.journal_id
             JOIN lignes_ecriture l ON l.ecriture_id = e.id
             JOIN comptes c ON c.id = l.compte_id
             WHERE e.organisation_id = ? AND e.dossier_id = ?
               AND e.exercice_id = ?
             ORDER BY e.date_comptable, e.id, l.ordre'
        );
        $stmt->execute([$organisationId, $dossierId, $exerciseId]);
        $items = $stmt->fetchAll();
        $entries = [];
        $debit = 0;
        $credit = 0;
        foreach ($items as &$item) {
            $item['entry_id'] = (int) $item['entry_id'];
            $item['line_order'] = (int) $item['line_order'];
            $item['debit_centimes'] = (int) $item['debit_centimes'];
            $item['credit_centimes'] = (int) $item['credit_centimes'];
            $entries[$item['entry_id']] = true;
            $debit += $item['debit_centimes'];
            $credit += $item['credit_centimes'];
        }
        unset($item);
        return [
            'items' => $items,
            'total_lines' => count($items),
            'total_entries' => count($entries),
            'total_debit_cents' => $debit,
            'total_credit_cents' => $credit,
        ];
    }

    /** @return array{filename:string,content:string} */
    public function exportJournal(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
    ): array {
        $details = $this->journalDetails(
            $organisationId,
            $dossierId,
            $exerciseId
        );
        $rows = [[
            'ecriture', 'date', 'journal', 'reference', 'piece',
            'libelle_ecriture', 'compte', 'libelle_ligne', 'debit', 'credit',
            'statut',
        ]];
        foreach ($details['items'] as $line) {
            $rows[] = [
                (string) ($line['numero'] ?: 'ID-' . $line['entry_id']),
                (string) $line['date_comptable'],
                (string) $line['journal'],
                (string) $line['reference'],
                (string) $line['piece'],
                (string) $line['entry_label'],
                (string) $line['account_number'],
                (string) $line['line_label'],
                $this->decimal((int) $line['debit_centimes']),
                $this->decimal((int) $line['credit_centimes']),
                $line['statut'] === 'brouillon' ? 'brouillon' : 'validee',
            ];
        }
        return [
            'filename' => "journal-detaille-{$exerciseId}.csv",
            'content' => $this->csv($rows),
        ];
    }

    /** @return array<string,mixed> */
    public function previewJournalImport(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $csv,
    ): array {
        $groups = $this->parseJournal(
            $organisationId,
            $dossierId,
            $exerciseId,
            $csv
        );
        $lines = 0;
        $drafts = 0;
        $validated = 0;
        $debit = 0;
        foreach ($groups as $group) {
            $lines += count($group['lines']);
            $debit += array_sum(array_column($group['lines'], 'debit_centimes'));
            if ($group['status'] === 'validee') {
                ++$validated;
            } else {
                ++$drafts;
            }
        }
        return [
            'fingerprint' => hash('sha256', $csv),
            'summary' => [
                'entries' => count($groups),
                'lines' => $lines,
                'drafts' => $drafts,
                'validated' => $validated,
                'total_cents' => $debit,
            ],
        ];
    }

    /** @return array{entries:int,lines:int} */
    public function importJournal(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $csv,
        string $fingerprint,
        int $actorId,
    ): array {
        if (!hash_equals(hash('sha256', $csv), $fingerprint)) {
            throw new AccountingException('Le fichier journal a changé depuis la prévisualisation.');
        }
        $groups = $this->parseJournal(
            $organisationId,
            $dossierId,
            $exerciseId,
            $csv
        );
        $sourceId = 'csv:' . $fingerprint;
        $items = [];
        $lineCount = 0;
        foreach ($groups as $key => $group) {
            $items[] = [
                'command' => [
                    'organisation_id' => $organisationId,
                    'dossier_id' => $dossierId,
                    'exercice_id' => $exerciseId,
                    'journal_id' => $group['journal_id'],
                    'date_comptable' => $group['date'],
                    'libelle' => $group['label'],
                    'reference' => $group['reference'],
                    'piece' => $group['piece'],
                    'source_type' => 'import_journal',
                    'source_id' => $sourceId,
                    'source_action' => (string) $key,
                    'lignes' => $group['lines'],
                ],
                'validate' => $group['status'] === 'validee',
            ];
            $lineCount += count($group['lines']);
        }
        $this->entries->importBatch(
            $organisationId,
            $dossierId,
            $sourceId,
            $items,
            $actorId
        );
        return ['entries' => count($groups), 'lines' => $lineCount];
    }

    /** @return list<array<string,mixed>> */
    private function openingAccounts(int $organisationId, int $dossierId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, numero, libelle, sens_normal
             FROM comptes
             WHERE organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND imputable = 1 AND type IN ('actif', 'passif')
             ORDER BY length(numero), numero COLLATE NOCASE"
        );
        $stmt->execute([$organisationId, $dossierId]);
        return $stmt->fetchAll();
    }

    /**
     * @return array{balances:array<int,int>,sides:array<int,string>}
     */
    private function parseOpening(
        int $organisationId,
        int $dossierId,
        string $csv,
    ): array {
        $rows = $this->rows($csv);
        $header = array_map([$this, 'key'], array_shift($rows) ?: []);
        $numberIndex = array_search('numero', $header, true);
        $balanceIndex = array_search('solde', $header, true);
        if ($numberIndex === false || $balanceIndex === false) {
            throw new AccountingException(
                'CSV d’ouverture invalide : colonnes « numero » et « solde » requises.'
            );
        }
        $accounts = [];
        foreach ($this->openingAccounts($organisationId, $dossierId) as $account) {
            $accounts[(string) $account['numero']] = $account;
        }
        $balances = [];
        $sides = [];
        foreach ($rows as $offset => $row) {
            if ($this->emptyRow($row)) {
                continue;
            }
            $number = trim((string) ($row[$numberIndex] ?? ''));
            if (!isset($accounts[$number])) {
                throw new AccountingException(
                    'Ligne ' . ($offset + 2) . " : compte d’ouverture « {$number} » introuvable."
                );
            }
            $accountId = (int) $accounts[$number]['id'];
            if (isset($balances[$accountId])) {
                throw new AccountingException(
                    'Ligne ' . ($offset + 2) . " : compte « {$number} » dupliqué."
                );
            }
            $balances[$accountId] = $this->cents(
                (string) ($row[$balanceIndex] ?? ''),
                $offset + 2
            );
            $sides[$accountId] = (string) $accounts[$number]['sens_normal'];
        }
        if ($balances === []) {
            throw new AccountingException('Le CSV d’ouverture ne contient aucun compte.');
        }
        return ['balances' => $balances, 'sides' => $sides];
    }

    /**
     * @return array<string,array{
     *   date:string,journal_id:int,reference:string,piece:string,label:string,
     *   status:string,lines:list<array<string,mixed>>
     * }>
     */
    private function parseJournal(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $csv,
    ): array {
        $exercise = $this->pdo->prepare(
            'SELECT date_debut, date_fin FROM exercices
             WHERE id = ? AND dossier_id = ?'
        );
        $exercise->execute([$exerciseId, $dossierId]);
        $period = $exercise->fetch();
        if ($period === false) {
            throw new AccountingException('Exercice d’import introuvable.');
        }
        $journals = $this->mapByCode(
            'SELECT id, code FROM journaux
             WHERE organisation_id = ? AND dossier_id = ? AND actif = 1',
            $organisationId,
            $dossierId
        );
        $accounts = $this->mapByCode(
            'SELECT id, numero AS code FROM comptes
             WHERE organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND imputable = 1',
            $organisationId,
            $dossierId
        );
        $rows = $this->rows($csv);
        $header = array_map([$this, 'key'], array_shift($rows) ?: []);
        $required = [
            'ecriture', 'date', 'journal', 'libelle_ecriture',
            'compte', 'debit', 'credit',
        ];
        $indexes = [];
        foreach ($required as $column) {
            $index = array_search($column, $header, true);
            if ($index === false) {
                throw new AccountingException("CSV journal invalide : colonne « {$column} » requise.");
            }
            $indexes[$column] = $index;
        }
        foreach (['reference', 'piece', 'libelle_ligne', 'statut'] as $column) {
            $indexes[$column] = array_search($column, $header, true);
        }
        $groups = [];
        foreach ($rows as $offset => $row) {
            if ($this->emptyRow($row)) {
                continue;
            }
            $lineNumber = $offset + 2;
            $key = trim((string) ($row[$indexes['ecriture']] ?? ''));
            $date = trim((string) ($row[$indexes['date']] ?? ''));
            $journal = strtoupper(trim((string) ($row[$indexes['journal']] ?? '')));
            $account = trim((string) ($row[$indexes['compte']] ?? ''));
            $label = trim((string) ($row[$indexes['libelle_ecriture']] ?? ''));
            $status = $indexes['statut'] === false
                ? 'brouillon'
                : trim((string) ($row[$indexes['statut']] ?? 'brouillon'));
            if (
                $key === '' || $label === ''
                || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
                || $date < $period['date_debut'] || $date > $period['date_fin']
            ) {
                throw new AccountingException("Ligne {$lineNumber} : en-tête d’écriture invalide.");
            }
            if (!isset($journals[$journal]) || !isset($accounts[$account])) {
                throw new AccountingException(
                    "Ligne {$lineNumber} : journal ou compte introuvable dans ce dossier."
                );
            }
            if (!in_array($status, ['brouillon', 'validee'], true)) {
                throw new AccountingException(
                    "Ligne {$lineNumber} : statut « {$status} » non importable."
                );
            }
            $debit = $this->cents((string) ($row[$indexes['debit']] ?? ''), $lineNumber);
            $credit = $this->cents((string) ($row[$indexes['credit']] ?? ''), $lineNumber);
            if (($debit > 0) === ($credit > 0)) {
                throw new AccountingException(
                    "Ligne {$lineNumber} : renseignez exactement un montant au débit ou au crédit."
                );
            }
            $reference = $indexes['reference'] === false
                ? ''
                : trim((string) ($row[$indexes['reference']] ?? ''));
            $piece = $indexes['piece'] === false
                ? ''
                : trim((string) ($row[$indexes['piece']] ?? ''));
            $lineLabel = $indexes['libelle_ligne'] === false
                ? ''
                : trim((string) ($row[$indexes['libelle_ligne']] ?? ''));
            $headerValues = compact('date', 'reference', 'piece', 'label', 'status');
            $headerValues['journal_id'] = (int) $journals[$journal]['id'];
            if (!isset($groups[$key])) {
                $groups[$key] = $headerValues + ['lines' => []];
            } elseif (array_diff_assoc($headerValues, array_intersect_key(
                $groups[$key],
                $headerValues
            )) !== []) {
                throw new AccountingException(
                    "Ligne {$lineNumber} : données incohérentes pour l’écriture « {$key} »."
                );
            }
            $groups[$key]['lines'][] = [
                'compte_id' => (int) $accounts[$account]['id'],
                'libelle' => $lineLabel,
                'debit_centimes' => $debit,
                'credit_centimes' => $credit,
            ];
        }
        foreach ($groups as $key => $group) {
            if (count($group['lines']) < 2) {
                throw new AccountingException("Écriture « {$key} » : au moins deux lignes sont requises.");
            }
            $debit = array_sum(array_column($group['lines'], 'debit_centimes'));
            $credit = array_sum(array_column($group['lines'], 'credit_centimes'));
            if ($debit !== $credit) {
                throw new AccountingException("Écriture « {$key} » déséquilibrée.");
            }
        }
        if ($groups === []) {
            throw new AccountingException('Le CSV journal ne contient aucune écriture.');
        }
        return $groups;
    }

    /** @return array<string,array<string,mixed>> */
    private function mapByCode(
        string $sql,
        int $organisationId,
        int $dossierId,
    ): array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$organisationId, $dossierId]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[strtoupper((string) $row['code'])] = $row;
        }
        return $result;
    }

    /** @return list<list<string>> */
    private function rows(string $csv): array
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new AccountingException('Lecture CSV impossible.');
        }
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, ';', '"', '')) !== false) {
            $rows[] = array_map(static fn ($value): string => trim((string) $value), $row);
        }
        fclose($stream);
        return $rows;
    }

    /** @param list<list<string>> $rows */
    private function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new AccountingException('Création CSV impossible.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($stream, $row, ';', '"', '');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        return $content === false ? '' : $content;
    }

    private function cents(string $value, int $line): int
    {
        $normalized = str_replace(["\u{00A0}", ' ', "'"], '', trim($value));
        $normalized = str_replace(',', '.', $normalized);
        if ($normalized === '') {
            return 0;
        }
        if (preg_match('/^-?\d+(?:\.\d{1,2})?$/', $normalized) !== 1) {
            throw new AccountingException("Ligne {$line} : montant invalide.");
        }
        $negative = str_starts_with($normalized, '-');
        $parts = explode('.', ltrim($normalized, '-'), 2);
        $cents = ((int) $parts[0] * 100)
            + (int) str_pad($parts[1] ?? '', 2, '0');
        return $negative ? -$cents : $cents;
    }

    private function decimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '')
            . intdiv(abs($cents), 100)
            . '.'
            . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @param list<string> $row */
    private function emptyRow(array $row): bool
    {
        return implode('', array_map('trim', $row)) === '';
    }

    private function key(string $value): string
    {
        $value = strtolower(trim($value));
        return strtr($value, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a',
            'ù' => 'u', 'ô' => 'o', 'î' => 'i', 'ï' => 'i',
            ' ' => '_', '-' => '_',
        ]);
    }

    /** @param array<string,mixed> $state */
    private function openingFingerprint(string $csv, array $state): string
    {
        return hash('sha256', $csv . "\n" . json_encode([
            'id' => $state['id'],
            'status' => $state['status'],
            'version' => $state['version'],
            'soldes' => $state['soldes'],
        ], JSON_THROW_ON_ERROR));
    }
}
