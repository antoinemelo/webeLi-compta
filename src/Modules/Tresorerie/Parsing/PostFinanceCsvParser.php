<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie\Parsing;

use Compta\Modules\Tresorerie\TreasuryException;
use DateTimeImmutable;

final class PostFinanceCsvParser implements StatementParser
{
    public function supports(string $content, string $filename = ''): bool
    {
        return str_contains($content, 'Texte de notification')
            && str_contains($content, 'Crédit')
            && str_contains($content, 'Débit');
    }

    public function parse(string $content, string $filename = ''): ParsedStatement
    {
        if (strlen($content) > 10 * 1024 * 1024) {
            throw new TreasuryException('Fichier PostFinance trop volumineux.');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $rawLines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $metadata = ['iban' => '', 'currency' => '', 'start' => '', 'end' => ''];
        $transactions = [];
        $balances = [];
        $errors = [];
        $inData = false;

        foreach ($rawLines as $lineNumber => $line) {
            if ($inData) {
                if (str_starts_with(trim($line), 'Disclaimer')) {
                    break;
                }
                if (trim($line) === '') {
                    continue;
                }
                $columns = str_getcsv($line, ';', '"', '');
                $bookingDate = $this->date((string) ($columns[0] ?? ''));
                if ($bookingDate === '') {
                    continue;
                }
                $creditRaw = trim((string) ($columns[2] ?? ''));
                $debitRaw = trim((string) ($columns[3] ?? ''));
                if (($creditRaw === '') === ($debitRaw === '')) {
                    $errors[] = 'Ligne ' . ($lineNumber + 1)
                        . ' : crédit ou débit attendu, exclusivement.';
                    continue;
                }
                $amount = $creditRaw !== ''
                    ? abs(Money::toCents($creditRaw))
                    : -abs(Money::toCents($debitRaw));
                if ($amount === 0) {
                    $errors[] = 'Ligne ' . ($lineNumber + 1)
                        . ' : montant nul ignoré.';
                    continue;
                }
                $balanceRaw = trim((string) ($columns[5] ?? ''));
                $balance = $balanceRaw === '' ? null : Money::toCents($balanceRaw);
                $valueDate = $this->date((string) ($columns[4] ?? ''));
                $text = trim((string) ($columns[1] ?? ''));
                $transactions[] = [
                    'date_booking' => $bookingDate,
                    'date_value' => $valueDate,
                    'label' => $text,
                    'counterparty' => '',
                    'communication' => '',
                    'reference_type' => '',
                    'reference' => '',
                    'counterparty_iban' => '',
                    'bank_id' => '',
                    'group_id' => '',
                    'transaction_code' => '',
                    'amount_cents' => $amount,
                    'fee_cents' => 0,
                    'currency' => $metadata['currency'] ?: 'CHF',
                    'balance_after_cents' => $balance,
                    'raw' => ['columns' => $columns],
                ];
                if ($balance !== null) {
                    $balances[] = [
                        'type' => 'ITBD',
                        'date' => $bookingDate,
                        'amount_cents' => $balance,
                        'currency' => $metadata['currency'] ?: 'CHF',
                    ];
                }
                continue;
            }

            if (
                str_starts_with($line, 'Date;')
                && str_contains(mb_strtolower($line), 'notification')
            ) {
                $inData = true;
                continue;
            }
            $value = $this->metadataValue($line);
            if (str_starts_with($line, 'Compte:')) {
                $metadata['iban'] = $this->normalizeIban($value);
            } elseif (str_starts_with($line, 'Monnaie:')) {
                $metadata['currency'] = mb_strtoupper($value);
            } elseif (str_starts_with($line, 'Date de début:')) {
                $metadata['start'] = $this->date($value);
            } elseif (str_starts_with($line, 'Date de fin:')) {
                $metadata['end'] = $this->date($value);
            }
        }
        if (!$inData) {
            throw new TreasuryException('En-tête de mouvements PostFinance introuvable.');
        }
        if ($metadata['iban'] === '') {
            $errors[] = 'IBAN absent du fichier PostFinance.';
        }
        if ($transactions === []) {
            $errors[] = 'Aucun mouvement bancaire reconnu.';
        }
        return new ParsedStatement(
            'postfinance_csv',
            '',
            $metadata['iban'],
            $metadata['currency'] ?: 'CHF',
            $metadata['start'],
            $metadata['end'],
            $transactions,
            $balances,
            $errors,
        );
    }

    private function metadataValue(string $line): string
    {
        $parts = explode(';', $line, 2);
        $value = trim($parts[1] ?? '');
        if (str_starts_with($value, '="') && str_ends_with($value, '"')) {
            $value = substr($value, 2, -1);
        }
        return trim($value, '"');
    }

    private function date(string $value): string
    {
        $value = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!d.m.Y', $value);
        return $parsed !== false && $parsed->format('d.m.Y') === $value
            ? $parsed->format('Y-m-d')
            : '';
    }

    private function normalizeIban(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/', '', $value));
    }
}
