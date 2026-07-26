<?php
declare(strict_types=1);

namespace Compta\Modules\Facturation\Http;

use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Request;
use DateTimeImmutable;

final class BillingInputValidator
{
    /** @return array{as_of_date:string,direction:string,status:string,search:string,contact_id:?int} */
    public function filters(Request $request): array
    {
        $errors = [];
        foreach (array_keys($request->query) as $field) {
            if (!in_array(
                $field,
                ['as_of_date', 'direction', 'status', 'search', 'contact_id'],
                true
            )) {
                $errors[$field][] = 'Paramètre non autorisé.';
            }
        }
        $date = (string) ($request->query['as_of_date'] ?? date('Y-m-d'));
        $direction = (string) ($request->query['direction'] ?? 'all');
        $status = (string) ($request->query['status'] ?? 'all');
        $search = mb_substr(trim((string) ($request->query['search'] ?? '')), 0, 120);
        $contactId = null;
        if (($request->query['contact_id'] ?? '') !== '') {
            $raw = (string) $request->query['contact_id'];
            if (preg_match('/^[1-9][0-9]*$/', $raw) !== 1) {
                $errors['contact_id'][] = 'Identifiant positif requis.';
            } else {
                $contactId = (int) $raw;
            }
        }
        if (!$this->validDate($date)) {
            $errors['as_of_date'][] = 'Date AAAA-MM-JJ requise.';
        }
        if (!in_array($direction, ['all', 'sales', 'purchases'], true)) {
            $errors['direction'][] = 'Direction de document invalide.';
        }
        if (!in_array($status, [
            'all', 'brouillon', 'annule', 'solde', 'non_echu',
            'retard_0_30', 'retard_31_60', 'retard_61_90', 'retard_91_plus',
        ], true)) {
            $errors['status'][] = 'État de paiement invalide.';
        }
        $this->fail($errors);
        return [
            'as_of_date' => $date,
            'direction' => $direction,
            'status' => $status,
            'search' => $search,
            'contact_id' => $contactId,
        ];
    }

    /** @return array<string,mixed> */
    public function document(Request $request): array
    {
        $this->rejectScope($request);
        $data = $request->input();
        $errors = [];
        $type = (string) ($data['type'] ?? '');
        if (!in_array($type, [
            'facture_client', 'avoir_client',
            'facture_fournisseur', 'avoir_fournisseur',
        ], true)) {
            $errors['type'][] = 'Type de document invalide.';
        }
        foreach (['document_date', 'due_date'] as $field) {
            if (!$this->validDate($data[$field] ?? null)) {
                $errors[$field][] = 'Date AAAA-MM-JJ requise.';
            }
        }
        if (
            is_string($data['document_date'] ?? null)
            && is_string($data['due_date'] ?? null)
            && $data['due_date'] < $data['document_date']
        ) {
            $errors['due_date'][] = 'L’échéance doit suivre la date du document.';
        }
        $external = trim((string) ($data['external_number'] ?? ''));
        if (str_contains($type, 'fournisseur') && $external === '') {
            $errors['external_number'][] = 'Numéro fournisseur requis.';
        }
        $result = [
            'type' => $type,
            'contact_id' => $this->positiveInt($data, 'contact_id', $errors),
            'document_date' => (string) ($data['document_date'] ?? ''),
            'due_date' => (string) ($data['due_date'] ?? ''),
            'collective_account_id' => $this->positiveInt(
                $data,
                'collective_account_id',
                $errors
            ),
            'external_number' => $external,
            'lines' => $this->lines($data['lines'] ?? null, $errors),
            'attachment' => $this->attachment($data['attachment'] ?? null, $errors),
        ];
        $this->fail($errors);
        return $result;
    }

    /** @return array{document_id:int,version:int} */
    public function transition(Request $request): array
    {
        return $this->ids($request, ['document_id', 'version']);
    }

    /** @return array{document_id:int,exercise_id:int,journal_id:int} */
    public function posting(Request $request): array
    {
        return $this->ids($request, ['document_id', 'exercise_id', 'journal_id']);
    }

