<?php
declare(strict_types=1);

namespace Compta\Modules\Compta;

use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Request;
use DateTimeImmutable;

final class AccountingInputValidator
{
    /** @return array{exercise_id:int,journal_id:int,date:string,idempotency_key:string} */
    public function exchangeRevaluation(Request $request): array
    {
        $data = $this->only(
            $request,
            ['exercise_id', 'journal_id', 'date', 'idempotency_key']
        );
        $key = trim((string) ($data['idempotency_key'] ?? ''));
        if ($key === '') {
            throw ApiException::validation([
                'idempotency_key' => ['Clé idempotente requise.'],
            ]);
        }
        return [
            'exercise_id' => $this->positiveInteger(
                $data['exercise_id'] ?? null,
                'exercise_id'
            ),
            'journal_id' => $this->positiveInteger(
                $data['journal_id'] ?? null,
                'journal_id'
            ),
            'date' => $this->requiredDate($data['date'] ?? null, 'date'),
            'idempotency_key' => $key,
        ];
    }

    /** @return array{revaluation_id:int,date:string} */
    public function exchangeRevaluationReversal(Request $request): array
    {
        $data = $this->only($request, ['revaluation_id', 'date']);
        return [
            'revaluation_id' => $this->positiveInteger(
                $data['revaluation_id'] ?? null,
                'revaluation_id'
            ),
            'date' => $this->requiredDate($data['date'] ?? null, 'date'),
        ];
    }

    /** @return array{exercise_id:int,account_id:?int,date_start:?string,date_end:?string,vat_statement_id:?int} */
    public function query(Request $request): array
    {
        $this->rejectUnknown(array_keys($request->query), [
            'exercise_id', 'account_id', 'date_start', 'date_end',
            'vat_statement_id',
        ]);
        $exerciseId = $this->positiveInteger(
            $request->query['exercise_id'] ?? null,
            'exercise_id'
        );
        $rawAccount = $request->query['account_id'] ?? null;
        $rawVatStatement = $request->query['vat_statement_id'] ?? null;
        $dateStart = $this->optionalDate(
            $request->query['date_start'] ?? null,
            'date_start'
        );
        $dateEnd = $this->optionalDate(
            $request->query['date_end'] ?? null,
            'date_end'
        );
        if ($dateStart !== null && $dateEnd !== null && $dateStart > $dateEnd) {
            throw ApiException::validation([
                'date_end' => ['La fin doit suivre le début.'],
            ]);
        }
        return [
            'exercise_id' => $exerciseId,
            'account_id' => $rawAccount === null || $rawAccount === ''
                ? null
                : $this->positiveInteger($rawAccount, 'account_id'),
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'vat_statement_id' =>
                $rawVatStatement === null || $rawVatStatement === ''
                    ? null
                    : $this->positiveInteger(
                        $rawVatStatement,
                        'vat_statement_id'
                    ),
        ];
    }

    /** @return array{start:string,end:string} */
    public function vatPeriod(Request $request): array
    {
        $data = $this->only($request, ['start', 'end']);
        $start = $this->requiredDate($data['start'] ?? null, 'start');
        $end = $this->requiredDate($data['end'] ?? null, 'end');
        if ($start > $end) {
            throw ApiException::validation([
                'end' => ['La fin doit suivre le début.'],
            ]);
        }
        return ['start' => $start, 'end' => $end];
    }

    /** @return array{period_id:int,corrects_id:?int} */
    public function vatPreparation(Request $request): array
    {
        $data = $this->only($request, ['period_id', 'corrects_id']);
        return [
            'period_id' => $this->positiveInteger(
                $data['period_id'] ?? null,
                'period_id'
            ),
            'corrects_id' => ($data['corrects_id'] ?? null) === null
                ? null
                : $this->positiveInteger(
                    $data['corrects_id'],
                    'corrects_id'
                ),
        ];
    }

    /** @return array{statement_id:int} */
    public function vatStatement(Request $request): array
    {
        $data = $this->only($request, ['statement_id']);
        return [
            'statement_id' => $this->positiveInteger(
                $data['statement_id'] ?? null,
                'statement_id'
            ),
        ];
    }

