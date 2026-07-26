<?php
declare(strict_types=1);

namespace Compta\Modules\Facturation\Http;

use Compta\Core\Auth\AccessControl;
use Compta\Core\Auth\AuthService;
use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Api\ApiResponse;
use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Compta\Core\Security\SessionStore;
use Compta\Modules\Compta\AccountingException;
use Compta\Modules\Facturation\BillingException;
use Compta\Modules\Facturation\BillingService;
use Compta\Modules\Facturation\BillingWorkspaceService;
use Compta\Modules\Facturation\AttachmentService;
use Compta\Modules\Facturation\ContactService;
use Compta\Modules\Facturation\InvoicePdfService;
use Compta\Modules\Facturation\PaymentService;
use Compta\Modules\Facturation\RecurringBillingService;
use PDOException;

final class BillingApiController
{
    public function __construct(
        private readonly SessionStore $session,
        private readonly AuthService $auth,
        private readonly AccessControl $access,
        private readonly BillingWorkspaceService $workspace,
        private readonly BillingService $billing,
        private readonly ContactService $contacts,
        private readonly PaymentService $payments,
        private readonly RecurringBillingService $recurrences,
        private readonly InvoicePdfService $pdf,
        private readonly AttachmentService $attachments,
        private readonly BillingInputValidator $validator,
    ) {
    }

    public function show(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('facturation.view');
        $filters = $this->validator->filters($request);
        return $this->execute($request, function () use (
            $userId,
            $organisationId,
            $dossierId,
            $filters
        ): array {
            $data = $this->workspace->read(
                $organisationId,
                $dossierId,
                $filters['as_of_date'],
                [
                    'direction' => $filters['direction'],
                    'status' => $filters['status'],
                    'search' => $filters['search'],
                    'contact_id' => $filters['contact_id'],
                ]
            );
            $data['capabilities'] = [
                'manage' => $this->has(
                    $userId, $organisationId, $dossierId, 'facturation.manage'
                ),
                'issue' => $this->has(
                    $userId, $organisationId, $dossierId, 'facturation.issue'
                ),
                'post' => $this->has(
                    $userId, $organisationId, $dossierId, 'facturation.post'
                ),
                'pay' => $this->has(
                    $userId, $organisationId, $dossierId, 'facturation.pay'
                ),
                'remind' => $this->has(
                    $userId, $organisationId, $dossierId, 'facturation.remind'
                ),
            ];
            return $data;
        });
    }

