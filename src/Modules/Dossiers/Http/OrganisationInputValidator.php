<?php
declare(strict_types=1);

namespace Compta\Modules\Dossiers\Http;

use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Request;
use DateTimeImmutable;

final class OrganisationInputValidator
{
    /** @return array<string,mixed> */
    public function listing(Request $request): array
    {
        $this->unknown(array_keys($request->query), [
            'search', 'status', 'page', 'per_page',
        ]);
        return [
            'search' => mb_substr(trim((string) ($request->query['search'] ?? '')), 0, 120),
            'status' => (string) ($request->query['status'] ?? 'active'),
            'page' => $this->queryInteger($request, 'page', 1),
            'per_page' => $this->queryInteger($request, 'per_page', 20),
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
        $data = $this->only($request, ['name', 'nature', 'identity']);
        $identity = $data['identity'] ?? null;
        if ($identity !== null && !is_array($identity)) {
            throw ApiException::validation(['identity' => ['Objet attendu.']]);
        }
        return [
            'name' => $this->text($data, 'name'),
            'nature' => $this->text($data, 'nature'),
            'identity' => $identity === null ? null : $this->identity($identity),
        ];
    }

    /** @return array{id:int,version:int,name:string} */
    public function update(Request $request): array
    {
        $data = $this->only($request, ['id', 'version', 'name']);
        return [
            'id' => $this->positiveInt($data['id'] ?? null, 'id'),
            'version' => $this->positiveInt($data['version'] ?? null, 'version'),
            'name' => $this->text($data, 'name'),
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

    /** @return array{id:int,version:int,expected_legal_identity_id:int,identity:array<string,mixed>} */
    public function legalIdentity(Request $request): array
    {
        $data = $this->only($request, [
            'id', 'version', 'expected_legal_identity_id', 'identity',
        ]);
        if (!is_array($data['identity'] ?? null)) {
            throw ApiException::validation(['identity' => ['Objet attendu.']]);
        }
        return [
            'id' => $this->positiveInt($data['id'] ?? null, 'id'),
            'version' => $this->positiveInt($data['version'] ?? null, 'version'),
            'expected_legal_identity_id' => max(
                0,
                (int) ($data['expected_legal_identity_id'] ?? 0)
            ),
            'identity' => $this->identity($data['identity']),
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function identity(array $data): array
    {
        $allowed = [
            'valid_from', 'legal_name', 'legal_form', 'uid', 'source', 'address',
        ];
        $this->unknown(array_keys($data), $allowed);
        $address = $data['address'] ?? [];
        if (!is_array($address)) {
            throw ApiException::validation(['address' => ['Objet attendu.']]);
        }
        $this->unknown(array_keys($address), [
            'line1', 'line2', 'postal_code', 'city', 'canton', 'country',
        ]);
        $cleanAddress = [];
        foreach ([
            'line1', 'line2', 'postal_code', 'city', 'canton', 'country',
        ] as $field) {
            $value = $address[$field] ?? '';
            if (!is_string($value)) {
                throw ApiException::validation([
                    'address' => ['Les champs d’adresse doivent être textuels.'],
                ]);
            }
            $cleanAddress[$field] = mb_substr(trim($value), 0, 255);
        }
        $cleanAddress['country'] = $cleanAddress['country'] ?: 'CH';
        $validFrom = $this->text($data, 'valid_from');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $validFrom);
        if ($date === false || $date->format('Y-m-d') !== $validFrom) {
            throw ApiException::validation(['valid_from' => ['Date ISO invalide.']]);
        }
        return [
            'valid_from' => $validFrom,
            'legal_name' => $this->text($data, 'legal_name'),
            'legal_form' => mb_substr(trim((string) ($data['legal_form'] ?? '')), 0, 255),
            'uid' => mb_substr(trim((string) ($data['uid'] ?? '')), 0, 80),
            'source' => $this->text($data, 'source'),
            'address' => $cleanAddress,
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

    private function queryInteger(Request $request, string $key, int $default): int
    {
        if (!array_key_exists($key, $request->query)) {
            return $default;
        }
        return $this->positiveInt($request->query[$key], $key);
    }
}
