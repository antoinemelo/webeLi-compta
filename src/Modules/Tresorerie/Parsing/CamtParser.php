<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie\Parsing;

use Compta\Modules\Tresorerie\TreasuryException;

abstract class CamtParser implements StatementParser
{
    abstract protected function messageNumber(): string;

    public function supports(string $content, string $filename = ''): bool
    {
        return preg_match(
            '/urn:iso:std:iso:20022:tech:xsd:camt\.' . $this->messageNumber() . '\.001\.\d{2}/',
            $content
        ) === 1;
    }

    public function parse(string $content, string $filename = ''): ParsedStatement
    {
        $root = (new SecureXmlParser())->parse($content);
        $namespace = $root->attribute('xmlns');
        if ($namespace === '') {
            foreach ($root->attributes as $name => $value) {
                if (str_starts_with($name, 'xmlns:')) {
                    $namespace = $value;
                    break;
                }
            }
        }
        if (
            preg_match(
                '/^urn:iso:std:iso:20022:tech:xsd:camt\.'
                . $this->messageNumber() . '\.001\.\d{2}$/',
                $namespace
            ) !== 1
        ) {
            throw new TreasuryException('Namespace CAMT non pris en charge.');
        }
        $containerName = $this->messageNumber() === '053' ? 'Stmt' : 'Ntfctn';
        $containers = $root->descendants($containerName);
        if ($containers === []) {
            throw new TreasuryException("Message CAMT sans {$containerName}.");
        }
        $transactions = [];
        $balances = [];
        $errors = [];
        $iban = '';
        $currency = '';
        $dates = [];
        foreach ($containers as $container) {
            $containerIban = $container->pathValue('Acct', 'Id', 'IBAN');
            if ($iban === '') {
                $iban = $this->iban($containerIban);
            } elseif ($containerIban !== '' && $iban !== $this->iban($containerIban)) {
                $errors[] = 'Le message contient plusieurs comptes bancaires.';
            }
            $containerCurrency = strtoupper($container->pathValue('Acct', 'Ccy'));
            $currency = $currency ?: $containerCurrency;
            foreach ($container->childrenNamed('Bal') as $balance) {
                $amount = $balance->child('Amt');
                $date = $this->dateValue($balance->child('Dt'));
                if ($amount === null || $date === '') {
                    continue;
                }
                $balances[] = [
                    'type' => $balance->pathValue('Tp', 'CdOrPrtry', 'Cd')
                        ?: $balance->pathValue('Tp', 'CdOrPrtry', 'Prtry')
                        ?: 'OTHR',
                    'date' => $date,
                    'amount_cents' => $this->signedAmount(
                        $amount->value(),
                        $balance->child('CdtDbtInd')?->value() ?? 'CRDT'
                    ),
                    'currency' => strtoupper($amount->attribute('Ccy') ?: $currency ?: 'CHF'),
                ];
            }
            foreach ($container->childrenNamed('Ntry') as $entry) {
                array_push($transactions, ...$this->entryTransactions($entry, $currency ?: 'CHF'));
            }
        }
        foreach ($transactions as $transaction) {
            $dates[] = $transaction['date_booking'];
            $currency = $currency ?: $transaction['currency'];
        }
        sort($dates);
        if ($transactions === []) {
            $errors[] = 'Aucun mouvement bancaire reconnu.';
        }
        return new ParsedStatement(
            'camt' . $this->messageNumber(),
            $namespace,
            $iban,
            $currency ?: 'CHF',
            $dates[0] ?? '',
            $dates === [] ? '' : $dates[array_key_last($dates)],
            $transactions,
            $balances,
            $errors,
        );
    }

