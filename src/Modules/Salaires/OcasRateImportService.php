<?php
declare(strict_types=1);

namespace Compta\Modules\Salaires;

use Compta\Core\Audit\AuditLogger;
use PDO;
use Throwable;

final class OcasRateImportService
{
    public const KEY_MAP = [
        'taux_avs' => 'avs_ppm',
        'taux_ac' => 'ac_ppm',
        'taux_amat' => 'amat_ppm',
        'taux_laa_reduit' => 'laa_reduit_ppm',
        'taux_laa_plein' => 'laa_plein_ppm',
        'taux_lpp' => 'lpp_ppm',
        'emp_taux_avs' => 'emp_avs_ppm',
        'emp_taux_ac' => 'emp_ac_ppm',
        'emp_taux_amat' => 'emp_amat_ppm',
        'emp_taux_af' => 'emp_af_ppm',
        'emp_taux_laa_reduit' => 'emp_laa_reduit_ppm',
        'emp_taux_laa_plein' => 'emp_laa_plein_ppm',
        'emp_taux_frais' => 'emp_frais_ppm',
        'emp_taux_cpe' => 'emp_cpe_ppm',
        'emp_taux_lfp' => 'emp_lfp_ppm',
        'emp_taux_lpp' => 'emp_lpp_ppm',
    ];

    public function __construct(
        private readonly string $databasePath,
        private readonly PayrollConfigurationService $configuration,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function preview(int $year): array
    {
        if ($year < 2000 || $year > 9999) {
            throw new PayrollException('Année de taux OCAS invalide.');
        }
        if ($this->databasePath === '' || !is_file($this->databasePath)) {
            return [
                'available' => false,
                'year' => $year,
                'source' => $this->databasePath,
                'message' => 'Source OCAS absente : aucun millésime n’est inventé.',
                'rows' => [],
                'rates' => [],
                'unknown_keys' => [],
                'missing_keys' => array_keys(self::KEY_MAP),
                'fingerprint' => '',
            ];
        }
        try {
            $source = new PDO('sqlite:' . $this->databasePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $table = $source->query(
                "SELECT 1 FROM sqlite_master
                 WHERE type = 'table' AND name = 'taux_par_annee'"
            )->fetchColumn();
            if ($table === false) {
                throw new PayrollException(
                    'La table des taux annuels OCAS est absente.'
                );
            }
            $stmt = $source->prepare(
                'SELECT cle, valeur FROM taux_par_annee
                 WHERE annee = ? ORDER BY cle'
            );
            $stmt->execute([$year]);
            $sourceRows = $stmt->fetchAll();
        } catch (PayrollException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new PayrollException('La source OCAS ne peut pas être lue.');
        }
        $rates = [];
        $rows = [];
        $unknown = [];
        foreach ($sourceRows as $row) {
            $key = (string) $row['cle'];
            $raw = trim((string) $row['valeur']);
            $target = self::KEY_MAP[$key] ?? null;
            if ($target === null) {
                $unknown[] = $key;
                $rows[] = [
                    'key' => $key,
                    'value' => $raw,
                    'target' => null,
                    'status' => 'non_applicable',
                    'reason' => 'Clé inconnue de lib/calc.php, non importée.',
                ];
                continue;
            }
            $ppm = $this->fractionToPpm($raw, $key);
            $rates[$target] = $ppm;
            $rows[] = [
                'key' => $key,
                'value' => $raw,
                'target' => $target,
                'ppm' => $ppm,
                'status' => 'importable',
                'reason' => '',
            ];
        }
        $missing = array_values(array_diff(array_keys(self::KEY_MAP), array_column($sourceRows, 'cle')));
        $canonical = json_encode(
            ['year' => $year, 'rows' => $sourceRows],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        );
        return [
            'available' => true,
            'year' => $year,
            'source' => $this->databasePath,
            'message' => $sourceRows === []
                ? 'Aucun taux OCAS pour ce millésime.'
                : 'Prévisualisation sans écriture.',
            'rows' => $rows,
            'rates' => $rates,
            'unknown_keys' => $unknown,
            'missing_keys' => $missing,
            'fingerprint' => hash('sha256', $canonical),
        ];
    }

    /** @return array{id:int,idempotent:bool,year:int} */
    public function confirm(
        int $organisationId,
        int $dossierId,
        int $year,
        string $fingerprint,
        string $verifiedOn,
        ?int $actorId = null,
    ): array {
        $preview = $this->preview($year);
        if (
            !$preview['available']
            || $preview['fingerprint'] === ''
            || !hash_equals((string) $preview['fingerprint'], $fingerprint)
        ) {
            throw new PayrollException('Prévisualisation OCAS absente ou périmée.');
        }
        if ($preview['missing_keys'] !== []) {
            throw new PayrollException(
                'Millésime OCAS incomplet : ' . implode(', ', $preview['missing_keys']) . '.'
            );
        }
        try {
            $existing = $this->configuration->rates(
                $organisationId,
                $dossierId,
                $year
            );
            if (
                (int) $existing['annee'] === $year
                && (string) $existing['source_empreinte'] !== ''
                && hash_equals((string) $existing['source_empreinte'], $fingerprint)
            ) {
                return [
                    'id' => (int) $existing['id'],
                    'idempotent' => true,
                    'year' => $year,
                ];
            }
        } catch (PayrollException) {
        }
        $data = $preview['rates'];
        $data['source'] = 'OCAS — taux annuels — ' . $this->databasePath;
        $data['source_annee'] = $year;
        $data['source_empreinte'] = $fingerprint;
        $data['importe_le'] = gmdate('Y-m-d H:i:s');
        $data['verifie_le'] = $verifiedOn;
        $id = $this->configuration->saveRates(
            $organisationId,
            $dossierId,
            $year,
            $data,
            $actorId
        );
        $this->audit->log(
            'salaires.taux_ocas_importes',
            $actorId,
            $organisationId,
            $dossierId,
            'taux_salaires',
            (string) $id,
            [
                'annee' => $year,
                'empreinte' => $fingerprint,
                'cles_inconnues' => $preview['unknown_keys'],
            ]
        );
        return ['id' => $id, 'idempotent' => false, 'year' => $year];
    }

    private function fractionToPpm(string $value, string $key): int
    {
        if (preg_match('/^(0|1)(?:\.(\d{1,9}))?$/', $value, $match) !== 1) {
            throw new PayrollException("Valeur OCAS invalide pour {$key}.");
        }
        $whole = (int) $match[1];
        $decimals = $match[2] ?? '';
        if ($whole === 1 && trim($decimals, '0') !== '') {
            throw new PayrollException("Valeur OCAS invalide pour {$key}.");
        }
        $firstSix = str_pad(substr($decimals, 0, 6), 6, '0');
        $ppm = ($whole * 1_000_000) + (int) $firstSix;
        if (isset($decimals[6]) && (int) $decimals[6] >= 5) {
            $ppm++;
        }
        if ($ppm > 1_000_000) {
            throw new PayrollException("Valeur OCAS invalide pour {$key}.");
        }
        return $ppm;
    }
}