    /** @return array{exercise_id:int,code:string,status:string,note:string,version:int} */
    public function closingControl(Request $request): array
    {
        $data = $this->only($request, [
            'exercise_id', 'code', 'status', 'note', 'version',
        ]);
        return [
            'exercise_id' => $this->positiveInteger(
                $data['exercise_id'] ?? null,
                'exercise_id'
            ),
            'code' => trim((string) ($data['code'] ?? '')),
            'status' => trim((string) ($data['status'] ?? '')),
            'note' => trim((string) ($data['note'] ?? '')),
            'version' => $this->integer($data['version'] ?? null, 'version', 0),
        ];
    }

    /** @return array{exercise_id:int,period_id:int,status:string,version:int} */
    public function periodStatus(Request $request): array
    {
        $data = $this->only($request, [
            'exercise_id', 'period_id', 'status', 'version',
        ]);
        $status = (string) ($data['status'] ?? '');
        if (!in_array($status, ['ouverte', 'fermee'], true)) {
            throw ApiException::validation([
                'status' => ['Statut de période invalide.'],
            ]);
        }
        return [
            'exercise_id' => $this->positiveInteger(
                $data['exercise_id'] ?? null,
                'exercise_id'
            ),
            'period_id' => $this->positiveInteger(
                $data['period_id'] ?? null,
                'period_id'
            ),
            'status' => $status,
            'version' => $this->positiveInteger(
                $data['version'] ?? null,
                'version'
            ),
        ];
    }

    /** @return array{exercise_id:int,label:string,nature:string,amount_cents:int,note:string,idempotency_key:string} */
    public function taxAdjustment(Request $request): array
    {
        $data = $this->only($request, [
            'exercise_id', 'label', 'nature', 'amount_cents', 'note',
            'idempotency_key',
        ]);
        $amount = $data['amount_cents'] ?? null;
        if (!is_int($amount) || $amount < 0) {
            throw ApiException::validation([
                'amount_cents' => ['Montant entier positif ou nul requis.'],
            ]);
        }
        $label = trim((string) ($data['label'] ?? ''));
        $key = trim((string) ($data['idempotency_key'] ?? ''));
        if ($label === '' || $key === '' || strlen($key) > 190) {
            throw ApiException::validation([
                'adjustment' => ['Libellé et clé idempotente requis.'],
            ]);
        }
        return [
            'exercise_id' => $this->positiveInteger(
                $data['exercise_id'] ?? null,
                'exercise_id'
            ),
            'label' => $label,
            'nature' => trim((string) ($data['nature'] ?? '')),
            'amount_cents' => $amount,
            'note' => trim((string) ($data['note'] ?? '')),
            'idempotency_key' => $key,
        ];
    }

    /** @return array{adjustment_id:int,status:string,version:int} */
    public function taxAdjustmentStatus(Request $request): array
    {
        $data = $this->only($request, [
            'adjustment_id', 'status', 'version',
        ]);
        return [
            'adjustment_id' => $this->positiveInteger(
                $data['adjustment_id'] ?? null,
                'adjustment_id'
            ),
            'status' => trim((string) ($data['status'] ?? '')),
            'version' => $this->positiveInteger(
                $data['version'] ?? null,
                'version'
            ),
        ];
    }

    /** @return array{exercise_id:int,type:string,date_start:string,date_end:string} */
    public function archive(Request $request): array
    {
        $data = $this->only($request, [
            'exercise_id', 'type', 'date_start', 'date_end',
        ]);
        $start = $this->requiredDate(
            $data['date_start'] ?? null,
            'date_start'
        );
        $end = $this->requiredDate($data['date_end'] ?? null, 'date_end');
        if ($start > $end) {
            throw ApiException::validation([
                'date_end' => ['La fin doit suivre le début.'],
            ]);
        }
        return [
            'exercise_id' => $this->positiveInteger(
                $data['exercise_id'] ?? null,
                'exercise_id'
            ),
            'type' => trim((string) ($data['type'] ?? '')),
            'date_start' => $start,
            'date_end' => $end,
        ];
    }