    /** @return list<array<string,mixed>> */
    private function entryTransactions(SecureXmlNode $entry, string $defaultCurrency): array
    {
        $booking = $this->dateValue($entry->child('BookgDt'));
        $valueDate = $this->dateValue($entry->child('ValDt'));
        $entryAmount = $entry->child('Amt');
        if ($booking === '' || $entryAmount === null) {
            return [];
        }
        $indicator = $entry->child('CdtDbtInd')?->value() ?? 'CRDT';
        $currency = strtoupper($entryAmount->attribute('Ccy') ?: $defaultCurrency);
        $entryCents = $this->signedAmount($entryAmount->value(), $indicator);
        $details = $entry->descendants('TxDtls');
        if ($details === []) {
            $details = [$entry];
        }
        $result = [];
        foreach ($details as $index => $detail) {
            $detailAmount = $detail->path('AmtDtls', 'TxAmt', 'Amt')
                ?? $detail->path('AmtDtls', 'InstdAmt', 'Amt')
                ?? ($details === [$entry] ? $entryAmount : null);
            $amount = $detailAmount === null
                ? ($index === 0 ? $entryCents : 0)
                : $this->signedAmount($detailAmount->value(), $indicator);
            if ($amount === 0) {
                continue;
            }
            $refs = $detail->child('Refs');
            $remittance = $detail->child('RmtInf');
            $referenceNode = $remittance?->path('Strd', 'CdtrRefInf', 'Ref');
            $referenceType = $remittance?->pathValue(
                'Strd',
                'CdtrRefInf',
                'Tp',
                'CdOrPrtry',
                'Cd'
            ) ?? '';
            $name = $indicator === 'CRDT'
                ? (
                    $detail->pathValue('RltdPties', 'Dbtr', 'Nm')
                    ?: $detail->pathValue('RltdPties', 'Dbtr', 'Pty', 'Nm')
                )
                : (
                    $detail->pathValue('RltdPties', 'Cdtr', 'Nm')
                    ?: $detail->pathValue('RltdPties', 'Cdtr', 'Pty', 'Nm')
                );
            $counterpartyIban = $indicator === 'CRDT'
                ? $detail->pathValue('RltdPties', 'DbtrAcct', 'Id', 'IBAN')
                : $detail->pathValue('RltdPties', 'CdtrAcct', 'Id', 'IBAN');
            $unstructured = array_map(
                static fn (SecureXmlNode $node): string => $node->value(),
                $remittance?->childrenNamed('Ustrd') ?? []
            );
            $charges = 0;
            $chargeNodes = [
                ...$detail->descendants('Chrgs'),
                ...$detail->descendants('ChrgsInf'),
            ];
            foreach ($chargeNodes as $charge) {
                $chargeAmount = $charge->child('Amt')
                    ?? $charge->child('TtlChrgsAndTaxAmt');
                if ($chargeAmount !== null) {
                    $charges += abs(Money::toCents($chargeAmount->value()));
                }
            }
            $transactionCode = implode('/', array_filter([
                $entry->pathValue('BkTxCd', 'Domn', 'Cd'),
                $entry->pathValue('BkTxCd', 'Domn', 'Fmly', 'Cd'),
                $entry->pathValue('BkTxCd', 'Domn', 'Fmly', 'SubFmlyCd'),
                $entry->pathValue('BkTxCd', 'Prtry', 'Cd'),
            ]));
            $result[] = [
                'date_booking' => $booking,
                'date_value' => $valueDate,
                'label' => $entry->child('AddtlNtryInf')?->value()
                    ?: implode(' ', $unstructured),
                'counterparty' => $name,
                'communication' => implode("\n", $unstructured),
                'reference_type' => strtoupper($referenceType),
                'reference' => $referenceNode?->value() ?? '',
                'counterparty_iban' => $this->iban($counterpartyIban),
                'bank_id' => $refs?->pathValue('AcctSvcrRef')
                    ?: $refs?->pathValue('EndToEndId')
                    ?: $entry->child('AcctSvcrRef')?->value()
                    ?: $entry->child('NtryRef')?->value()
                    ?: '',
                'group_id' => $refs?->pathValue('MsgId') ?: $entry->child('NtryRef')?->value() ?: '',
                'transaction_code' => $transactionCode,
                'amount_cents' => $amount,
                'fee_cents' => $charges,
                'currency' => strtoupper($detailAmount?->attribute('Ccy') ?: $currency),
                'balance_after_cents' => null,
                'raw' => ['entry_index' => $index],
            ];
        }
        return $result;
    }

    private function dateValue(?SecureXmlNode $node): string
    {
        if ($node === null) {
            return '';
        }
        $value = $node->pathValue('Dt') ?: $node->pathValue('DtTm') ?: $node->value();
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 ? substr($value, 0, 10) : '';
    }

    private function signedAmount(string $amount, string $indicator): int
    {
        $cents = abs(Money::toCents($amount));
        return strtoupper($indicator) === 'DBIT' ? -$cents : $cents;
    }

    private function iban(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $value));
    }
}