    /** @return array{document_id:int,date:string} */
    public function credit(Request $request): array
    {
        $this->rejectScope($request);
        $data = $request->input();
        $errors = [];
        $date = (string) ($data['date'] ?? '');
        if (!$this->validDate($date)) {
            $errors['date'][] = 'Date AAAA-MM-JJ requise.';
        }
        $id = $this->positiveInt($data, 'document_id', $errors);
        $this->fail($errors);
        return ['document_id' => $id, 'date' => $date];
    }

    /** @return array<string,mixed> */
    public function contact(Request $request, bool $update = false): array
    {
        $this->rejectScope($request);
        $data = $request->input();
        $errors = [];
        $type = (string) ($data['type'] ?? 'entreprise');
        $company = trim((string) ($data['company'] ?? ''));
        $first = trim((string) ($data['first_name'] ?? ''));
        $last = trim((string) ($data['last_name'] ?? ''));
        if (
            !in_array($type, ['entreprise', 'personne'], true)
            || ($type === 'entreprise' && $company === '')
            || ($type === 'personne' && $first === '' && $last === '')
        ) {
            $errors['identity'][] = 'Identité du contact incomplète.';
        }
        $roles = $data['roles'] ?? null;
        if (!is_array($roles) || $roles === []) {
            $errors['roles'][] = 'Au moins un rôle est requis.';
            $roles = [];
        } else {
            foreach ($roles as $role) {
                if (
                    !is_string($role)
                    || !in_array($role, ['client', 'fournisseur', 'employe', 'autre'], true)
                ) {
                    $errors['roles'][] = 'Rôle invalide.';
                }
            }
        }
        $address = is_array($data['address'] ?? null) ? $data['address'] : [];
        foreach (['line1', 'postal_code', 'city'] as $field) {
            if (trim((string) ($address[$field] ?? '')) === '') {
                $errors["address.{$field}"][] = 'Valeur requise.';
            }
        }
        $key = trim((string) ($data['idempotency_key'] ?? ''));
        if (!$update && $key === '') {
            $errors['idempotency_key'][] = 'Clé idempotente requise.';
        }
        $result = [
            'contact_id' => $update
                ? $this->positiveInt($data, 'contact_id', $errors) : null,
            'version' => $update
                ? $this->positiveInt($data, 'version', $errors) : null,
            'data' => [
                'type_personne' => $type,
                'raison_sociale' => $company,
                'prenom' => $first,
                'nom' => $last,
                'email' => trim((string) ($data['email'] ?? '')),
                'telephone' => trim((string) ($data['phone'] ?? '')),
                'iban_paiement' => trim((string) ($data['iban'] ?? '')),
                'bic_paiement' => trim((string) ($data['bic'] ?? '')),
                'langue' => (string) ($data['language'] ?? 'fr'),
            ],
            'roles' => array_values(array_unique($roles)),
            'address' => [
                'ligne1' => trim((string) ($address['line1'] ?? '')),
                'ligne2' => trim((string) ($address['line2'] ?? '')),
                'code_postal' => trim((string) ($address['postal_code'] ?? '')),
                'localite' => trim((string) ($address['city'] ?? '')),
                'pays' => strtoupper(trim((string) ($address['country'] ?? 'CH'))),
            ],
            'idempotency_key' => $key,
        ];
        $this->fail($errors);
        return $result;
    }

