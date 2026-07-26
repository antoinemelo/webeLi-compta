<?php
declare(strict_types=1);

namespace Compta\Modules\Pedagogie;

use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Request;

final class PedagogyInputValidator
{
    /** @return array{step_id:int,entry_id:?int} */
    public function attempt(Request $request): array
    {
        $data = $this->input($request, ['step_id', 'entry_id']);
        return [
            'step_id' => $this->positive($data['step_id'] ?? null, 'step_id'),
            'entry_id' => ($data['entry_id'] ?? null) === null
                ? null
                : $this->positive($data['entry_id'], 'entry_id'),
        ];
    }

    public function identifier(Request $request, string $field): int
    {
        $data = $this->input($request, [$field]);
        return $this->positive($data[$field] ?? null, $field);
    }

    /** @return array<string,mixed> */
    public function model(Request $request): array
    {
        $data = $this->input($request, [
            'title', 'description', 'competence', 'level', 'duration_minutes',
            'instructions', 'steps', 'opening', 'initial', 'solution',
            'correction_rule', 'correction_value',
        ]);
        foreach (['title', 'competence', 'level', 'instructions'] as $field) {
            if (!is_string($data[$field] ?? null) || trim($data[$field]) === '') {
                throw ApiException::validation([$field => ['Valeur requise.']]);
            }
        }
        if (!is_array($data['steps'] ?? null) || $data['steps'] === []) {
            throw ApiException::validation(['steps' => ['Au moins une étape est requise.']]);
        }
        return [
            'title' => trim($data['title']),
            'description' => trim((string) ($data['description'] ?? '')),
            'competence' => $data['competence'],
            'level' => $data['level'],
            'duration_minutes' => $this->integer(
                $data['duration_minutes'] ?? 30,
                'duration_minutes',
                5,
                480
            ),
            'instructions' => trim($data['instructions']),
            'steps' => $data['steps'],
            'opening' => is_array($data['opening'] ?? null) ? $data['opening'] : [],
            'initial' => is_array($data['initial'] ?? null) ? $data['initial'] : [],
            'solution' => is_array($data['solution'] ?? null) ? $data['solution'] : [],
            'correction_rule' => (string) ($data['correction_rule'] ?? 'manuelle'),
            'correction_value' => trim((string) ($data['correction_value'] ?? '')),
        ];
    }

    public function group(Request $request): string
    {
        $data = $this->input($request, ['name']);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ApiException::validation(['name' => ['Nom requis.']]);
        }
        return $name;
    }

    /** @return array{group_id:int,user_id:int} */
    public function member(Request $request): array
    {
        $data = $this->input($request, ['group_id', 'user_id']);
        return [
            'group_id' => $this->positive($data['group_id'] ?? null, 'group_id'),
            'user_id' => $this->positive($data['user_id'] ?? null, 'user_id'),
        ];
    }

    /** @return array{version_id:int,target_type:string,target_id:int,name:string} */
    public function assignment(Request $request): array
    {
        $data = $this->input($request, [
            'version_id', 'target_type', 'target_id', 'name',
        ]);
        $type = (string) ($data['target_type'] ?? '');
        if (!in_array($type, ['user', 'group'], true)) {
            throw ApiException::validation([
                'target_type' => ['Cible individuelle ou groupe requise.'],
            ]);
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ApiException::validation(['name' => ['Nom de copie requis.']]);
        }
        return [
            'version_id' => $this->positive(
                $data['version_id'] ?? null,
                'version_id'
            ),
            'target_type' => $type,
            'target_id' => $this->positive($data['target_id'] ?? null, 'target_id'),
            'name' => $name,
        ];
    }

    /** @param list<string> $allowed @return array<string,mixed> */
    private function input(Request $request, array $allowed): array
    {
        $data = $request->input();
        foreach (['organisation_id', 'organization_id', 'dossier_id'] as $scope) {
            if (array_key_exists($scope, $data)) {
                throw ApiException::validation([
                    $scope => ['Le contexte de session est imposé.'],
                ]);
            }
        }
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown !== []) {
            throw ApiException::validation([
                'request' => ['Champ non autorisé : ' . (string) reset($unknown)],
            ]);
        }
        return $data;
    }

    private function positive(mixed $value, string $field): int
    {
        return $this->integer($value, $field, 1, PHP_INT_MAX);
    }

    private function integer(
        mixed $value,
        string $field,
        int $minimum,
        int $maximum,
    ): int {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw ApiException::validation([$field => ['Entier invalide.']]);
        }
        return $value;
    }
}
