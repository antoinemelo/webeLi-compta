<?php
declare(strict_types=1);

namespace Compta\Modules\Shell\Http;

use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Api\ListQuery;
use Compta\Core\Http\Request;

final class ShellInputValidator
{
    /** @return array{organisation_id:int,dossier_id:int} */
    public function dossierSelection(Request $request): array
    {
        $input = $request->input();
        $errors = [];
        foreach (array_keys($input) as $key) {
            if (!in_array($key, ['organisation_id', 'dossier_id', '_csrf'], true)) {
                $errors[(string) $key][] = 'Champ non autorisé.';
            }
        }
        $organisationId = $this->positiveInteger(
            $input['organisation_id'] ?? null,
            'organisation_id',
            $errors
        );
        $dossierId = $this->positiveInteger(
            $input['dossier_id'] ?? null,
            'dossier_id',
            $errors
        );
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        return [
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
        ];
    }

    /**
     * @param list<string> $allowedSorts
     * @param array<string, list<string>|null> $allowedFilters
     */
    public function listQuery(
        Request $request,
        array $allowedSorts,
        string $defaultSort,
        array $allowedFilters = [],
    ): ListQuery {
        $errors = [];
        $allowedKeys = ['page', 'per_page', 'sort', 'order', ...array_keys($allowedFilters)];
        foreach (array_keys($request->query) as $key) {
            if (!in_array($key, $allowedKeys, true)) {
                $errors[(string) $key][] = 'Filtre non autorisé.';
            }
        }
        $page = $this->boundedInteger($request->query['page'] ?? '1', 'page', 1, PHP_INT_MAX, $errors);
        $perPage = $this->boundedInteger(
            $request->query['per_page'] ?? '25',
            'per_page',
            1,
            100,
            $errors
        );
        $sort = (string) ($request->query['sort'] ?? $defaultSort);
        if (!in_array($sort, $allowedSorts, true)) {
            $errors['sort'][] = 'Tri non autorisé.';
        }
        $order = mb_strtolower((string) ($request->query['order'] ?? 'asc'));
        if (!in_array($order, ['asc', 'desc'], true)) {
            $errors['order'][] = 'Ordre attendu : asc ou desc.';
        }
        $filters = [];
        foreach ($allowedFilters as $key => $values) {
            $value = trim((string) ($request->query[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            if ($values !== null && !in_array($value, $values, true)) {
                $errors[$key][] = 'Valeur de filtre non autorisée.';
                continue;
            }
            if ($values === null && mb_strlen($value) > 100) {
                $errors[$key][] = 'Valeur limitée à 100 caractères.';
                continue;
            }
            $filters[$key] = $value;
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        return new ListQuery($page, $perPage, $sort, $order, $filters);
    }

    /** @param array<string, list<string>> $errors */
    private function positiveInteger(
        mixed $value,
        string $field,
        array &$errors,
    ): int {
        return $this->boundedInteger($value, $field, 1, PHP_INT_MAX, $errors);
    }

    /** @param array<string, list<string>> $errors */
    private function boundedInteger(
        mixed $value,
        string $field,
        int $minimum,
        int $maximum,
        array &$errors,
    ): int {
        $raw = is_int($value) ? (string) $value : (is_string($value) ? $value : '');
        if (preg_match('/^[0-9]+$/', $raw) !== 1) {
            $errors[$field][] = 'Entier positif attendu.';
            return $minimum;
        }
        $integer = (int) $raw;
        if ($integer < $minimum || $integer > $maximum) {
            $errors[$field][] = "Valeur attendue entre {$minimum} et {$maximum}.";
            return $minimum;
        }
        return $integer;
    }
}
