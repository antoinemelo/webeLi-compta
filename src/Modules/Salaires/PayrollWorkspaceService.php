<?php
declare(strict_types=1);

namespace Compta\Modules\Salaires;

final class PayrollWorkspaceService
{
    public function __construct(
        private readonly PayrollConfigurationService $configuration,
        private readonly PayrollService $payrolls,
        private readonly PayrollPaymentService $payments,
        private readonly PayrollCertificateService $certificates,
    ) {
    }

    /** @return array<string,mixed> */
    public function read(
        int $organisationId,
        int $dossierId,
        int $year,
        ?int $payrollId,
        bool $revealPii,
    ): array {
        $employer = null;
        $mapping = null;
        try {
            $employer = $this->configuration->employer($organisationId, $dossierId);
        } catch (PayrollException) {
        }
        try {
            $mapping = $this->configuration->mapping($organisationId, $dossierId);
        } catch (PayrollException) {
        }
        $catalog = $this->configuration->catalog($organisationId, $dossierId);
        $ratesReady = false;
        foreach ($catalog['rates'] as $rates) {
            if ((int) ($rates['annee'] ?? 0) <= $year) {
                $ratesReady = true;
                break;
            }
        }
        $employerSuggestion = $employer === null
            ? $this->configuration->employerSuggestion($organisationId, $dossierId)
            : null;
        $selected = null;
        if ($payrollId !== null) {
            $selected = $this->payrolls->payroll(
                $organisationId,
                $dossierId,
                $payrollId,
                $revealPii
            );
            $selected['lines'] = $this->payrolls->lines($payrollId);
            $selected['components'] = $this->payrolls->components($payrollId);
            $selected['period_elements'] = $this->payrolls->periodElements($payrollId);
        }
        $summary = $this->payrolls->annualSummary(
            $organisationId,
            $dossierId,
            $year
        );
        return [
            'year' => $year,
            'scope' => ['organisation_id' => $organisationId, 'dossier_id' => $dossierId],
            'employer' => $employer,
            'employer_suggestion' => $employerSuggestion,
            'mapping' => $mapping,
            'configuration' => [
                'employer_ready' => $employer !== null,
                'rates_ready' => $ratesReady,
                'mapping_ready' => $mapping !== null,
                'calculation_ready' => $employer !== null && $ratesReady,
                'validation_ready' => $mapping !== null,
            ],
            'employees' => $this->configuration->employees(
                $organisationId,
                $dossierId,
                $revealPii
            ),
            'payrolls' => $this->payrolls->payrolls(
                $organisationId,
                $dossierId,
                $revealPii
            ),
            'payments' => $this->payments->payments($organisationId, $dossierId),
            'liabilities' => $this->payments->liabilities($organisationId, $dossierId),
            'catalog' => $catalog,
            'annual' => [
                'employees' => $summary,
                'employer' => [
                    'gross_cents' => array_sum(array_column($summary, 'brut_centimes')),
                    'net_cents' => array_sum(array_column($summary, 'net_centimes')),
                    'deductions_cents' => array_sum(array_column($summary, 'retenues_centimes')),
                    'employer_charges_cents' => array_sum(
                        array_column($summary, 'charges_employeur_centimes')
                    ),
                    'total_cost_cents' => array_sum(array_column($summary, 'cout_total_centimes')),
                ],
            ],
            'certificates' => $this->certificates->certificates(
                $organisationId,
                $dossierId
            ),
            'selected' => $selected,
            'definitions' => [
                'scope' => 'Genève — aucun envoi Swissdec.',
                'certificate' => 'Préparé, contrôlé puis exporté; toujours non transmis.',
                'rates' => 'Les taux validés sont figés dès leur première fiche validée.',
            ],
        ];
    }
}
