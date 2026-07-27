<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie\Http;

use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Request;
use DateTimeImmutable;

final class ExpenseInputValidator
{
    /** @return array<string,mixed> */
    public function expense(Request $request): array
    {
        $data = $request->input();
        $errors = [];
        foreach (['contact_id', 'collective_account_id'] as $field) {
            if (!is_int($data[$field] ?? null) || (int) $data[$field] < 1) {
                $errors[$field][] = 'Identifiant positif requis.';
            }
        }
        foreach (['document_date', 'due_date'] as $field) {
            if (!$this->validDate($data[$field] ?? null)) {
                $errors[$field][] = 'Date au format AAAA-MM-JJ requise.';
            }
        }
        if (
            is_string($data['document_date'] ?? null)
            && is_string($data['due_date'] ?? null)
            && $data['due_date'] < $data['document_date']
        ) {
            $errors['due_date'][] = 'L’échéance doit suivre la date du document.';
        }
        if (
            !is_string($data['external_number'] ?? null)
            || trim((string) $data['external_number']) === ''
        ) {
            $errors['external_number'][] = 'Référence fournisseur requise.';
        }
        $lines = $this->lines($data['lines'] ?? null, $errors);
        $attachment = $this->attachment($data['attachment'] ?? null, $errors);
        $this->fail($errors);
        return [
            'contact_id' => (int) $data['contact_id'],
            'document_date' => (string) $data['document_date'],
            'due_date' => (string) $data['due_date'],
            'external_number' => trim((string) $data['external_number']),
            'collective_account_id' => (int) $data['collective_account_id'],
            'lines' => $lines,
            'attachment' => $attachment,
        ];
    }

    /** @return array{document_id:int,version:int} */
    public function transition(Request $request): array
    {
        $data = $request->input();
        $errors = [];
        foreach (['document_id', 'version'] as $field) {
            if (!is_int($data[$field] ?? null) || (int) $data[$field] < 1) {
                $errors[$field][] = 'Entier positif requis.';
            }
        }
        $this->fail($errors);
        return [
            'document_id' => (int) $data['document_id'],
            'version' => (int) $data['version'],
        ];
    }

    /** @return array{document_id:int,version:int,date:string} */
    public function cancellation(Request $request): array
    {
        $data = $this->transition($request);
        $date = $request->input()['date'] ?? null;
        if (!$this->validDate($date)) {
            throw ApiException::validation([
                'date' => ['Date au format AAAA-MM-JJ requise.'],
            ]);
        }
        return $data + ['date' => (string) $date];
    }

    /** @return array{document_id:int,exercise_id:int,journal_id:int} */
    public function posting(Request $request): array
    {
        $data = $request->input();
        $errors = [];
        foreach (['document_id', 'exercise_id', 'journal_id'] as $field) {
            if (!is_int($data[$field] ?? null) || (int) $data[$field] < 1) {
                $errors[$field][] = 'Entier positif requis.';
            }
        }
        $this->fail($errors);
        return [
            'document_id' => (int) $data['document_id'],
            'exercise_id' => (int) $data['exercise_id'],
            'journal_id' => (int) $data['journal_id'],
        ];
    }

