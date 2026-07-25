<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie;

use PDO;

final class TreasuryStateService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function state(
        int $organisationId,
        int $dossierId,
        int $treasuryAccountId,
        string $asOfDate = '',
    ): array {
        $accountStmt = $this->pdo->prepare(
            'SELECT * FROM comptes_tresorerie
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $accountStmt->execute([$treasuryAccountId, $organisationId, $dossierId]);
        $account = $accountStmt->fetch();
        if ($account === false) {
            throw new TreasuryException('Compte de trésorerie introuvable dans ce dossier.');
        }
        $asOfDate = $asOfDate ?: '9999-12-31';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate) !== 1) {
            throw new TreasuryException('Date d’état invalide.');
        }
        $bank = $this->pdo->prepare(
            'SELECT montant_centimes, date_solde, monnaie, type
             FROM soldes_bancaires
             WHERE organisation_id = ? AND dossier_id = ?
               AND compte_tresorerie_id = ? AND date_solde <= ?
             ORDER BY date_solde DESC,
               CASE type WHEN \'CLBD\' THEN 0 WHEN \'ITBD\' THEN 1 ELSE 2 END,
               id DESC LIMIT 1'
        );
        $bank->execute([$organisationId, $dossierId, $treasuryAccountId, $asOfDate]);
        $bankBalance = $bank->fetch();
        $accounting = $this->pdo->prepare(
            "SELECT COALESCE(SUM(l.debit_centimes - l.credit_centimes), 0)
             FROM lignes_ecriture l
             JOIN ecritures e ON e.id = l.ecriture_id
             WHERE e.organisation_id = ? AND e.dossier_id = ?
               AND l.compte_id = ? AND e.date_comptable <= ?
               AND e.statut IN ('validee', 'contre_passee')"
        );
        $accounting->execute([
            $organisationId,
            $dossierId,
            $account['compte_comptable_id'],
            $asOfDate,
        ]);
        $accountingCents = (int) $accounting->fetchColumn()
            * (int) $account['multiplicateur_comptable'];
        $bankCents = $bankBalance === false ? null : (int) $bankBalance['montant_centimes'];
        return [
            'treasury_account_id' => $treasuryAccountId,
            'currency' => $account['monnaie'],
            'bank_balance_cents' => $bankCents,
            'bank_balance_date' => $bankBalance['date_solde'] ?? null,
            'accounting_balance_cents' => $accountingCents,
            'difference_cents' => $bankCents === null ? null : $bankCents - $accountingCents,
        ];
    }
}
