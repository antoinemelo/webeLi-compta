<?php
declare(strict_types=1);

namespace Compta\Modules\Immobilisations;

use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Request;
use DateTimeImmutable;

final class AssetInputValidator
{
    /** @return array{exercise_id:int,asset_id:?int,page:int,per_page:int} */
    public function query(Request $request): array
    {
        $this->rejectUnknown(array_keys($request->query), [
            'exercise_id', 'asset_id', 'page', 'per_page',
        ]);
        $asset = $request->query['asset_id'] ?? null;
        return [
            'exercise_id' => $this->queryInteger(
                $request->query['exercise_id'] ?? null,
                'exercise_id',
                1,
                PHP_INT_MAX
            ),
            'asset_id' => $asset === null || $asset === ''
                ? null
                : $this->queryInteger($asset, 'asset_id', 1, PHP_INT_MAX),
            'page' => $this->queryInteger(
                $request->query['page'] ?? 1,
                'page',
                1,
                100000
            ),
            'per_page' => $this->queryInteger(
                $request->query['per_page'] ?? 50,
                'per_page',
                1,
                100
            ),
        ];
    }

    /** @return array<string,mixed> */
    public function category(Request $request): array
    {
        $data = $this->only($request, [
            'id', 'version', 'code', 'label', 'default_duration_months',
            'asset_account_id', 'accumulated_depreciation_account_id',
            'depreciation_expense_account_id', 'disposal_gain_account_id',
            'disposal_loss_account_id', 'active',
        ]);
        $id = $this->nonNegativeInteger($data['id'] ?? 0, 'id');
        $code = trim((string) ($data['code'] ?? ''));
        $label = trim((string) ($data['label'] ?? ''));
        if ($code === '' || $label === '') {
            throw ApiException::validation([
                'category' => ['Code et libellé requis.'],
            ]);
        }
        if (!is_bool($data['active'] ?? true)) {
            throw ApiException::validation([
                'active' => ['Booléen requis.'],
            ]);
        }
        return [
            'id' => $id,
            'version' => $id > 0
                ? $this->positiveInteger($data['version'] ?? null, 'version')
                : 0,
            'code' => $code,
            'label' => $label,
            'default_duration_months' => $this->boundedInteger(
                $data['default_duration_months'] ?? null,
                'default_duration_months',
                1,
                1200
            ),
            'asset_account_id' => $this->positiveInteger(
                $data['asset_account_id'] ?? null,
                'asset_account_id'
            ),
            'accumulated_depreciation_account_id' => $this->positiveInteger(
                $data['accumulated_depreciation_account_id'] ?? null,
                'accumulated_depreciation_account_id'
            ),
            'depreciation_expense_account_id' => $this->positiveInteger(
                $data['depreciation_expense_account_id'] ?? null,
                'depreciation_expense_account_id'
            ),
            'disposal_gain_account_id' => $this->positiveInteger(
                $data['disposal_gain_account_id'] ?? null,
                'disposal_gain_account_id'
            ),
            'disposal_loss_account_id' => $this->positiveInteger(
                $data['disposal_loss_account_id'] ?? null,
                'disposal_loss_account_id'
            ),
            'active' => (bool) ($data['active'] ?? true),
        ];
    }

    /** @return array<string,mixed> */
    public function asset(Request $request): array
    {
        $data = $this->only($request, [
            'id', 'version', 'category_id', 'code', 'label',
            'acquisition_reference', 'acquisition_document_id',
            'acquisition_attachment_id', 'acquisition_date',
            'in_service_date', 'acquisition_value_cents',
            'residual_value_cents', 'duration_months', 'note',
        ]);
        $id = $this->nonNegativeInteger($data['id'] ?? 0, 'id');
        $code = trim((string) ($data['code'] ?? ''));
        $label = trim((string) ($data['label'] ?? ''));
        $reference = trim((string) ($data['acquisition_reference'] ?? ''));
        if ($code === '' || $label === '' || $reference === '') {
            throw ApiException::validation([
                'asset' => ['Code, libellé et référence de pièce requis.'],
            ]);
        }
        $acquisition = $this->date(
            $data['acquisition_date'] ?? null,
            'acquisition_date'
        );
        $service = $this->date(
            $data['in_service_date'] ?? null,
            'in_service_date'
        );
        if ($acquisition > $service) {
            throw ApiException::validation([
                'in_service_date' => [
                    'La mise en service doit suivre l’acquisition.',
                ],
            ]);
        }
        $value = $this->boundedInteger(
            $data['acquisition_value_cents'] ?? null,
            'acquisition_value_cents',
            1,
            1_000_000_000_000
        );
        $residual = $this->boundedInteger(
            $data['residual_value_cents'] ?? 0,
            'residual_value_cents',
            0,
            999_999_999_999
        );
        if ($residual >= $value) {
            throw ApiException::validation([
                'residual_value_cents' => [
                    'La valeur résiduelle doit être inférieure à la valeur d’acquisition.',
                ],
            ]);
        }
        return [
            'id' => $id,
            'version' => $id > 0
                ? $this->positiveInteger($data['version'] ?? null, 'version')
                : 0,
            'category_id' => $this->positiveInteger(
                $data['category_id'] ?? null,
                'category_id'
            ),
            'code' => $code,
            'label' => $label,
            'acquisition_reference' => $reference,
            'acquisition_document_id' => $this->optionalIdentifier(
                $data['acquisition_document_id'] ?? null,
                'acquisition_document_id'
            ),
            'acquisition_attachment_id' => $this->optionalIdentifier(
                $data['acquisition_attachment_id'] ?? null,
                'acquisition_attachment_id'
            ),
            'acquisition_date' => $acquisition,
            'in_service_date' => $service,
            'acquisition_value_cents' => $value,
            'residual_value_cents' => $residual,
            'duration_months' => $this->boundedInteger(
                $data['duration_months'] ?? null,
                'duration_months',
                1,
                1200
            ),
            'note' => trim((string) ($data['note'] ?? '')),
        ];
    }

