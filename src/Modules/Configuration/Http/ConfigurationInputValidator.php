<?php
declare(strict_types=1);

namespace Compta\Modules\Configuration\Http;

use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Request;
use Compta\Modules\Salaires\PayrollConfigurationService;
use DateTimeImmutable;

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

    /** @return array<string,mixed> */
    public function contact(Request $request): array
    {
        $data = $this->only($request, [
            'id', 'version', 'type', 'company', 'first_name', 'last_name', 'email', 'phone',
            'language', 'roles', 'address_line1', 'address_line2',
            'postal_code', 'city', 'country',
        ]);
        $errors = [];
        foreach (['id', 'version'] as $field) {
            if (!is_int($data[$field] ?? null) || $data[$field] < 0) {
                $errors[$field][] = 'Entier positif ou nul requis.';
            }
        }
        if (($data['id'] ?? 0) > 0 && ($data['version'] ?? 0) < 1) {
            $errors['version'][] = 'Version requise pour une modification.';
        }
        $type = $data['type'] ?? null;
        if (!is_string($type) || !in_array($type, ['entreprise', 'personne'], true)) {
            $errors['type'][] = 'Type de contact invalide.';
        }
        foreach ([
            'company', 'first_name', 'last_name', 'email', 'phone', 'language',
            'address_line1', 'address_line2', 'postal_code', 'city', 'country',
        ] as $field) {
            if (!is_string($data[$field] ?? null)) {
                $errors[$field][] = 'Texte requis.';
            }
        }
        if (
            $type === 'entreprise'
            && trim((string) ($data['company'] ?? '')) === ''
        ) {
            $errors['company'][] = 'Raison sociale requise.';
        }
        if (
            $type === 'personne'
            && trim(
                (string) ($data['first_name'] ?? '')
                . (string) ($data['last_name'] ?? '')
            ) === ''
        ) {
            $errors['last_name'][] = 'Prénom ou nom requis.';
        }
        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'][] = 'Adresse e-mail invalide.';
        }
        if (!in_array($data['language'] ?? null, ['fr', 'de', 'it', 'en'], true)) {
            $errors['language'][] = 'Langue invalide.';
        }
        $roles = $data['roles'] ?? null;
        if (!is_array($roles) || $roles === []) {
            $errors['roles'][] = 'Sélectionnez au moins un rôle.';
        } else {
            foreach ($roles as $role) {
                if (!is_string($role) || !in_array(
                    $role,
                    ['client', 'fournisseur', 'employe', 'autre'],
                    true
                )) {
                    $errors['roles'][] = 'Rôle invalide.';
                    break;
                }
            }
        }
        foreach (['address_line1', 'postal_code', 'city'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $errors[$field][] = 'Valeur requise.';
            }
        }
        $country = strtoupper(trim((string) ($data['country'] ?? '')));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            $errors['country'][] = 'Code pays ISO à deux lettres requis.';
        }
        $this->fail($errors);
        return [
            'id' => (int) $data['id'],
            'version' => (int) $data['version'],
            'type' => $type,
            'company' => trim((string) $data['company']),
            'first_name' => trim((string) $data['first_name']),
            'last_name' => trim((string) $data['last_name']),
            'email' => $email,
            'phone' => trim((string) $data['phone']),
            'language' => (string) $data['language'],
            'roles' => array_values(array_unique($roles)),
            'address_line1' => trim((string) $data['address_line1']),
            'address_line2' => trim((string) $data['address_line2']),
            'postal_code' => trim((string) $data['postal_code']),
            'city' => trim((string) $data['city']),
            'country' => $country,
        ];
    }

    /** @return array<string,mixed> */
    public function vatCode(Request $request): array
    {
        $data = $this->only($request, [
            'code', 'label', 'treatment', 'nature', 'legal_rate_id',
            'deduction_right', 'default_deduction_bp', 'afc_box',
            'account_id', 'valid_from', 'valid_until',
        ]);
        $errors = [];
        $treatments = [
            'normal', 'reduit', 'special', 'exonere', 'exclu',
            'hors_champ', 'acquisition', 'import', 'correction',
        ];
        $natures = [
            'collectee', 'prealable', 'acquisition', 'non_taxable', 'correction',
        ];
        if (
            !is_string($data['code'] ?? null)
            || preg_match('/^[A-Z0-9_-]{1,20}$/i', trim($data['code'])) !== 1
        ) {
            $errors['code'][] = 'Code alphanumérique requis.';
        }
        if (!is_string($data['label'] ?? null) || trim($data['label']) === '') {
            $errors['label'][] = 'Libellé requis.';
        }
        if (!is_string($data['treatment'] ?? null) || !in_array(
            $data['treatment'],
            $treatments,
            true
        )) {
            $errors['treatment'][] = 'Traitement TVA invalide.';
        }
        if (!is_string($data['nature'] ?? null) || !in_array(
            $data['nature'],
            $natures,
            true
        )) {
            $errors['nature'][] = 'Nature TVA invalide.';
        }
        $taxed = in_array(
            $data['treatment'] ?? null,
            ['normal', 'reduit', 'special', 'acquisition', 'import'],
            true
        );
        $rateId = $data['legal_rate_id'] ?? null;
        if (
            ($taxed && (!is_int($rateId) || $rateId < 1))
            || (!$taxed && $rateId !== null)
        ) {
            $errors['legal_rate_id'][] = $taxed
                ? 'Taux légal requis.'
                : 'Aucun taux légal ne doit être associé à ce traitement.';
        }
        if (!is_bool($data['deduction_right'] ?? null)) {
            $errors['deduction_right'][] = 'Booléen requis.';
        }
        if (
            !is_int($data['default_deduction_bp'] ?? null)
            || $data['default_deduction_bp'] < 0
            || $data['default_deduction_bp'] > 10000
        ) {
            $errors['default_deduction_bp'][] = 'Pourcentage invalide.';
        }
        foreach (['legal_rate_id', 'account_id'] as $field) {
            if (
                ($data[$field] ?? null) !== null
                && (!is_int($data[$field]) || $data[$field] < 1)
            ) {
                $errors[$field][] = 'Identifiant positif requis.';
            }
        }
        foreach (['valid_from', 'valid_until'] as $field) {
            $value = (string) ($data[$field] ?? '');
            if (
                ($field === 'valid_from' || $value !== '')
                && !$this->validDate($value)
            ) {
                $errors[$field][] = 'Date AAAA-MM-JJ invalide.';
            }
        }
        $validFrom = is_string($data['valid_from'] ?? null)
            ? $data['valid_from']
            : '';
        $validUntil = is_string($data['valid_until'] ?? null)
            ? $data['valid_until']
            : '';
        if ($validUntil !== '' && $validFrom !== '' && $validUntil < $validFrom) {
            $errors['valid_until'][] = 'La fin précède le début.';
        }
        foreach (['afc_box'] as $field) {
            if (!is_string($data[$field] ?? null)) {
                $errors[$field][] = 'Texte requis.';
            }
        }
        $this->fail($errors);
        return [
            'code' => strtoupper(trim((string) $data['code'])),
            'label' => trim((string) $data['label']),
            'treatment' => (string) $data['treatment'],
            'nature' => (string) $data['nature'],
            'legal_rate_id' => $rateId,
            'deduction_right' => (bool) $data['deduction_right'],
            'default_deduction_bp' => (int) $data['default_deduction_bp'],
            'afc_box' => trim((string) $data['afc_box']),
            'account_id' => $data['account_id'] ?? null,
            'valid_from' => (string) $data['valid_from'],
            'valid_until' => (string) ($data['valid_until'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    public function payrollRates(Request $request): array
    {
        $allowed = [
            'year', ...PayrollConfigurationService::RATE_FIELDS,
            'source', 'verified_on',
        ];
        $data = $this->only($request, $allowed);
        $errors = [];
        if (
            !is_int($data['year'] ?? null)
            || $data['year'] < 2000
            || $data['year'] > 9999
        ) {
            $errors['year'][] = 'Année invalide.';
        }
        foreach (PayrollConfigurationService::RATE_FIELDS as $field) {
            if (
                !is_int($data[$field] ?? null)
                || $data[$field] < 0
                || $data[$field] > 1_000_000
            ) {
                $errors[$field][] = 'Taux entier en ppm invalide.';
            }
        }
        if (!is_string($data['source'] ?? null) || trim($data['source']) === '') {
            $errors['source'][] = 'Source requise.';
        }
        if (
            !is_string($data['verified_on'] ?? null)
            || !$this->validDate($data['verified_on'])
        ) {
            $errors['verified_on'][] = 'Date de vérification requise.';
        }
        $this->fail($errors);
        $result = [
            'year' => (int) $data['year'],
            'source' => trim((string) $data['source']),
            'verifie_le' => (string) $data['verified_on'],
        ];
        foreach (PayrollConfigurationService::RATE_FIELDS as $field) {
            $result[$field] = (int) $data[$field];
        }
        return $result;
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

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
