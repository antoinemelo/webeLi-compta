<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie\Http;

use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Request;
use DateTimeImmutable;

final class TreasuryInputValidator
{
    public function marketExercise(Request $request): int
    {
        $errors = [];
        foreach (array_keys($request->query) as $key) {
            if ($key !== 'exercise_id') {
                $errors[(string) $key][] = 'Paramètre non autorisé.';
            }
        }
        $raw = (string) ($request->query['exercise_id'] ?? '');
        $exerciseId = preg_match('/^[1-9][0-9]*$/', $raw) === 1 ? (int) $raw : 0;
        if ($exerciseId < 1) {
            $errors['exercise_id'][] = 'Identifiant d’exercice positif attendu.';
        }
        $this->fail($errors);
        return $exerciseId;
    }

    /** @return array{treasury_account_id:int,filename:string,content:string} */
    public function import(Request $request): array
    {
        $data = $request->input();
        $errors = [];
        $accountId = $this->positiveInt($data, 'treasury_account_id', $errors);
        $filename = trim((string) ($data['filename'] ?? ''));
        $encoded = $data['content_base64'] ?? null;
        $content = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($filename === '') {
            $errors['filename'][] = 'Nom de fichier requis.';
        }
        if ($content === false || strlen($content) > 10 * 1024 * 1024) {
            $errors['content_base64'][] = 'Relevé base64 invalide ou supérieur à 10 Mo.';
        }
        $this->fail($errors);
        return [
            'treasury_account_id' => $accountId,
            'filename' => basename($filename),
            'content' => (string) $content,
        ];
    }

    public function identifier(Request $request, string $field): int
    {
        $errors = [];
        $id = $this->positiveInt($request->input(), $field, $errors);
        $this->fail($errors);
        return $id;
    }

    /** @return array<string,mixed> */
    public function reconciliation(Request $request): array
    {
        $data = $request->input();
        $errors = [];
        $bank = $this->positiveIntList($data['bank_line_ids'] ?? null, 'bank_line_ids', $errors);
        $accounting = $this->positiveIntList(
            $data['accounting_line_ids'] ?? null,
            'accounting_line_ids',
            $errors
        );
        $result = [
            'treasury_account_id' => $this->positiveInt(
                $data,
                'treasury_account_id',
                $errors
            ),
            'bank_line_ids' => $bank,
            'accounting_line_ids' => $accounting,
            'label' => trim((string) ($data['label'] ?? '')),
            'tolerance_cents' => is_int($data['tolerance_cents'] ?? null)
                ? (int) $data['tolerance_cents'] : -1,
        ];
        if ($result['tolerance_cents'] < 0) {
            $errors['tolerance_cents'][] = 'Tolérance positive ou nulle requise.';
        }
        $this->fail($errors);
        return $result;
    }

    /** @return array{reconciliation_id:int,version:int} */
    public function reconciliationCancellation(Request $request): array
    {
        return $this->ids($request, ['reconciliation_id', 'version']);
    }

    /** @return array<string,mixed> */
    public function suggestion(Request $request): array
    {
        $data = $request->input();
        $errors = [];
        $confidence = is_int($data['confidence'] ?? null)
            ? (int) $data['confidence'] : -1;
        $label = trim((string) ($data['label'] ?? ''));
        if ($confidence < 0 || $confidence > 100) {
            $errors['confidence'][] = 'Confiance entre 0 et 100 requise.';
        }
        if ($label === '') {
            $errors['label'][] = 'Libellé requis.';
        }
        $result = [
            'bank_line_id' => $this->positiveInt($data, 'bank_line_id', $errors),
            'counterpart_account_id' => $this->positiveInt(
                $data,
                'counterpart_account_id',
                $errors
            ),
            'label' => $label,
            'confidence' => $confidence,
            'reason' => trim((string) ($data['reason'] ?? '')),
        ];
        $this->fail($errors);
        return $result;
    }

    /** @return array{suggestion_id:int,exercise_id:int,journal_id:int} */
    public function suggestionAcceptance(Request $request): array
    {
        return $this->ids($request, ['suggestion_id', 'exercise_id', 'journal_id']);
    }