    public function export(Request $request): Response
    {
        [, $organisationId, $dossierId] = $this->scope('facturation.view');
        $filters = $this->validator->filters($request);
        return $this->executeRaw(function () use (
            $organisationId,
            $dossierId,
            $filters
        ): Response {
            $rows = $this->workspace->documents(
                $organisationId,
                $dossierId,
                $filters['as_of_date'],
                [
                    'direction' => $filters['direction'],
                    'status' => $filters['status'],
                    'search' => $filters['search'],
                    'contact_id' => $filters['contact_id'],
                ]
            );
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                throw new BillingException('Export temporaire indisponible.');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['date_reference', $filters['as_of_date']], ';');
            fputcsv($stream, [
                'date_reference', 'direction', 'type', 'numero', 'numero_externe',
                'contact', 'date_document', 'date_echeance', 'statut',
                'etat_paiement', 'monnaie', 'total_centimes',
                'alloue_centimes', 'ouvert_centimes',
            ], ';');
            foreach ($rows as $row) {
                fputcsv($stream, [
                    $filters['as_of_date'],
                    $row['direction'],
                    $row['type'],
                    $row['number'],
                    $row['external_number'],
                    $row['contact'],
                    $row['document_date'],
                    $row['due_date'],
                    $row['status'],
                    $row['payment_state'],
                    $row['currency'],
                    $row['gross_cents'],
                    $row['allocated_cents'],
                    $row['open_cents'],
                ], ';');
            }
            rewind($stream);
            $contents = stream_get_contents($stream);
            fclose($stream);
            return new Response(
                is_string($contents) ? $contents : '',
                200,
                [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Disposition' =>
                        'attachment; filename="facturation-'
                        . $filters['as_of_date'] . '.csv"',
                ]
            );
        });
    }

    public function createDocument(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('facturation.manage');
        $data = $this->validator->document($request);
        return $this->execute($request, function () use (
            $data,
            $userId,
            $organisationId,
            $dossierId
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
            return ['id' => $this->billing->createDraft(
                $organisationId,
                $dossierId,
                $data['type'],
                $data['contact_id'],
                $data['document_date'],
                $data['due_date'],
                $data['lines'],
                $data['collective_account_id'],
                $data['external_number'],
                attachmentId: $attachmentId,
                actorId: $userId
            )];
        }, 201);
    }

    public function issueDocument(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('facturation.issue');
        $data = $this->validator->transition($request);
        return $this->execute($request, fn (): array => [
            'number' => $this->billing->issue(
                $organisationId,
                $dossierId,
                $data['document_id'],
                $data['version'],
                $userId
            ),
        ]);
    }

    public function postDocument(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('facturation.post');
        $data = $this->validator->posting($request);
        return $this->execute($request, fn (): array => [
            'entry_id' => $this->billing->post(
                $organisationId,
                $dossierId,
                $data['document_id'],
                $data['exercise_id'],
                $data['journal_id'],
                $userId
            ),
        ]);
    }

    public function createCredit(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('facturation.manage');
        $data = $this->validator->credit($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->billing->creditFrom(
                $organisationId,
                $dossierId,
                $data['document_id'],
                $data['date'],
                $userId
            ),
        ], 201);
    }

    public function archivePdf(Request $request): Response
    {
        [, $organisationId, $dossierId] = $this->scope('facturation.issue');
        $documentId = $this->validator->identifier($request, 'document_id');
        return $this->execute($request, function () use (
            $organisationId,
            $dossierId,
            $documentId
        ): array {
            $bytes = $this->pdf->archive(
                $organisationId,
                $dossierId,
                $documentId,
                $this->billing->creditorProfile($organisationId, $dossierId)
            );
            $document = $this->billing->document(
                $organisationId,
                $dossierId,
                $documentId
            );
            return [
                'filename' => ((string) $document['numero'] ?: 'facture') . '.pdf',
                'content_base64' => base64_encode($bytes),
                'qr_included' => (string) $document['qr_payload'] !== '',
                'warning' => (string) $document['qr_payload'] === ''
                    ? 'PDF généré sans section Swiss QR. Complétez l’identité, l’IBAN de facturation et l’adresse du client pour l’ajouter.'
                    : '',
            ];
        });
    }

    public function createContact(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('facturation.manage');
        $data = $this->validator->contact($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->contacts->create(
                $organisationId,
                $dossierId,
                $data['data'],
                $data['roles'],
                $data['address'],
                $userId,
                $data['idempotency_key']
            ),
        ], 201);
    }

    public function updateContact(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('facturation.manage');
        $data = $this->validator->contact($request, true);
        return $this->execute($request, function () use (
            $data,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $this->contacts->update(
                $organisationId,
                $dossierId,
                (int) $data['contact_id'],
                (int) $data['version'],
                $data['data'],
                $data['roles'],
                $data['address'],
                $userId
            );
            return ['updated' => true];
        });
    }

    public function createRecurrence(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('facturation.manage');
        $data = $this->validator->recurrence($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->recurrences->create(
                $organisationId,
                $dossierId,
                $data['type'],
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
        [$userId, $organisationId, $dossierId] = $this->scope('facturation.manage');
        $data = $this->validator->recurrenceState($request);
        return $this->execute($request, function () use (
            $data,
            $userId,
            $organisationId,
            $dossierId
        ): array {
            $this->recurrences->setPaused(
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
        [$userId, $organisationId, $dossierId] = $this->scope('facturation.manage');
        $date = $this->validator->generationDate($request);
        return $this->execute($request, fn (): array => [
            'document_ids' => $this->recurrences->generateDue(
                $organisationId,
                $dossierId,
                $date,
                $userId
            ),
        ]);
    }

    public function createReminder(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('facturation.remind');
        $data = $this->validator->reminder($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->billing->remind(
                $organisationId,
                $dossierId,
                $data['document_id'],
                $data['level'],
                $data['channel'],
                $data['note'],
                $userId
            ),
        ], 201);
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
                $userId
            ),
        ], 201);
    }

    public function allocatePayment(Request $request): Response
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

    public function allocateCredit(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('facturation.pay');
        $data = $this->validator->creditAllocation($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->payments->allocateCredit(
                $organisationId,
                $dossierId,
                $data['credit_id'],
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
            throw ApiException::forbidden('Permission de facturation insuffisante.');
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
        } catch (BillingException|AccountingException $exception) {
            $message = $exception->getMessage();
            if (
                str_contains($message, 'modifié')
                || str_contains($message, 'Conflit')
                || str_contains($message, 'déjà')
                || str_contains($message, 'Surallocation')
            ) {
                throw ApiException::conflict('BILLING_CONFLICT', $message);
            }
            throw ApiException::validation(['billing' => [$message]]);
        } catch (PDOException) {
            throw ApiException::validation([
                'billing' => ['Référence invalide, déjà utilisée ou hors du dossier.'],
            ]);
        }
    }

    /** @param callable():Response $callback */
    private function executeRaw(callable $callback): Response
    {
        try {
            return $callback();
        } catch (BillingException $exception) {
            throw ApiException::validation(['billing' => [$exception->getMessage()]]);
        }
    }
}
