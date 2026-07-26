<?php
declare(strict_types=1);

namespace Compta\Modules\Compta;

final class AccountingWorkspaceService
{
    public function __construct(
        private readonly ChartOfAccountsService $chart,
        private readonly EntryService $entries,
        private readonly ReportingService $reports,
    ) {
    }

    /** @return array<string,mixed> */
    public function read(
        int $organisationId,
        int $dossierId,
        int $exerciseId,
        ?int $accountId = null,
    ): array {
        $exercise = $this->reports->exercise(
            $organisationId,
            $dossierId,
            $exerciseId
        );
        $catalog = $this->entries->entryCatalog($organisationId, $dossierId);
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
        ];
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
