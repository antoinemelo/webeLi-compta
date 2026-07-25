<?php
declare(strict_types=1);

namespace Compta\Modules\Salaires;

use Compta\Core\Audit\AuditLogger;
use PDO;
use Throwable;

final class PayrollImportService
{
    private bool $transactionActive = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly PayrollService $payrolls,
    ) {
    }

    /**
     * Le format portable attend {"type":"fiches_salaires","fiches":[...]}.
     *
     * @return array{simulation:bool,empreinte:string,crees:list<int>,ignores:list<string>,erreurs:list<string>}
     */
    public function import(
        int $organisationId,
        int $dossierId,
        string $json,
        bool $simulation = true,
        ?int $actorId = null,
    ): array {
        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PayrollException('Fichier JSON salarial invalide.');
        }
        if (
            !is_array($payload)
            || ($payload['type'] ?? '') !== 'fiches_salaires'
            || !is_array($payload['fiches'] ?? null)
        ) {
            throw new PayrollException('Structure JSON salariale inconnue.');
        }
        $canonical = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $hash = hash('sha256', $canonical);
        $result = [
            'simulation' => $simulation,
            'empreinte' => $hash,
            'crees' => [],
            'ignores' => [],
            'erreurs' => [],
        ];
        $seen = [];
        $jobs = [];
        foreach ($payload['fiches'] as $index => $item) {
            if (!is_array($item)) {
                $result['erreurs'][] = 'Ligne ' . ($index + 1) . ' invalide.';
                continue;
            }
            $avs = $this->normalizeAvs((string) ($item['numero_avs'] ?? ''));
            $year = (int) ($item['annee'] ?? 0);
            $month = (int) ($item['mois'] ?? 0);
            $key = $avs . ':' . $year . ':' . $month;
            if ($avs === '' || $year < 2000 || $month < 1 || $month > 12) {
                $result['erreurs'][] = 'Identité ou période invalide à la ligne '
                    . ($index + 1) . '.';
                continue;
            }
            if (isset($seen[$key])) {
                $result['erreurs'][] = "Période {$key} répétée dans le fichier.";
                continue;
            }
            $seen[$key] = true;
            $employeeId = $this->employeeByAvs(
                $organisationId,
                $dossierId,
                $avs
            );
            if ($employeeId === null) {
                $result['erreurs'][] = "Employé AVS {$this->maskAvs($avs)} absent.";
                continue;
            }
            if ($this->periodExists($employeeId, $year, $month)) {
                $result['ignores'][] = "{$this->maskAvs($avs)} {$month}/{$year}";
                continue;
            }
            $lines = $this->normalizeLines($item['prestations'] ?? null, $index);
            if ($lines === null) {
                $result['erreurs'][] = 'Prestations invalides à la ligne '
                    . ($index + 1) . '.';
                continue;
            }
            $jobs[] = [
                'employee_id' => $employeeId,
                'year' => $year,
                'month' => $month,
                'lines' => $lines,
                'vacation' => isset($item['supplement_vacances_ppm'])
                    ? (int) $item['supplement_vacances_ppm']
                    : null,
                'source_tax' => isset($item['impot_source_ppm'])
                    ? (int) $item['impot_source_ppm']
                    : null,
            ];
        }
        if ($simulation || $result['erreurs'] !== []) {
            return $result;
        }
        return $this->transaction(function () use (
            $organisationId,
            $dossierId,
            $hash,
            $result,
            $jobs,
            $actorId
        ): array {
            foreach ($jobs as $job) {
                $result['crees'][] = $this->payrolls->createDraft(
                    $organisationId,
                    $dossierId,
                    $job['employee_id'],
                    $job['year'],
                    $job['month'],
                    $job['lines'],
                    $job['vacation'],
                    $job['source_tax'],
                    $actorId
                );
            }
            $stmt = $this->pdo->prepare(
                'INSERT OR IGNORE INTO imports_salaires
                 (organisation_id, dossier_id, empreinte_sha256, resume_json, cree_par)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId,
                $dossierId,
                $hash,
                json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $actorId,
            ]);
            $this->audit->log(
                'salaires.import_json_applique',
                $actorId,
                $organisationId,
                $dossierId,
                'import_salaires',
                $hash,
                ['fiches_creees' => count($result['crees'])]
            );
            return $result;
        });
    }

    private function employeeByAvs(
        int $organisationId,
        int $dossierId,
        string $avs,
    ): ?int {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM employes WHERE organisation_id = ? AND dossier_id = ?
             AND numero_avs_normalise = ? AND actif = 1'
        );
        $stmt->execute([$organisationId, $dossierId, $avs]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    private function periodExists(int $employeeId, int $year, int $month): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM fiches_salaires WHERE employe_id = ?
             AND annee = ? AND mois = ? AND statut <> 'annulee'"
        );
        $stmt->execute([$employeeId, $year, $month]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return ?list<array<string,int|string>> */
    private function normalizeLines(mixed $lines, int $index): ?array
    {
        if (!is_array($lines) || $lines === []) {
            return null;
        }
        $normalized = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                return null;
            }
            $normalized[] = [
                'libelle' => (string) ($line['libelle'] ?? 'Import '
                    . ($index + 1)),
                'unite_libelle' => (string) ($line['unite_libelle'] ?? 'Heure'),
                'heures_unite_milli' => (int) ($line['heures_unite_milli'] ?? 1000),
                'quantite_milli' => (int) ($line['quantite_milli'] ?? 0),
                'taux_horaire_centimes' => (int) ($line['taux_horaire_centimes'] ?? 0),
            ];
        }
        return $normalized;
    }

    private function normalizeAvs(string $avs): string
    {
        $digits = (string) preg_replace('/\D+/', '', $avs);
        return preg_match('/^756\d{10}$/', $digits) === 1 ? $digits : '';
    }

    private function maskAvs(string $avs): string
    {
        return strlen($avs) === 13 ? '756.****.****.' . substr($avs, -2) : '***';
    }

    private function transaction(callable $callback): mixed
    {
        if ($this->transactionActive || $this->pdo->inTransaction()) {
            return $callback();
        }
        $this->pdo->beginTransaction();
        $this->transactionActive = true;
        try {
            $result = $callback();
            $this->pdo->commit();
            $this->transactionActive = false;
            return $result;
        } catch (Throwable $exception) {
            if ($this->transactionActive) {
                $this->pdo->rollBack();
                $this->transactionActive = false;
            }
            throw $exception;
        }
    }
}
