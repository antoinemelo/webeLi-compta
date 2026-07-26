<?php
declare(strict_types=1);

namespace Compta\Modules\Configuration\Http;

use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Request;

final class ConfigurationInputValidator
{
    /** @return array<string,mixed> */
    public function identity(Request $request): array
    {
        $data = $this->only($request, [
            'organization_version', 'dossier_version', 'name', 'legal_name',
            'legal_form', 'uid', 'address_line1', 'address_line2',
            'postal_code', 'city', 'canton', 'country', 'phone', 'email',
            'website', 'base_currency',
        ]);
        $errors = [];
        foreach (['organization_version', 'dossier_version'] as $field) {
            if (!is_int($data[$field] ?? null) || (int) $data[$field] < 1) {
                $errors[$field][] = 'Version positive requise.';
            }
        }
        foreach (['name', 'base_currency'] as $field) {
            if (!is_string($data[$field] ?? null) || trim((string) $data[$field]) === '') {
                $errors[$field][] = 'Valeur requise.';
            }
        }
        $this->fail($errors);
        return $data;
    }

    /** @return array{code:string,enabled:bool,version:int} */
    public function module(Request $request): array
    {
        $data = $this->only($request, ['code', 'enabled', 'version']);
        $errors = [];
        if (!is_string($data['code'] ?? null) || trim((string) $data['code']) === '') {
            $errors['code'][] = 'Code requis.';
        }
        if (!is_bool($data['enabled'] ?? null)) {
            $errors['enabled'][] = 'Booléen requis.';
        }
        if (!is_int($data['version'] ?? null) || (int) $data['version'] < 1) {
            $errors['version'][] = 'Version positive requise.';
        }
        $this->fail($errors);
        return [
            'code' => (string) $data['code'],
            'enabled' => (bool) $data['enabled'],
            'version' => (int) $data['version'],
        ];
    }

    /** @return array<string,mixed> */
    public function paymentTerm(Request $request): array
    {
        $data = $this->only($request, [
            'code', 'label', 'direction', 'days', 'end_of_month',
            'valid_from', 'valid_until',
        ]);
        $errors = [];
        foreach (['code', 'label', 'direction', 'valid_from'] as $field) {
            if (!is_string($data[$field] ?? null) || trim((string) $data[$field]) === '') {
                $errors[$field][] = 'Valeur requise.';
            }
        }
        if (!is_int($data['days'] ?? null)) {
            $errors['days'][] = 'Nombre entier requis.';
        }
        if (!is_bool($data['end_of_month'] ?? null)) {
            $errors['end_of_month'][] = 'Booléen requis.';
        }
        if (isset($data['valid_until']) && !is_string($data['valid_until'])) {
            $errors['valid_until'][] = 'Date invalide.';
        }
        $this->fail($errors);
        return $data;
    }

    /** @return array{direction:string,condition_id:int,valid_from:string} */
    public function paymentDefault(Request $request): array
    {
        $data = $this->only($request, ['direction', 'condition_id', 'valid_from']);
        $errors = [];
        if (!is_string($data['direction'] ?? null)) {
            $errors['direction'][] = 'Direction requise.';
        }
        if (!is_int($data['condition_id'] ?? null) || (int) $data['condition_id'] < 1) {
            $errors['condition_id'][] = 'Condition requise.';
        }
        if (!is_string($data['valid_from'] ?? null)) {
            $errors['valid_from'][] = 'Date requise.';
        }
        $this->fail($errors);
        return [
            'direction' => (string) $data['direction'],
            'condition_id' => (int) $data['condition_id'],
            'valid_from' => (string) $data['valid_from'],
        ];
    }

    /** @param list<string> $allowed @return array<string,mixed> */
    private function only(Request $request, array $allowed): array
    {
        $data = $request->input();
        $unknown = array_values(array_diff(array_keys($data), $allowed));
        if ($unknown !== []) {
            throw ApiException::validation([
                '_request' => ['Champs inconnus : ' . implode(', ', $unknown) . '.'],
            ]);
        }
        return $data;
    }

    /** @param array<string,list<string>> $errors */
    private function fail(array $errors): void
    {
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
    }
}
