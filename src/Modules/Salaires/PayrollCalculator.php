<?php
declare(strict_types=1);

namespace Compta\Modules\Salaires;

final class PayrollCalculator
{
    /**
     * Les taux sont exprimés en millionièmes : 53 000 = 5,3 %.
     *
     * @param array<string,int|string> $employee
     * @param array<string,int> $rates
     * @return array<string,int>
     */
    public function calculate(array $employee, int $workCents, array $rates): array
    {
        if ($workCents < 0) {
            throw new PayrollException('Le salaire du travail ne peut pas être négatif.');
        }
        $vacation = $this->rate(
            $workCents,
            (int) ($employee['supplement_vacances_ppm'] ?? 0)
        );
        $gross = $workCents + $vacation;
        $values = [
            'salaire_travail_centimes' => $workCents,
            'supplement_centimes' => $vacation,
            'brut_centimes' => $gross,
            'ded_avs_centimes' => $this->rate($gross, $rates['avs_ppm'] ?? 0),
            'ded_ac_centimes' => $this->rate($gross, $rates['ac_ppm'] ?? 0),
            'ded_amat_centimes' => $this->rate($gross, $rates['amat_ppm'] ?? 0),
            'ded_laa_centimes' => $this->rate($gross, $rates['laa_ppm'] ?? 0),
            'ded_lpp_centimes' => $this->rate($gross, $rates['lpp_ppm'] ?? 0),
            'ded_impot_source_centimes' => (
                ($employee['procedure'] ?? '') === 'ordinaire_impot_source'
            ) ? $this->rate($gross, (int) ($employee['impot_source_ppm'] ?? 0)) : 0,
            'ded_caf_centimes' => 0,
            'emp_avs_centimes' => $this->rate($gross, $rates['emp_avs_ppm'] ?? 0),
            'emp_ac_centimes' => $this->rate($gross, $rates['emp_ac_ppm'] ?? 0),
            'emp_amat_centimes' => $this->rate($gross, $rates['emp_amat_ppm'] ?? 0),
            'emp_af_centimes' => $this->rate($gross, $rates['emp_af_ppm'] ?? 0),
            'emp_laa_centimes' => $this->rate($gross, $rates['emp_laa_ppm'] ?? 0),
            'emp_frais_centimes' => $this->rate($gross, $rates['emp_frais_ppm'] ?? 0),
            'emp_cpe_centimes' => $this->rate($gross, $rates['emp_cpe_ppm'] ?? 0),
            'emp_lfp_centimes' => $this->rate($gross, $rates['emp_lfp_ppm'] ?? 0),
            'emp_lpp_centimes' => $this->rate($gross, $rates['emp_lpp_ppm'] ?? 0),
        ];
        $values['total_deductions_centimes'] = array_sum([
            $values['ded_avs_centimes'],
            $values['ded_ac_centimes'],
            $values['ded_amat_centimes'],
            $values['ded_laa_centimes'],
            $values['ded_lpp_centimes'],
            $values['ded_impot_source_centimes'],
        ]);
        $values['net_centimes'] = $gross - $values['total_deductions_centimes'];
        $values['total_charges_employeur_centimes'] = array_sum([
            $values['emp_avs_centimes'],
            $values['emp_ac_centimes'],
            $values['emp_amat_centimes'],
            $values['emp_af_centimes'],
            $values['emp_laa_centimes'],
            $values['emp_frais_centimes'],
            $values['emp_cpe_centimes'],
            $values['emp_lfp_centimes'],
            $values['emp_lpp_centimes'],
        ]);
        $values['cout_total_centimes'] =
            $gross + $values['total_charges_employeur_centimes'];
        return $values;
    }

    public function monthlyHourThreshold(int $year, int $month): float
    {
        if ($year < 2000 || $month < 1 || $month > 12) {
            throw new PayrollException('Période de salaire invalide.');
        }
        return cal_days_in_month(CAL_GREGORIAN, $month, $year) / 7 * 8;
    }

    /**
     * @param array<string,int> $rates
     * @return array{laa_ppm:int,emp_laa_ppm:int}
     */
    public function effectiveAccidentRates(
        array $rates,
        float $hours,
        int $year,
        int $month,
    ): array {
        $full = round($hours, 2) > round(
            $this->monthlyHourThreshold($year, $month),
            2
        );
        return [
            'laa_ppm' => (int) ($rates[
                $full ? 'laa_plein_ppm' : 'laa_reduit_ppm'
            ] ?? 0),
            'emp_laa_ppm' => (int) ($rates[
                $full ? 'emp_laa_plein_ppm' : 'emp_laa_reduit_ppm'
            ] ?? 0),
        ];
    }

    public function rate(int $baseCents, int $ratePpm): int
    {
        if ($ratePpm < 0 || $ratePpm > 1_000_000) {
            throw new PayrollException('Taux salarial hors limites.');
        }
        return intdiv(($baseCents * $ratePpm) + 500_000, 1_000_000);
    }
}
