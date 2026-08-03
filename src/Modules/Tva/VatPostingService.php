<?php
declare(strict_types=1);

namespace Compta\Modules\Tva;

use Compta\Core\Audit\AuditLogger;
use PDO;

final class VatPostingService
{
    private VatLineService $vat;

    public function __construct(PDO $pdo, AuditLogger $audit)
    {
        $this->vat = new VatLineService($pdo, $audit);
    }

    /**
     * @return array{quote:array<string,mixed>,lines:list<array<string,int|string>>}
     */
    public function sale(
        int $organisationId,
        int $dossierId,
        int $codeId,
        string $serviceDate,
        int $amountCents,
        string $inputMode,
        int $receivableAccountId,
        int $revenueAccountId,
        int $vatDueAccountId,
        ?int $tdfnId = null,
    ): array {
        $quote = $this->vat->quote(
            $organisationId,
            $dossierId,
            $codeId,
            $serviceDate,
            $amountCents,
            $inputMode,
            tdfnId: $tdfnId
        );
        $lines = $this->signedLines(
            (int) $quote['gross_cents'],
            $receivableAccountId,
            [
                [$revenueAccountId, -(int) $quote['net_cents']],
                [$vatDueAccountId, -(int) $quote['vat_cents']],
            ]
        );
        return ['quote' => $quote, 'lines' => $lines];
    }

    /**
     * @return array{quote:array<string,mixed>,lines:list<array<string,int|string>>}
     */
    public function purchase(
        int $organisationId,
        int $dossierId,
        int $codeId,
        string $serviceDate,
        int $amountCents,
        string $inputMode,
        int $payableAccountId,
        int $expenseAccountId,
        int $inputVatAccountId,
        ?int $deductionBp = null,
        string $reason = '',
    ): array {
        $quote = $this->vat->quote(
            $organisationId,
            $dossierId,
            $codeId,
            $serviceDate,
            $amountCents,
            $inputMode,
            $deductionBp,
            $reason,
            purchaseDocument: true
        );
        $deductible = (int) $quote['deductible_vat_cents'];
        $expense = (int) $quote['gross_cents'] - $deductible;
        $lines = $this->signedLines(
            -(int) $quote['gross_cents'],
            $payableAccountId,
            [
                [$expenseAccountId, $expense],
                [$inputVatAccountId, $deductible],
            ]
        );
        return ['quote' => $quote, 'lines' => $lines];
    }

    /**
     * Le premier montant représente le compte de tiers. Les contreparties ont
     * le signe opposé. Signe positif = débit, négatif = crédit.
     *
     * @param list<array{0:int,1:int}> $counterparts
     * @return list<array<string,int|string>>
     */
    private function signedLines(int $mainAmount, int $mainAccount, array $counterparts): array
    {
        $lines = [];
        $append = static function (array &$target, int $account, int $signed): void {
            if ($signed === 0) {
                return;
            }
            $target[] = $signed > 0
                ? ['compte_id' => $account, 'debit_centimes' => $signed]
                : ['compte_id' => $account, 'credit_centimes' => abs($signed)];
        };
        $append($lines, $mainAccount, $mainAmount);
        foreach ($counterparts as [$account, $signed]) {
            $append($lines, $account, $signed);
        }
        return $lines;
    }
}
