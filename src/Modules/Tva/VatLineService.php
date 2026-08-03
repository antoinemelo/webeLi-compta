<?php
declare(strict_types=1);

namespace Compta\Modules\Tva;

use Compta\Core\Audit\AuditLogger;
use PDO;

final class VatLineService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly VatCalculator $calculator = new VatCalculator(),
    ) {
    }

    /**
     * Calcule une ligne sans deviner sa qualification juridique : le code TVA
     * doit toujours être choisi explicitement par l'appelant.
     *
     * @return array<string,mixed>
     */
    public function quote(
        int $organisationId,
        int $dossierId,
        int $codeId,
        string $serviceDate,
        int $amountCents,
        string $inputMode,
        ?int $deductionBp = null,
        string $reason = '',
        ?int $tdfnId = null,
        int $correctionCents = 0,
        bool $purchaseDocument = false,
    ): array {
        $regime = (new VatConfigurationService($this->pdo, $this->audit))
            ->regimeAt($organisationId, $dossierId, $serviceDate);
        $code = $this->codeAt($organisationId, $dossierId, $codeId, $serviceDate);
        $rateBp = 0;
        if (
            ($regime['statut'] !== 'non_assujetti' || $purchaseDocument)
            && $code['taux_legal_id'] !== null
        ) {
            $rateBp = $this->legalRateAt((int) $code['taux_legal_id'], $serviceDate);
        }
        $amounts = $this->calculator->calculate($amountCents, $rateBp, $inputMode);
        $deduction = $deductionBp ?? (int) $code['deduction_defaut_bp'];
        if ($deduction < 0 || $deduction > 10000) {
            throw new VatException('Part déductible invalide.');
        }
        if (
            $regime['statut'] === 'non_assujetti'
            || $regime['methode'] === 'tdfn'
            || $code['nature'] !== 'prealable'
            || (int) $code['droit_deduction'] !== 1
        ) {
            $deduction = 0;
        }
        if (
            (
                $regime['methode'] === 'effective'
                && $regime['statut'] !== 'non_assujetti'
                && $code['nature'] === 'prealable'
                && $deduction !== (int) $code['deduction_defaut_bp']
            )
            || $correctionCents !== 0
        ) {
            if (trim($reason) === '') {
                throw new VatException('Une correction ou déduction dérogatoire exige un motif.');
            }
        }
        $tdfn = null;
        if ($regime['methode'] === 'tdfn' && $code['nature'] === 'collectee') {
            if ($tdfnId === null) {
                throw new VatException('Activité TDFN requise pour ce chiffre d’affaires.');
            }
            $tdfn = $this->tdfnAt(
                $organisationId,
                $dossierId,
                $tdfnId,
                $serviceDate
            );
        }
        return $amounts + [
            'regime_id' => (int) $regime['id'],
            'regime_status' => $regime['statut'],
            'method' => $regime['methode'],
            'reporting_mode' => $regime['mode_decompte'],
            'code_id' => (int) $code['id'],
            'code' => $code['code'],
            'treatment' => $code['traitement'],
            'nature' => $code['nature'],
            'afc_box' => $code['chiffre_afc'],
            'deduction_bp' => $deduction,
            'deductible_vat_cents' => VatCalculator::divideRounded(
                $amounts['vat_cents'] * $deduction,
                10000
            ),
            'correction_cents' => $correctionCents,
            'reason' => trim($reason),
            'tdfn_id' => $tdfn === null ? null : (int) $tdfn['id'],
            'activity_id' => $tdfn['activite_id'] ?? '',
            'tdfn_rate_bp' => $tdfn === null ? null : (int) $tdfn['taux_bp'],
        ];
    }

    public function attach(
        int $organisationId,
        int $dossierId,
        int $accountingLineId,
        int $codeId,
        string $serviceDate,
        int $amountCents,
        string $inputMode,
        ?int $deductionBp = null,
        string $reason = '',
        ?int $tdfnId = null,
        int $correctionCents = 0,
        array $document = [],
        ?int $actorId = null,
        bool $purchaseDocument = false,
    ): int {
        $quote = $this->quote(
            $organisationId,
            $dossierId,
            $codeId,
            $serviceDate,
            $amountCents,
            $inputMode,
            $deductionBp,
            $reason,
            $tdfnId,
            $correctionCents,
            $purchaseDocument
        );
        $stmt = $this->pdo->prepare(
            'INSERT INTO tva_lignes
             (organisation_id, dossier_id, ligne_ecriture_id, code_tva_id,
              date_prestation, mode_saisie, base_nette_centimes, tva_centimes,
              total_brut_centimes, taux_legal_snapshot_bp, code_snapshot,
              traitement_snapshot, nature_snapshot, chiffre_afc_snapshot,
              deduction_bp, tva_deductible_centimes, correction_centimes,
              motif_correction, tdfn_id, activite_id_snapshot,
              taux_tdfn_snapshot_bp, document_type, document_id,
              document_ligne_id, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId, $dossierId, $accountingLineId, $codeId,
            $serviceDate, $inputMode, $quote['net_cents'], $quote['vat_cents'],
            $quote['gross_cents'], $quote['rate_bp'], $quote['code'],
            $quote['treatment'], $quote['nature'], $quote['afc_box'],
            $quote['deduction_bp'], $quote['deductible_vat_cents'],
            $quote['correction_cents'], $quote['reason'], $quote['tdfn_id'],
            $quote['activity_id'], $quote['tdfn_rate_bp'],
            trim((string) ($document['type'] ?? '')),
            trim((string) ($document['id'] ?? '')),
            trim((string) ($document['line_id'] ?? '')),
            $actorId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->log(
            'tva.ligne_snapshot',
            $actorId,
            $organisationId,
            $dossierId,
            'tva_ligne',
            (string) $id,
            ['code' => $quote['code'], 'taux_bp' => $quote['rate_bp']]
        );
        return $id;
    }

    public function recordPayment(
        int $organisationId,
        int $dossierId,
        int $vatLineId,
        string $paymentDate,
        int $grossCents,
        string $sourceType,
        string $sourceId,
        ?int $actorId = null,
    ): int {
        $line = $this->pdo->prepare(
            'SELECT total_brut_centimes FROM tva_lignes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $line->execute([$vatLineId, $organisationId, $dossierId]);
        $total = $line->fetchColumn();
        if (
            $total === false
            || $grossCents === 0
            || ((int) $total < 0) !== ($grossCents < 0)
            || trim($sourceType) === ''
            || trim($sourceId) === ''
        ) {
            throw new VatException('Encaissement TVA invalide ou hors scope.');
        }
        $allocated = $this->pdo->prepare(
            'SELECT COALESCE(SUM(montant_brut_centimes), 0)
             FROM tva_encaissements WHERE tva_ligne_id = ?'
        );
        $allocated->execute([$vatLineId]);
        if (abs((int) $allocated->fetchColumn() + $grossCents) > abs((int) $total)) {
            throw new VatException('Les paiements dépassent le montant brut de la ligne.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO tva_encaissements
             (organisation_id, dossier_id, tva_ligne_id, date_paiement,
              montant_brut_centimes, source_type, source_id, cree_par)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId, $dossierId, $vatLineId, $paymentDate,
            $grossCents, trim($sourceType), trim($sourceId), $actorId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function codeAt(
        int $organisationId,
        int $dossierId,
        int $codeId,
        string $date,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tva_codes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND actif = 1 AND date_debut <= ?
               AND COALESCE(date_fin, \'9999-12-31\') >= ?'
        );
        $stmt->execute([$codeId, $organisationId, $dossierId, $date, $date]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new VatException('Code TVA absent, expiré ou hors du dossier.');
        }
        return $row;
    }

    private function legalRateAt(int $linkedRateId, string $date): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT current.taux_bp
             FROM tva_taux_legaux linked
             JOIN tva_taux_legaux current ON current.categorie = linked.categorie
             WHERE linked.id = ? AND current.date_debut <= ?
               AND COALESCE(current.date_fin, \'9999-12-31\') >= ?
             ORDER BY current.date_debut DESC LIMIT 1'
        );
        $stmt->execute([$linkedRateId, $date, $date]);
        $rate = $stmt->fetchColumn();
        if ($rate === false) {
            throw new VatException('Aucun taux légal applicable à la date de prestation.');
        }
        return (int) $rate;
    }

    /** @return array<string,mixed> */
    private function tdfnAt(
        int $organisationId,
        int $dossierId,
        int $tdfnId,
        string $date,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tva_tdfn
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND date_debut <= ? AND COALESCE(date_fin, \'9999-12-31\') >= ?'
        );
        $stmt->execute([$tdfnId, $organisationId, $dossierId, $date, $date]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new VatException('TDFN absent, expiré ou hors du dossier.');
        }
        return $row;
    }
}
