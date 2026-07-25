<?php
declare(strict_types=1);

namespace Compta\Modules\Tva;

use Compta\Core\Audit\AuditLogger;
use PDO;
use Throwable;

final class VatStatementService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
    ) {
    }

    public function createPeriod(
        int $organisationId,
        int $dossierId,
        string $start,
        string $end,
        ?int $actorId = null,
    ): int {
        $regime = (new VatConfigurationService($this->pdo, $this->audit))
            ->regimeAt($organisationId, $dossierId, $start);
        if (
            $end < $start
            || ($regime['date_fin'] !== null && $end > $regime['date_fin'])
        ) {
            throw new VatException('La période traverse deux régimes TVA ou ses dates sont invalides.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO tva_periodes
             (organisation_id, dossier_id, regime_tva_id, date_debut, date_fin, cree_par)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $organisationId, $dossierId, $regime['id'], $start, $end, $actorId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function prepare(
        int $organisationId,
        int $dossierId,
        int $periodId,
        ?int $rectifiesId = null,
        ?int $actorId = null,
    ): int {
        $period = $this->period($organisationId, $dossierId, $periodId);
        $regime = $this->regime((int) $period['regime_tva_id']);
        if ($regime['statut'] === 'non_assujetti') {
            throw new VatException('Un dossier non assujetti ne produit pas de décompte TVA.');
        }
        $correctionNumber = 0;
        $submissionType = 1;
        if ($rectifiesId !== null) {
            $original = $this->statement($organisationId, $dossierId, $rectifiesId);
            if (
                (int) $original['periode_tva_id'] !== $periodId
                || !in_array($original['statut'], ['declare', 'paye', 'rembourse'], true)
            ) {
                throw new VatException('Seul un décompte déclaré de cette période peut être rectifié.');
            }
            $max = $this->pdo->prepare(
                'SELECT COALESCE(MAX(numero_correction), 0)
                 FROM tva_decomptes WHERE periode_tva_id = ?'
            );
            $max->execute([$periodId]);
            $correctionNumber = (int) $max->fetchColumn() + 1;
            $submissionType = 2;
        }
        $sources = $regime['mode_decompte'] === 'convenues'
            ? $this->accrualSources($organisationId, $dossierId, $period)
            : $this->cashSources($organisationId, $dossierId, $period);
        $aggregates = $this->aggregate($sources, $regime['methode']);
        $organisation = $this->pdo->prepare(
            'SELECT nom FROM organisations WHERE id = ?'
        );
        $organisation->execute([$organisationId]);
        $name = (string) $organisation->fetchColumn();
        $params = [
            'regime_id' => (int) $regime['id'],
            'status' => $regime['statut'],
            'periodicity' => $regime['periodicite'],
            'verified_on' => $regime['verifie_le'],
            'regulatory_source' => $regime['source_reglementaire'],
        ];
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO tva_decomptes
                 (organisation_id, dossier_id, periode_tva_id, rectifie_de_id,
                  numero_correction, type_soumission, methode_snapshot,
                  mode_decompte_snapshot, numero_tva_snapshot,
                  nom_organisation_snapshot, date_arret, parametres_json,
                  agregats_json, total_chiffre_affaires_centimes,
                  tva_due_centimes, impot_prealable_centimes,
                  corrections_centimes, solde_centimes, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime(\'now\'), ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $organisationId, $dossierId, $periodId, $rectifiesId,
                $correctionNumber, $submissionType, $regime['methode'],
                $regime['mode_decompte'], $regime['numero_tva'], $name,
                $this->json($params), $this->json($aggregates),
                $aggregates['total_turnover_cents'], $aggregates['vat_due_cents'],
                $aggregates['input_tax_cents'], $aggregates['corrections_cents'],
                $aggregates['payable_cents'], $actorId,
            ]);
            $statementId = (int) $this->pdo->lastInsertId();
            $sourceInsert = $this->pdo->prepare(
                'INSERT INTO tva_decompte_sources
                 (decompte_tva_id, tva_ligne_id, encaissement_id, proportion_bp,
                  base_centimes, tva_centimes, tva_deductible_centimes,
                  correction_centimes, brut_centimes, code_snapshot,
                  chiffre_afc_snapshot)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($sources as $source) {
                $sourceInsert->execute([
                    $statementId, $source['id'], $source['payment_id'],
                    $source['proportion_bp'], $source['allocated_net_cents'],
                    $source['allocated_vat_cents'], $source['allocated_input_cents'],
                    $source['allocated_correction_cents'],
                    $source['allocated_gross_cents'], $source['code_snapshot'],
                    $source['chiffre_afc_snapshot'],
                ]);
            }
            $caseInsert = $this->pdo->prepare(
                'INSERT INTO tva_decompte_cases
                 (decompte_tva_id, chiffre_afc, libelle, montant_centimes)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($aggregates['boxes'] as $box => $amount) {
                $caseInsert->execute([
                    $statementId, (string) $box, $this->boxLabel((string) $box), $amount,
                ]);
            }
            $this->pdo->prepare(
                "UPDATE tva_periodes SET statut = 'preparee',
                 modifie_le = datetime('now'), version = version + 1 WHERE id = ?"
            )->execute([$periodId]);
            $this->audit->log(
                $rectifiesId === null ? 'tva.decompte_prepare' : 'tva.decompte_rectificatif_prepare',
                $actorId,
                $organisationId,
                $dossierId,
                'decompte_tva',
                (string) $statementId,
                ['sources' => count($sources), 'rectifie_de' => $rectifiesId]
            );
            $this->pdo->commit();
            return $statementId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,int|string|null> */
    public function generalLedgerReconciliation(
        int $organisationId,
        int $dossierId,
        int $statementId,
    ): array {
        $statement = $this->statement($organisationId, $dossierId, $statementId);
        $period = $this->period(
            $organisationId,
            $dossierId,
            (int) $statement['periode_tva_id']
        );
        $regime = $this->regime((int) $period['regime_tva_id']);
        $dueLedger = $this->accountMovement(
            $organisationId,
            $dossierId,
            (int) $regime['compte_tva_due_id'],
            $period['date_debut'],
            $period['date_fin'],
            'credit'
        );
        $inputLedger = 0;
        foreach ([
            $regime['compte_impot_prealable_materiel_id'],
            $regime['compte_impot_prealable_investissements_id'],
        ] as $accountId) {
            if ($accountId !== null) {
                $inputLedger += $this->accountMovement(
                    $organisationId,
                    $dossierId,
                    (int) $accountId,
                    $period['date_debut'],
                    $period['date_fin'],
                    'debit'
                );
            }
        }
        return [
            'method' => $statement['methode_snapshot'],
            'vat_due_statement_cents' => (int) $statement['tva_due_centimes'],
            'vat_due_ledger_cents' => $dueLedger,
            'vat_due_difference_cents' => (int) $statement['tva_due_centimes'] - $dueLedger,
            'input_tax_statement_cents' => (int) $statement['impot_prealable_centimes'],
            'input_tax_ledger_cents' => $inputLedger,
            'input_tax_difference_cents' => (int) $statement['impot_prealable_centimes'] - $inputLedger,
        ];
    }

    public function control(
        int $organisationId,
        int $dossierId,
        int $statementId,
        ?int $actorId = null,
    ): void {
        $statement = $this->statement($organisationId, $dossierId, $statementId);
        if ($statement['statut'] !== 'prepare') {
            throw new VatException('Seul un décompte préparé peut être contrôlé.');
        }
        $reconciliation = $this->generalLedgerReconciliation(
            $organisationId,
            $dossierId,
            $statementId
        );
        if (
            $statement['methode_snapshot'] === 'effective'
            && (
                $reconciliation['vat_due_difference_cents'] !== 0
                || $reconciliation['input_tax_difference_cents'] !== 0
            )
        ) {
            throw new VatException('Le décompte ne concorde pas avec le grand livre TVA.');
        }
        $update = $this->pdo->prepare(
            "UPDATE tva_decomptes SET statut = 'controle',
             controle_le = datetime('now'), controle_par = ?
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND statut = 'prepare'"
        );
        $update->execute([$actorId, $statementId, $organisationId, $dossierId]);
        $this->pdo->prepare(
            "UPDATE tva_periodes SET statut = 'controlee',
             modifie_le = datetime('now'), version = version + 1
             WHERE id = ?"
        )->execute([$statement['periode_tva_id']]);
    }

    public function markDeclared(
        int $organisationId,
        int $dossierId,
        int $statementId,
        ?int $actorId = null,
    ): void {
        $stmt = $this->pdo->prepare(
            "UPDATE tva_decomptes SET statut = 'declare',
             declare_le = datetime('now'), declare_par = ?
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND statut = 'exporte'"
        );
        $stmt->execute([$actorId, $statementId, $organisationId, $dossierId]);
        if ($stmt->rowCount() !== 1) {
            throw new VatException('Le décompte doit être contrôlé puis exporté avant déclaration.');
        }
        $this->audit->log(
            'tva.decompte_declare_manuellement',
            $actorId,
            $organisationId,
            $dossierId,
            'decompte_tva',
            (string) $statementId,
            ['transmission_automatique' => false]
        );
    }

    /** @return list<array<string,mixed>> */
    public function drillDown(
        int $organisationId,
        int $dossierId,
        int $statementId,
        string $box = '',
    ): array {
        $this->statement($organisationId, $dossierId, $statementId);
        $sql = 'SELECT s.*, t.ligne_ecriture_id, t.date_prestation,
                       e.id AS ecriture_id, e.numero, e.date_comptable, e.libelle
                FROM tva_decompte_sources s
                JOIN tva_lignes t ON t.id = s.tva_ligne_id
                JOIN lignes_ecriture l ON l.id = t.ligne_ecriture_id
                JOIN ecritures e ON e.id = l.ecriture_id
                WHERE s.decompte_tva_id = ?';
        $params = [$statementId];
        if ($box !== '') {
            $sql .= ' AND s.chiffre_afc_snapshot = ?';
            $params[] = $box;
        }
        $sql .= ' ORDER BY e.date_comptable, e.id, t.id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    private function accrualSources(int $organisationId, int $dossierId, array $period): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, e.date_comptable, c.type AS account_type,
                    NULL AS payment_id, 10000 AS proportion_bp,
                    t.base_nette_centimes AS allocated_net_cents,
                    t.tva_centimes AS allocated_vat_cents,
                    t.tva_deductible_centimes AS allocated_input_cents,
                    t.total_brut_centimes AS allocated_gross_cents,
                    t.correction_centimes AS allocated_correction_cents
             FROM tva_lignes t
             JOIN lignes_ecriture l ON l.id = t.ligne_ecriture_id
             JOIN ecritures e ON e.id = l.ecriture_id
             JOIN comptes c ON c.id = l.compte_id
             WHERE t.organisation_id = ? AND t.dossier_id = ?
               AND e.date_comptable BETWEEN ? AND ?
               AND e.statut IN (\'validee\', \'contre_passee\')
             ORDER BY e.date_comptable, t.id'
        );
        $stmt->execute([
            $organisationId, $dossierId, $period['date_debut'], $period['date_fin'],
        ]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    private function cashSources(int $organisationId, int $dossierId, array $period): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, e.date_comptable, c.type AS account_type,
                    p.id AS payment_id, p.montant_brut_centimes,
                    COALESCE((
                        SELECT SUM(previous.montant_brut_centimes)
                        FROM tva_encaissements previous
                        WHERE previous.tva_ligne_id = p.tva_ligne_id
                          AND (
                            previous.date_paiement < p.date_paiement
                            OR (
                              previous.date_paiement = p.date_paiement
                              AND previous.id < p.id
                            )
                          )
                    ), 0) AS brut_precedemment_alloue
             FROM tva_encaissements p
             JOIN tva_lignes t ON t.id = p.tva_ligne_id
             JOIN lignes_ecriture l ON l.id = t.ligne_ecriture_id
             JOIN ecritures e ON e.id = l.ecriture_id
             JOIN comptes c ON c.id = l.compte_id
             WHERE p.organisation_id = ? AND p.dossier_id = ?
               AND p.date_paiement BETWEEN ? AND ?
               AND e.statut IN (\'validee\', \'contre_passee\')
             ORDER BY p.date_paiement, p.id'
        );
        $stmt->execute([
            $organisationId, $dossierId, $period['date_debut'], $period['date_fin'],
        ]);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $gross = (int) $row['montant_brut_centimes'];
            $lineGross = (int) $row['total_brut_centimes'];
            if ($lineGross === 0) {
                continue;
            }
            $previousGross = (int) $row['brut_precedemment_alloue'];
            $cumulativeGross = $previousGross + $gross;
            $row['proportion_bp'] = VatCalculator::divideRounded($gross * 10000, $lineGross);
            $row['allocated_gross_cents'] = $gross;
            $row['allocated_vat_cents'] = VatCalculator::divideRounded(
                (int) $row['tva_centimes'] * $cumulativeGross,
                $lineGross
            ) - VatCalculator::divideRounded(
                (int) $row['tva_centimes'] * $previousGross,
                $lineGross
            );
            $row['allocated_net_cents'] = $gross - $row['allocated_vat_cents'];
            $row['allocated_input_cents'] = VatCalculator::divideRounded(
                (int) $row['tva_deductible_centimes'] * $cumulativeGross,
                $lineGross
            ) - VatCalculator::divideRounded(
                (int) $row['tva_deductible_centimes'] * $previousGross,
                $lineGross
            );
            $row['allocated_correction_cents'] = VatCalculator::divideRounded(
                (int) $row['correction_centimes'] * $cumulativeGross,
                $lineGross
            ) - VatCalculator::divideRounded(
                (int) $row['correction_centimes'] * $previousGross,
                $lineGross
            );
            $result[] = $row;
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $sources @return array<string,mixed> */
    private function aggregate(array $sources, string $method): array
    {
        $turnover = 0;
        $due = 0;
        $input = 0;
        $corrections = 0;
        $boxes = [];
        $legalRates = [];
        $tdfnRates = [];
        $acquisitionRates = [];
        $acquisitionDue = 0;
        foreach ($sources as $source) {
            $nature = $source['nature_snapshot'];
            $isSale = $nature === 'collectee'
                || ($nature === 'non_taxable' && $source['account_type'] === 'produit');
            if ($isSale) {
                $turnover += (int) $source['allocated_gross_cents'];
            }
            if ($method === 'effective') {
                if (in_array($nature, ['collectee', 'acquisition'], true)) {
                    $due += (int) $source['allocated_vat_cents'];
                }
                if ($nature === 'prealable') {
                    $input += (int) $source['allocated_input_cents'];
                }
                if ($nature === 'collectee') {
                    $rate = (int) $source['taux_legal_snapshot_bp'];
                    $legalRates[$rate] = ($legalRates[$rate] ?? 0)
                        + (int) $source['allocated_net_cents'];
                }
            } else {
                if ($nature === 'collectee') {
                    $key = $source['activite_id_snapshot'] . ':'
                        . $source['taux_tdfn_snapshot_bp'];
                    $tdfnRates[$key] = ($tdfnRates[$key] ?? 0)
                        + (int) $source['allocated_gross_cents'];
                } elseif ($nature === 'acquisition') {
                    $acquisitionDue += (int) $source['allocated_vat_cents'];
                }
            }
            if ($nature === 'acquisition') {
                $rate = (int) $source['taux_legal_snapshot_bp'];
                $acquisitionRates[$rate] = ($acquisitionRates[$rate] ?? 0)
                    + (int) $source['allocated_net_cents'];
            }
            $corrections += (int) $source['allocated_correction_cents'];
            $box = trim((string) $source['chiffre_afc_snapshot']);
            if ($box !== '') {
                $boxAmount = match ($box) {
                    '400', '405' => (int) $source['allocated_input_cents'],
                    '410', '415', '420' => (int) $source['allocated_correction_cents'],
                    '900', '910' => (int) $source['allocated_gross_cents'],
                    default => (int) $source['allocated_net_cents'],
                };
                $boxes[$box] = ($boxes[$box] ?? 0) + $boxAmount;
            }
        }
        $boxes['200'] = $turnover;
        $boxes[$due - $input + $corrections >= 0 ? '500' : '510']
            = $due - $input + $corrections;
        ksort($boxes);
        ksort($legalRates);
        ksort($tdfnRates);
        ksort($acquisitionRates);
        if ($method === 'tdfn') {
            $due = $acquisitionDue;
            foreach ($tdfnRates as $key => $gross) {
                [, $rate] = explode(':', (string) $key, 2);
                $due += VatCalculator::divideRounded((int) $gross * (int) $rate, 10000);
            }
        }
        return [
            'total_turnover_cents' => $turnover,
            'vat_due_cents' => $due,
            'input_tax_cents' => $method === 'effective' ? $input : 0,
            'corrections_cents' => $corrections,
            'payable_cents' => $due - ($method === 'effective' ? $input : 0) + $corrections,
            'legal_rate_turnover' => $legalRates,
            'tdfn_turnover' => $tdfnRates,
            'acquisition_turnover' => $acquisitionRates,
            'boxes' => $boxes,
        ];
    }

    private function accountMovement(
        int $organisationId,
        int $dossierId,
        int $accountId,
        string $start,
        string $end,
        string $normalSide,
    ): int {
        if ($accountId < 1) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(l.debit_centimes), 0) AS debit,
                    COALESCE(SUM(l.credit_centimes), 0) AS credit
             FROM lignes_ecriture l JOIN ecritures e ON e.id = l.ecriture_id
             WHERE e.organisation_id = ? AND e.dossier_id = ? AND l.compte_id = ?
               AND e.date_comptable BETWEEN ? AND ?
               AND e.statut IN (\'validee\', \'contre_passee\')'
        );
        $stmt->execute([$organisationId, $dossierId, $accountId, $start, $end]);
        $row = $stmt->fetch();
        return $normalSide === 'credit'
            ? (int) $row['credit'] - (int) $row['debit']
            : (int) $row['debit'] - (int) $row['credit'];
    }

    /** @return array<string,mixed> */
    private function period(int $organisationId, int $dossierId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tva_periodes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$id, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new VatException('Période TVA introuvable dans ce dossier.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function regime(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tva_regimes WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new VatException('Régime TVA introuvable.');
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function statement(int $organisationId, int $dossierId, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tva_decomptes
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$id, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new VatException('Décompte TVA introuvable dans ce dossier.');
        }
        return $row;
    }

    private function boxLabel(string $box): string
    {
        return match ($box) {
            '200' => 'Total des contre-prestations',
            '220' => 'Prestations exonérées à l’étranger',
            '221' => 'Prestations fournies à l’étranger',
            '230' => 'Prestations exclues du champ',
            '235' => 'Diminutions de la contre-prestation',
            '400' => 'Impôt préalable matériel et services',
            '405' => 'Impôt préalable investissements',
            '415' => 'Corrections de l’impôt préalable',
            '420' => 'Réductions de l’impôt préalable',
            '500' => 'Montant à payer',
            '510' => 'Créance',
            '900' => 'Subventions',
            '910' => 'Dons et autres flux',
            default => 'Chiffre AFC ' . $box,
        };
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
