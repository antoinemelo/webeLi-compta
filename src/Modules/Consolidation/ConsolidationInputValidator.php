<?php
declare(strict_types=1);

namespace Compta\Modules\Consolidation;

use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Request;
use DateTimeImmutable;

final class ConsolidationInputValidator
{
    /** @return array{group_id:?int,period_id:?int} */
    public function query(Request $request): array
    {
        $this->unknown(array_keys($request->query), ['group_id', 'period_id']);
        return [
            'group_id' => $this->optionalQueryId($request->query['group_id'] ?? null),
            'period_id' => $this->optionalQueryId($request->query['period_id'] ?? null),
        ];
    }

    /** @return array<string,mixed> */
    public function group(Request $request): array
    {
        $data = $this->only($request, [
            'code', 'label', 'currency', 'valid_from',
        ]);
        return [
            'code' => $this->text($data, 'code'),
            'label' => $this->text($data, 'label'),
            'currency' => $this->text($data, 'currency'),
            'valid_from' => $this->date($data, 'valid_from'),
        ];
    }

    /** @return array<string,mixed> */
    public function member(Request $request): array
    {
        $data = $this->only($request, [
            'group_id', 'organisation_id', 'dossier_id',
            'valid_from', 'valid_until',
        ]);
        return [
            'group_id' => $this->id($data, 'group_id'),
            'organisation_id' => $this->id($data, 'organisation_id'),
            'dossier_id' => $this->id($data, 'dossier_id'),
            'valid_from' => $this->date($data, 'valid_from'),
            'valid_until' => $this->optionalDate($data, 'valid_until'),
        ];
    }

    /** @return array<string,mixed> */
    public function legalAttributes(Request $request): array
    {
        $data = $this->only($request, [
            'valid_from', 'legal_name', 'legal_form', 'uid', 'source',
            'address',
        ]);
        $address = $data['address'] ?? [];
        if (!is_array($address)) {
            throw ApiException::validation(['address' => ['Objet adresse requis.']]);
        }
        $cleanAddress = [];
        foreach ([
            'line1', 'line2', 'postal_code', 'city', 'canton', 'country',
        ] as $field) {
            if (isset($address[$field]) && !is_string($address[$field])) {
                throw ApiException::validation([
                    'address' => ['Les champs d’adresse doivent être textuels.'],
                ]);
            }
            $cleanAddress[$field] = trim((string) ($address[$field] ?? ''));
        }
        $cleanAddress['country'] = $cleanAddress['country'] ?: 'CH';
        return [
            'valid_from' => $this->date($data, 'valid_from'),
            'legal_name' => $this->text($data, 'legal_name'),
            'legal_form' => trim((string) ($data['legal_form'] ?? '')),
            'uid' => trim((string) ($data['uid'] ?? '')),
            'source' => $this->text($data, 'source'),
            'address' => $cleanAddress,
        ];
    }

    /** @return array<string,mixed> */
    public function period(Request $request): array
    {
        $data = $this->only($request, [
            'group_id', 'label', 'start', 'end', 'conversions',
        ]);
        if (!is_array($data['conversions'] ?? null)) {
            throw ApiException::validation([
                'conversions' => ['Liste de conversions requise.'],
            ]);
        }
        $conversions = [];
        foreach ($data['conversions'] as $conversion) {
            if (!is_array($conversion)) {
                throw ApiException::validation([
                    'conversions' => ['Conversion invalide.'],
                ]);
            }
            $conversions[] = [
                'member_id' => $this->id($conversion, 'member_id'),
                'numerator' => $this->id($conversion, 'numerator'),
                'denominator' => $this->id($conversion, 'denominator'),
                'rate_date' => $this->date($conversion, 'rate_date'),
                'source' => $this->text($conversion, 'source'),
            ];
        }
        return [
            'group_id' => $this->id($data, 'group_id'),
            'label' => $this->text($data, 'label'),
            'start' => $this->date($data, 'start'),
            'end' => $this->date($data, 'end'),
            'conversions' => $conversions,
        ];
    }

    /** @return array<string,mixed> */
    public function mapping(Request $request): array
    {
        $data = $this->only($request, [
            'group_id', 'member_id', 'source_account_id', 'target_account',
            'target_label', 'target_type', 'version',
        ]);
        return [
            'group_id' => $this->id($data, 'group_id'),
            'member_id' => $this->id($data, 'member_id'),
            'source_account_id' => $this->id($data, 'source_account_id'),
            'target_account' => $this->text($data, 'target_account'),
            'target_label' => $this->text($data, 'target_label'),
            'target_type' => $this->text($data, 'target_type'),
            'version' => $this->nonNegativeInt($data, 'version'),
        ];
    }

