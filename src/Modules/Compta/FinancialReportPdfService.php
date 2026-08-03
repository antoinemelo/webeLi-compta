<?php
declare(strict_types=1);

namespace Compta\Modules\Compta;

final class FinancialReportPdfService
{
    /**
     * @param array<string,mixed> $financial
     * @param list<array<string,mixed>> $journal
     * @param array<string,mixed> $context
     */
    public function render(
        string $type,
        array $financial,
        array $journal,
        array $context,
    ): string {
        $landscape = in_array($type, ['journal', 'grand_livre', 'balance'], true);
        $pdf = new \TCPDF($landscape ? 'L' : 'P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('webe.li COMPTA');
        $pdf->SetAuthor((string) ($context['organisation_name'] ?? ''));
        $pdf->SetTitle($this->title($type));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(14, 12, 14);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->writeHTML($this->documentHtml($type, $financial, $journal, $context), true, false, true);
        $bytes = $pdf->Output('', 'S');
        if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-')) {
            throw new AccountingException('La génération du PDF a échoué.');
        }
        return $bytes;
    }

    private function title(string $type): string
    {
        return match ($type) {
            'journal' => 'Journal comptable',
            'grand_livre' => 'Balances de vérification',
            'balance' => 'Balance de vérification',
            'bilan' => 'Bilan',
            'resultat' => 'Compte de résultat',
            'flux_tresorerie' => 'Flux de trésorerie',
            default => throw new AccountingException('Rapport inconnu.'),
        };
    }

    /**
     * @param array<string,mixed> $financial
     * @param list<array<string,mixed>> $journal
     * @param array<string,mixed> $context
     */
    private function documentHtml(
        string $type,
        array $financial,
        array $journal,
        array $context,
    ): string {
        $title = $this->title($type);
        $organisation = $this->e((string) ($context['organisation_name'] ?? 'Organisation'));
        $dossier = trim((string) ($context['dossier_name'] ?? ''));
        $currency = $this->e((string) ($context['currency'] ?? 'CHF'));
        $start = $this->date((string) ($context['date_start'] ?? ''));
        $end = $this->date((string) ($context['date_end'] ?? ''));
        $period = in_array($type, ['bilan', 'grand_livre', 'balance'], true)
            ? 'Au ' . $end
            : 'Du ' . $start . ' au ' . $end;
        $subtitle = $dossier === '' ? $period : $this->e($dossier) . ' · ' . $period;

        return '<style>'
            . 'h1{color:#17324d;font-size:17pt;margin:0 0 2mm 0}'
            . '.meta{color:#64717d;font-size:8.5pt;margin-bottom:5mm}'
            . 'table{border-collapse:collapse;width:100%;font-family:courier;font-size:8pt}'
            . 'th{color:#17324d;font-weight:bold;border-bottom:1px solid #9aa7b2;padding:4px}'
            . 'td{border-bottom:1px solid #dce2e7;padding:4px;color:#27343e}'
            . '.section td{background-color:#eaf0f4;color:#17324d;font-weight:bold;border-bottom:1px solid #aab7c1}'
            . '.subtotal td{background-color:#f3f6f8;color:#17324d;font-weight:bold}'
            . '.total td{background-color:#17324d;color:#ffffff;font-weight:bold}'
            . '.code{color:#667580;font-size:8pt}'
            . '</style>'
            . '<h1>' . $organisation . ' — ' . $this->e(mb_strtoupper($title)) . '</h1>'
            . '<div class="meta">' . $subtitle . ' · ' . $currency . '</div>'
            . match ($type) {
                'journal' => $this->journalHtml($journal),
                'grand_livre' => $this->ledgerHtml($financial['general_ledger']['items'] ?? []),
                'balance' => $this->trialBalanceHtml($financial['trial_balance']['items'] ?? []),
                'bilan' => $this->balanceSheetHtml($financial['balance_sheet'] ?? []),
                'resultat' => $this->incomeStatementHtml($financial['income_statement'] ?? []),
                'flux_tresorerie' => $this->cashFlowHtml($financial['cash_flow'] ?? []),
                default => throw new AccountingException('Rapport inconnu.'),
            };
    }

