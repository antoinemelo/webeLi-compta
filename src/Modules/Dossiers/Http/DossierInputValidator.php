<?php
declare(strict_types=1);

namespace Compta\Modules\Dossiers\Http;

use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Request;
use DateTimeImmutable;

final class DossierInputValidator
{
    /** @return array{organisation_id:int,status:string} */
    public function listing(Request $request): array
    {
        $this->unknown(array_keys($request->query), ['organisation_id', 'status']);
        return [
            'organisation_id' => $this->positiveInt(
                $request->query['organisation_id'] ?? null,
                'organisation_id'
            ),
            'status' => (string) ($request->query['status'] ?? 'all'),
        ];
    }

    public function detail(Request $request): int
    {
        $this->unknown(array_keys($request->query), ['id']);
        return $this->positiveInt($request->query['id'] ?? null, 'id');
    }

    /** @return array<string,mixed> */
    public function create(Request $request): array
    {
        $data = $this->only($request, [
            'organisation_id', 'name', 'slug', 'type', 'currency', 'modules',
            'plan_variant', 'association', 'exercise', 'journal',
        ]);
        $modules = $data['modules'] ?? null;
        $association = $data['association'] ?? [];
        $exercise = $data['exercise'] ?? null;
        $journal = $data['journal'] ?? null;
        if (!is_array($modules) || !is_array($association)
            || !is_array($exercise) || !is_array($journal)) {
            throw ApiException::validation([
                'request' => ['Modules, association, exercice et journal doivent être structurés.'],
            ]);
        }
        $this->unknown(array_keys($association), ['enabled', 'projects', 'restricted_funds']);
        $this->unknown(array_keys($exercise), ['label', 'start', 'end']);
        $this->unknown(array_keys($journal), ['code', 'label']);
        $cleanModules = [];
        foreach ($modules as $module) {
            if (!is_string($module)) {
                throw ApiException::validation(['modules' => ['Codes textuels attendus.']]);
            }
            $cleanModules[] = trim($module);
        }
        $start = $this->text($exercise, 'start');
        $end = $this->text($exercise, 'end');
        $this->date($start, 'exercise.start');
        $this->date($end, 'exercise.end');
        return [
            'organisation_id' => $this->positiveInt(
                $data['organisation_id'] ?? null,
                'organisation_id'
            ),
            'name' => $this->text($data, 'name'),
            'slug' => $this->text($data, 'slug'),
            'type' => $this->text($data, 'type'),
            'currency' => $this->text($data, 'currency'),
            'modules' => array_values(array_unique($cleanModules)),
            'plan_variant' => $this->text($data, 'plan_variant'),
            'association' => [
                'enabled' => (bool) ($association['enabled'] ?? false),
                'projects' => (bool) ($association['projects'] ?? false),
                'restricted_funds' => (bool) ($association['restricted_funds'] ?? false),
            ],
            'exercise' => [
                'label' => $this->text($exercise, 'label'),
                'start' => $start,
                'end' => $end,
            ],
            'journal' => [
                'code' => $this->text($journal, 'code'),
                'label' => $this->text($journal, 'label'),
            ],
        ];
    }

    /** @return array{id:int,version:int,name:string,type:string,currency:string} */
    public function update(Request $request): array
    {
        $data = $this->only($request, ['id', 'version', 'name', 'type', 'currency']);
        return [
            'id' => $this->positiveInt($data['id'] ?? null, 'id'),
            'version' => $this->positiveInt($data['version'] ?? null, 'version'),
            'name' => $this->text($data, 'name'),
            'type' => $this->text($data, 'type'),
            'currency' => $this->text($data, 'currency'),
        ];
    }

    /** @return array{id:int,version:int} */
    public function action(Request $request): array
    {
        $data = $this->only($request, ['id', 'version']);
        return [
            'id' => $this->positiveInt($data['id'] ?? null, 'id'),
            'version' => $this->positiveInt($data['version'] ?? null, 'version'),
        ];
    }

    /** @param list<string> $allowed */
    private function unknown(array $keys, array $allowed): void
    {
        $unknown = array_values(array_diff($keys, $allowed));
        if ($unknown !== []) {
            throw ApiException::validation([
                'request' => ['Champs inconnus : ' . implode(', ', $unknown)],
            ]);
        }
    }

    /** @param list<string> $allowed @return array<string,mixed> */
    private function only(Request $request, array $allowed): array
    {
        $data = $request->input();
        $this->unknown(array_keys($data), $allowed);
        return $data;
    }

    /** @param array<string,mixed> $data */
    private function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw ApiException::validation([$key => ['Valeur textuelle requise.']]);
        }
        return mb_substr(trim($value), 0, 255);
    }

    private function positiveInt(mixed $value, string $key): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false || $validated < 1) {
            throw ApiException::validation([$key => ['Entier positif requis.']]);
        }
        return (int) $validated;
    }

    private function date(string $value, string $key): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw ApiException::validation([$key => ['Date ISO invalide.']]);
        }
    }
}
