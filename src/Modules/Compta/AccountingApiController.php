<?php
declare(strict_types=1);

namespace Compta\Modules\Compta;

use Compta\Core\Audit\AuditLogger;
use Compta\Core\Auth\AccessControl;
use Compta\Core\Auth\AuthService;
use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Api\ApiResponse;
use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Compta\Core\Security\SessionStore;
use Compta\Modules\Tva\VatException;
use Compta\Modules\Devises\ExchangeRateException;
use PDOException;

final class AccountingApiController
{
    public function __construct(
        private readonly SessionStore $session,
        private readonly AuthService $auth,
        private readonly AccessControl $access,
        private readonly AccountingWorkspaceService $workspace,
        private readonly AccountingInputValidator $validator,
        private readonly AuditLogger $audit,
    ) {
    }

    public function show(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.view');
        $query = $this->validator->query($request);
        return $this->execute($request, function () use (
            $userId,
            $organisationId,
            $dossierId,
            $query
        ): array {
            $data = $this->workspace->read(
                $organisationId,
                $dossierId,
                $query['exercise_id'],
                $query['account_id'],
                $query['date_start'],
                $query['date_end'],
                $query['vat_statement_id']
            );
            $data['capabilities'] = [
                'edit' => $this->has(
                    $userId,
                    $organisationId,
                    $dossierId,
                    'compta.edit'
                ),
                'validate' => $this->has(
                    $userId,
                    $organisationId,
                    $dossierId,
                    'compta.validate'
                ),
                'setup' => $this->has(
                    $userId,
                    $organisationId,
                    $dossierId,
                    'compta.setup'
                ),
                'export' => $this->has(
                    $userId,
                    $organisationId,
                    $dossierId,
                    'compta.export'
                ),
                'vat_setup' => $this->has(
                    $userId,
                    $organisationId,
                    $dossierId,
                    'tva.setup'
                ),
                'vat_prepare' => $this->has(
                    $userId,
                    $organisationId,
                    $dossierId,
                    'tva.prepare'
                ),
                'vat_control' => $this->has(
                    $userId,
                    $organisationId,
                    $dossierId,
                    'tva.control'
                ),
                'vat_export' => $this->has(
                    $userId,
                    $organisationId,
                    $dossierId,
                    'tva.export'
                ),
                'vat_declare' => $this->has(
                    $userId,
                    $organisationId,
                    $dossierId,
                    'tva.declare'
                ),
            ];
            return $data;
        });
    }

