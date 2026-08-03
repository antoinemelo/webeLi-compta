<?php
declare(strict_types=1);

namespace Compta\Modules\Tresorerie\Http;

use Compta\Core\Auth\AccessControl;
use Compta\Core\Auth\AuthService;
use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Api\ApiResponse;
use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Compta\Core\Security\SessionStore;
use Compta\Modules\Compta\AccountingException;
use Compta\Modules\Facturation\BillingException;
use Compta\Modules\Facturation\PaymentService;
use Compta\Modules\Tresorerie\BankImportService;
use Compta\Modules\Tresorerie\OutgoingPaymentService;
use Compta\Modules\Tresorerie\PublicMarketDataService;
use Compta\Modules\Tresorerie\ReconciliationService;
use Compta\Modules\Tresorerie\SuggestionService;
use Compta\Modules\Tresorerie\TreasuryException;
use Compta\Modules\Tresorerie\TreasuryWorkspaceService;
use PDOException;

final class TreasuryApiController
{
    public function __construct(
        private readonly SessionStore $session,
        private readonly AuthService $auth,
        private readonly AccessControl $access,
        private readonly TreasuryWorkspaceService $workspace,
        private readonly BankImportService $imports,
        private readonly ReconciliationService $reconciliations,
        private readonly SuggestionService $suggestions,
        private readonly PaymentService $payments,
        private readonly OutgoingPaymentService $outgoing,
        private readonly PublicMarketDataService $market,
        private readonly TreasuryInputValidator $validator,
    ) {
    }

    public function show(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('tresorerie.view');
        return $this->execute($request, function () use (
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $data = $this->workspace->read($organisationId, $dossierId);
            $data['capabilities'] = [
                'import' => $this->has($userId, $organisationId, $dossierId, 'tresorerie.import'),
                'reconcile' => $this->has($userId, $organisationId, $dossierId, 'tresorerie.reconcile'),
                'suggest' => $this->has($userId, $organisationId, $dossierId, 'compta.edit'),
                'accept_suggestion' => $this->has($userId, $organisationId, $dossierId, 'compta.validate'),
                'match' => $this->has($userId, $organisationId, $dossierId, 'facturation.pay'),
                'prepare_payments' => $this->has($userId, $organisationId, $dossierId, 'paiements.prepare'),
                'export_payments' => $this->has($userId, $organisationId, $dossierId, 'paiements.export'),
                'confirm_payments' => $this->has($userId, $organisationId, $dossierId, 'paiements.confirm'),
            ];
            return $data;
        });
    }

    public function exchangeRates(Request $request): Response
    {
        [, $organisationId, $dossierId] = $this->scope('tresorerie.view');
        $exerciseId = $this->validator->marketExercise($request);
        return $this->execute(
            $request,
            fn (): array => $this->market->exchangeHistory(
                $organisationId,
                $dossierId,
                $exerciseId
            )
        );
    }

    public function interestRates(Request $request): Response
    {
        [, $organisationId, $dossierId] = $this->scope('tresorerie.view');
        $exerciseId = $this->validator->marketExercise($request);
        return $this->execute(
            $request,
            fn (): array => $this->market->interestHistory(
                $organisationId,
                $dossierId,
                $exerciseId
            )
        );
    }

    public function previewImport(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('tresorerie.import');
        $data = $this->validator->import($request);
        return $this->execute($request, fn (): array => $this->imports->preview(
            $organisationId,
            $dossierId,
            $data['treasury_account_id'],
            $data['filename'],
            $data['content'],
            $userId
        ));
    }

    public function confirmImport(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('tresorerie.import');
        $id = $this->validator->identifier($request, 'import_id');
        return $this->execute($request, fn (): array => $this->imports->confirm(
            $organisationId,
            $dossierId,
            $id,
            $userId
        ));
    }