    /** @return array<string,mixed> */
    public function recurrence(Request $request): array
    {
        $data = $request->input();
        $errors = [];
        foreach (['contact_id', 'collective_account_id', 'interval', 'due_days'] as $field) {
            if (!is_int($data[$field] ?? null)) {
                $errors[$field][] = 'Entier requis.';
            }
        }
        if (
            !is_string($data['label'] ?? null)
            || trim((string) $data['label']) === ''
        ) {
            $errors['label'][] = 'Libellé requis.';
        }
        if (
            !is_string($data['frequency'] ?? null)
            || !in_array(
                $data['frequency'],
                ['hebdomadaire', 'mensuelle', 'trimestrielle', 'annuelle'],
                true
            )
        ) {
            $errors['frequency'][] = 'Périodicité invalide.';
        }
        if (!$this->validDate($data['next_date'] ?? null)) {
            $errors['next_date'][] = 'Date au format AAAA-MM-JJ requise.';
        }
        $endDate = $data['end_date'] ?? null;
        if ($endDate !== null && $endDate !== '' && !$this->validDate($endDate)) {
            $errors['end_date'][] = 'Date de fin invalide.';
        }
        if (
            !is_string($data['external_prefix'] ?? null)
            || trim((string) $data['external_prefix']) === ''
        ) {
            $errors['external_prefix'][] = 'Préfixe fournisseur requis.';
        }
        $lines = $this->lines($data['lines'] ?? null, $errors);
        $this->fail($errors);
        return [
            'contact_id' => (int) $data['contact_id'],
            'label' => trim((string) $data['label']),
            'frequency' => (string) $data['frequency'],
            'interval' => (int) $data['interval'],
            'next_date' => (string) $data['next_date'],
            'end_date' => $endDate === null || $endDate === ''
                ? null : (string) $endDate,
            'due_days' => (int) $data['due_days'],
            'collective_account_id' => (int) $data['collective_account_id'],
            'external_prefix' => trim((string) $data['external_prefix']),
            'lines' => $lines,
        ];
    }

    /** @return array{recurrence_id:int,paused:bool,version:int} */
    public function recurrenceState(Request $request): array
    {
        $data = $request->input();
        $errors = [];
        foreach (['recurrence_id', 'version'] as $field) {
            if (!is_int($data[$field] ?? null) || (int) $data[$field] < 1) {
                $errors[$field][] = 'Entier positif requis.';
            }
        }
        if (!is_bool($data['paused'] ?? null)) {
            $errors['paused'][] = 'Booléen requis.';
        }
        $this->fail($errors);
        return [
            'recurrence_id' => (int) $data['recurrence_id'],
            'paused' => (bool) $data['paused'],
            'version' => (int) $data['version'],
        ];
    }

    public function generationDate(Request $request): string
    {
        $date = $request->input()['through_date'] ?? null;
        if (!$this->validDate($date)) {
            throw ApiException::validation([
                'through_date' => ['Date au format AAAA-MM-JJ requise.'],
            ]);
        }
        return (string) $date;
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
            $requiredInts = [
                'quantite_milli', 'prix_unitaire_centimes',
                'compte_id', 'code_tva_id',
            ];
            foreach ($requiredInts as $field) {
                if (!is_int($line[$field] ?? null)) {
                    $errors["lines.{$index}.{$field}"][] = 'Entier requis.';
                }
            }
            if (
                !is_string($line['libelle'] ?? null)
                || trim((string) $line['libelle']) === ''
            ) {
                $errors["lines.{$index}.libelle"][] = 'Libellé requis.';
            }
            if (!in_array($line['mode_saisie'] ?? null, ['net', 'brut'], true)) {
                $errors["lines.{$index}.mode_saisie"][] = 'Mode net ou brut requis.';
            }
            if (!$this->validDate($line['date_prestation'] ?? null)) {
                $errors["lines.{$index}.date_prestation"][] = 'Date invalide.';
            }
            $result[] = [
                'libelle' => trim((string) ($line['libelle'] ?? '')),
                'quantite_milli' => (int) ($line['quantite_milli'] ?? 0),
                'prix_unitaire_centimes' => (int) ($line['prix_unitaire_centimes'] ?? -1),
                'mode_saisie' => (string) ($line['mode_saisie'] ?? ''),
                'compte_id' => (int) ($line['compte_id'] ?? 0),
                'code_tva_id' => (int) ($line['code_tva_id'] ?? 0),
                'date_prestation' => (string) ($line['date_prestation'] ?? ''),
            ];
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
        return ['name' => (string) $value['name'], 'content' => $contents];
    }

    private function validDate(mixed $date): bool
    {
        if (!is_string($date)) {
            return false;
        }
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
