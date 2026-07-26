<?php
declare(strict_types=1);

namespace Compta\Modules\Dossiers\Http;

use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Request;

final class StructureAccessInputValidator
{
    /** @return array{scope:string,organisation_id:?int,dossier_id:?int} */
    public function matrix(Request $request): array
    {
        $this->unknown(
            array_keys($request->query),
            ['scope', 'organisation_id', 'dossier_id']
        );
        return $this->scope($request->query);
    }

    /** @return array<string,mixed> */
    public function preview(Request $request): array
    {
        $data = $this->only($request, [
            'scope',
            'organisation_id',
            'dossier_id',
            'user_id',
            'role_ids',
            'expected_version',
            'successor_user_id',
        ]);
        return [
            ...$this->scope($data),
            'user_id' => $this->positiveInt($data['user_id'] ?? null, 'user_id'),
            'role_ids' => $this->roleIds($data['role_ids'] ?? null),
            'expected_version' => $this->hash(
                $data['expected_version'] ?? null,
                'expected_version'
            ),
            'successor_user_id' => $this->optionalPositiveInt(
                $data['successor_user_id'] ?? null,
                'successor_user_id'
            ),
        ];
    }

    /** @return array<string,mixed> */
    public function apply(Request $request): array
    {
        $data = $this->only($request, [
            'scope',
            'organisation_id',
            'dossier_id',
            'user_id',
            'role_ids',
            'expected_version',
            'confirmation_token',
            'successor_user_id',
        ]);
        return [
            ...$this->scope($data),
            'user_id' => $this->positiveInt($data['user_id'] ?? null, 'user_id'),
            'role_ids' => $this->roleIds($data['role_ids'] ?? null),
            'expected_version' => $this->hash(
                $data['expected_version'] ?? null,
                'expected_version'
            ),
            'confirmation_token' => $this->hash(
                $data['confirmation_token'] ?? null,
                'confirmation_token'
            ),
            'successor_user_id' => $this->optionalPositiveInt(
                $data['successor_user_id'] ?? null,
                'successor_user_id'
            ),
        ];
    }

    /** @return array{organisation_id:int,source_dossier_id:int} */
    public function copyPreview(Request $request): array
    {
        $data = $this->only(
            $request,
            ['organisation_id', 'source_dossier_id']
        );
        return [
            'organisation_id' => $this->positiveInt(
                $data['organisation_id'] ?? null,
                'organisation_id'
            ),
            'source_dossier_id' => $this->positiveInt(
                $data['source_dossier_id'] ?? null,
                'source_dossier_id'
            ),
        ];
    }

    /** @param array<string,mixed> $data */
    private function scope(array $data): array
    {
        $scope = $data['scope'] ?? null;
        if (
            !is_string($scope)
            || !in_array($scope, ['installation', 'organisation', 'dossier'], true)
        ) {
            throw ApiException::validation([
                'scope' => ['Périmètre invalide.'],
            ]);
        }
        return [
            'scope' => $scope,
            'organisation_id' => $scope === 'installation'
                ? null
                : $this->positiveInt(
                    $data['organisation_id'] ?? null,
                    'organisation_id'
                ),
            'dossier_id' => $scope === 'dossier'
                ? $this->positiveInt(
                    $data['dossier_id'] ?? null,
                    'dossier_id'
                )
                : null,
        ];
    }

    /** @return list<int> */
    private function roleIds(mixed $value): array
    {
        if (!is_array($value) || count($value) > 20) {
            throw ApiException::validation([
                'role_ids' => ['Liste de rôles invalide.'],
            ]);
        }
        $result = [];
        foreach ($value as $roleId) {
            $result[] = $this->positiveInt($roleId, 'role_ids');
        }
        return array_values(array_unique($result));
    }

    private function hash(mixed $value, string $key): string
    {
        if (
            !is_string($value)
            || preg_match('/^[a-f0-9]{64}$/', $value) !== 1
        ) {
            throw ApiException::validation([$key => ['Jeton invalide.']]);
        }
        return $value;
    }

    private function positiveInt(mixed $value, string $key): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false || $validated < 1) {
            throw ApiException::validation([$key => ['Entier positif requis.']]);
        }
        return (int) $validated;
    }

    private function optionalPositiveInt(mixed $value, string $key): ?int
    {
        return $value === null || $value === ''
            ? null
            : $this->positiveInt($value, $key);
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
}
