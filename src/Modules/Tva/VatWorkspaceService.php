<?php
declare(strict_types=1);

namespace Compta\Modules\Tva;

use PDO;

final class VatWorkspaceService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly VatStatementService $statements,
        private readonly Ech0217ExportService $exports,
    ) {
    }

    /** @return array<string,mixed> */
    public function read(
        int $organisationId,
        int $dossierId,
        string $exerciseStart,
        string $exerciseEnd,
        ?int $selectedStatementId = null,
    ): array {
        $regimeStmt = $this->pdo->prepare(
            'SELECT id, statut, numero_tva, methode, mode_decompte,
                    periodicite, date_debut, date_fin,
                    source_reglementaire, verifie_le
             FROM tva_regimes
             WHERE organisation_id = ? AND dossier_id = ?
               AND date_debut <= ?
               AND (date_fin IS NULL OR date_fin >= ?)
             ORDER BY date_debut DESC LIMIT 1'
        );
        $regimeStmt->execute([
            $organisationId,
            $dossierId,
            $exerciseEnd,
            $exerciseStart,
        ]);
        $regime = $regimeStmt->fetch();

        $periodStmt = $this->pdo->prepare(
            'SELECT p.id, p.date_debut, p.date_fin, p.statut, p.version,
                    p.regime_tva_id
             FROM tva_periodes p
             WHERE p.organisation_id = ? AND p.dossier_id = ?
               AND p.date_debut <= ? AND p.date_fin >= ?
             ORDER BY p.date_debut DESC, p.id DESC'
        );
        $periodStmt->execute([
            $organisationId,
            $dossierId,
            $exerciseEnd,
            $exerciseStart,
        ]);
        $periods = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'start_date' => (string) $row['date_debut'],
            'end_date' => (string) $row['date_fin'],
            'status' => (string) $row['statut'],
            'version' => (int) $row['version'],
            'regime_id' => (int) $row['regime_tva_id'],
        ], $periodStmt->fetchAll());

        $statementStmt = $this->pdo->prepare(
            'SELECT d.id, d.periode_tva_id, d.rectifie_de_id,
                    d.numero_correction, d.type_soumission, d.statut,
                    d.methode_snapshot, d.mode_decompte_snapshot,
                    d.numero_tva_snapshot, d.date_arret,
                    d.total_chiffre_affaires_centimes,
                    d.tva_due_centimes, d.impot_prealable_centimes,
                    d.corrections_centimes, d.solde_centimes,
                    d.cree_le, d.controle_le, d.declare_le,
                    p.date_debut, p.date_fin
             FROM tva_decomptes d
             JOIN tva_periodes p ON p.id = d.periode_tva_id
             WHERE d.organisation_id = ? AND d.dossier_id = ?
               AND p.date_debut <= ? AND p.date_fin >= ?
             ORDER BY p.date_debut DESC, d.numero_correction DESC, d.id DESC'
        );
        $statementStmt->execute([
            $organisationId,
            $dossierId,
            $exerciseEnd,
            $exerciseStart,
        ]);
        $statements = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'period_id' => (int) $row['periode_tva_id'],
            'corrects_id' => $row['rectifie_de_id'] === null
                ? null : (int) $row['rectifie_de_id'],
            'correction_number' => (int) $row['numero_correction'],
            'submission_type' => (int) $row['type_soumission'],
            'status' => (string) $row['statut'],
            'method' => (string) $row['methode_snapshot'],
            'reporting_mode' => (string) $row['mode_decompte_snapshot'],
            'vat_number' => (string) $row['numero_tva_snapshot'],
            'snapshot_at' => (string) $row['date_arret'],
            'start_date' => (string) $row['date_debut'],
            'end_date' => (string) $row['date_fin'],
            'turnover_cents' => (int) $row['total_chiffre_affaires_centimes'],
            'vat_due_cents' => (int) $row['tva_due_centimes'],
            'input_tax_cents' => (int) $row['impot_prealable_centimes'],
            'corrections_cents' => (int) $row['corrections_centimes'],
            'balance_cents' => (int) $row['solde_centimes'],
            'created_at' => (string) $row['cree_le'],
            'controlled_at' => $row['controle_le'],
            'declared_at' => $row['declare_le'],
        ], $statementStmt->fetchAll());

        if ($selectedStatementId === null && $statements !== []) {
            $selectedStatementId = (int) $statements[0]['id'];
        }
        $detail = $selectedStatementId === null
            ? null
            : $this->detail(
                $organisationId,
                $dossierId,
                $selectedStatementId,
                $statements
            );
        return [
            'regime' => $regime === false ? null : [
                'id' => (int) $regime['id'],
                'status' => (string) $regime['statut'],
                'vat_number' => (string) $regime['numero_tva'],
                'method' => (string) $regime['methode'],
                'reporting_mode' => (string) $regime['mode_decompte'],
                'frequency' => (string) $regime['periodicite'],
                'start_date' => (string) $regime['date_debut'],
                'end_date' => $regime['date_fin'],
                'regulatory_source' => (string) $regime['source_reglementaire'],
                'verified_on' => (string) $regime['verifie_le'],
            ],
            'periods' => $periods,
            'statements' => $statements,
            'selected_statement' => $detail,
            'standard' => [
                'format' => 'eCH-0217',
                'version' => Ech0217Validator::VERSION,
                'verified_on' => Ech0217ExportService::VERIFIED_ON,
                'transmission' => 'manuelle',
                'transmitted_by_application' => false,
            ],
        ];
    }

    public function createPeriod(
        int $organisationId,
        int $dossierId,
        string $start,
        string $end,
        int $actorId,
    ): int {
        return $this->statements->createPeriod(
            $organisationId,
            $dossierId,
            $start,
            $end,
            $actorId
        );
    }

    public function prepare(
        int $organisationId,
        int $dossierId,
        int $periodId,
        ?int $correctsId,
        int $actorId,
    ): int {
        return $this->statements->prepare(
            $organisationId,
            $dossierId,
            $periodId,
            $correctsId,
            $actorId
        );
    }

    public function control(
        int $organisationId,
        int $dossierId,
        int $statementId,
        int $actorId,
    ): void {
        $this->statements->control(
            $organisationId,
            $dossierId,
            $statementId,
            $actorId
        );
    }

    /** @return array<string,mixed> */
    public function export(
        int $organisationId,
        int $dossierId,
        int $statementId,
        int $actorId,
    ): array {
        $result = $this->exports->export(
            $organisationId,
            $dossierId,
            $statementId,
            $actorId
        );
        return [
            'id' => $result['id'],
            'schema_valid' => $result['schema_valid'],
            'errors' => $result['errors'],
            'transmitted' => false,
            'hash' => hash('sha256', $result['xml']),
        ];
    }

    public function declare(
        int $organisationId,
        int $dossierId,
        int $statementId,
        int $actorId,
    ): void {
        $this->statements->markDeclared(
            $organisationId,
            $dossierId,
            $statementId,
            $actorId
        );
    }

    /** @return array{xml:string,hash:string,statement_id:int} */
    public function exportContent(
        int $organisationId,
        int $dossierId,
        int $exportId,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT contenu_xml, empreinte_sha256, decompte_tva_id
             FROM tva_exports
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$exportId, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new VatException('Export TVA absent du dossier.');
        }
        return [
            'xml' => (string) $row['contenu_xml'],
            'hash' => (string) $row['empreinte_sha256'],
            'statement_id' => (int) $row['decompte_tva_id'],
        ];
    }

    /** @param list<array<string,mixed>> $statementRows */
    private function detail(
        int $organisationId,
        int $dossierId,
        int $statementId,
        array $statementRows,
    ): array {
        $statement = null;
        foreach ($statementRows as $candidate) {
            if ((int) $candidate['id'] === $statementId) {
                $statement = $candidate;
                break;
            }
        }
        if ($statement === null) {
            throw new VatException('Décompte TVA absent de cet exercice.');
        }
        $boxes = $this->pdo->prepare(
            'SELECT chiffre_afc, libelle, montant_centimes
             FROM tva_decompte_cases
             WHERE decompte_tva_id = ?
             ORDER BY CAST(chiffre_afc AS INTEGER), chiffre_afc'
        );
        $boxes->execute([$statementId]);
        $exports = $this->pdo->prepare(
            'SELECT id, format, version_schema, empreinte_sha256,
                    schema_valide, transmis, cree_le
             FROM tva_exports
             WHERE organisation_id = ? AND dossier_id = ?
               AND decompte_tva_id = ?
             ORDER BY id DESC'
        );
        $exports->execute([$organisationId, $dossierId, $statementId]);
        return [
            'summary' => $statement,
            'boxes' => array_map(static fn (array $row): array => [
                'code' => (string) $row['chiffre_afc'],
                'label' => (string) $row['libelle'],
                'amount_cents' => (int) $row['montant_centimes'],
            ], $boxes->fetchAll()),
            'reconciliation' => $this->statements->generalLedgerReconciliation(
                $organisationId,
                $dossierId,
                $statementId
            ),
            'sources' => array_map(static fn (array $row): array => [
                'vat_line_id' => (int) $row['tva_ligne_id'],
                'payment_id' => $row['encaissement_id'] === null
                    ? null : (int) $row['encaissement_id'],
                'entry_id' => (int) $row['ecriture_id'],
                'entry_number' => (string) $row['numero'],
                'date' => (string) $row['date_comptable'],
                'label' => (string) $row['libelle'],
                'box' => (string) $row['chiffre_afc_snapshot'],
                'base_cents' => (int) $row['base_centimes'],
                'vat_cents' => (int) $row['tva_centimes'],
                'input_tax_cents' => (int) $row['tva_deductible_centimes'],
                'gross_cents' => (int) $row['brut_centimes'],
            ], $this->statements->drillDown(
                $organisationId,
                $dossierId,
                $statementId
            )),
            'exports' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'format' => (string) $row['format'],
                'schema_version' => (string) $row['version_schema'],
                'hash' => (string) $row['empreinte_sha256'],
                'schema_valid' => (int) $row['schema_valide'] === 1,
                'transmitted' => false,
                'created_at' => (string) $row['cree_le'],
            ], $exports->fetchAll()),
        ];
    }
}