    public function queryId(Request $request, string $field): int
    {
        $this->rejectUnknown(array_keys($request->query), [$field]);
        return $this->positiveInteger(
            $request->query[$field] ?? null,
            $field
        );
    }

    /** @return array{exercise_id:int,type:string,date_start:string,date_end:string} */
    public function reportExport(Request $request): array
    {
        $this->rejectUnknown(array_keys($request->query), [
            'exercise_id', 'type', 'date_start', 'date_end',
        ]);
        $type = trim((string) ($request->query['type'] ?? ''));
        if (!in_array($type, [
            'journal', 'grand_livre', 'balance', 'bilan',
            'resultat', 'flux_tresorerie',
        ], true)) {
            throw ApiException::validation([
                'type' => ['Type de rapport invalide.'],
            ]);
        }
        $start = $this->requiredDate(
            $request->query['date_start'] ?? null,
            'date_start'
        );
        $end = $this->requiredDate(
            $request->query['date_end'] ?? null,
            'date_end'
        );
        if ($start > $end) {
            throw ApiException::validation([
                'date_end' => ['La fin doit suivre le début.'],
            ]);
        }
        return [
            'exercise_id' => $this->positiveInteger(
                $request->query['exercise_id'] ?? null,
                'exercise_id'
            ),
            'type' => $type,
            'date_start' => $start,
            'date_end' => $end,
        ];
    }