    public function exportReport(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.export');
        $query = $this->validator->reportExport($request);
        return $this->raw(function () use (
            $userId,
            $organisationId,
            $dossierId,
            $query,
            $request
        ): Response {
            $export = $this->workspace->exportReport(
                $organisationId,
                $dossierId,
                $query['exercise_id'],
                $query['type'],
                $query['date_start'],
                $query['date_end']
            );
            $this->audit->log(
                'compta.rapport_exporte',
                $userId,
                $organisationId,
                $dossierId,
                'rapport',
                $query['type'],
                [
                    'date_debut' => $query['date_start'],
                    'date_fin' => $query['date_end'],
                ],
                $request->ip()
            );
            return new Response($export['content'], 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' =>
                    'attachment; filename="' . $export['filename'] . '"',
            ]);
        });
    }

    public function exportChart(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.export');
        return $this->raw(function () use (
            $userId,
            $organisationId,
            $dossierId,
            $request
        ): Response {
            $export = $this->workspace->exportChart($organisationId, $dossierId);
            $this->audit->log(
                'compta.plan_csv_exporte',
                $userId,
                $organisationId,
                $dossierId,
                'plan_comptable',
                (string) $dossierId,
                [],
                $request->ip()
            );
            return new Response($export['content'], 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' =>
                    'attachment; filename="' . $export['filename'] . '"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }

    public function exportOpening(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.export');
        $exerciseId = $this->validator->queryId($request, 'exercise_id');
        return $this->raw(function () use (
            $userId, $organisationId, $dossierId, $exerciseId, $request
        ): Response {
            $export = $this->workspace->exportOpening(
                $organisationId,
                $dossierId,
                $exerciseId
            );
            $this->audit->log(
                'compta.ouverture_csv_exportee',
                $userId,
                $organisationId,
                $dossierId,
                'exercice',
                (string) $exerciseId,
                [],
                $request->ip()
            );
            return new Response($export['content'], 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $export['filename'] . '"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }

    public function previewOpeningImport(Request $request): Response
    {
        [, $organisationId, $dossierId] = $this->scope('compta.setup');
        $data = $this->validator->accountingCsvImport($request, false);
        return $this->execute(
            $request,
            fn (): array => $this->workspace->previewOpeningImport(
                $organisationId,
                $dossierId,
                $data['exercise_id'],
                $data['csv']
            )
        );
    }

    public function importOpening(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        $data = $this->validator->accountingCsvImport($request, true);
        return $this->execute(
            $request,
            fn (): array => $this->workspace->importOpening(
                $organisationId,
                $dossierId,
                $data['exercise_id'],
                $data['csv'],
                $data['fingerprint'],
                $userId
            )
        );
    }

    public function journalDetails(Request $request): Response
    {
        [, $organisationId, $dossierId] = $this->scope('compta.view');
        $exerciseId = $this->validator->queryId($request, 'exercise_id');
        return $this->execute(
            $request,
            fn (): array => $this->workspace->journalDetails(
                $organisationId,
                $dossierId,
                $exerciseId
            )
        );
    }

    public function exportJournal(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.export');
        $exerciseId = $this->validator->queryId($request, 'exercise_id');
        return $this->raw(function () use (
            $userId, $organisationId, $dossierId, $exerciseId, $request
        ): Response {
            $export = $this->workspace->exportJournal(
                $organisationId,
                $dossierId,
                $exerciseId
            );
            $this->audit->log(
                'compta.journal_csv_exporte',
                $userId,
                $organisationId,
                $dossierId,
                'exercice',
                (string) $exerciseId,
                [],
                $request->ip()
            );
            return new Response($export['content'], 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $export['filename'] . '"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }

    public function previewJournalImport(Request $request): Response
    {
        [, $organisationId, $dossierId] = $this->scope('compta.edit');
        $data = $this->validator->accountingCsvImport($request, false);
        return $this->execute(
            $request,
            fn (): array => $this->workspace->previewJournalImport(
                $organisationId,
                $dossierId,
                $data['exercise_id'],
                $data['csv']
            )
        );
    }

    public function importJournal(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.edit');
        $data = $this->validator->accountingCsvImport($request, true);
        return $this->execute(
            $request,
            fn (): array => $this->workspace->importJournal(
                $organisationId,
                $dossierId,
                $data['exercise_id'],
                $data['csv'],
                $data['fingerprint'],
                $userId
            )
        );
    }

    public function previewChartImport(Request $request): Response
    {
        [, $organisationId, $dossierId] = $this->scope('compta.setup');
        $data = $this->validator->chartImport($request, false);
        return $this->execute($request, fn (): array =>
            $this->workspace->previewChartImport(
                $organisationId,
                $dossierId,
                $data['csv']
            )
        );
    }

    public function importChart(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        $data = $this->validator->chartImport($request, true);
        return $this->execute($request, fn (): array =>
            $this->workspace->importChart(
                $organisationId,
                $dossierId,
                $data['csv'],
                $data['fingerprint'],
                $userId
            )
        );
    }

    public function previewChartReset(Request $request): Response
    {
        [, $organisationId, $dossierId] = $this->scope('compta.setup');
        return $this->execute(
            $request,
            fn (): array => $this->workspace->previewChartReset(
                $organisationId,
                $dossierId
            )
        );
    }

    public function resetChart(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        $data = $this->validator->chartReset($request);
        return $this->execute(
            $request,
            fn (): array => $this->workspace->resetChart(
                $organisationId,
                $dossierId,
                $data['fingerprint'],
                $data['confirmation'],
                $userId
            )
        );
    }

    public function createVatPeriod(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('tva.setup');
        $data = $this->validator->vatPeriod($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->workspace->createVatPeriod(
                $organisationId,
                $dossierId,
                $data['start'],
                $data['end'],
                $userId
            ),
        ]);
    }

    public function prepareVatStatement(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('tva.prepare');
        $data = $this->validator->vatPreparation($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->workspace->prepareVatStatement(
                $organisationId,
                $dossierId,
                $data['period_id'],
                $data['corrects_id'],
                $userId
            ),
        ]);
    }

    public function controlVatStatement(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('tva.control');
        $data = $this->validator->vatStatement($request);
        return $this->execute($request, function () use (
            $userId,
            $organisationId,
            $dossierId,
            $data
        ): array {
            $this->workspace->controlVatStatement(
                $organisationId,
                $dossierId,
                $data['statement_id'],
                $userId
            );
            return ['controlled' => true];
        });
    }

    public function exportVatStatement(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('tva.export');
        $data = $this->validator->vatStatement($request);
        return $this->execute($request, fn (): array =>
            $this->workspace->exportVatStatement(
                $organisationId,
                $dossierId,
                $data['statement_id'],
                $userId
            )
        );
    }

    public function declareVatStatement(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('tva.declare');
        $data = $this->validator->vatStatement($request);
        return $this->execute($request, function () use (
            $userId,
            $organisationId,
            $dossierId,
            $data
        ): array {
            $this->workspace->declareVatStatement(
                $organisationId,
                $dossierId,
                $data['statement_id'],
                $userId
            );
            return ['declared' => true, 'automatic_transmission' => false];
        });
    }

    public function downloadVatExport(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('tva.export');
        $exportId = $this->validator->queryId($request, 'export_id');
        return $this->raw(function () use (
            $userId,
            $organisationId,
            $dossierId,
            $exportId,
            $request
        ): Response {
            $export = $this->workspace->vatExportContent(
                $organisationId,
                $dossierId,
                $exportId
            );
            $this->audit->log(
                'tva.export_telecharge',
                $userId,
                $organisationId,
                $dossierId,
                'tva_export',
                (string) $exportId,
                ['empreinte_sha256' => $export['hash']],
                $request->ip()
            );
            return new Response($export['xml'], 200, [
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Content-Disposition' =>
                    'attachment; filename="tva-ech0217-'
                    . $export['statement_id'] . '.xml"',
                'X-Content-SHA256' => $export['hash'],
            ]);
        });
    }

    public function saveClosingControl(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        $data = $this->validator->closingControl($request);
        return $this->execute($request, function () use (
            $userId,
            $organisationId,
            $dossierId,
            $data
        ): array {
            $this->workspace->saveClosingControl(
                $organisationId,
                $dossierId,
                $data['exercise_id'],
                $data['code'],
                $data['status'],
                $data['note'],
                $data['version'],
                $userId
            );
            return ['saved' => true];
        });
    }

    public function setPeriodStatus(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        $data = $this->validator->periodStatus($request);
        return $this->execute($request, function () use (
            $userId,
            $organisationId,
            $dossierId,
            $data
        ): array {
            $this->workspace->setPeriodStatus(
                $organisationId,
                $dossierId,
                $data['exercise_id'],
                $data['period_id'],
                $data['status'],
                $data['version'],
                $userId
            );
            return ['saved' => true];
        });
    }

    public function createTaxAdjustment(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        $data = $this->validator->taxAdjustment($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->workspace->createTaxAdjustment(
                $organisationId,
                $dossierId,
                $data['exercise_id'],
                $data['label'],
                $data['nature'],
                $data['amount_cents'],
                $data['note'],
                $data['idempotency_key'],
                $userId
            ),
        ]);
    }

    public function setTaxAdjustmentStatus(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        $data = $this->validator->taxAdjustmentStatus($request);
        return $this->execute($request, function () use (
            $userId,
            $organisationId,
            $dossierId,
            $data
        ): array {
            $this->workspace->setTaxAdjustmentStatus(
                $organisationId,
                $dossierId,
                $data['adjustment_id'],
                $data['status'],
                $data['version'],
                $userId
            );
            return ['saved' => true];
        });
    }

    public function archive(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.export');
        $data = $this->validator->archive($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->workspace->archive(
                $organisationId,
                $dossierId,
                $data['exercise_id'],
                $data['type'],
                $data['date_start'],
                $data['date_end'],
                $userId
            ),
        ]);
    }

    public function downloadArchive(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.export');
        $archiveId = $this->validator->queryId($request, 'archive_id');
        return $this->raw(function () use (
            $userId,
            $organisationId,
            $dossierId,
            $archiveId,
            $request
        ): Response {
            $archive = $this->workspace->archiveContent(
                $organisationId,
                $dossierId,
                $archiveId
            );
            $this->audit->log(
                'compta.archive_financiere_telechargee',
                $userId,
                $organisationId,
                $dossierId,
                'archive_rapport_financier',
                (string) $archiveId,
                ['empreinte_sha256' => $archive['hash']],
                $request->ip()
            );
            return new Response($archive['content'], 200, [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Content-Disposition' =>
                    'attachment; filename="' . $archive['type']
                    . '-' . $archiveId . '.json"',
                'X-Content-SHA256' => $archive['hash'],
            ]);
        });
    }

    public function deleteArchive(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        $archiveId = $this->validator->archiveDeletion($request);
        return $this->execute($request, function () use (
            $organisationId,
            $dossierId,
            $archiveId,
            $userId
        ): array {
            $this->workspace->deleteArchive(
                $organisationId,
                $dossierId,
                $archiveId,
                $userId
            );
            return ['id' => $archiveId, 'deleted' => true];
        });
    }

    public function createEntry(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.edit');
        $data = $this->validator->entry($request);
        if ($data['validate']) {
            $this->assertPermission(
                $userId,
                $organisationId,
                $dossierId,
                'compta.validate'
            );
        }
        return $this->execute($request, fn (): array => $this->workspace->createEntry(
            $organisationId,
            $dossierId,
            $data,
            $userId
        ));
    }

    public function draft(Request $request): Response
    {
        [, $organisationId, $dossierId] = $this->scope('compta.edit');
        $entryId = $this->validator->queryId($request, 'entry_id');
        return $this->execute(
            $request,
            fn (): array => $this->workspace->draft(
                $organisationId,
                $dossierId,
                $entryId
            )
        );
    }

    public function deleteDraft(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.edit');
        $data = $this->validator->entryDeletion($request);
        return $this->execute($request, function () use (
            $organisationId,
            $dossierId,
            $data,
            $userId
        ): array {
            $this->workspace->deleteDraft(
                $organisationId,
                $dossierId,
                $data['id'],
                $data['version'],
                $userId
            );
            return ['id' => $data['id'], 'deleted' => true];
        });
    }

    public function postExchangeRevaluation(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.validate');
        return $this->execute($request, fn (): array => [
            'id' => $this->workspace->postExchangeRevaluation(
                $organisationId,
                $dossierId,
                $this->validator->exchangeRevaluation($request),
                $userId
            ),
        ]);
    }

    public function reverseExchangeRevaluation(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.validate');
        return $this->execute($request, fn (): array => [
            'entry_id' => $this->workspace->reverseExchangeRevaluation(
                $organisationId,
                $dossierId,
                $this->validator->exchangeRevaluationReversal($request),
                $userId
            ),
        ]);
    }

    public function saveTypes(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        return $this->execute($request, function () use (
            $request, $userId, $organisationId, $dossierId
        ): array {
            $this->workspace->saveTypes(
                $organisationId,
                $dossierId,
                $this->validator->types($request),
                $userId
            );
            return ['saved' => true];
        });
    }

    public function saveSenseRules(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        return $this->execute($request, function () use (
            $request, $userId, $organisationId, $dossierId
        ): array {
            $this->workspace->saveSenseRules(
                $organisationId,
                $dossierId,
                $this->validator->senseRules($request),
                $userId
            );
            return ['saved' => true];
        });
    }

    public function mutateRubric(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        return $this->execute($request, function () use (
            $request, $userId, $organisationId, $dossierId
        ): array {
            $this->workspace->mutateRubric(
                $organisationId,
                $dossierId,
                $this->validator->rubric($request),
                $userId
            );
            return ['saved' => true];
        });
    }

    public function mutateAccount(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        return $this->execute($request, function () use (
            $request, $userId, $organisationId, $dossierId
        ): array {
            $this->workspace->mutateAccount(
                $organisationId,
                $dossierId,
                $this->validator->account($request),
                $userId
            );
            return ['saved' => true];
        });
    }

    public function saveOpening(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.setup');
        $data = $this->validator->opening($request);
        if ($data['validate']) {
            $this->assertPermission(
                $userId,
                $organisationId,
                $dossierId,
                'compta.validate'
            );
        }
        return $this->execute($request, fn (): array => $this->workspace->saveOpening(
            $organisationId,
            $dossierId,
            $data['exercise_id'],
            $data['balances'],
            $data['validate'],
            $userId
        ));
    }

    /** @return array{int,int,int} */
    private function scope(string $permission): array
    {
        $userId = $this->auth->userId();
        if ($userId === null) {
            throw ApiException::authenticationRequired();
        }
        $organisationId = (int) $this->session->get('organisation_id', 0);
        $dossierId = (int) $this->session->get('dossier_id', 0);
        if ($organisationId < 1 || $dossierId < 1) {
            throw ApiException::conflict(
                'CONTEXT_REQUIRED',
                'Sélectionnez un dossier avant cette opération.'
            );
        }
        if (
            !$this->access->canViewDossier($userId, $organisationId, $dossierId)
        ) {
            throw ApiException::forbidden('Accès comptable refusé.');
        }
        $this->assertPermission(
            $userId,
            $organisationId,
            $dossierId,
            $permission
        );
        return [$userId, $organisationId, $dossierId];
    }

    private function assertPermission(
        int $userId,
        int $organisationId,
        int $dossierId,
        string $permission,
    ): void {
        if (!$this->access->hasDossierPermission(
            $userId,
            $organisationId,
            $dossierId,
            $permission
        )) {
            throw ApiException::forbidden('Permission comptable insuffisante.');
        }
    }

    private function has(
        int $userId,
        int $organisationId,
        int $dossierId,
        string $permission,
    ): bool {
        return $this->access->hasDossierPermission(
            $userId,
            $organisationId,
            $dossierId,
            $permission
        );
    }

    /** @param callable():array<string,mixed> $callback */
    private function execute(Request $request, callable $callback): Response
    {
        try {
            return ApiResponse::success($request, $callback());
        } catch (AccountingException|VatException|ExchangeRateException $exception) {
            $message = $exception->getMessage();
            if (
                str_contains($message, 'modifié')
                || str_contains($message, 'déjà validé')
            ) {
                throw ApiException::conflict('ACCOUNTING_CONFLICT', $message);
            }
            throw ApiException::validation(['accounting' => [$message]]);
        } catch (PDOException) {
            throw ApiException::validation([
                'accounting' => [
                    'Ce numéro est déjà utilisé ou la donnée reste référencée.',
                ],
            ]);
        }
    }

    /** @param callable():Response $callback */
    private function raw(callable $callback): Response
    {
        try {
            return $callback();
        } catch (AccountingException|VatException|ExchangeRateException $exception) {
            throw ApiException::validation([
                'accounting' => [$exception->getMessage()],
            ]);
        }
    }
}
