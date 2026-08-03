<?php
declare(strict_types=1);

namespace Compta\Modules\Salaires;

use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Request;

final class PayrollInputValidator
{
    /** @return array{year:int,payroll_id:?int} */
    public function query(Request $request): array
    {
        $this->rejectScope($request->query);
        $year = $this->int($request->query['year'] ?? date('Y'), 'year', 2000, 9999, true);
        $id = $request->query['payroll_id'] ?? null;
        return [
            'year' => $year,
            'payroll_id' => $id === null || $id === ''
                ? null
                : $this->int($id, 'payroll_id', 1, PHP_INT_MAX, true),
        ];
    }

    /** @return array<string,mixed> */
    public function employee(Request $request): array
    {
        $d = $request->input();
        $this->rejectScope($d);
        return [
            'id' => $this->int($d['id'] ?? 0, 'id', 0, PHP_INT_MAX),
            'version' => $this->int($d['version'] ?? 0, 'version', 0, PHP_INT_MAX),
            'prenom' => $this->text($d['first_name'] ?? '', 'first_name'),
            'nom' => $this->text($d['last_name'] ?? '', 'last_name'),
            'email' => trim((string) ($d['email'] ?? '')),
            'rue' => trim((string) ($d['address'] ?? '')),
            'npa' => trim((string) ($d['postal_code'] ?? '')),
            'localite' => trim((string) ($d['city'] ?? '')),
            'numero_avs' => $this->text($d['avs'] ?? '', 'avs'),
            'date_naissance' => trim((string) ($d['birth_date'] ?? '')),
            'procedure' => (string) ($d['procedure'] ?? 'ordinaire'),
            'supplement_vacances_ppm' => $this->int(
                $d['vacation_ppm'] ?? 83300, 'vacation_ppm', 0, 1_000_000
            ),
            'impot_source_ppm' => $this->int(
                $d['source_tax_ppm'] ?? 0, 'source_tax_ppm', 0, 1_000_000
            ),
            'lpp_ppm' => ($d['lpp_ppm'] ?? null) === null
                ? null
                : $this->int($d['lpp_ppm'], 'lpp_ppm', 0, 1_000_000),
            'emp_lpp_ppm' => ($d['emp_lpp_ppm'] ?? null) === null
                ? null
                : $this->int($d['emp_lpp_ppm'], 'emp_lpp_ppm', 0, 1_000_000),
            'actif' => ($d['active'] ?? true) ? 1 : 0,
        ];
    }

    /** @return array<string,mixed> */
    public function employer(Request $request): array
    {
        $d = $request->input();
        $this->rejectScope($d);
        return [
            'nom' => $this->text($d['name'] ?? '', 'name'),
            'rue' => trim((string) ($d['address'] ?? '')),
            'npa' => trim((string) ($d['postal_code'] ?? '')),
            'localite' => trim((string) ($d['city'] ?? '')),
            'pays' => 'CH',
            'telephone' => trim((string) ($d['phone'] ?? '')),
            'email' => trim((string) ($d['email'] ?? '')),
            'heures_hebdo_milli' => $this->int(
                $d['weekly_hours_milli'] ?? 40000,
                'weekly_hours_milli',
                1,
                168000
            ),
        ];
    }

    /** @return array<string,int> */
    public function mapping(Request $request): array
    {
        $d = $request->input();
        $this->rejectScope($d);
        $result = [];
        foreach (PayrollConfigurationService::MAPPING_FIELDS as $field) {
            $result[$field] = $this->int($d[$field] ?? null, $field);
        }
        return $result;
    }

    /** @return array<string,mixed> */
    public function contract(Request $request): array
    {
        $d = $request->input();
        $this->rejectScope($d);
        return [
            'id' => $this->int($d['id'] ?? 0, 'id', 0, PHP_INT_MAX),
            'version' => $this->int($d['version'] ?? 0, 'version', 0, PHP_INT_MAX),
            'employe_id' => $this->int($d['employee_id'] ?? null, 'employee_id'),
            'type' => (string) ($d['type'] ?? ''),
            'date_debut' => $this->text($d['valid_from'] ?? '', 'valid_from'),
            'date_fin' => trim((string) ($d['valid_until'] ?? '')),
            'taux_horaire_centimes' => $this->int(
                $d['hourly_cents'] ?? 0, 'hourly_cents', 0, 100_000_000
            ),
            'salaire_mensuel_centimes' => $this->int(
                $d['monthly_cents'] ?? 0, 'monthly_cents', 0, 100_000_000
            ),
            'heures_hebdo_milli' => $this->int(
                $d['weekly_hours_milli'] ?? 40000,
                'weekly_hours_milli',
                1,
                168000
            ),
            'taux_activite_ppm' => $this->int(
                $d['activity_ppm'] ?? 1_000_000,
                'activity_ppm',
                1,
                1_000_000
            ),
            'source' => $this->text($d['source'] ?? '', 'source'),
            'actif' => ($d['active'] ?? true) ? 1 : 0,
        ];
    }

