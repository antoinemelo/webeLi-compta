<?php
declare(strict_types=1);

namespace Compta\Modules\Compta;

use Compta\Core\Audit\AuditLogger;
use PDO;
use PDOException;

final class ClosingAndTaxService
{
    private const MANUAL_CONTROLS = [
        'pieces' => 'Pièces justificatives revues',
        'ajustements' => 'Ajustements de clôture revus',
        'revue_fiscale' => 'Revue fiscale externe ou interne effectuée',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditLogger $audit,
        private readonly AccountingSetupService $setup,
        private readonly FinancialReportingService $financial,
    ) {
    }

    /** @return array<string,mixed> */
    public function read(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $dateStart,
        string $dateEnd,
        array $reports,
        array $vat,
    ): array {
        $periods = $this->periods(
            $organisationId,
            $dossierId,
            $exerciseId
        );
        $automatic = $this->automaticControls(
            $organisationId,
            $dossierId,
            $exerciseId,
            $dateStart,
            $dateEnd,
            $reports
        );
        $manual = $this->manualControls(
            $organisationId,
            $dossierId,
            $exerciseId
        );
        $taxFile = $this->taxFile(
            $organisationId,
            $dossierId,
            $exerciseId,
            $dateStart,
            $dateEnd,
            $vat
        );
        return [
            'closing' => [
                'periods' => $periods,
                'automatic_controls' => $automatic,
                'manual_controls' => $manual,
                'can_close' => !in_array(
                    false,
                    array_column($automatic, 'passed'),
                    true
                ),
                'archives' => $this->archives(
                    $organisationId,
                    $dossierId,
                    $exerciseId
                ),
                'definition' =>
                    'La fermeture verrouille les nouvelles écritures ; elle ne crée ni écriture de résultat ni déclaration.',
            ],
            'tax_file' => $taxFile,
        ];
    }