    /** @return array<string,mixed> */
    public function recurrence(Request $request): array
    {
        $this->rejectScope($request);
        $data = $request->input();
        $errors = [];
        $type = (string) ($data['type'] ?? '');
        $frequency = (string) ($data['frequency'] ?? '');
        $nextDate = (string) ($data['next_date'] ?? '');
        $endDate = $data['end_date'] ?? null;
        if (!in_array($type, ['facture_client', 'facture_fournisseur'], true)) {
            $errors['type'][] = 'Type de facture invalide.';
        }
        if (!in_array(
            $frequency,
            ['hebdomadaire', 'mensuelle', 'trimestrielle', 'annuelle'],
            true
        )) {
            $errors['frequency'][] = 'Périodicité invalide.';
        }
        if (!$this->validDate($nextDate)) {
            $errors['next_date'][] = 'Date AAAA-MM-JJ requise.';
        }
        if ($endDate !== null && $endDate !== '' && !$this->validDate($endDate)) {
            $errors['end_date'][] = 'Date de fin invalide.';
        }
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            $errors['label'][] = 'Libellé requis.';
        }
        $externalPrefix = trim((string) ($data['external_prefix'] ?? ''));
        if ($type === 'facture_fournisseur' && $externalPrefix === '') {
            $errors['external_prefix'][] = 'Préfixe fournisseur requis.';
        }
        $result = [
            'type' => $type,
            'contact_id' => $this->positiveInt($data, 'contact_id', $errors),
            'label' => $label,
            'frequency' => $frequency,
            'interval' => $this->positiveInt($data, 'interval', $errors),
            'next_date' => $nextDate,
            'end_date' => $endDate === null || $endDate === ''
                ? null : (string) $endDate,
            'due_days' => $this->nonNegativeInt($data, 'due_days', $errors),
            'collective_account_id' => $this->positiveInt(
                $data,
                'collective_account_id',
                $errors
            ),
            'external_prefix' => $externalPrefix,
            'lines' => $this->lines($data['lines'] ?? null, $errors),
        ];
        $this->fail($errors);
        return $result;
    }

    /** @return array{recurrence_id:int,paused:bool,version:int} */
    public function recurrenceState(Request $request): array
    {
        $this->rejectScope($request);
        $data = $request->input();
        $errors = [];
        $paused = $data['paused'] ?? null;
        if (!is_bool($paused)) {
            $errors['paused'][] = 'Booléen requis.';
        }
        $result = [
            'recurrence_id' => $this->positiveInt(
                $data,
                'recurrence_id',
                $errors
            ),
            'paused' => (bool) $paused,
            'version' => $this->positiveInt($data, 'version', $errors),
        ];
        $this->fail($errors);
        return $result;
    }

    public function generationDate(Request $request): string
    {
        $this->rejectScope($request);
        $date = $request->input()['through_date'] ?? null;
        if (!$this->validDate($date)) {
            throw ApiException::validation([
                'through_date' => ['Date AAAA-MM-JJ requise.'],
            ]);
        }
        return (string) $date;
    }

    /** @return array{document_id:int,level:int,channel:string,note:string} */
    public function reminder(Request $request): array
    {
        $this->rejectScope($request);
        $data = $request->input();
        $errors = [];
        $level = $this->positiveInt($data, 'level', $errors);
        $channel = (string) ($data['channel'] ?? '');
        if ($level > 9) {
            $errors['level'][] = 'Niveau entre 1 et 9 requis.';
        }
        if (!in_array($channel, ['courrier', 'email', 'telephone', 'autre'], true)) {
            $errors['channel'][] = 'Canal invalide.';
        }
        $result = [
            'document_id' => $this->positiveInt($data, 'document_id', $errors),
            'level' => $level,
            'channel' => $channel,
            'note' => mb_substr(trim((string) ($data['note'] ?? '')), 0, 1000),
        ];
        $this->fail($errors);
        return $result;
    }

    /** @return array<string,mixed> */
    public function payment(Request $request): array
    {
        $this->rejectScope($request);
        $data = $request->input();
        $errors = [];
        $direction = (string) ($data['direction'] ?? '');
        $date = (string) ($data['date'] ?? '');
        if (!in_array($direction, ['encaissement', 'decaissement'], true)) {
            $errors['direction'][] = 'Sens de paiement invalide.';
        }
        if (!$this->validDate($date)) {
            $errors['date'][] = 'Date AAAA-MM-JJ requise.';
        }
        $result = [
            'contact_id' => $this->positiveInt($data, 'contact_id', $errors),
            'direction' => $direction,
            'date' => $date,
            'amount_cents' => $this->positiveInt($data, 'amount_cents', $errors),
            'reference' => trim((string) ($data['reference'] ?? '')),
            'ledger_account_id' => $this->positiveInt(
                $data,
                'ledger_account_id',
                $errors
            ),
        ];
        $this->fail($errors);
        return $result;
    }

    /** @return array{payment_id:int,document_id:int,amount_cents:int} */
    public function allocation(Request $request): array
    {
        return $this->ids($request, ['payment_id', 'document_id', 'amount_cents']);
    }

    /** @return array{credit_id:int,document_id:int,amount_cents:int} */
    public function creditAllocation(Request $request): array
    {
        return $this->ids($request, ['credit_id', 'document_id', 'amount_cents']);
    }

    public function identifier(Request $request, string $field): int
    {
        $this->rejectScope($request);
        $errors = [];
        $id = $this->positiveInt($request->input(), $field, $errors);
        $this->fail($errors);
        return $id;
    }

    /**
     * @param mixed $value
     * @param array<string,list<string>> $errors
     * @return list<array<string,mixed>>
     */
    private function lines(mixed $value, array &$errors): array
    {
        if (!is_array($value) || $value === []) {
            $errors['lines'][] = 'Au moins une ligne est requise.';
            return [];
        }
        $result = [];
        foreach (array_values($value) as $index => $line) {
            if (!is_array($line)) {
                $errors["lines.{$index}"][] = 'Ligne invalide.';
                continue;
            }
            $label = trim((string) ($line['label'] ?? ''));
            $mode = (string) ($line['input_mode'] ?? '');
            if ($label === '') {
                $errors["lines.{$index}.label"][] = 'Libellé requis.';
            }
            if (!in_array($mode, ['net', 'brut'], true)) {
                $errors["lines.{$index}.input_mode"][] = 'Mode net ou brut requis.';
            }
            $result[] = [
                'libelle' => $label,
                'quantite_milli' => $this->positiveInt(
                    $line,
                    'quantity_milli',
                    $errors,
                    "lines.{$index}.quantity_milli"
                ),
                'prix_unitaire_centimes' => $this->nonNegativeInt(
                    $line,
                    'unit_price_cents',
                    $errors,
                    "lines.{$index}.unit_price_cents"
                ),
                'mode_saisie' => $mode,
                'compte_id' => $this->positiveInt(
                    $line,
                    'account_id',
                    $errors,
                    "lines.{$index}.account_id"
                ),
                'code_tva_id' => $this->positiveInt(
                    $line,
                    'vat_code_id',
                    $errors,
                    "lines.{$index}.vat_code_id"
                ),
                'date_prestation' => $this->validDate($line['service_date'] ?? null)
                    ? (string) $line['service_date']
                    : '',
            ];
            if (!$this->validDate($line['service_date'] ?? null)) {
                $errors["lines.{$index}.service_date"][] = 'Date invalide.';
            }
        }
        return $result;
    }

    /**
     * @param mixed $value
     * @param array<string,list<string>> $errors
     * @return array{name:string,content:string}|null
     */
    private function attachment(mixed $value, array &$errors): ?array
    {
        if ($value === null) {
            return null;
        }
        if (
            !is_array($value)
            || !is_string($value['name'] ?? null)
            || trim((string) $value['name']) === ''
            || !is_string($value['content_base64'] ?? null)
        ) {
            $errors['attachment'][] = 'Pièce jointe invalide.';
            return null;
        }
        $contents = base64_decode((string) $value['content_base64'], true);
        if ($contents === false) {
            $errors['attachment'][] = 'Contenu base64 invalide.';
            return null;
        }
        return ['name' => basename((string) $value['name']), 'content' => $contents];
    }

    /** @param list<string> $fields @return array<string,int> */
    private function ids(Request $request, array $fields): array
    {
        $this->rejectScope($request);
        $errors = [];
        $result = [];
        foreach ($fields as $field) {
            $result[$field] = $this->positiveInt(
                $request->input(),
                $field,
                $errors
            );
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

    /** @param array<string,mixed> $data @param array<string,list<string>> $errors */
    private function nonNegativeInt(
        array $data,
        string $field,
        array &$errors,
        ?string $errorField = null,
    ): int {
        $value = $data[$field] ?? null;
        if (!is_int($value) || $value < 0) {
            $errors[$errorField ?? $field][] = 'Entier positif ou nul requis.';
            return 0;
        }
        return $value;
    }

    private function validDate(mixed $date): bool
    {
        if (!is_string($date)) {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
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

    /** @param array<string,list<string>> $errors */
    private function fail(array $errors): void
    {
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
    }
}
