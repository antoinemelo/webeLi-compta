<?php
declare(strict_types=1);

namespace Compta\Modules\Salaires;

use Compta\Core\Auth\AccessControl;
use Compta\Core\Auth\AuthService;
use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Api\ApiResponse;
use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Compta\Core\Security\SessionStore;
use Compta\Modules\Compta\AccountingException;
use PDOException;

final class PayrollApiController
{
    public function __construct(
        private readonly SessionStore $session,
        private readonly AuthService $auth,
        private readonly AccessControl $access,
        private readonly PayrollWorkspaceService $workspace,
        private readonly PayrollConfigurationService $configuration,
        private readonly PayrollService $payrolls,
        private readonly PayrollPaymentService $payments,
        private readonly PayrollCertificateService $certificates,
        private readonly OcasRateImportService $ocas,
        private readonly PayrollInputValidator $validator,
    ) {
    }

    public function show(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.view');
        $query = $this->validator->query($request);
        return $this->execute($request, function () use (
            $userId, $organisationId, $dossierId, $query
        ): array {
            $pii = $this->has($userId, $organisationId, $dossierId, 'salaires.pii');
            $data = $this->workspace->read(
                $organisationId,
                $dossierId,
                $query['year'],
                $query['payroll_id'],
                $pii
            );
            $data['capabilities'] = [
                'manage' => $this->has($userId, $organisationId, $dossierId, 'salaires.manage'),
                'validate' => $this->has($userId, $organisationId, $dossierId, 'salaires.validate'),
                'post' => $this->has($userId, $organisationId, $dossierId, 'salaires.post'),
                'pay' => $this->has($userId, $organisationId, $dossierId, 'salaires.pay'),
                'export' => $this->has($userId, $organisationId, $dossierId, 'salaires.export'),
                'pii' => $pii,
            ];
            return $data;
        });
    }

    public function createEmployee(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.manage');
        $data = $this->validator->employee($request);
        if ($data['id'] > 0) {
            $this->requirePii($userId, $organisationId, $dossierId);
        }
        return $this->execute($request, fn (): array => [
            'id' => $this->configuration->saveEmployee(
                $organisationId, $dossierId, $data, $userId
            ),
        ]);
    }

    public function deleteEmployee(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.manage');
        $this->requirePii($userId, $organisationId, $dossierId);
        $data = $this->validator->identity($request);
        return $this->execute($request, function () use (
            $organisationId,
            $dossierId,
            $data,
            $userId
        ): array {
            $this->configuration->deleteEmployee(
                $organisationId,
                $dossierId,
                $data['id'],
                $data['version'],
                $userId
            );
            return ['deleted' => true, 'id' => $data['id']];
        });
    }