    public function saveManualControl(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $code,
        string $status,
        string $note,
        int $expectedVersion,
        int $actorId,
    ): void {
        if (
            !isset(self::MANUAL_CONTROLS[$code])
            || !in_array(
                $status,
                ['a_faire', 'termine', 'non_applicable'],
                true
            )
        ) {
            throw new AccountingException('Contrôle de clôture invalide.');
        }
        $this->assertExercise(
            $organisationId,
            $dossierId,
            $exerciseId
        );
        $existing = $this->pdo->prepare(
            'SELECT id, version FROM controles_cloture
             WHERE exercice_id = ? AND code = ?'
        );
        $existing->execute([$exerciseId, $code]);
        $row = $existing->fetch();
        if ($row === false) {
            if ($expectedVersion !== 0) {
                throw new AccountingException(
                    'Contrôle de clôture modifié par un autre utilisateur.'
                );
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO controles_cloture
                 (organisation_id, dossier_id, exercice_id, code,
                  statut, note, modifie_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId,
                $dossierId,
                $exerciseId,
                $code,
                $status,
                trim($note),
                $actorId,
            ]);
        } else {
            $stmt = $this->pdo->prepare(
                "UPDATE controles_cloture
                 SET statut = ?, note = ?, modifie_le = datetime('now'),
                     modifie_par = ?, version = version + 1
                 WHERE id = ? AND version = ?"
            );
            $stmt->execute([
                $status,
                trim($note),
                $actorId,
                (int) $row['id'],
                $expectedVersion,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new AccountingException(
                    'Contrôle de clôture modifié par un autre utilisateur.'
                );
            }
        }
        $this->audit->log(
            'compta.controle_cloture_modifie',
            $actorId,
            $organisationId,
            $dossierId,
            'exercice',
            (string) $exerciseId,
            ['code' => $code, 'statut' => $status]
        );
    }

    public function setPeriodStatus(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        int $periodId,
        string $status,
        int $expectedVersion,
        int $actorId,
    ): void {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $period = null;
            $reports = null;
            $controls = [];
            if ($status === 'fermee') {
                $period = $this->period(
                    $organisationId,
                    $dossierId,
                    $exerciseId,
                    $periodId
                );
                $reports = $this->financial->read(
                    $organisationId,
                    $dossierId,
                    $exerciseId,
                    (string) $period['exercise_start'],
                    (string) $period['date_fin']
                );
                $controls = $this->automaticControls(
                    $organisationId,
                    $dossierId,
                    $exerciseId,
                    (string) $period['date_debut'],
                    (string) $period['date_fin'],
                    $reports
                );
                foreach ($controls as $control) {
                    if (!$control['passed']) {
                        throw new AccountingException(
                            'Clôture refusée : ' . $control['label'] . '.'
                        );
                    }
                }
            }
            $this->setup->setPeriodStatus(
                $organisationId,
                $dossierId,
                $periodId,
                $expectedVersion,
                $status,
                $actorId
            );
            if ($status === 'fermee' && $period !== null && $reports !== null) {
                $this->archive(
                    $organisationId,
                    $dossierId,
                    $exerciseId,
                    'cloture',
                    (string) $period['exercise_start'],
                    (string) $period['date_fin'],
                    [
                        'reports' => $reports,
                        'closing' => [
                            'period' => [
                                'id' => $periodId,
                                'label' => (string) $period['libelle'],
                                'start_date' => (string) $period['date_debut'],
                                'end_date' => (string) $period['date_fin'],
                                'status' => 'fermee',
                            ],
                            'automatic_controls' => $controls,
                            'manual_controls' => $this->manualControls(
                                $organisationId,
                                $dossierId,
                                $exerciseId
                            ),
                        ],
                    ],
                    $actorId
                );
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function createAdjustment(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $label,
        string $nature,
        int $amountCents,
        string $note,
        string $idempotencyKey,
        int $actorId,
    ): int {
        if (
            trim($label) === ''
            || !in_array(
                $nature,
                ['augmentation', 'deduction', 'information'],
                true
            )
            || $amountCents < 0
            || trim($idempotencyKey) === ''
        ) {
            throw new AccountingException('Ajustement fiscal invalide.');
        }
        $this->assertExercise(
            $organisationId,
            $dossierId,
            $exerciseId
        );
        $existing = $this->pdo->prepare(
            'SELECT id, exercice_id, libelle, nature, montant_centimes, note
             FROM ajustements_fiscaux
             WHERE dossier_id = ? AND cle_idempotence = ?'
        );
        $existing->execute([$dossierId, trim($idempotencyKey)]);
        $existingRow = $existing->fetch();
        if ($existingRow !== false) {
            $this->assertSameAdjustment(
                $existingRow,
                $exerciseId,
                $label,
                $nature,
                $amountCents,
                $note
            );
            return (int) $existingRow['id'];
        }
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ajustements_fiscaux
                 (organisation_id, dossier_id, exercice_id, libelle, nature,
                  montant_centimes, note, cle_idempotence, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $organisationId,
                $dossierId,
                $exerciseId,
                trim($label),
                $nature,
                $amountCents,
                trim($note),
                trim($idempotencyKey),
                $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
        } catch (PDOException $exception) {
            $existing->execute([$dossierId, trim($idempotencyKey)]);
            $existingRow = $existing->fetch();
            if ($existingRow === false) {
                throw $exception;
            }
            $this->assertSameAdjustment(
                $existingRow,
                $exerciseId,
                $label,
                $nature,
                $amountCents,
                $note
            );
            $id = (int) $existingRow['id'];
        }
        $this->audit->log(
            'compta.ajustement_fiscal_prepare',
            $actorId,
            $organisationId,
            $dossierId,
            'ajustement_fiscal',
            (string) $id,
            ['nature' => $nature, 'montant_centimes' => $amountCents]
        );
        return (int) $id;
    }

    public function setAdjustmentStatus(
        int $organisationId,
        int $dossierId,
        int $adjustmentId,
        string $status,
        int $expectedVersion,
        int $actorId,
    ): void {
        if (!in_array($status, ['propose', 'valide', 'ecarte'], true)) {
            throw new AccountingException('Statut d’ajustement fiscal invalide.');
        }
        $stmt = $this->pdo->prepare(
            "UPDATE ajustements_fiscaux
             SET statut = ?, modifie_le = datetime('now'), modifie_par = ?,
                 version = version + 1
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?
               AND version = ?"
        );
        $stmt->execute([
            $status,
            $actorId,
            $adjustmentId,
            $organisationId,
            $dossierId,
            $expectedVersion,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new AccountingException(
                'Ajustement absent ou modifié par un autre utilisateur.'
            );
        }
        $this->audit->log(
            'compta.ajustement_fiscal_statut_modifie',
            $actorId,
            $organisationId,
            $dossierId,
            'ajustement_fiscal',
            (string) $adjustmentId,
            ['statut' => $status]
        );
    }

    public function archive(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $type,
        string $dateStart,
        string $dateEnd,
        array $payload,
        int $actorId,
    ): int {
        if (!in_array($type, ['cloture', 'dossier_fiscal'], true)) {
            throw new AccountingException('Type d’archive financière invalide.');
        }
        $parameters = [
            'exercise_id' => $exerciseId,
            'date_start' => $dateStart,
            'date_end' => $dateEnd,
            'type' => $type,
            'ledger_statuses' => ['validee', 'contre_passee'],
        ];
        $parameterJson = $this->json($parameters);
        $parameterHash = hash('sha256', $parameterJson);
        $ledgerHash = $this->financial->ledgerFingerprint(
            $organisationId,
            $dossierId,
            $exerciseId,
            $dateEnd
        );
        $content = $this->json([
            'format' => 'compta-financial-archive-v1',
            'parameters' => $parameters,
            'ledger_hash' => $ledgerHash,
            'payload' => $payload,
        ]);
        $contentHash = hash('sha256', $content);
        $existing = $this->pdo->prepare(
            'SELECT id FROM archives_rapports_financiers
             WHERE dossier_id = ? AND exercice_id = ? AND type = ?
               AND empreinte_sha256 = ?'
        );
        $existing->execute([
            $dossierId,
            $exerciseId,
            $type,
            $contentHash,
        ]);
        $id = $existing->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO archives_rapports_financiers
                 (organisation_id, dossier_id, exercice_id, type,
                  date_debut, date_fin, parametres_json,
                  empreinte_parametres, empreinte_grand_livre,
                  contenu_json, empreinte_sha256, cree_par)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $organisationId,
                $dossierId,
                $exerciseId,
                $type,
                $dateStart,
                $dateEnd,
                $parameterJson,
                $parameterHash,
                $ledgerHash,
                $content,
                $contentHash,
                $actorId,
            ]);
            $id = (int) $this->pdo->lastInsertId();
        } catch (PDOException $exception) {
            $existing->execute([
                $dossierId,
                $exerciseId,
                $type,
                $contentHash,
            ]);
            $id = $existing->fetchColumn();
            if ($id === false) {
                throw $exception;
            }
        }
        $this->audit->log(
            'compta.archive_financiere_creee',
            $actorId,
            $organisationId,
            $dossierId,
            'archive_rapport_financier',
            (string) $id,
            [
                'type' => $type,
                'empreinte_sha256' => $contentHash,
                'empreinte_grand_livre' => $ledgerHash,
            ]
        );
        return (int) $id;
    }

    /** @param array<string,mixed> $existing */
    private function assertSameAdjustment(
        array $existing,
        int $exerciseId,
        string $label,
        string $nature,
        int $amountCents,
        string $note,
    ): void {
        if (
            (int) $existing['exercice_id'] !== $exerciseId
            || (string) $existing['libelle'] !== trim($label)
            || (string) $existing['nature'] !== $nature
            || (int) $existing['montant_centimes'] !== $amountCents
            || (string) $existing['note'] !== trim($note)
        ) {
            throw new AccountingException(
                'Clé idempotente réutilisée avec un autre ajustement.'
            );
        }
    }

    /** @return array{content:string,hash:string,type:string} */
    public function archiveContent(
        int $organisationId,
        int $dossierId,
        int $archiveId,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT type, contenu_json, empreinte_sha256
             FROM archives_rapports_financiers
             WHERE id = ? AND organisation_id = ? AND dossier_id = ?'
        );
        $stmt->execute([$archiveId, $organisationId, $dossierId]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new AccountingException('Archive financière absente du dossier.');
        }
        if (
            !hash_equals(
                (string) $row['empreinte_sha256'],
                hash('sha256', (string) $row['contenu_json'])
            )
        ) {
            throw new AccountingException('Empreinte de l’archive incohérente.');
        }
        return [
            'content' => (string) $row['contenu_json'],
            'hash' => (string) $row['empreinte_sha256'],
            'type' => (string) $row['type'],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function automaticControls(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $dateStart,
        string $dateEnd,
        array $reports,
    ): array {
        $draft = $this->pdo->prepare(
            "SELECT COUNT(*) FROM ecritures
             WHERE organisation_id = ? AND dossier_id = ? AND exercice_id = ?
               AND statut = 'brouillon' AND date_comptable BETWEEN ? AND ?"
        );
        $draft->execute([
            $organisationId,
            $dossierId,
            $exerciseId,
            $dateStart,
            $dateEnd,
        ]);
        $draftCount = (int) $draft->fetchColumn();
        $assetSchedules = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM echeances_amortissement e
             JOIN immobilisations i ON i.id = e.immobilisation_id
             WHERE i.organisation_id = ? AND i.dossier_id = ?
               AND i.statut = 'actif' AND e.statut = 'planifiee'
               AND e.montant_centimes > 0
               AND e.date_comptable BETWEEN ? AND ?"
        );
        $assetSchedules->execute([
            $organisationId,
            $dossierId,
            $dateStart,
            $dateEnd,
        ]);
        $pendingAssetSchedules = (int) $assetSchedules->fetchColumn();
        return [
            [
                'code' => 'balance',
                'label' => 'Balance débit/crédit équilibrée',
                'passed' => (bool) $reports['controls']['debit_equals_credit'],
                'detail' => '',
            ],
            [
                'code' => 'bilan',
                'label' => 'Bilan équilibré',
                'passed' => (bool) $reports['controls']['balance_sheet_balanced'],
                'detail' => '',
            ],
            [
                'code' => 'resultat',
                'label' => 'Résultat réconcilié entre les trois états',
                'passed' => (bool) $reports['controls']['result_reconciled'],
                'detail' => '',
            ],
            [
                'code' => 'liquidites',
                'label' => 'Flux réconcilié avec la variation des liquidités',
                'passed' => (bool) $reports['controls']['cash_reconciled'],
                'detail' => '',
            ],
            [
                'code' => 'immobilisations',
                'label' => 'Dotations d’amortissement échues comptabilisées',
                'passed' => $pendingAssetSchedules === 0,
                'detail' => $pendingAssetSchedules . ' échéance(s) à comptabiliser',
            ],
            [
                'code' => 'brouillons',
                'label' => 'Aucune écriture brouillon dans la période',
                'passed' => $draftCount === 0,
                'detail' => $draftCount . ' brouillon(s)',
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function manualControls(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT code, statut, note, version, modifie_le
             FROM controles_cloture
             WHERE organisation_id = ? AND dossier_id = ? AND exercice_id = ?'
        );
        $stmt->execute([$organisationId, $dossierId, $exerciseId]);
        $saved = [];
        foreach ($stmt->fetchAll() as $row) {
            $saved[(string) $row['code']] = $row;
        }
        $items = [];
        foreach (self::MANUAL_CONTROLS as $code => $label) {
            $row = $saved[$code] ?? null;
            $items[] = [
                'code' => $code,
                'label' => $label,
                'status' => $row === null
                    ? 'a_faire' : (string) $row['statut'],
                'note' => $row === null ? '' : (string) $row['note'],
                'version' => $row === null ? 0 : (int) $row['version'],
                'updated_at' => $row['modifie_le'] ?? null,
            ];
        }
        return $items;
    }

    /** @return array<string,mixed> */
    private function taxFile(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $dateStart,
        string $dateEnd,
        array $vat,
    ): array {
        $bank = $this->pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN NOT EXISTS (
                      SELECT 1 FROM rapprochement_lignes_bancaires rb
                      JOIN rapprochements_bancaires r
                        ON r.id = rb.rapprochement_id
                      WHERE rb.ligne_bancaire_id = l.id AND rb.actif = 1
                        AND r.statut = 'confirme'
                    ) THEN 1 ELSE 0 END), 0) AS unmatched,
                    COALESCE(SUM(CASE WHEN NOT EXISTS (
                      SELECT 1 FROM rapprochement_lignes_bancaires rb
                      JOIN rapprochements_bancaires r
                        ON r.id = rb.rapprochement_id
                      WHERE rb.ligne_bancaire_id = l.id AND rb.actif = 1
                        AND r.statut = 'confirme'
                    ) THEN l.montant_centimes ELSE 0 END), 0) AS unmatched_cents
             FROM lignes_bancaires l
             JOIN imports_bancaires i ON i.id = l.import_id
               AND i.statut = 'confirme'
             WHERE l.organisation_id = ? AND l.dossier_id = ?
               AND l.date_comptabilisation BETWEEN ? AND ?"
        );
        $bank->execute([
            $organisationId,
            $dossierId,
            $dateStart,
            $dateEnd,
        ]);
        $bankRow = $bank->fetch() ?: ['total' => 0, 'unmatched' => 0, 'unmatched_cents' => 0];
        $pieces = $this->pdo->prepare(
            "SELECT COUNT(*) AS documents,
                    COALESCE(SUM(CASE
                      WHEN type IN ('facture_fournisseur', 'avoir_fournisseur')
                       AND justificatif_id IS NULL THEN 1 ELSE 0 END), 0)
                       AS missing_supplier_attachments,
                    COUNT(DISTINCT justificatif_id) AS linked_attachments
             FROM documents_financiers
             WHERE organisation_id = ? AND dossier_id = ?
               AND date_document BETWEEN ? AND ?
               AND statut <> 'brouillon'"
        );
        $pieces->execute([
            $organisationId,
            $dossierId,
            $dateStart,
            $dateEnd,
        ]);
        $pieceRow = $pieces->fetch() ?: [];
        $adjustments = $this->pdo->prepare(
            'SELECT id, libelle, nature, montant_centimes, note,
                    statut, cree_le, modifie_le, version
             FROM ajustements_fiscaux
             WHERE organisation_id = ? AND dossier_id = ? AND exercice_id = ?
             ORDER BY id DESC'
        );
        $adjustments->execute([
            $organisationId,
            $dossierId,
            $exerciseId,
        ]);
        $rows = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'label' => (string) $row['libelle'],
            'nature' => (string) $row['nature'],
            'amount_cents' => (int) $row['montant_centimes'],
            'note' => (string) $row['note'],
            'status' => (string) $row['statut'],
            'created_at' => (string) $row['cree_le'],
            'updated_at' => $row['modifie_le'],
            'version' => (int) $row['version'],
        ], $adjustments->fetchAll());
        return [
            'status' => 'preparatoire',
            'official_declaration' => false,
            'disclaimer' =>
                'Dossier de travail : aucun calcul fiscal officiel, conseil ou envoi à une administration.',
            'period' => ['start_date' => $dateStart, 'end_date' => $dateEnd],
            'bank_reconciliation' => [
                'bank_lines' => (int) $bankRow['total'],
                'unmatched_lines' => (int) $bankRow['unmatched'],
                'unmatched_cents' => (int) $bankRow['unmatched_cents'],
            ],
            'supporting_documents' => [
                'financial_documents' => (int) ($pieceRow['documents'] ?? 0),
                'linked_attachments' => (int) ($pieceRow['linked_attachments'] ?? 0),
                'missing_supplier_attachments' =>
                    (int) ($pieceRow['missing_supplier_attachments'] ?? 0),
            ],
            'vat' => [
                'regime' => $vat['regime'],
                'period_count' => count($vat['periods']),
                'statement_count' => count($vat['statements']),
                'latest_statement' => $vat['statements'][0] ?? null,
            ],
            'adjustments' => $rows,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function periods(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, libelle, date_debut, date_fin, statut, version
             FROM periodes
             WHERE organisation_id = ? AND dossier_id = ? AND exercice_id = ?
             ORDER BY date_debut, id'
        );
        $stmt->execute([$organisationId, $dossierId, $exerciseId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'label' => (string) $row['libelle'],
            'start_date' => (string) $row['date_debut'],
            'end_date' => (string) $row['date_fin'],
            'status' => (string) $row['statut'],
            'version' => (int) $row['version'],
        ], $stmt->fetchAll());
    }

    /** @return list<array<string,mixed>> */
    private function archives(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT id, type, date_debut, date_fin, empreinte_parametres,
                    empreinte_grand_livre, empreinte_sha256, cree_le
             FROM archives_rapports_financiers
             WHERE organisation_id = ? AND dossier_id = ? AND exercice_id = ?
             ORDER BY id DESC'
        );
        $stmt->execute([$organisationId, $dossierId, $exerciseId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'start_date' => (string) $row['date_debut'],
            'end_date' => (string) $row['date_fin'],
            'parameters_hash' => (string) $row['empreinte_parametres'],
            'ledger_hash' => (string) $row['empreinte_grand_livre'],
            'hash' => (string) $row['empreinte_sha256'],
            'created_at' => (string) $row['cree_le'],
        ], $stmt->fetchAll());
    }

    /** @return array<string,mixed> */
    private function period(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        int $periodId,
    ): array {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, x.date_debut AS exercise_start
             FROM periodes p
             JOIN exercices x ON x.id = p.exercice_id
             WHERE p.id = ? AND p.organisation_id = ? AND p.dossier_id = ?
               AND p.exercice_id = ?'
        );
        $stmt->execute([
            $periodId,
            $organisationId,
            $dossierId,
            $exerciseId,
        ]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new AccountingException('Période absente de cet exercice.');
        }
        return $row;
    }

    private function assertExercise(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
    ): void {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM exercices x
             JOIN dossiers d ON d.id = x.dossier_id
             WHERE x.id = ? AND x.dossier_id = ? AND d.organisation_id = ?'
        );
        $stmt->execute([$exerciseId, $dossierId, $organisationId]);
        if ($stmt->fetchColumn() === false) {
            throw new AccountingException('Exercice absent du dossier.');
        }
    }

    private function json(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
        );
    }
}