    public function reconcile(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('tresorerie.reconcile');
        $data = $this->validator->reconciliation($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->reconciliations->reconcile(
                $organisationId,
                $dossierId,
                $data['treasury_account_id'],
                $data['bank_line_ids'],
                $data['accounting_line_ids'],
                $data['tolerance_cents'],
                $data['label'],
                $userId
            ),
        ], 201);
    }

    public function cancelReconciliation(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('tresorerie.reconcile');
        $data = $this->validator->reconciliationCancellation($request);
        return $this->execute($request, function () use (
            $data,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $this->reconciliations->cancel(
                $organisationId,
                $dossierId,
                $data['reconciliation_id'],
                $data['version'],
                $userId
            );
            return ['cancelled' => true];
        });
    }

    public function proposeSuggestion(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.edit');
        $data = $this->validator->suggestion($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->suggestions->propose(
                $organisationId,
                $dossierId,
                $data['bank_line_id'],
                $data['counterpart_account_id'],
                $data['label'],
                $data['confidence'],
                $data['reason'],
                $userId
            ),
        ], 201);
    }

    public function acceptSuggestion(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('compta.validate');
        $data = $this->validator->suggestionAcceptance($request);
        return $this->execute($request, fn (): array => [
            'entry_id' => $this->suggestions->accept(
                $organisationId,
                $dossierId,
                $data['suggestion_id'],
                $data['exercise_id'],
                $data['journal_id'],
                $userId
            ),
        ]);
    }

    public function createPayment(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('facturation.pay');
        $data = $this->validator->payment($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->payments->create(
                $organisationId,
                $dossierId,
                $data['contact_id'],
                $data['direction'],
                $data['date'],
                $data['amount_cents'],
                $data['reference'],
                $data['ledger_account_id'],
                $userId,
                $data['bank_line_id'],
                $data['currency'],
                $data['exchange_rate_id'],
                $data['collective_account_id'],
                treasuryOperationalAccountId: $data['treasury_account_id']
            ),
        ], 201);
    }

    public function allocate(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('facturation.pay');
        $data = $this->validator->allocation($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->payments->allocatePayment(
                $organisationId,
                $dossierId,
                $data['payment_id'],
                $data['document_id'],
                $data['amount_cents'],
                $userId
            ),
        ], 201);
    }

    public function unallocate(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('facturation.pay');
        $id = $this->validator->identifier($request, 'allocation_id');
        return $this->execute($request, function () use (
            $id,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $this->payments->unallocate(
                $organisationId,
                $dossierId,
                $id,
                $userId
            );
            return ['cancelled' => true];
        });
    }

    public function prepareBatch(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('paiements.prepare');
        $data = $this->validator->batch($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->outgoing->prepare(
                $organisationId,
                $dossierId,
                $data['treasury_account_id'],
                $data['execution_date'],
                $data['orders'],
                $data['idempotency_key'],
                $userId
            ),
        ], 201);
    }

    public function exportBatch(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('paiements.export');
        $data = $this->validator->batchExport($request);
        return $this->execute($request, function () use (
            $data,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $export = $this->outgoing->export(
                $organisationId,
                $dossierId,
                $data['batch_id'],
                $data['version'],
                $userId
            );
            return [
                'filename' => $export['filename'],
                'hash' => $export['hash'],
                'content_base64' => base64_encode($export['content']),
                'transmitted' => false,
            ];
        });
    }

    public function confirmBatch(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('paiements.confirm');
        $data = $this->validator->batchConfirmation($request);
        return $this->execute($request, fn (): array => [
            'reconciliation_id' => $this->outgoing->confirmFromStatement(
                $organisationId,
                $dossierId,
                $data['batch_id'],
                $data['bank_line_id'],
                $data['exercise_id'],
                $data['journal_id'],
                $data['fee_account_id'],
                $userId
            ),
        ]);
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
            || !$this->has($userId, $organisationId, $dossierId, $permission)
        ) {
            throw ApiException::forbidden('Permission de trésorerie insuffisante.');
        }
        return [$userId, $organisationId, $dossierId];
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
    private function execute(
        Request $request,
        callable $callback,
        int $status = 200,
    ): Response {
        try {
            return ApiResponse::success($request, $callback(), status: $status);
        } catch (TreasuryException|BillingException|AccountingException $exception) {
            $message = $exception->getMessage();
            if (
                str_contains($message, 'simultanément')
                || str_contains($message, 'déjà')
                || str_contains($message, 'concorde')
            ) {
                throw ApiException::conflict('TREASURY_CONFLICT', $message);
            }
            throw ApiException::validation(['treasury' => [$message]]);
        } catch (PDOException) {
            throw ApiException::conflict(
                'TREASURY_CONSTRAINT',
                'Opération déjà consommée, dupliquée ou hors du dossier.'
            );
        }
    }
}