    public function saveEmployer(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.manage');
        $data = $this->validator->employer($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->configuration->saveEmployer(
                $organisationId, $dossierId, $data, $userId
            ),
        ]);
    }

    public function saveMapping(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.manage');
        $data = $this->validator->mapping($request);
        return $this->execute($request, function () use (
            $organisationId, $dossierId, $data, $userId
        ): array {
            $this->configuration->saveMapping(
                $organisationId, $dossierId, $data, $userId
            );
            return ['saved' => true];
        });
    }

    public function saveContract(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.manage');
        $data = $this->validator->contract($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->configuration->saveContract(
                $organisationId, $dossierId, $data, $userId
            ),
        ]);
    }

    public function deleteContract(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.manage');
        $data = $this->validator->identity($request);
        return $this->execute($request, function () use (
            $organisationId,
            $dossierId,
            $data,
            $userId
        ): array {
            $this->configuration->deleteContract(
                $organisationId,
                $dossierId,
                $data['id'],
                $data['version'],
                $userId
            );
            return ['deleted' => true, 'id' => $data['id']];
        });
    }

    public function createDraft(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.manage');
        $data = $this->validator->draft($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->payrolls->createPeriodDraft(
                $organisationId,
                $dossierId,
                $data['employee_id'],
                $data['year'],
                $data['month'],
                $data['elements'],
                $userId,
                $data['id'] > 0 ? $data['id'] : null,
                $data['id'] > 0 ? $data['version'] : null
            ),
        ]);
    }

    public function deleteDraft(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.manage');
        $data = $this->validator->identity($request);
        return $this->execute($request, function () use (
            $organisationId,
            $dossierId,
            $data,
            $userId
        ): array {
            $this->payrolls->deleteDraft(
                $organisationId,
                $dossierId,
                $data['id'],
                $data['version'],
                $userId
            );
            return ['deleted' => true, 'id' => $data['id']];
        });
    }

    public function validate(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.validate');
        $data = $this->validator->identity($request);
        return $this->execute($request, function () use (
            $organisationId, $dossierId, $data, $userId
        ): array {
            $this->payrolls->validate(
                $organisationId, $dossierId, $data['id'], $data['version'], $userId
            );
            return ['id' => $data['id']];
        });
    }

    public function post(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.post');
        $data = $this->validator->posting($request);
        return $this->execute($request, fn (): array => [
            'entry_id' => $this->payrolls->post(
                $organisationId, $dossierId, $data['id'],
                $data['exercise_id'], $data['journal_id'], $data['date'], $userId
            ),
        ]);
    }

    public function cancel(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.post');
        $data = $this->validator->posting($request);
        return $this->execute($request, fn (): array => [
            'entry_id' => $this->payrolls->cancel(
                $organisationId, $dossierId, $data['id'], $data['date'], $userId
            ),
        ]);
    }

    public function createPayment(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.pay');
        $data = $this->validator->payment($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->payments->create(
                $organisationId, $dossierId, $data['beneficiary_type'],
                $data['employee_id'], $data['date'], $data['amount_cents'],
                $data['account_id'], $data['reference'], $userId,
                $data['treasury_account_id'], $data['liability_id']
            ),
        ]);
    }

    public function allocate(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.pay');
        $data = $this->validator->allocation($request);
        return $this->execute($request, fn (): array => [
            'id' => $this->payments->allocate(
                $organisationId, $dossierId, $data['payment_id'],
                $data['liability_id'], $data['amount_cents'], $userId
            ),
        ]);
    }

    public function postPayment(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.pay');
        $data = $this->validator->posting($request);
        return $this->execute($request, fn (): array => [
            'entry_id' => $this->payments->post(
                $organisationId, $dossierId, $data['id'],
                $data['exercise_id'], $data['journal_id'], $userId
            ),
        ]);
    }

    public function unallocate(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.pay');
        $data = $this->validator->cancellation($request);
        return $this->execute($request, function () use (
            $organisationId, $dossierId, $data, $userId
        ): array {
            $this->payments->unallocate(
                $organisationId,
                $dossierId,
                $data['id'],
                $userId
            );
            return ['cancelled' => true, 'id' => $data['id']];
        });
    }

    public function cancelPayment(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.pay');
        $data = $this->validator->cancellation($request);
        return $this->execute($request, function () use (
            $organisationId, $dossierId, $data, $userId
        ): array {
            $this->payments->cancel(
                $organisationId,
                $dossierId,
                $data['id'],
                $userId
            );
            return ['cancelled' => true, 'id' => $data['id']];
        });
    }

    public function previewOcas(Request $request): Response
    {
        $this->scope('salaires.manage');
        $data = $this->validator->yearAction($request);
        return $this->execute(
            $request,
            fn (): array => $this->ocas->preview(
                $data['year'],
                $data['source_csv']
            )
        );
    }

    public function confirmOcas(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.manage');
        $data = $this->validator->yearAction($request);
        return $this->execute($request, fn (): array => $this->ocas->confirm(
            $organisationId, $dossierId, $data['year'], $data['fingerprint'],
            $data['verified_on'], $userId, $data['source_csv']
        ));
    }

    public function prepareCertificate(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.export');
        $this->requirePii($userId, $organisationId, $dossierId);
        $data = $this->validator->yearAction($request);
        return $this->execute($request, function () use (
            $organisationId, $dossierId, $data, $userId
        ): array {
            $xml = $this->certificates->generateXml(
                $organisationId, $dossierId, $data['employee_id'], $data['year'], $userId
            );
            return ['fingerprint' => hash('sha256', $xml), 'status' => 'prepare'];
        });
    }

    public function controlCertificate(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.export');
        $this->requirePii($userId, $organisationId, $dossierId);
        $data = $this->validator->yearAction($request);
        return $this->execute($request, function () use (
            $organisationId, $dossierId, $data, $userId
        ): array {
            $this->certificates->control(
                $organisationId, $dossierId, $data['employee_id'], $data['year'], $userId
            );
            return ['status' => 'controle'];
        });
    }

    public function exportCertificate(Request $request): Response
    {
        [$userId, $organisationId, $dossierId] = $this->scope('salaires.export');
        $this->requirePii($userId, $organisationId, $dossierId);
        $query = $this->validator->query($request);
        $employeeId = isset($request->query['employee_id'])
            ? (int) $request->query['employee_id'] : 0;
        if ($employeeId < 1) {
            throw ApiException::validation(['employee_id' => ['Identifiant requis.']]);
        }
        try {
            $xml = $this->certificates->export(
                $organisationId, $dossierId, $employeeId, $query['year'], $userId
            );
            return new Response($xml, 200, [
                'Content-Type' => 'application/xml; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="certificat-salaire.xml"',
            ]);
        } catch (PayrollException $exception) {
            throw ApiException::validation(['certificate' => [$exception->getMessage()]]);
        }
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
            throw ApiException::conflict('CONTEXT_REQUIRED', 'Sélectionnez un dossier.');
        }
        if (
            !$this->access->canViewDossier($userId, $organisationId, $dossierId)
            || !$this->has($userId, $organisationId, $dossierId, $permission)
        ) {
            throw ApiException::forbidden('Accès aux salaires refusé.');
        }
        return [$userId, $organisationId, $dossierId];
    }

    private function requirePii(int $userId, int $organisationId, int $dossierId): void
    {
        if (!$this->has($userId, $organisationId, $dossierId, 'salaires.pii')) {
            throw ApiException::forbidden('Données personnelles salariales non autorisées.');
        }
    }

    private function has(int $userId, int $organisationId, int $dossierId, string $permission): bool
    {
        return $this->access->hasDossierPermission(
            $userId, $organisationId, $dossierId, $permission
        );
    }

    /** @param callable():array<string,mixed> $callback */
    private function execute(Request $request, callable $callback): Response
    {
        try {
            return ApiResponse::success($request, $callback());
        } catch (PayrollException|AccountingException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'simultanément')) {
                throw ApiException::conflict('PAYROLL_CONFLICT', $message);
            }
            throw ApiException::validation(['payroll' => [$message]]);
        } catch (PDOException) {
            throw ApiException::validation([
                'payroll' => ['Donnée dupliquée, invalide ou encore référencée.'],
            ]);
        }
    }
}
