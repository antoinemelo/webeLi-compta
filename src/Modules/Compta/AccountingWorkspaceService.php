<?php
declare(strict_types=1);

namespace Compta\Modules\Compta;

use Compta\Modules\Tva\VatWorkspaceService;
use Compta\Modules\Devises\ExchangeRevaluationService;
use Compta\Modules\Devises\ExchangeRateException;

final class AccountingWorkspaceService
{
    public function __construct(
        private readonly ChartOfAccountsService $chart,
        private readonly EntryService $entries,
        private readonly ReportingService $reports,
        private readonly FinancialReportingService $financial,
        private readonly VatWorkspaceService $vat,
        private readonly ClosingAndTaxService $closing,
        private readonly ?ExchangeRevaluationService $revaluations = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function read(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        ?int $accountId = null,
        ?string $dateStart = null,
        ?string $dateEnd = null,
        ?int $vatStatementId = null,
    ): array {
        $exercise = $this->reports->exercise(
            $organisationId,
            $dossierId,
            $exerciseId
        );
        $catalog = $this->entries->entryCatalog($organisationId, $dossierId);
        $dateStart = $dateStart ?: $exercise['date_debut'];
        $dateEnd = $dateEnd ?: $exercise['date_fin'];
        $financial = $this->financial->read(
            $organisationId,
            $dossierId,
            $exerciseId,
            $dateStart,
            $dateEnd
        );
        $vat = $this->vat->read(
            $organisationId,
            $dossierId,
            $exercise['date_debut'],
            $exercise['date_fin'],
            $vatStatementId
        );
        $closing = $this->closing->read(
            $organisationId,
            $dossierId,
            $exerciseId,
            $dateStart,
            $dateEnd,
            $financial,
            $vat
        );
        return [
            'exercise' => [
                'id' => $exercise['id'],
                'label' => $exercise['libelle'],
                'start_date' => $exercise['date_debut'],
                'end_date' => $exercise['date_fin'],
            ],
            'catalog' => [
                'exercises' => array_map(
                    static fn (array $row): array => [
                        'id' => (int) $row['id'],
                        'label' => (string) $row['libelle'],
                        'start_date' => (string) $row['date_debut'],
                        'end_date' => (string) $row['date_fin'],
                        'status' => (string) $row['statut'],
                    ],
                    $catalog['exercises']
                ),
                'journals' => array_map(
                    static fn (array $row): array => [
                        'id' => (int) $row['id'],
                        'code' => (string) $row['code'],
                        'label' => (string) $row['libelle'],
                        'type' => (string) $row['type'],
                    ],
                    $catalog['journals']
                ),
                'accounts' => array_map(
                    static fn (array $row): array => [
                        'id' => (int) $row['id'],
                        'number' => (string) $row['numero'],
                        'label' => (string) $row['libelle'],
                        'normal_side' => (string) $row['sens_normal'],
                    ],
                    $catalog['accounts']
                ),
            ],
            'chart' => [
                'types' => array_map(
                    static fn (array $row): array => [
                        'id' => (int) $row['id'],
                        'code' => (string) $row['code'],
                        'label' => (string) $row['libelle'],
                        'order' => (int) $row['ordre'],
                        'version' => (int) $row['version'],
                    ],
                    $this->chart->accountTypes($organisationId, $dossierId)
                ),
                'credit_prefixes' => $this->chart->creditPrefixes(
                    $organisationId,
                    $dossierId
                ),
                'rubrics' => array_map(
                    static fn (array $row): array => [
                        'id' => (int) $row['id'],
                        'code' => (string) $row['code'],
                        'label' => (string) $row['libelle'],
                        'structure_level' => (string) $row['niveau_structure'],
                        'type' => (string) $row['type'],
                        'parent_id' => $row['parent_id'] === null
                            ? null
                            : (int) $row['parent_id'],
                        'path' => (string) $row['chemin'],
                        'order' => (int) $row['ordre'],
                        'version' => (int) $row['version'],
                    ],
                    $this->chart->rubrics($organisationId, $dossierId)
                ),
                'accounts' => array_map(
                    static fn (array $row): array => [
                        'id' => (int) $row['id'],
                        'number' => (string) $row['numero'],
                        'label' => (string) $row['libelle'],
                        'type' => (string) $row['type'],
                        'normal_side' => (string) $row['sens_normal'],
                        'sense_mode' => (string) $row['sens_mode'],
                        'rubric_id' => $row['rubrique_id'] === null
                            ? null
                            : (int) $row['rubrique_id'],
                        'rubric_path' => (string) $row['rubrique_chemin'],
                        'active' => (int) $row['actif'] === 1,
                        'order' => (int) $row['ordre'],
                        'version' => (int) $row['version'],
                    ],
                    $this->chart->accounts($organisationId, $dossierId)
                ),
            ],
            'opening' => $this->entries->openingState(
                $organisationId,
                $dossierId,
                $exerciseId
            ),
            'journal' => $this->reports->journal(
                $organisationId,
                $dossierId,
                [
                    'exercice_id' => $exerciseId,
                    'page' => 1,
                    'par_page' => 50,
                    'ordre' => 'desc',
                ]
            ),
            'ledger' => $accountId === null
                ? null
                : $this->reports->ledger(
                    $organisationId,
                    $dossierId,
                    $accountId,
                    [
                        'exercice_id' => $exerciseId,
                        'page' => 1,
                        'par_page' => 100,
                    ]
                ),
            'reports' => $financial,
            'vat' => $vat,
            'closing' => $closing['closing'],
            'tax_file' => $closing['tax_file'],
            'exchange_revaluations' => $this->revaluations?->history(
                $organisationId,
                $dossierId
            ) ?? [],
        ];
    }

    /** @param array<string,mixed> $data */
    public function postExchangeRevaluation(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): int {
        if ($this->revaluations === null) {
            throw new ExchangeRateException('Service de réévaluation indisponible.');
        }
        return $this->revaluations->post(
            $organisationId,
            $dossierId,
            (int) $data['exercise_id'],
            (int) $data['journal_id'],
            (string) $data['date'],
            (string) $data['idempotency_key'],
            $actorId
        );
    }

    /** @param array<string,mixed> $data */
    public function reverseExchangeRevaluation(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): int {
        if ($this->revaluations === null) {
            throw new ExchangeRateException('Service de réévaluation indisponible.');
        }
        return $this->revaluations->reverse(
            $organisationId,
            $dossierId,
            (int) $data['revaluation_id'],
            (string) $data['date'],
            $actorId
        );
    }

    public function createVatPeriod(
        int $organisationId,
        int $dossierId,
        string $start,
        string $end,
        int $actorId,
    ): int {
        return $this->vat->createPeriod(
            $organisationId,
            $dossierId,
            $start,
            $end,
            $actorId
        );
    }

    public function prepareVatStatement(
        int $organisationId,
        int $dossierId,
        int $periodId,
        ?int $correctsId,
        int $actorId,
    ): int {
        return $this->vat->prepare(
            $organisationId,
            $dossierId,
            $periodId,
            $correctsId,
            $actorId
        );
    }

    public function controlVatStatement(
        int $organisationId,
        int $dossierId,
        int $statementId,
        int $actorId,
    ): void {
        $this->vat->control(
            $organisationId,
            $dossierId,
            $statementId,
            $actorId
        );
    }

    /** @return array<string,mixed> */
    public function exportVatStatement(
        int $organisationId,
        int $dossierId,
        int $statementId,
        int $actorId,
    ): array {
        return $this->vat->export(
            $organisationId,
            $dossierId,
            $statementId,
            $actorId
        );
    }

    public function declareVatStatement(
        int $organisationId,
        int $dossierId,
        int $statementId,
        int $actorId,
    ): void {
        $this->vat->declare(
            $organisationId,
            $dossierId,
            $statementId,
            $actorId
        );
    }

    /** @return array{xml:string,hash:string,statement_id:int} */
    public function vatExportContent(
        int $organisationId,
        int $dossierId,
        int $exportId,
    ): array {
        return $this->vat->exportContent(
            $organisationId,
            $dossierId,
            $exportId
        );
    }

    public function saveClosingControl(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $code,
        string $status,
        string $note,
        int $expectedVersion,
        int $actorId,
    ): void {
        $this->closing->saveManualControl(
            $organisationId,
            $dossierId,
            $exerciseId,
            $code,
            $status,
            $note,
            $expectedVersion,
            $actorId
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
        $this->closing->setPeriodStatus(
            $organisationId,
            $dossierId,
            $exerciseId,
            $periodId,
            $status,
            $expectedVersion,
            $actorId
        );
    }

    public function createTaxAdjustment(
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
        return $this->closing->createAdjustment(
            $organisationId,
            $dossierId,
            $exerciseId,
            $label,
            $nature,
            $amountCents,
            $note,
            $idempotencyKey,
            $actorId
        );
    }

    public function setTaxAdjustmentStatus(
        int $organisationId,
        int $dossierId,
        int $adjustmentId,
        string $status,
        int $expectedVersion,
        int $actorId,
    ): void {
        $this->closing->setAdjustmentStatus(
            $organisationId,
            $dossierId,
            $adjustmentId,
            $status,
            $expectedVersion,
            $actorId
        );
    }

    public function archive(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $type,
        string $dateStart,
        string $dateEnd,
        int $actorId,
    ): int {
        $exercise = $this->reports->exercise(
            $organisationId,
            $dossierId,
            $exerciseId
        );
        $reports = $this->financial->read(
            $organisationId,
            $dossierId,
            $exerciseId,
            $dateStart,
            $dateEnd
        );
        $vat = $this->vat->read(
            $organisationId,
            $dossierId,
            $exercise['date_debut'],
            $exercise['date_fin']
        );
        $closing = $this->closing->read(
            $organisationId,
            $dossierId,
            $exerciseId,
            $dateStart,
            $dateEnd,
            $reports,
            $vat
        );
        $closingSnapshot = $closing['closing'];
        unset($closingSnapshot['archives']);
        $payload = $type === 'cloture'
            ? [
                'reports' => $reports,
                'vat' => $vat,
                'closing' => $closingSnapshot,
            ]
            : [
                'reports' => $reports,
                'vat' => $vat,
                'tax_file' => $closing['tax_file'],
            ];
        return $this->closing->archive(
            $organisationId,
            $dossierId,
            $exerciseId,
            $type,
            $dateStart,
            $dateEnd,
            $payload,
            $actorId
        );
    }

    /** @return array{content:string,hash:string,type:string} */
    public function archiveContent(
        int $organisationId,
        int $dossierId,
        int $archiveId,
    ): array {
        return $this->closing->archiveContent(
            $organisationId,
            $dossierId,
            $archiveId
        );
    }

    /** @return array{content:string,filename:string} */
    public function exportReport(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $type,
        string $dateStart,
        string $dateEnd,
    ): array {
        $financial = $this->financial->read(
            $organisationId,
            $dossierId,
            $exerciseId,
            $dateStart,
            $dateEnd
        );
        $balanceColumns = [
            'numero' => 'Compte',
            'libelle' => 'Libellé',
            'type_libelle' => 'Type',
            'current_cents' => (string) $financial['balance_sheet']['current_label'],
        ];
        if ($financial['balance_sheet']['previous_label'] !== null) {
            $balanceColumns['previous_cents'] =
                (string) $financial['balance_sheet']['previous_label'];
        }
        $incomeColumns = [
            'number' => 'Compte',
            'label' => 'Libellé',
            'type' => 'Type',
            'current_cents' =>
                (string) $financial['income_statement']['current']['label'],
        ];
        if ($financial['income_statement']['previous']['exercise_id'] !== null) {
            $incomeColumns['previous_cents'] =
                (string) $financial['income_statement']['previous']['label'];
        }
        [$rows, $columns] = match ($type) {
            'journal' => [
                $this->completeJournal(
                    $organisationId,
                    $dossierId,
                    $exerciseId,
                    $dateStart,
                    $dateEnd
                ),
                [
                    'date_comptable' => 'Date',
                    'numero' => 'Numéro',
                    'journal' => 'Journal',
                    'libelle' => 'Libellé',
                    'reference' => 'Référence',
                    'debit_centimes' => 'Débit',
                    'credit_centimes' => 'Crédit',
                    'statut' => 'Statut',
                ],
            ],
            'grand_livre' => [
                $financial['general_ledger']['items'],
                [
                    'numero' => 'Compte',
                    'libelle' => 'Libellé',
                    'initial_centimes' => 'Solde initial',
                    'debit_centimes' => 'Débit',
                    'credit_centimes' => 'Crédit',
                    'solde_centimes' => 'Solde final',
                ],
            ],
            'balance' => [
                $financial['trial_balance']['items'],
                [
                    'numero' => 'Compte',
                    'libelle' => 'Libellé',
                    'debit_centimes' => 'Débit',
                    'credit_centimes' => 'Crédit',
                    'solde_centimes' => 'Solde',
                ],
            ],
            'bilan' => [
                $financial['balance_sheet']['items'],
                $balanceColumns,
            ],
            'resultat' => [
                $financial['income_statement']['items'],
                $incomeColumns,
            ],
            'flux_tresorerie' => [
                $financial['cash_flow']['statement_items'],
                [
                    'label' => 'Libellé',
                    'category' => 'Catégorie',
                    'amount_cents' => 'Flux',
                ],
            ],
            default => throw new AccountingException('Rapport inconnu.'),
        };
        $metadata = $this->reports->csv([
            ['parameter' => 'type', 'value' => $type],
            ['parameter' => 'exercise_id', 'value' => $exerciseId],
            ['parameter' => 'date_start', 'value' => $dateStart],
            ['parameter' => 'date_end', 'value' => $dateEnd],
            [
                'parameter' => 'ledger_hash',
                'value' => $this->financial->ledgerFingerprint(
                    $organisationId,
                    $dossierId,
                    $exerciseId,
                    $dateEnd
                ),
            ],
        ], ['parameter' => 'Paramètre', 'value' => 'Valeur']);
        $body = $this->reports->csv($rows, $columns);
        if (str_starts_with($body, "\xEF\xBB\xBF")) {
            $body = substr($body, 3);
        }
        return [
            'content' => $metadata . "\n" . $body,
            'filename' => $type . '-' . $dateEnd . '.csv',
        ];
    }

    /** @return list<array<string,mixed>> */
    private function completeJournal(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        string $dateStart,
        string $dateEnd,
    ): array {
        $page = 1;
        $items = [];
        do {
            $result = $this->reports->journal(
                $organisationId,
                $dossierId,
                [
                    'exercice_id' => $exerciseId,
                    'date_debut' => $dateStart,
                    'date_fin' => $dateEnd,
                    'statut' => 'comptabilisee',
                    'page' => $page,
                    'par_page' => 200,
                ]
            );
            array_push($items, ...$result['items']);
            $page++;
        } while ($page <= $result['pages']);
        return $items;
    }

    /** @param array<string,mixed> $data @return array{id:int,number:string} */
    public function createEntry(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): array {
        $entryId = $this->entries->createDraft([
            'organisation_id' => $organisationId,
            'dossier_id' => $dossierId,
            'exercice_id' => $data['exercise_id'],
            'journal_id' => $data['journal_id'],
            'date_comptable' => $data['date'],
            'libelle' => $data['label'],
            'reference' => $data['reference'],
            'piece' => $data['attachment_reference'],
            'lignes' => array_map(
                static fn (array $line): array => [
                    'compte_id' => $line['account_id'],
                    'libelle' => $line['label'],
                    'debit_centimes' => $line['debit_cents'],
                    'credit_centimes' => $line['credit_cents'],
                ],
                $data['lines']
            ),
        ], $actorId);
        $number = '';
        if ($data['validate']) {
            $number = $this->entries->validate(
                $organisationId,
                $dossierId,
                $entryId,
                $actorId
            );
        }
        return ['id' => $entryId, 'number' => $number];
    }

    /** @param list<array{id:int,label:string,version:int}> $rows */
    public function saveTypes(
        int $organisationId,
        int $dossierId,
        array $rows,
        int $actorId,
    ): void {
        $this->chart->renameAccountTypesBatch(
            $organisationId,
            $dossierId,
            array_map(static fn (array $row): array => [
                'id' => $row['id'],
                'libelle' => $row['label'],
                'version' => $row['version'],
            ], $rows),
            $actorId
        );
    }

    /** @param list<string> $prefixes */
    public function saveSenseRules(
        int $organisationId,
        int $dossierId,
        array $prefixes,
        int $actorId,
    ): void {
        $this->chart->replaceCreditPrefixes(
            $organisationId,
            $dossierId,
            $prefixes,
            $actorId
        );
    }

    /** @param array<string,mixed> $data */
    public function mutateRubric(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): void {
        if ($data['action'] === 'delete') {
            $this->chart->removeRubric(
                $organisationId,
                $dossierId,
                $data['id'],
                $actorId
            );
            return;
        }
        if ($data['action'] === 'reorder') {
            $this->chart->reorderRubrics(
                $organisationId,
                $dossierId,
                $data['structure_level'],
                $data['ordered_ids'],
                $actorId
            );
            return;
        }
        $this->chart->saveRubric(
            $organisationId,
            $dossierId,
            $data['id'] > 0 ? $data['id'] : null,
            $data['structure_level'],
            $data['code'],
            $data['label'],
            $data['type'],
            $data['parent_id'],
            $data['position'],
            $data['id'] > 0 ? $data['version'] : null,
            $actorId
        );
    }

    /** @param array<string,mixed> $data */
    public function mutateAccount(
        int $organisationId,
        int $dossierId,
        array $data,
        int $actorId,
    ): void {
        if ($data['action'] === 'save_batch') {
            $rows = array_map(
                static fn (array $row): array => [
                    'id' => $row['id'],
                    'numero' => $row['number'],
                    'libelle' => $row['label'],
                    'sens_mode' => $row['sense_mode'],
                    'rubrique_id' => $row['rubric_id'],
                    'version' => $row['version'],
                ],
                $data['accounts']
            );
            $this->chart->updateAccountsBatch(
                $organisationId,
                $dossierId,
                $rows,
                $data['ordered_ids'],
                $actorId
            );
            return;
        }
        if ($data['action'] === 'delete') {
            $this->chart->removeOrDeactivate(
                $organisationId,
                $dossierId,
                $data['id'],
                $actorId
            );
            return;
        }
        if ($data['action'] === 'reorder') {
            $this->chart->reorderAccounts(
                $organisationId,
                $dossierId,
                $data['ordered_ids'],
                $actorId
            );
            return;
        }
        if ($data['id'] < 1) {
            $this->chart->createConfigured(
                $organisationId,
                $dossierId,
                $data['number'],
                $data['label'],
                '',
                $data['sense_mode'],
                $actorId,
                $data['rubric_id']
            );
            return;
        }
        $this->chart->updateAccount(
            $organisationId,
            $dossierId,
            $data['id'],
            $data['number'],
            $data['label'],
            '',
            $data['sense_mode'],
            $data['version'],
            $actorId,
            $data['rubric_id']
        );
    }

    /** @param array<int,int> $balances */
    public function saveOpening(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        array $balances,
        bool $validate,
        int $actorId,
    ): array {
        $journalId = $this->chart->ensureOpeningJournal(
            $organisationId,
            $dossierId,
            $actorId
        );
        $entryId = $this->entries->saveOpeningDraft(
            $organisationId,
            $dossierId,
            $exerciseId,
            $journalId,
            $balances,
            $actorId
        );
        $number = '';
        if ($validate) {
            $number = $this->entries->validateOpeningDraft(
                $organisationId,
                $dossierId,
                $exerciseId,
                $actorId
            );
        }
        return ['id' => $entryId, 'number' => $number];
    }
}
