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
use Compta\Modules\Facturation\AttachmentService;
use Compta\Modules\Facturation\BillingException;
use Compta\Modules\Tresorerie\ExpenseException;
use Compta\Modules\Tresorerie\ExpenseService;
use PDOException;

final class ExpenseApiController
{
    public function __construct(
        private readonly SessionStore $session,
        private readonly AuthService $auth,
        private readonly AccessControl $access,
        private readonly ExpenseService $expenses,
        private readonly AttachmentService $attachments,
        private readonly ExpenseInputValidator $validator,
    ) {
    }

    public function show(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('depenses.view');
        return $this->execute($request, function () use (
            $userId, $organisationId, $dossierId
        ): array {
            $data = $this->expenses->read($organisationId, $dossierId);
            $data['capabilities'] = [
                'manage' => $this->has(
                    $userId, $organisationId, $dossierId, 'depenses.manage'
                ),
                'approve' => $this->has(
                    $userId, $organisationId, $dossierId, 'depenses.approve'
                ),
                'post' => $this->has(
                    $userId, $organisationId, $dossierId, 'depenses.post'
                ),
            ];
            return $data;
        });
    }

    public function create(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('depenses.manage');
        $data = $this->validator->expense($request);
        return $this->execute($request, function () use (
            $data, $userId, $organisationId, $dossierId
        ): array {
            $attachmentId = null;
            if ($data['attachment'] !== null) {
                $attachmentId = $this->attachments->store(
                    $organisationId,
                    $dossierId,
                    $data['attachment']['name'],
                    $data['attachment']['content'],
                    $userId
                );
            }
            $id = $this->expenses->createDraft(
                $organisationId,
                $dossierId,
                $data['contact_id'],
                $data['document_date'],
                $data['due_date'],
                $data['external_number'],
                $data['collective_account_id'],
                $data['lines'],
                $attachmentId,
                $userId
            );
            return ['id' => $id];
        }, 201);
    }

    public function submit(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('depenses.manage');
        $data = $this->validator->transition($request);
        return $this->execute($request, fn (): array => [
            'number' => $this->expenses->submit(
                $organisationId,
                $dossierId,
                $data['document_id'],
                $data['version'],
                $userId
            ),
        ]);
    }

    public function approve(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('depenses.approve');
        $data = $this->validator->transition($request);
        return $this->execute($request, function () use (
            $data, $userId, $organisationId, $dossierId
        ): array {
            $this->expenses->approve(
                $organisationId,
                $dossierId,
                $data['document_id'],
                $data['version'],
                $userId
            );
            return ['approved' => true];
        });
    }

    public function post(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('depenses.post');
        $data = $this->validator->posting($request);
        return $this->execute($request, fn (): array => [
            'entry_id' => $this->expenses->post(
                $organisationId,
                $dossierId,
                $data['document_id'],
                $data['exercise_id'],
                $data['journal_id'],
                $userId
            ),
        ]);
    }

    public function cancel(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('depenses.post');
        $data = $this->validator->cancellation($request);
        return $this->execute($request, fn (): array => [
            'reversal_entry_id' => $this->expenses->cancel(
                $organisationId,
                $dossierId,
                $data['document_id'],
                $data['version'],
                $data['date'],
                $userId
            ),
        ]);
    }

    public function createRecurrence(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('depenses.manage');
        $data = $this->validator->recurrence($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->expenses->createRecurrence(
                $organisationId,
                $dossierId,
                $data['contact_id'],
                $data['label'],
                $data['frequency'],
                $data['interval'],
                $data['next_date'],
                $data['end_date'],
                $data['due_days'],
                $data['collective_account_id'],
                $data['external_prefix'],
                $data['lines'],
                $userId
            ),
        ], 201);
    }

    public function pauseRecurrence(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('depenses.manage');
        $data = $this->validator->recurrenceState($request);
        return $this->execute($request, function () use (
            $data, $userId, $organisationId, $dossierId
        ): array {
            $this->expenses->setRecurrencePaused(
                $organisationId,
                $dossierId,
                $data['recurrence_id'],
                $data['paused'],
                $data['version'],
                $userId
            );
            return ['paused' => $data['paused']];
        });
    }

    public function generateRecurrences(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('depenses.manage');
        $throughDate = $this->validator->generationDate($request);
        return $this->execute($request, fn (): array => [
            'document_ids' => $this->expenses->generateDue(
                $organisationId,
                $dossierId,
                $throughDate,
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
        if (!$this->access->canViewDossier($userId, $organisationId, $dossierId)) {
            throw ApiException::forbidden('Accès au dossier refusé.');
        }
        if (!$this->has($userId, $organisationId, $dossierId, $permission)) {
            throw ApiException::forbidden('Permission dépenses insuffisante.');
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
        } catch (ExpenseException|BillingException|AccountingException $exception) {
            $message = $exception->getMessage();
            if (
                str_contains($message, 'modifiée')
                || str_contains($message, 'Conflit')
                || str_contains($message, 'déjà')
            ) {
                throw ApiException::conflict('EXPENSE_CONFLICT', $message);
            }
            throw ApiException::validation(['expense' => [$message]]);
        } catch (PDOException) {
            throw ApiException::validation([
                'expense' => ['Référence invalide, déjà utilisée ou hors du dossier.'],
            ]);
        }
    }
}