    /** @return array<string,mixed> */
    public function payment(Request $request): array
    {
        $data = $request->input();
        $errors = [];
        $direction = (string) ($data['direction'] ?? '');
        $date = (string) ($data['date'] ?? '');
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'CHF')));
        if (!in_array($direction, ['encaissement', 'decaissement'], true)) {
            $errors['direction'][] = 'Sens de paiement invalide.';
        }
        if (!$this->validDate($date)) {
            $errors['date'][] = 'Date AAAA-MM-JJ requise.';
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $errors['currency'][] = 'Devise de paiement invalide.';
        }
        $bankLineId = $data['bank_line_id'] ?? null;
        if ($bankLineId !== null && (!is_int($bankLineId) || $bankLineId < 1)) {
            $errors['bank_line_id'][] = 'Identifiant positif requis.';
        }
        $rateId = $data['exchange_rate_id'] ?? null;
        if ($rateId !== null && (!is_int($rateId) || $rateId < 1)) {
            $errors['exchange_rate_id'][] = 'Identifiant de taux positif requis.';
        }
        $contactId = $data['contact_id'] ?? null;
        if (
            $contactId !== null
            && (!is_int($contactId) || $contactId < 1)
        ) {
            $errors['contact_id'][] = 'Identifiant de contact positif requis.';
        }
        $result = [
            'contact_id' => $contactId,
            'direction' => $direction,
            'date' => $date,
            'amount_cents' => $this->positiveInt($data, 'amount_cents', $errors),
            'reference' => trim((string) ($data['reference'] ?? '')),
            'ledger_account_id' => $this->positiveInt(
                $data,
                'ledger_account_id',
                $errors
            ),
            'treasury_account_id' => $this->positiveInt(
                $data,
                'treasury_account_id',
                $errors
            ),
            'collective_account_id' => $this->positiveInt(
                $data,
                'collective_account_id',
                $errors
            ),
            'bank_line_id' => $bankLineId,
            'currency' => $currency,
            'exchange_rate_id' => $rateId,
        ];
        $this->fail($errors);
        return $result;
    }

    /** @return array{payment_id:int,document_id:int,amount_cents:int} */
    public function allocation(Request $request): array
    {
        return $this->ids($request, ['payment_id', 'document_id', 'amount_cents']);
    }

    /** @return array<string,mixed> */
    public function batch(Request $request): array
    {
        $data = $request->input();
        $errors = [];
        $executionDate = (string) ($data['execution_date'] ?? '');
        $key = trim((string) ($data['idempotency_key'] ?? ''));
        if (!$this->validDate($executionDate)) {
            $errors['execution_date'][] = 'Date AAAA-MM-JJ requise.';
        }
        if ($key === '') {
            $errors['idempotency_key'][] = 'Clé de préparation requise.';
        }
        $orders = [];
        if (!is_array($data['orders'] ?? null) || $data['orders'] === []) {
            $errors['orders'][] = 'Sélectionnez au moins une dette.';
        } else {
            foreach (array_values($data['orders']) as $index => $order) {
                if (!is_array($order)) {
                    $errors["orders.{$index}"][] = 'Ordre invalide.';
                    continue;
                }
                $orders[] = [
                    'document_id' => $this->positiveInt(
                        $order,
                        'document_id',
                        $errors,
                        "orders.{$index}.document_id"
                    ),
                    'amount_cents' => $this->positiveInt(
                        $order,
                        'amount_cents',
                        $errors,
                        "orders.{$index}.amount_cents"
                    ),
                ];
            }
        }
        $result = [
            'treasury_account_id' => $this->positiveInt(
                $data,
                'treasury_account_id',
                $errors
            ),
            'execution_date' => $executionDate,
            'idempotency_key' => $key,
            'orders' => $orders,
        ];
        $this->fail($errors);
        return $result;
    }

    /** @return array{batch_id:int,version:int} */
    public function batchExport(Request $request): array
    {
        return $this->ids($request, ['batch_id', 'version']);
    }

    /** @return array<string,mixed> */
    public function batchConfirmation(Request $request): array
    {
        $data = $request->input();
        $errors = [];
        $result = [];
        foreach (['batch_id', 'bank_line_id', 'exercise_id', 'journal_id'] as $field) {
            $result[$field] = $this->positiveInt($data, $field, $errors);
        }
        $feeAccount = $data['fee_account_id'] ?? null;
        if ($feeAccount !== null && (!is_int($feeAccount) || $feeAccount < 1)) {
            $errors['fee_account_id'][] = 'Compte de frais invalide.';
        }
        $result['fee_account_id'] = $feeAccount;
        $this->fail($errors);
        return $result;
    }

    /** @param list<string> $fields @return array<string,int> */
    private function ids(Request $request, array $fields): array
    {
        $data = $request->input();
        $errors = [];
        $result = [];
        foreach ($fields as $field) {
            $result[$field] = $this->positiveInt($data, $field, $errors);
        }
        $this->fail($errors);
        return $result;
    }

    /** @param array<string,mixed> $data @param array<string,list<string>> $errors */
    private function positiveInt(
        array $data,
        string $field,
        array &$errors,
        ?string $errorField = null,
    ): int {
        $value = $data[$field] ?? null;
        if (!is_int($value) || $value < 1) {
            $errors[$errorField ?? $field][] = 'Entier positif requis.';
            return 0;
        }
        return $value;
    }

    /** @param array<string,list<string>> $errors @return list<int> */
    private function positiveIntList(mixed $value, string $field, array &$errors): array
    {
        if (!is_array($value) || $value === []) {
            $errors[$field][] = 'Sélection non vide requise.';
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            if (!is_int($item) || $item < 1) {
                $errors[$field][] = 'Identifiants positifs requis.';
                return [];
            }
            $result[] = $item;
        }
        return array_values(array_unique($result));
    }

    private function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /** @param array<string,list<string>> $errors */
    private function fail(array $errors): void
    {
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
    }
}
