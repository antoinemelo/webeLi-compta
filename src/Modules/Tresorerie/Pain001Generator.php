<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie;

final class Pain001Generator
{
    public const PROFILE = 'pain.001.001.09.ch.03';
    private const NAMESPACE = 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.09';

    /**
     * @param array{
     *   message_id:string,created_at:string,execution_date:string,currency:string,
     *   debtor_name:string,debtor_iban:string,debtor_bic:string
     * } $batch
     * @param list<array{
     *   instruction_id:string,end_to_end_id:string,amount_cents:int,
     *   creditor_name:string,creditor_iban:string,creditor_bic:string,
     *   address:array<string,mixed>,remittance:string
     * }> $orders
     */
    public function generate(array $batch, array $orders): string
    {
        if ($orders === []) {
            throw new TreasuryException('Un fichier pain.001 exige au moins un ordre.');
        }
        $debtorIban = BankCoordinates::assertIban($batch['debtor_iban']);
        $debtorBic = BankCoordinates::assertBic($batch['debtor_bic']);
        $currency = strtoupper(trim($batch['currency']));
        if (!in_array($currency, ['CHF', 'EUR'], true)) {
            throw new TreasuryException('Devise pain.001 non prise en charge.');
        }
        $total = array_sum(array_column($orders, 'amount_cents'));
        if ($total <= 0) {
            throw new TreasuryException('Montant du lot pain.001 invalide.');
        }

        $transactions = '';
        foreach ($orders as $order) {
            if ($order['amount_cents'] <= 0) {
                throw new TreasuryException('Montant d’ordre pain.001 invalide.');
            }
            $creditorIban = BankCoordinates::assertIban($order['creditor_iban']);
            $creditorBic = BankCoordinates::assertBic($order['creditor_bic']);
            $agent = $creditorBic === ''
                ? ''
                : '<CdtrAgt><FinInstnId><BICFI>' . $this->xml($creditorBic)
                    . '</BICFI></FinInstnId></CdtrAgt>';
            $transactions .= '<CdtTrfTxInf>'
                . '<PmtId><InstrId>' . $this->identifier($order['instruction_id'])
                . '</InstrId><EndToEndId>' . $this->identifier($order['end_to_end_id'])
                . '</EndToEndId></PmtId>'
                . '<Amt><InstdAmt Ccy="' . $currency . '">'
                . $this->amount($order['amount_cents']) . '</InstdAmt></Amt>'
                . '<ChrgBr>SLEV</ChrgBr>'
                . $agent
                . '<Cdtr><Nm>' . $this->text($order['creditor_name'], 70) . '</Nm>'
                . $this->address($order['address'])
                . '</Cdtr><CdtrAcct><Id><IBAN>' . $this->xml($creditorIban)
                . '</IBAN></Id></CdtrAcct>'
                . '<RmtInf><Ustrd>' . $this->text($order['remittance'], 140)
                . '</Ustrd></RmtInf></CdtTrfTxInf>';
        }

        $debtorAgent = $debtorBic === ''
            ? '<FinInstnId><Othr><Id>NOTPROVIDED</Id></Othr></FinInstnId>'
            : '<FinInstnId><BICFI>' . $this->xml($debtorBic) . '</BICFI></FinInstnId>';
        $messageId = $this->identifier($batch['message_id']);
        $count = count($orders);
        $controlSum = $this->amount($total);

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Document xmlns="' . self::NAMESPACE . '"><CstmrCdtTrfInitn>'
            . '<GrpHdr><MsgId>' . $messageId . '</MsgId><CreDtTm>'
            . $this->xml($batch['created_at']) . '</CreDtTm><NbOfTxs>' . $count
            . '</NbOfTxs><CtrlSum>' . $controlSum . '</CtrlSum><InitgPty><Nm>'
            . $this->text($batch['debtor_name'], 70) . '</Nm></InitgPty></GrpHdr>'
            . '<PmtInf><PmtInfId>' . $messageId . '</PmtInfId><PmtMtd>TRF</PmtMtd>'
            . '<BtchBookg>true</BtchBookg><NbOfTxs>' . $count . '</NbOfTxs>'
            . '<CtrlSum>' . $controlSum . '</CtrlSum><PmtTpInf><SvcLvl><Cd>NURG</Cd>'
            . '</SvcLvl></PmtTpInf><ReqdExctnDt><Dt>'
            . $this->xml($batch['execution_date']) . '</Dt></ReqdExctnDt>'
            . '<Dbtr><Nm>' . $this->text($batch['debtor_name'], 70) . '</Nm></Dbtr>'
            . '<DbtrAcct><Id><IBAN>' . $this->xml($debtorIban)
            . '</IBAN></Id><Ccy>' . $currency . '</Ccy></DbtrAcct>'
            . '<DbtrAgt>' . $debtorAgent . '</DbtrAgt>'
            . $transactions . '</PmtInf></CstmrCdtTrfInitn></Document>';
    }

    /** @param array<string,mixed> $address */
    private function address(array $address): string
    {
        $country = strtoupper(trim((string) ($address['pays'] ?? 'CH')));
        $lines = array_values(array_filter([
            trim((string) ($address['ligne1'] ?? '')),
            trim((string) ($address['ligne2'] ?? '')),
            trim((string) ($address['code_postal'] ?? ''))
                . ' ' . trim((string) ($address['localite'] ?? '')),
        ], static fn (string $line): bool => trim($line) !== ''));
        $xml = '<PstlAdr><Ctry>' . $this->xml($country) . '</Ctry>';
        foreach (array_slice($lines, 0, 2) as $line) {
            $xml .= '<AdrLine>' . $this->text($line, 70) . '</AdrLine>';
        }
        return $xml . '</PstlAdr>';
    }

    private function amount(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad(
            (string) ($cents % 100),
            2,
            '0',
            STR_PAD_LEFT
        );
    }

    private function identifier(string $value): string
    {
        $value = (string) preg_replace('/[^A-Za-z0-9+?\/:().,\x27 -]/', '-', trim($value));
        return $this->xml(substr($value === '' ? 'NOTPROVIDED' : $value, 0, 35));
    }

    private function text(string $value, int $length): string
    {
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', trim($value));
        return $this->xml(mb_substr($value === '' ? 'Non renseigné' : $value, 0, $length));
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