    /** @return array<string,mixed> */
    public function pair(Request $request): array
    {
        $data = $this->only($request, [
            'group_id', 'label', 'left_member_id', 'left_account_id',
            'right_member_id', 'right_account_id',
        ]);
        return [
            'group_id' => $this->id($data, 'group_id'),
            'label' => $this->text($data, 'label'),
            'left_member_id' => $this->id($data, 'left_member_id'),
            'left_account_id' => $this->id($data, 'left_account_id'),
            'right_member_id' => $this->id($data, 'right_member_id'),
            'right_account_id' => $this->id($data, 'right_account_id'),
        ];
    }

    /** @return array<string,mixed> */
    public function elimination(Request $request): array
    {
        $data = $this->only($request, [
            'group_id', 'period_id', 'reference', 'label',
            'justification', 'lines',
        ]);
        if (!is_array($data['lines'] ?? null)) {
            throw ApiException::validation(['lines' => ['Lignes requises.']]);
        }
        $lines = [];
        foreach ($data['lines'] as $line) {
            if (!is_array($line)) {
                throw ApiException::validation(['lines' => ['Ligne invalide.']]);
            }
            $lines[] = [
                'target_account' => $this->text($line, 'target_account'),
                'label' => trim((string) ($line['label'] ?? '')),
                'debit_cents' => $this->nonNegativeInt($line, 'debit_cents'),
                'credit_cents' => $this->nonNegativeInt($line, 'credit_cents'),
            ];
        }
        return [
            'group_id' => $this->id($data, 'group_id'),
            'period_id' => $this->id($data, 'period_id'),
            'reference' => $this->text($data, 'reference'),
            'label' => $this->text($data, 'label'),
            'justification' => $this->text($data, 'justification'),
            'lines' => $lines,
        ];
    }

    /** @return array{group_id:int,period_id:int} */
    public function periodAction(Request $request): array
    {
        $data = $this->only($request, ['group_id', 'period_id']);
        return [
            'group_id' => $this->id($data, 'group_id'),
            'period_id' => $this->id($data, 'period_id'),
        ];
    }

    /** @return array{group_id:int,period_id:int} */
    public function exportQuery(Request $request): array
    {
        $this->unknown(array_keys($request->query), ['group_id', 'period_id']);
        return [
            'group_id' => $this->queryId($request->query['group_id'] ?? null, 'group_id'),
            'period_id' => $this->queryId($request->query['period_id'] ?? null, 'period_id'),
        ];
    }

    /** @param list<string> $allowed @return array<string,mixed> */
    private function only(Request $request, array $allowed): array
    {
        $data = $request->input();
        unset($data['_csrf']);
        $this->unknown(array_keys($data), $allowed);
        foreach (['organisation_id', 'dossier_id'] as $scope) {
            if (array_key_exists($scope, $data) && !in_array($scope, $allowed, true)) {
                throw ApiException::validation([
                    $scope => ['Le scope vient exclusivement de la session.'],
                ]);
            }
        }
        return $data;
    }

    /** @param list<string> $actual @param list<string> $allowed */
    private function unknown(array $actual, array $allowed): void
    {
        $unknown = array_values(array_diff($actual, $allowed));
        if ($unknown !== []) {
            throw ApiException::validation([
                'request' => ['Champs inconnus : ' . implode(', ', $unknown) . '.'],
            ]);
        }
    }

    /** @param array<string,mixed> $data */
    private function text(array $data, string $field): string
    {
        if (!is_string($data[$field] ?? null) || trim($data[$field]) === '') {
            throw ApiException::validation([$field => ['Texte requis.']]);
        }
        return trim($data[$field]);
    }

    /** @param array<string,mixed> $data */
    private function id(array $data, string $field): int
    {
        if (!is_int($data[$field] ?? null) || $data[$field] < 1) {
            throw ApiException::validation([$field => ['Identifiant positif requis.']]);
        }
        return $data[$field];
    }

    /** @param array<string,mixed> $data */
    private function nonNegativeInt(array $data, string $field): int
    {
        if (!is_int($data[$field] ?? null) || $data[$field] < 0) {
            throw ApiException::validation([$field => ['Entier positif ou nul requis.']]);
        }
        return $data[$field];
    }

    /** @param array<string,mixed> $data */
    private function date(array $data, string $field): string
    {
        $date = is_string($data[$field] ?? null) ? $data[$field] : '';
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw ApiException::validation([$field => ['Date AAAA-MM-JJ requise.']]);
        }
        return $date;
    }

    /** @param array<string,mixed> $data */
    private function optionalDate(array $data, string $field): ?string
    {
        if (!isset($data[$field]) || $data[$field] === '') {
            return null;
        }
        return $this->date($data, $field);
    }

    private function optionalQueryId(?string $value): ?int
    {
        return $value === null || $value === ''
            ? null
            : $this->queryId($value, 'query');
    }

    private function queryId(?string $value, string $field): int
    {
        if ($value === null || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw ApiException::validation([$field => ['Identifiant positif requis.']]);
        }
        return (int) $value;
    }
}