    /** @param list<array<string,mixed>> $rows */
    private function journalHtml(array $rows): string
    {
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr><td width="11%">' . $this->e((string) ($row['date_comptable'] ?? '')) . '</td>'
                . '<td width="11%">' . $this->e((string) ($row['numero'] ?? '')) . '</td>'
                . '<td width="8%">' . $this->e((string) ($row['journal'] ?? '')) . '</td>'
                . '<td width="31%">' . $this->e((string) ($row['libelle'] ?? '')) . '</td>'
                . '<td width="17%">' . $this->e((string) ($row['reference'] ?? '')) . '</td>'
                . '<td width="11%" align="right">' . $this->money((int) ($row['debit_centimes'] ?? 0)) . '</td>'
                . '<td width="11%" align="right">' . $this->money((int) ($row['credit_centimes'] ?? 0)) . '</td></tr>';
        }
        return '<table cellpadding="4"><thead><tr><th width="11%">Date</th><th width="11%">N°</th>'
            . '<th width="8%">Journal</th><th width="31%"></th><th width="17%">Référence</th>'
            . '<th width="11%" align="right">Débit</th><th width="11%" align="right">Crédit</th>'
            . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    /** @param list<array<string,mixed>> $rows */
    private function ledgerHtml(array $rows): string
    {
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr><td width="44%"><span class="code">' . $this->e((string) ($row['numero'] ?? ''))
                . '</span> ' . $this->e((string) ($row['libelle'] ?? '')) . '</td>'
                . $this->amountCells(
                    $row,
                    ['initial_centimes', 'debit_centimes', 'credit_centimes', 'solde_centimes'],
                    '14%'
                );
        }
        return '<table cellpadding="4"><thead><tr><th width="44%"></th>'
            . '<th width="14%" align="right">Initial</th><th width="14%" align="right">Débit</th>'
            . '<th width="14%" align="right">Crédit</th><th width="14%" align="right">Final</th>'
            . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    /** @param list<array<string,mixed>> $rows */
    private function trialBalanceHtml(array $rows): string
    {
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr><td width="55%"><span class="code">' . $this->e((string) ($row['numero'] ?? ''))
                . '</span> ' . $this->e((string) ($row['libelle'] ?? '')) . '</td>'
                . $this->amountCells(
                    $row,
                    ['debit_centimes', 'credit_centimes', 'solde_centimes'],
                    '15%'
                );
        }
        return '<table cellpadding="4"><thead><tr><th width="55%"></th>'
            . '<th width="15%" align="right">Débit</th><th width="15%" align="right">Crédit</th>'
            . '<th width="15%" align="right">Solde</th></tr></thead><tbody>' . $body . '</tbody></table>';
    }

    /** @param array<string,mixed> $report */
    private function balanceSheetHtml(array $report): string
    {
        $previous = ($report['previous_label'] ?? null) !== null;
        $body = $this->statementSection('ACTIF', $report['presentation_items'] ?? [], 'actif', false, $previous);
        $body .= $this->totalRow(
            'TOTAL DE L’ACTIF',
            (int) ($report['total_actif_centimes'] ?? 0),
            $previous ? (int) ($report['previous_total_actif_centimes'] ?? 0) : null
        );
        $body .= $this->statementSection('PASSIF ET CAPITAUX PROPRES', $report['presentation_items'] ?? [], 'actif', true, $previous);
        $body .= $this->totalRow(
            'TOTAL DU PASSIF ET DES CAPITAUX PROPRES',
            (int) ($report['total_passif_centimes'] ?? 0),
            $previous ? (int) ($report['previous_total_passif_centimes'] ?? 0) : null
        );
        return $this->statementTable(
            (string) ($report['current_label'] ?? ''),
            $previous ? (string) $report['previous_label'] : null,
            $body
        );
    }

    /** @param array<string,mixed> $report */
    private function incomeStatementHtml(array $report): string
    {
        $previous = ($report['previous']['exercise_id'] ?? null) !== null;
        $body = $this->statementSection('PRODUITS', $report['presentation_items'] ?? [], 'produit', false, $previous);
        $body .= $this->totalRow(
            'TOTAL DES PRODUITS',
            (int) ($report['current']['products_cents'] ?? 0),
            $previous ? (int) ($report['previous']['products_cents'] ?? 0) : null
        );
        $body .= $this->statementSection('CHARGES', $report['presentation_items'] ?? [], 'charge', false, $previous, true);
        $body .= $this->totalRow(
            'TOTAL DES CHARGES',
            -(int) ($report['current']['expenses_cents'] ?? 0),
            $previous ? -(int) ($report['previous']['expenses_cents'] ?? 0) : null
        );
        $body .= $this->totalRow(
            'RÉSULTAT NET DE L’EXERCICE',
            (int) ($report['current']['result_cents'] ?? 0),
            $previous ? (int) ($report['previous']['result_cents'] ?? 0) : null
        );
        return $this->statementTable(
            (string) ($report['current']['label'] ?? ''),
            $previous ? (string) ($report['previous']['label'] ?? '') : null,
            $body
        );
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    private function statementSection(
        string $title,
        array $items,
        string $type,
        bool $inverse,
        bool $previous,
        bool $negate = false,
    ): string {
        [$labelWidth, $amountWidth] = $this->statementWidths($previous);
        $html = '<tr class="section"><td colspan="' . ($previous ? '3' : '2') . '">'
            . $this->e($title) . '</td></tr>';
        foreach ($items as $row) {
            $matches = (string) ($row['type'] ?? '') === $type;
            if ($inverse ? $matches : !$matches) {
                continue;
            }
            $label = (string) ($row['libelle'] ?? $row['label'] ?? '');
            $number = (string) ($row['numero'] ?? $row['number'] ?? '');
            $displayNumber = $number === 'RÉSULTAT' ? '' : $number;
            $factor = $negate ? -1 : 1;
            $class = ($row['row_kind'] ?? '') === 'subtotal' ? ' class="subtotal"' : '';
            $html .= '<tr' . $class . '><td width="' . $labelWidth . '">'
                . ($displayNumber === '' ? '' : '<span class="code">'
                    . $this->e($displayNumber) . '</span> ')
                . $this->e(($row['row_kind'] ?? '') === 'subtotal' ? mb_strtoupper($label) : $label)
                . '</td><td width="' . $amountWidth . '" align="right">'
                . $this->money($factor * (int) ($row['current_cents'] ?? 0)) . '</td>';
            if ($previous) {
                $html .= '<td width="' . $amountWidth . '" align="right">'
                    . $this->money($factor * (int) ($row['previous_cents'] ?? 0)) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html;
    }

    private function statementTable(string $current, ?string $previous, string $body): string
    {
        [$firstWidth, $amountWidth] = $this->statementWidths($previous !== null);
        return '<table cellpadding="4"><thead><tr><th width="' . $firstWidth . '"></th>'
            . '<th width="' . $amountWidth . '" align="right">' . $this->e($current) . '</th>'
            . ($previous === null ? '' : '<th width="21%" align="right">' . $this->e($previous) . '</th>')
            . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    /** @param array<string,mixed> $report */
    private function cashFlowHtml(array $report): string
    {
        $labels = [
            'exploitation' => 'ACTIVITÉS D’EXPLOITATION',
            'investissement' => 'ACTIVITÉS D’INVESTISSEMENT',
            'financement' => 'ACTIVITÉS DE FINANCEMENT',
            'a_classer' => 'À CLASSER',
        ];
        $grouped = [];
        foreach ($report['statement_items'] ?? [] as $row) {
            $grouped[(string) ($row['category'] ?? 'a_classer')][] = $row;
        }
        $body = '';
        foreach ($labels as $category => $label) {
            if (($grouped[$category] ?? []) === []) {
                continue;
            }
            $body .= '<tr class="section"><td colspan="2">' . $label . '</td></tr>';
            $total = 0;
            foreach ($grouped[$category] as $row) {
                $amount = (int) ($row['amount_cents'] ?? 0);
                $total += $amount;
                $body .= '<tr><td width="80%">' . $this->e((string) ($row['label'] ?? '')) . '</td>'
                    . '<td width="20%" align="right">' . $this->money($amount) . '</td></tr>';
            }
            $body .= '<tr class="subtotal"><td width="80%">' . $label
                . '</td><td width="20%" align="right">'
                . $this->money($total) . '</td></tr>';
        }
        $body .= $this->totalRow('VARIATION NETTE DE LA TRÉSORERIE', (int) ($report['net_change_cents'] ?? 0), null);
        $body .= '<tr><td width="80%">Trésorerie à l’ouverture</td><td width="20%" align="right">'
            . $this->money((int) ($report['opening_cash_cents'] ?? 0)) . '</td></tr>';
        $body .= $this->totalRow('TRÉSORERIE À LA CLÔTURE', (int) ($report['closing_cash_cents'] ?? 0), null);
        return '<table cellpadding="4"><thead><tr><th width="80%"></th><th width="20%" align="right"></th>'
            . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    private function totalRow(string $label, int $current, ?int $previous): string
    {
        [$labelWidth, $amountWidth] = $this->statementWidths($previous !== null);
        return '<tr class="total"><td width="' . $labelWidth . '">' . $this->e($label)
            . '</td><td width="' . $amountWidth . '" align="right">'
            . $this->money($current) . '</td>'
            . ($previous === null ? '' : '<td width="' . $amountWidth
                . '" align="right">' . $this->money($previous) . '</td>')
            . '</tr>';
    }

    /** @param array<string,mixed> $row @param list<string> $keys */
    private function amountCells(array $row, array $keys, string $width): string
    {
        $html = '';
        foreach ($keys as $key) {
            $html .= '<td width="' . $width . '" align="right">'
                . $this->money((int) ($row[$key] ?? 0)) . '</td>';
        }
        return $html . '</tr>';
    }

    /** @return array{string,string} */
    private function statementWidths(bool $previous): array
    {
        return $previous ? ['66%', '17%'] : ['80%', '20%'];
    }

    private function money(int $cents): string
    {
        $absolute = abs($cents);
        $whole = number_format(intdiv($absolute, 100), 0, '.', ' ');
        $value = $whole . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
        return $cents < 0 ? '(' . $value . ')' : $value . '&#160;';
    }

    private function date(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed === false ? $this->e($date) : $parsed->format('d.m.Y');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
