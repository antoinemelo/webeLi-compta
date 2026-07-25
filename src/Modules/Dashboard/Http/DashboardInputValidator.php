<?php
declare(strict_types=1);

namespace Compta\Modules\Dashboard\Http;

use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Request;
use DateTimeImmutable;

final class DashboardInputValidator
{
    /** @return array{exercise_id:int,as_of_date:string} */
    public function query(Request $request): array
    {
        $errors = [];
        foreach (array_keys($request->query) as $key) {
            if (!in_array($key, ['exercise_id', 'as_of_date'], true)) {
                $errors[(string) $key][] = 'Paramètre non autorisé.';
            }
        }

        $rawExercise = (string) ($request->query['exercise_id'] ?? '');
        $exerciseId = preg_match('/^[1-9][0-9]*$/', $rawExercise) === 1
            ? (int) $rawExercise
            : 0;
        if ($exerciseId < 1) {
            $errors['exercise_id'][] = 'Identifiant d’exercice positif attendu.';
        }

        $asOfDate = (string) ($request->query['as_of_date'] ?? '');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $asOfDate);
        if ($date === false || $date->format('Y-m-d') !== $asOfDate) {
            $errors['as_of_date'][] = 'Date valide attendue au format AAAA-MM-JJ.';
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        return ['exercise_id' => $exerciseId, 'as_of_date' => $asOfDate];
    }
}
