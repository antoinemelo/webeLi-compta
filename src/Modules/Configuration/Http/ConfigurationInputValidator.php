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
    public function setupGuideStep(Request $request): string
    {
        $data = $this->only($request, ['step']);
        $step = is_string($data['step'] ?? null)
            ? trim((string) $data['step'])
            : '';
        if (!in_array($step, ['exercises', 'opening', 'vat', 'accounting'], true)) {
            throw ApiException::validation([
                'step' => ['Étape de configuration invalide.'],
            ]);
        }
        return $step;
    }

    public function setupGuideAction(Request $request): string
    {
        $data = $this->only($request, ['action']);
        $action = is_string($data['action'] ?? null)
            ? trim((string) $data['action'])
            : '';
        if (!in_array($action, ['cancel', 'resume'], true)) {
            throw ApiException::validation([
                'action' => ['Action de parcours invalide.'],
            ]);
        }
        return $action;
    }

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
            'website', 'billing_treasury_account_id', 'base_currency',
            'vat_exempt', 'vat_effective_from',
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
        $billingAccountId = $data['billing_treasury_account_id'] ?? null;
        if (
            $billingAccountId !== null
            && (!is_int($billingAccountId) || $billingAccountId < 1)
        ) {
            $errors['billing_treasury_account_id'][] =
                'Compte de trésorerie invalide.';
        }
        if (!is_bool($data['vat_exempt'] ?? null)) {
            $errors['vat_exempt'][] = 'Statut TVA explicite requis.';
        }
        if (
            !is_string($data['vat_effective_from'] ?? null)
            || !$this->validDate((string) $data['vat_effective_from'])
        ) {
            $errors['vat_effective_from'][] = 'Date d’effet TVA invalide.';
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

    /** @return array{trigger:string,version:int} */
    public function paymentAccounting(Request $request): array
    {
        $data = $this->only($request, ['trigger', 'version']);
        $trigger = is_string($data['trigger'] ?? null)
            ? trim((string) $data['trigger'])
            : '';
        if (!in_array($trigger, ['premier_lettrage', 'lettrage_complet'], true)) {
            throw ApiException::validation([
                'trigger' => ['Mode de comptabilisation invalide.'],
            ]);
        }
        if (!is_int($data['version'] ?? null) || (int) $data['version'] < 0) {
            throw ApiException::validation([
                'version' => ['Version positive ou nulle requise.'],
            ]);
        }
        return ['trigger' => $trigger, 'version' => (int) $data['version']];
    }

    /** @return array<string,mixed> */
    public function contact(Request $request): array
    {
        $data = $this->only($request, [
            'id', 'version', 'type', 'company_contact_id',
            'company', 'first_name', 'last_name', 'email', 'phone',
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
        $companyContactId = $data['company_contact_id'] ?? null;
        if (
            $companyContactId !== null
            && (!is_int($companyContactId) || $companyContactId < 1)
        ) {
            $errors['company_contact_id'][] = 'Entreprise associée invalide.';
        }
        if ($type === 'entreprise' && $companyContactId !== null) {
            $errors['company_contact_id'][] =
                'Une entreprise ne peut pas être rattachée à une autre entreprise.';
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
            'company_contact_id' => $companyContactId === null
                ? null
                : (int) $companyContactId,
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
    public function vatRegime(Request $request): array
    {
        $data = $this->only($request, [
            'status', 'vat_number', 'method', 'reporting_mode', 'frequency',
            'valid_from', 'valid_until', 'input_material_account_id',
            'input_investment_account_id', 'vat_due_account_id',
            'vat_settlement_account_id', 'corrections_account_id',
        ]);
        $errors = [];
        $status = (string) ($data['status'] ?? '');
        if (!in_array($status, ['non_assujetti', 'assujetti', 'volontaire'], true)) {
            $errors['status'][] = 'Régime TVA invalide.';
        }
        $method = (string) ($data['method'] ?? 'effective');
        if (!in_array($method, ['effective', 'tdfn'], true)) {
            $errors['method'][] = 'Méthode TVA invalide.';
        }
        $reportingMode = (string) ($data['reporting_mode'] ?? 'convenues');
        if (!in_array($reportingMode, ['convenues', 'recues'], true)) {
            $errors['reporting_mode'][] = 'Mode de décompte invalide.';
        }
        $frequency = (string) ($data['frequency'] ?? 'trimestrielle');
        if (!in_array(
            $frequency,
            ['mensuelle', 'trimestrielle', 'semestrielle', 'annuelle'],
            true
        )) {
            $errors['frequency'][] = 'Périodicité TVA invalide.';
        }
        $validFrom = (string) ($data['valid_from'] ?? '');
        $validUntil = trim((string) ($data['valid_until'] ?? ''));
        if (!$this->validDate($validFrom)) {
            $errors['valid_from'][] = 'Date d’effet invalide.';
        }
        if ($validUntil !== '' && !$this->validDate($validUntil)) {
            $errors['valid_until'][] = 'Date de fin invalide.';
        }
        if ($validUntil !== '' && $validFrom !== '' && $validUntil < $validFrom) {
            $errors['valid_until'][] = 'La fin précède le début.';
        }
        $accountMap = [
            'input_material_account_id',
            'input_investment_account_id',
            'vat_due_account_id',
            'vat_settlement_account_id',
            'corrections_account_id',
        ];
        $accounts = [];
        foreach ($accountMap as $field) {
            $value = $data[$field] ?? null;
            if ($status !== 'non_assujetti') {
                if (!is_int($value) || $value < 1) {
                    $errors[$field][] = 'Compte comptable requis.';
                }
                $accounts[$field] = is_int($value) ? $value : null;
            } else {
                $accounts[$field] = null;
            }
        }
        $vatNumber = trim((string) ($data['vat_number'] ?? ''));
        if ($status !== 'non_assujetti' && $vatNumber === '') {
            $errors['vat_number'][] = 'Numéro IDE/TVA requis.';
        }
        $this->fail($errors);
        return [
            'status' => $status,
            'vat_number' => $vatNumber,
            'method' => $method,
            'reporting_mode' => $reportingMode,
            'frequency' => $frequency,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            ...$accounts,
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

    /** @return array{id:int,version:int} */
    public function contactDeletion(Request $request): array
    {
        $data = $this->only($request, ['id', 'version']);
        $errors = [];
        foreach (['id', 'version'] as $field) {
            if (!is_int($data[$field] ?? null) || $data[$field] < 1) {
                $errors[$field][] = 'Entier positif requis.';
            }
        }
        $this->fail($errors);
        return ['id' => (int) $data['id'], 'version' => (int) $data['version']];
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

    private function rejectScope(Request $request): void
    {
        $errors = [];
        foreach (['organisation_id', 'dossier_id'] as $field) {
            if (array_key_exists($field, $request->input())) {
                $errors[$field][] = 'Le scope provient exclusivement de la session.';
            }
        }
        $this->fail($errors);
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,list<string>> $errors
     */
    private function positiveInt(
        array $data,
        string $field,
        array &$errors,
    ): int {
        if (!is_int($data[$field] ?? null) || (int) $data[$field] < 1) {
            $errors[$field][] = 'Entier positif requis.';
            return 0;
        }
        return (int) $data[$field];
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