    /** @return array{schedule_id:int,exercise_id:int,journal_id:int} */
    public function posting(Request $request): array
    {
        $data = $this->only($request, [
            'schedule_id', 'exercise_id', 'journal_id',
        ]);
        return [
            'schedule_id' => $this->positiveInteger(
                $data['schedule_id'] ?? null,
                'schedule_id'
            ),
            'exercise_id' => $this->positiveInteger(
                $data['exercise_id'] ?? null,
                'exercise_id'
            ),
            'journal_id' => $this->positiveInteger(
                $data['journal_id'] ?? null,
                'journal_id'
            ),
        ];
    }

    /** @return array{schedule_id:int,date:string} */
    public function reversal(Request $request): array
    {
        $data = $this->only($request, ['schedule_id', 'date']);
        return [
            'schedule_id' => $this->positiveInteger(
                $data['schedule_id'] ?? null,
                'schedule_id'
            ),
            'date' => $this->date($data['date'] ?? null, 'date'),
        ];
    }

    /** @return array<string,mixed> */
    public function disposal(Request $request): array
    {
        $data = $this->only($request, [
            'asset_id', 'type', 'date', 'proceeds_cents',
            'proceeds_account_id', 'exercise_id', 'journal_id',
        ]);
        $type = (string) ($data['type'] ?? '');
        if (!in_array($type, ['cession', 'mise_au_rebut'], true)) {
            throw ApiException::validation([
                'type' => ['Type de sortie invalide.'],
            ]);
        }
        return [
            'asset_id' => $this->positiveInteger(
                $data['asset_id'] ?? null,
                'asset_id'
            ),
            'type' => $type,
            'date' => $this->date($data['date'] ?? null, 'date'),
            'proceeds_cents' => $this->boundedInteger(
                $data['proceeds_cents'] ?? 0,
                'proceeds_cents',
                0,
                1_000_000_000_000
            ),
            'proceeds_account_id' => $this->optionalIdentifier(
                $data['proceeds_account_id'] ?? null,
                'proceeds_account_id'
            ),
            'exercise_id' => $this->positiveInteger(
                $data['exercise_id'] ?? null,
                'exercise_id'
            ),
            'journal_id' => $this->positiveInteger(
                $data['journal_id'] ?? null,
                'journal_id'
            ),
        ];
    }

    /** @return array{asset_id:int,date:string} */
    public function disposalReversal(Request $request): array
    {
        $data = $this->only($request, ['asset_id', 'date']);
        return [
            'asset_id' => $this->positiveInteger(
                $data['asset_id'] ?? null,
                'asset_id'
            ),
            'date' => $this->date($data['date'] ?? null, 'date'),
        ];
    }

    /** @return array<string,mixed> */
    private function only(Request $request, array $allowed): array
    {
        $data = $request->json['data'] ?? null;
        if (!is_array($data)) {
            throw ApiException::validation([
                'data' => ['Objet de données requis.'],
            ]);
        }
        $this->rejectUnknown(array_keys($data), $allowed);
        return $data;
    }

    private function optionalIdentifier(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->positiveInteger($value, $field);
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        return $this->boundedInteger($value, $field, 1, PHP_INT_MAX);
    }

    private function queryInteger(
        mixed $value,
        string $field,
        int $minimum,
        int $maximum,
    ): int {
        if (
            is_string($value)
            && preg_match('/^[0-9]+$/', $value) === 1
        ) {
            $value = (int) $value;
        }
        return $this->boundedInteger($value, $field, $minimum, $maximum);
    }

    private function nonNegativeInteger(mixed $value, string $field): int
    {
        return $this->boundedInteger($value, $field, 0, PHP_INT_MAX);
    }

    private function boundedInteger(
        mixed $value,
        string $field,
        int $minimum,
        int $maximum,
    ): int {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw ApiException::validation([
                $field => ['Entier hors limites.'],
            ]);
        }
        return $value;
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw ApiException::validation([$field => ['Date requise.']]);
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw ApiException::validation([
                $field => ['Date ISO AAAA-MM-JJ requise.'],
            ]);
        }
        return $value;
    }

    /** @param list<string> $actual @param list<string> $allowed */
    private function rejectUnknown(array $actual, array $allowed): void
    {
        $errors = [];
        foreach (array_diff($actual, $allowed) as $field) {
            $errors[$field][] = 'Champ inconnu.';
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
    }
}