    /** @return array<string,mixed> */
    public function draft(Request $request): array
    {
        $d = $request->input();
        $this->rejectScope($d);
        if (!is_array($d['elements'] ?? null)) {
            throw ApiException::validation(['elements' => ['Liste requise.']]);
        }
        return [
            'id' => $this->int($d['id'] ?? 0, 'id', 0, PHP_INT_MAX),
            'version' => $this->int(
                $d['version'] ?? 0,
                'version',
                0,
                PHP_INT_MAX
            ),
            'employee_id' => $this->int($d['employee_id'] ?? null, 'employee_id'),
            'year' => $this->int($d['year'] ?? null, 'year', 2000, 9999),
            'month' => $this->int($d['month'] ?? null, 'month', 1, 12),
            'elements' => $d['elements'],
        ];
    }

    /** @return array{id:int,version:int} */
    public function identity(Request $request): array
    {
        $d = $request->input();
        $this->rejectScope($d);
        return [
            'id' => $this->int($d['id'] ?? null, 'id'),
            'version' => $this->int($d['version'] ?? 0, 'version', 0),
        ];
    }

    /** @return array<string,mixed> */
    public function posting(Request $request): array
    {
        $d = $this->identity($request);
        $input = $request->input();
        return $d + [
            'exercise_id' => $this->int($input['exercise_id'] ?? null, 'exercise_id'),
            'journal_id' => $this->int($input['journal_id'] ?? null, 'journal_id'),
            'date' => $this->text($input['date'] ?? '', 'date'),
        ];
    }

    /** @return array<string,mixed> */
    public function payment(Request $request): array
    {
        $d = $request->input();
        $this->rejectScope($d);
        return [
            'beneficiary_type' => (string) ($d['beneficiary_type'] ?? ''),
            'employee_id' => ($d['employee_id'] ?? null) === null
                ? null : $this->int($d['employee_id'], 'employee_id'),
            'date' => $this->text($d['date'] ?? '', 'date'),
            'amount_cents' => $this->int($d['amount_cents'] ?? null, 'amount_cents'),
            'account_id' => $this->int($d['account_id'] ?? null, 'account_id'),
            'treasury_account_id' => $this->int(
                $d['treasury_account_id'] ?? null,
                'treasury_account_id'
            ),
            'reference' => trim((string) ($d['reference'] ?? '')),
        ];
    }

    /** @return array{payment_id:int,liability_id:int,amount_cents:int} */
    public function allocation(Request $request): array
    {
        $d = $request->input();
        $this->rejectScope($d);
        return [
            'payment_id' => $this->int($d['payment_id'] ?? null, 'payment_id'),
            'liability_id' => $this->int($d['liability_id'] ?? null, 'liability_id'),
            'amount_cents' => $this->int($d['amount_cents'] ?? null, 'amount_cents'),
        ];
    }

    /** @return array<string,mixed> */
    public function yearAction(Request $request): array
    {
        $d = $request->input();
        $this->rejectScope($d);
        return [
            'year' => $this->int($d['year'] ?? null, 'year', 2000, 9999),
            'employee_id' => isset($d['employee_id'])
                ? $this->int($d['employee_id'], 'employee_id') : 0,
            'fingerprint' => trim((string) ($d['fingerprint'] ?? '')),
            'verified_on' => trim((string) ($d['verified_on'] ?? '')),
            'source_csv' => is_string($d['source_csv'] ?? null)
                ? (string) $d['source_csv']
                : '',
        ];
    }

    /** @param array<string,mixed> $data */
    private function rejectScope(array $data): void
    {
        foreach (['organisation_id', 'dossier_id', 'user_id'] as $field) {
            if (array_key_exists($field, $data)) {
                throw ApiException::validation([$field => ['Champ de scope interdit.']]);
            }
        }
    }

    private function int(
        mixed $value,
        string $field,
        int $min = 1,
        int $max = PHP_INT_MAX,
        bool $query = false,
    ): int {
        if ($query && is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < $min || $value > $max) {
            throw ApiException::validation([$field => ['Entier hors limites.']]);
        }
        return $value;
    }

    private function text(mixed $value, string $field): string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '') {
            throw ApiException::validation([$field => ['Valeur requise.']]);
        }
        return $text;
    }
}