    /** @return array<string,mixed> */
    public function entry(Request $request): array
    {
        $data = $this->only($request, [
            'exercise_id', 'journal_id', 'date', 'label', 'reference',
            'attachment_reference', 'validate', 'lines',
        ]);
        $errors = [];
        foreach (['exercise_id', 'journal_id'] as $field) {
            if (!is_int($data[$field] ?? null) || $data[$field] < 1) {
                $errors[$field][] = 'Identifiant positif requis.';
            }
        }
        $date = (string) ($data['date'] ?? '');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            $errors['date'][] = 'Date AAAA-MM-JJ valide requise.';
        }
        if (!is_bool($data['validate'] ?? null)) {
            $errors['validate'][] = 'Booléen requis.';
        }
        if (!is_array($data['lines'] ?? null) || count($data['lines']) < 2) {
            $errors['lines'][] = 'Deux lignes au minimum sont requises.';
        }
        $lines = [];
        foreach (is_array($data['lines'] ?? null) ? $data['lines'] : [] as $index => $line) {
            if (!is_array($line)) {
                $errors["lines.{$index}"][] = 'Ligne invalide.';
                continue;
            }
            $unknown = array_diff(
                array_keys($line),
                ['account_id', 'label', 'debit_cents', 'credit_cents']
            );
            if ($unknown !== []) {
                $errors["lines.{$index}"][] = 'Champ de ligne inconnu.';
            }
            $accountId = $line['account_id'] ?? null;
            $debit = $line['debit_cents'] ?? null;
            $credit = $line['credit_cents'] ?? null;
            if (!is_int($accountId) || $accountId < 1) {
                $errors["lines.{$index}.account_id"][] = 'Compte requis.';
            }
            if (!is_int($debit) || $debit < 0 || !is_int($credit) || $credit < 0) {
                $errors["lines.{$index}"][] = 'Montants entiers positifs requis.';
            } elseif (($debit > 0) === ($credit > 0)) {
                $errors["lines.{$index}"][] = 'Renseignez exactement un débit ou un crédit.';
            }
            $lines[] = [
                'account_id' => (int) $accountId,
                'label' => is_string($line['label'] ?? null)
                    ? trim($line['label'])
                    : '',
                'debit_cents' => (int) $debit,
                'credit_cents' => (int) $credit,
            ];
        }
        if (
            count(array_unique(array_column($lines, 'account_id'))) < 2
        ) {
            $errors['lines'][] = 'Deux comptes distincts au minimum sont requis.';
        }
        $this->fail($errors);
        return [
            'exercise_id' => (int) $data['exercise_id'],
            'journal_id' => (int) $data['journal_id'],
            'date' => $date,
            'label' => trim((string) ($data['label'] ?? '')),
            'reference' => trim((string) ($data['reference'] ?? '')),
            'attachment_reference' => trim(
                (string) ($data['attachment_reference'] ?? '')
            ),
            'validate' => (bool) $data['validate'],
            'lines' => $lines,
        ];
    }

    /** @return list<array{id:int,label:string,version:int}> */
    public function types(Request $request): array
    {
        $data = $this->only($request, ['types']);
        if (!is_array($data['types'] ?? null) || $data['types'] === []) {
            throw ApiException::validation(['types' => ['Liste requise.']]);
        }
        $rows = [];
        foreach ($data['types'] as $index => $row) {
            if (
                !is_array($row)
                || array_diff(array_keys($row), ['id', 'label', 'version']) !== []
                || !is_int($row['id'] ?? null)
                || !is_int($row['version'] ?? null)
                || !is_string($row['label'] ?? null)
                || trim($row['label']) === ''
            ) {
                throw ApiException::validation([
                    "types.{$index}" => ['Type de compte invalide.'],
                ]);
            }
            $rows[] = [
                'id' => $row['id'],
                'label' => trim($row['label']),
                'version' => $row['version'],
            ];
        }
        return $rows;
    }

    /** @return list<string> */
    public function senseRules(Request $request): array
    {
        $data = $this->only($request, ['prefixes']);
        if (!is_array($data['prefixes'] ?? null)) {
            throw ApiException::validation(['prefixes' => ['Liste requise.']]);
        }
        $prefixes = [];
        foreach ($data['prefixes'] as $prefix) {
            if (!is_string($prefix)) {
                throw ApiException::validation([
                    'prefixes' => ['Préfixes textuels requis.'],
                ]);
            }
            $prefixes[] = trim($prefix);
        }
        return $prefixes;
    }

    /** @return array<string,mixed> */
    public function rubric(Request $request): array
    {
        $data = $this->only($request, [
            'action', 'id', 'structure_level', 'code', 'label', 'type',
            'parent_id', 'position', 'version', 'ordered_ids',
        ]);
        $action = (string) ($data['action'] ?? '');
        if (!in_array($action, ['save', 'delete', 'reorder'], true)) {
            throw ApiException::validation(['action' => ['Action invalide.']]);
        }
        return [
            'action' => $action,
            'id' => $this->integer($data['id'] ?? 0, 'id', 0),
            'structure_level' => (string) ($data['structure_level'] ?? ''),
            'code' => (string) ($data['code'] ?? ''),
            'label' => (string) ($data['label'] ?? ''),
            'type' => (string) ($data['type'] ?? ''),
            'parent_id' => $this->nullablePositiveInteger(
                $data['parent_id'] ?? null,
                'parent_id'
            ),
            'position' => $this->integer($data['position'] ?? 0, 'position', 0),
            'version' => $this->integer($data['version'] ?? 0, 'version', 0),
            'ordered_ids' => $this->positiveIntegerList(
                $data['ordered_ids'] ?? [],
                'ordered_ids'
            ),
        ];
    }

    /** @return array<string,mixed> */
    public function account(Request $request): array
    {
        $data = $this->only($request, [
            'action', 'id', 'number', 'label', 'sense_mode', 'rubric_id',
            'version', 'ordered_ids', 'accounts',
        ]);
        $action = (string) ($data['action'] ?? '');
        if (!in_array($action, ['save', 'save_batch', 'delete', 'reorder'], true)) {
            throw ApiException::validation(['action' => ['Action invalide.']]);
        }
        $accounts = [];
        if ($action === 'save_batch') {
            if (!is_array($data['accounts'] ?? null) || $data['accounts'] === []) {
                throw ApiException::validation([
                    'accounts' => ['Au moins un compte modifié est requis.'],
                ]);
            }
            foreach ($data['accounts'] as $index => $account) {
                if (!is_array($account)) {
                    throw ApiException::validation([
                        "accounts.{$index}" => ['Compte invalide.'],
                    ]);
                }
                $accounts[] = [
                    'id' => $this->integer($account['id'] ?? 0, "accounts.{$index}.id", 1),
                    'number' => (string) ($account['number'] ?? ''),
                    'label' => (string) ($account['label'] ?? ''),
                    'sense_mode' => (string) ($account['sense_mode'] ?? 'automatique'),
                    'rubric_id' => $this->nullablePositiveInteger(
                        $account['rubric_id'] ?? null,
                        "accounts.{$index}.rubric_id"
                    ),
                    'version' => $this->integer(
                        $account['version'] ?? 0,
                        "accounts.{$index}.version",
                        0
                    ),
                ];
            }
        }
        return [
            'action' => $action,
            'id' => $this->integer($data['id'] ?? 0, 'id', 0),
            'number' => (string) ($data['number'] ?? ''),
            'label' => (string) ($data['label'] ?? ''),
            'sense_mode' => (string) ($data['sense_mode'] ?? 'automatique'),
            'rubric_id' => $this->nullablePositiveInteger(
                $data['rubric_id'] ?? null,
                'rubric_id'
            ),
            'version' => $this->integer($data['version'] ?? 0, 'version', 0),
            'ordered_ids' => $this->positiveIntegerList(
                $data['ordered_ids'] ?? [],
                'ordered_ids'
            ),
            'accounts' => $accounts,
        ];
    }

    /** @return array{exercise_id:int,validate:bool,balances:array<int,int>} */
    public function opening(Request $request): array
    {
        $data = $this->only($request, ['exercise_id', 'validate', 'balances']);
        if (
            !is_int($data['exercise_id'] ?? null)
            || $data['exercise_id'] < 1
            || !is_bool($data['validate'] ?? null)
            || !is_array($data['balances'] ?? null)
        ) {
            throw ApiException::validation([
                'opening' => ['Données d’ouverture invalides.'],
            ]);
        }
        $balances = [];
        foreach ($data['balances'] as $accountId => $cents) {
            if (
                preg_match('/^[1-9][0-9]*$/', (string) $accountId) !== 1
                || !is_int($cents)
            ) {
                throw ApiException::validation([
                    'balances' => ['Soldes entiers par compte requis.'],
                ]);
            }
            $balances[(int) $accountId] = $cents;
        }
        return [
            'exercise_id' => $data['exercise_id'],
            'validate' => $data['validate'],
            'balances' => $balances,
        ];
    }

    /** @param list<string> $allowed @return array<string,mixed> */
    private function only(Request $request, array $allowed): array
    {
        $data = $request->input();
        $this->rejectUnknown(array_keys($data), $allowed);
        return $data;
    }

    /** @param list<string|int> $actual @param list<string> $allowed */
    private function rejectUnknown(array $actual, array $allowed): void
    {
        $unknown = array_values(array_diff(array_map('strval', $actual), $allowed));
        if ($unknown !== []) {
            throw ApiException::validation([
                '_request' => ['Champs inconnus : ' . implode(', ', $unknown) . '.'],
            ]);
        }
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        if (
            (!is_int($value) && (!is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1))
            || (int) $value < 1
        ) {
            throw ApiException::validation([$field => ['Entier positif requis.']]);
        }
        return (int) $value;
    }

    private function integer(mixed $value, string $field, int $minimum): int
    {
        if (!is_int($value) || $value < $minimum) {
            throw ApiException::validation([
                $field => ["Entier supérieur ou égal à {$minimum} requis."],
            ]);
        }
        return $value;
    }

    private function nullablePositiveInteger(mixed $value, string $field): ?int
    {
        return $value === null ? null : $this->positiveInteger($value, $field);
    }

    private function optionalDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->requiredDate($value, $field);
    }

    private function requiredDate(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw ApiException::validation([
                $field => ['Date AAAA-MM-JJ requise.'],
            ]);
        }
        $date = trim($value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw ApiException::validation([
                $field => ['Date AAAA-MM-JJ valide requise.'],
            ]);
        }
        return $date;
    }

    /** @return list<int> */
    private function positiveIntegerList(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw ApiException::validation([$field => ['Liste requise.']]);
        }
        return array_map(
            fn (mixed $item): int => $this->positiveInteger($item, $field),
            array_values($value)
        );
    }

    /** @param array<string,list<string>> $errors */
    private function fail(array $errors): void
    {
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
    }
}
