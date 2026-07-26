<?php
declare(strict_types=1);

namespace Compta\Modules\Configuration\Http;

use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Request;
use Compta\Modules\Compta\AccountingSetupService;
use Compta\Modules\Salaires\PayrollConfigurationService;
use Compta\Modules\Tresorerie\BankCoordinates;
use Compta\Modules\Tresorerie\TreasuryException;
use DateTimeImmutable;

final class ConfigurationInputValidator
{
    /** @return array{currency:string,active:bool} */
    public function currency(Request $request): array
    {
        $this->rejectScope($request);
        $data = $request->input();
        $errors = [];
        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $errors['currency'][] = 'Code devise ISO requis.';
        }
        if (!is_bool($data['active'] ?? null)) {
            $errors['active'][] = 'État actif explicite requis.';
        }
        $this->fail($errors);
        return ['currency' => $currency, 'active' => (bool) $data['active']];
    }

    /** @return array<string,mixed> */
    public function exchangeRate(Request $request): array
    {
        $this->rejectScope($request);
        $data = $request->input();
        $errors = [];
        $currency = strtoupper(trim((string) ($data['source_currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $errors['source_currency'][] = 'Code devise ISO requis.';
        }
        foreach (['rate_date', 'verified_on'] as $field) {
            if (!$this->validDate((string) ($data[$field] ?? ''))) {
                $errors[$field][] = 'Date AAAA-MM-JJ requise.';
            }
        }
        if (trim((string) ($data['source'] ?? '')) === '') {
            $errors['source'][] = 'Source du taux requise.';
        }
        $result = [
            'source_currency' => $currency,
            'rate_date' => (string) ($data['rate_date'] ?? ''),
            'numerator' => $this->positiveInt($data, 'numerator', $errors),
            'denominator' => $this->positiveInt($data, 'denominator', $errors),
            'source' => trim((string) ($data['source'] ?? '')),
            'verified_on' => (string) ($data['verified_on'] ?? ''),
            'active' => is_bool($data['active'] ?? null) ? $data['active'] : true,
        ];
        $this->fail($errors);
        return $result;
    }

    /** @return array<string,int> */
    public function exchangeMapping(Request $request): array
    {
        $this->rejectScope($request);
        $data = $request->input();
        $errors = [];
        $result = [];
        foreach ([
            'realized_gain_account_id',
            'realized_loss_account_id',
            'unrealized_gain_account_id',
            'unrealized_loss_account_id',
        ] as $field) {
            $result[$field] = $this->positiveInt($data, $field, $errors);
        }
        $this->fail($errors);
        return $result;
    }

    /** @return array<string,mixed> */
    public function identity(Request $request): array
    {
        $data = $this->only($request, [
            'organization_version', 'dossier_version', 'name', 'legal_name',
            'legal_form', 'uid', 'address_line1', 'address_line2',
            'postal_code', 'city', 'canton', 'country', 'phone', 'email',
            'website', 'billing_iban', 'base_currency',
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
        $data['billing_iban'] ??= '';
        if (!is_string($data['billing_iban'])) {
            $errors['billing_iban'][] = 'IBAN textuel requis.';
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
            'postal_code', 'city', 'country', 'payment_iban', 'payment_bic',
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
        $paymentIban = BankCoordinates::normalizeIban(
            (string) ($data['payment_iban'] ?? '')
        );
        $paymentBic = BankCoordinates::normalizeBic(
            (string) ($data['payment_bic'] ?? '')
        );
        try {
            if ($paymentIban !== '') {
                BankCoordinates::assertIban($paymentIban);
            }
        } catch (TreasuryException) {
            $errors['payment_iban'][] = 'IBAN de paiement invalide.';
        }
        try {
            BankCoordinates::assertBic($paymentBic);
        } catch (TreasuryException) {
            $errors['payment_bic'][] = 'BIC de paiement invalide.';
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
            'payment_iban' => $paymentIban,
            'payment_bic' => $paymentBic,
        ];
    }

    /** @return array<string,mixed> */
    public function vatCode(Request $request): array
    {
        $data = $this->only($request, [
            'id', 'active',
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
        $id = $data['id'] ?? 0;
        if (!is_int($id) || $id < 0) {
            $errors['id'][] = 'Identifiant positif ou nul requis.';
        }
        $active = $data['active'] ?? true;
        if (!is_bool($active)) {
            $errors['active'][] = 'Booléen requis.';
        }
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
            'id' => (int) $id,
            'active' => (bool) $active,
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

    public function referenceIdentifier(Request $request): int
    {
        $data = $this->only($request, ['id']);
        $errors = [];
        if (!is_int($data['id'] ?? null) || $data['id'] < 1) {
            $errors['id'][] = 'Identifiant positif requis.';
        }
        $this->fail($errors);
        return (int) $data['id'];
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

    public function payrollEmployerSettings(Request $request): int
    {
        $data = $this->only($request, ['weekly_hours_milli']);
        $errors = [];
        if (
            !is_int($data['weekly_hours_milli'] ?? null)
            || $data['weekly_hours_milli'] < 1
            || $data['weekly_hours_milli'] > 168000
        ) {
            $errors['weekly_hours_milli'][] = 'Durée hebdomadaire entière invalide.';
        }
        $this->fail($errors);
        return (int) $data['weekly_hours_milli'];
    }

    /** @return array<string,int> */
    public function payrollMappingSettings(Request $request): array
    {
        $data = $this->only($request, PayrollConfigurationService::MAPPING_FIELDS);
        $errors = [];
        $mapping = [];
        foreach (PayrollConfigurationService::MAPPING_FIELDS as $field) {
            if (!is_int($data[$field] ?? null) || $data[$field] < 1) {
                $errors[$field][] = 'Compte requis.';
                continue;
            }
            $mapping[$field] = (int) $data[$field];
        }
        $this->fail($errors);
        return $mapping;
    }

    /** @return array<string,mixed> */
    public function treasuryAccount(Request $request): array
    {
        $data = $this->only($request, [
            'id', 'version', 'ledger_account_id', 'label', 'type', 'iban',
            'bic', 'currency', 'accounting_multiplier', 'active',
        ]);
        $errors = [];
        $this->idsAndVersion($data, $errors);
        if (
            !is_int($data['ledger_account_id'] ?? null)
            || $data['ledger_account_id'] < 1
        ) {
            $errors['ledger_account_id'][] = 'Compte comptable requis.';
        }
        if (!is_string($data['label'] ?? null) || trim($data['label']) === '') {
            $errors['label'][] = 'Libellé requis.';
        }
        if (
            !is_string($data['type'] ?? null)
            || !in_array($data['type'], ['banque', 'poste', 'caisse', 'carte'], true)
        ) {
            $errors['type'][] = 'Type de trésorerie invalide.';
        }
        $iban = BankCoordinates::normalizeIban((string) ($data['iban'] ?? ''));
        $bic = BankCoordinates::normalizeBic((string) ($data['bic'] ?? ''));
        try {
            if ($iban !== '') {
                BankCoordinates::assertIban($iban);
            }
        } catch (TreasuryException) {
            $errors['iban'][] = 'IBAN invalide.';
        }
        try {
            BankCoordinates::assertBic($bic);
        } catch (TreasuryException) {
            $errors['bic'][] = 'BIC invalide.';
        }
        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $errors['currency'][] = 'Code devise ISO requis.';
        }
        if (
            !is_int($data['accounting_multiplier'] ?? null)
            || !in_array($data['accounting_multiplier'], [-1, 1], true)
        ) {
            $errors['accounting_multiplier'][] = 'Sens comptable invalide.';
        }
        if (!is_bool($data['active'] ?? null)) {
            $errors['active'][] = 'Booléen requis.';
        }
        $this->fail($errors);
        return [
            'id' => (int) $data['id'],
            'version' => (int) $data['version'],
            'ledger_account_id' => (int) $data['ledger_account_id'],
            'label' => trim((string) $data['label']),
            'type' => (string) $data['type'],
            'iban' => $iban,
            'bic' => $bic,
            'currency' => $currency,
            'accounting_multiplier' => (int) $data['accounting_multiplier'],
            'active' => (bool) $data['active'],
        ];
    }

    /** @return array<string,mixed> */
    public function journal(Request $request): array
    {
        $data = $this->only($request, [
            'id', 'version', 'code', 'label', 'type', 'active',
        ]);
        $errors = [];
        $this->idsAndVersion($data, $errors);
        if (
            !is_string($data['code'] ?? null)
            || preg_match('/^[A-Z0-9_-]{1,12}$/i', trim($data['code'])) !== 1
        ) {
            $errors['code'][] = 'Code alphanumérique requis.';
        }
        if (!is_string($data['label'] ?? null) || trim($data['label']) === '') {
            $errors['label'][] = 'Libellé requis.';
        }
        if (
            !is_string($data['type'] ?? null)
            || !in_array($data['type'], AccountingSetupService::JOURNAL_TYPES, true)
        ) {
            $errors['type'][] = 'Type de journal invalide.';
        }
        if (!is_bool($data['active'] ?? null)) {
            $errors['active'][] = 'Booléen requis.';
        }
        $this->fail($errors);
        return [
            'id' => (int) $data['id'],
            'version' => (int) $data['version'],
            'code' => strtoupper(trim((string) $data['code'])),
            'label' => trim((string) $data['label']),
            'type' => (string) $data['type'],
            'active' => (bool) $data['active'],
        ];
    }

    /** @return array<string,mixed> */
    public function exercise(Request $request): array
    {
        $data = $this->only($request, [
            'id', 'version', 'label', 'start_date', 'end_date', 'status',
        ]);
        $errors = [];
        $this->idsAndVersion($data, $errors);
        if (!is_string($data['label'] ?? null) || trim($data['label']) === '') {
            $errors['label'][] = 'Libellé requis.';
        }
        $this->dateRange($data, 'start_date', 'end_date', $errors);
        if (
            !is_string($data['status'] ?? null)
            || !in_array($data['status'], ['ouvert', 'ferme'], true)
        ) {
            $errors['status'][] = 'Statut d’exercice invalide.';
        }
        $this->fail($errors);
        return [
            'id' => (int) $data['id'],
            'version' => (int) $data['version'],
            'label' => trim((string) $data['label']),
            'start_date' => (string) $data['start_date'],
            'end_date' => (string) $data['end_date'],
            'status' => (string) $data['status'],
        ];
    }

    /** @return array<string,mixed> */
    public function period(Request $request): array
    {
        $data = $this->only($request, [
            'id', 'version', 'exercise_id', 'label', 'start_date',
            'end_date', 'status',
        ]);
        $errors = [];
        $this->idsAndVersion($data, $errors);
        if (
            !is_int($data['exercise_id'] ?? null)
            || $data['exercise_id'] < 1
        ) {
            $errors['exercise_id'][] = 'Exercice requis.';
        }
        if (!is_string($data['label'] ?? null) || trim($data['label']) === '') {
            $errors['label'][] = 'Libellé requis.';
        }
        $this->dateRange($data, 'start_date', 'end_date', $errors);
        if (
            !is_string($data['status'] ?? null)
            || !in_array($data['status'], ['ouverte', 'fermee'], true)
        ) {
            $errors['status'][] = 'Statut de période invalide.';
        }
        $this->fail($errors);
        return [
            'id' => (int) $data['id'],
            'version' => (int) $data['version'],
            'exercise_id' => (int) $data['exercise_id'],
            'label' => trim((string) $data['label']),
            'start_date' => (string) $data['start_date'],
            'end_date' => (string) $data['end_date'],
            'status' => (string) $data['status'],
        ];
    }

    /** @return array{user_id:int,role_ids:list<int>} */
    public function dossierAccess(Request $request): array
    {
        $data = $this->only($request, ['user_id', 'role_ids']);
        $errors = [];
        if (!is_int($data['user_id'] ?? null) || $data['user_id'] < 1) {
            $errors['user_id'][] = 'Utilisateur requis.';
        }
        $roleIds = $data['role_ids'] ?? null;
        if (!is_array($roleIds)) {
            $errors['role_ids'][] = 'Liste de rôles requise.';
            $roleIds = [];
        } else {
            foreach ($roleIds as $roleId) {
                if (!is_int($roleId) || $roleId < 1) {
                    $errors['role_ids'][] = 'Identifiant de rôle invalide.';
                    break;
                }
            }
        }
        $this->fail($errors);
        return [
            'user_id' => (int) $data['user_id'],
            'role_ids' => array_values(array_unique($roleIds)),
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

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** @param array<string,mixed> $data @param array<string,list<string>> $errors */
    private function idsAndVersion(array $data, array &$errors): void
    {
        foreach (['id', 'version'] as $field) {
            if (!is_int($data[$field] ?? null) || $data[$field] < 0) {
                $errors[$field][] = 'Entier positif ou nul requis.';
            }
        }
        if (($data['id'] ?? 0) > 0 && ($data['version'] ?? 0) < 1) {
            $errors['version'][] = 'Version requise pour une modification.';
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,list<string>> $errors
     */
    private function dateRange(
        array $data,
        string $startField,
        string $endField,
        array &$errors,
    ): void {
        $start = is_string($data[$startField] ?? null)
            ? $data[$startField]
            : '';
        $end = is_string($data[$endField] ?? null)
            ? $data[$endField]
            : '';
        if (!$this->validDate($start)) {
            $errors[$startField][] = 'Date de début invalide.';
        }
        if (!$this->validDate($end)) {
            $errors[$endField][] = 'Date de fin invalide.';
        }
        if ($start !== '' && $end !== '' && $start > $end) {
            $errors[$endField][] = 'La fin précède le début.';
        }
    }
}
