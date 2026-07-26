<?php
declare(strict_types=1);

namespace Compta\Core\Http;

use Compta\Core\Audit\AuditLogger;
use Compta\Core\Auth\AccessControl;
use Compta\Core\Auth\AuthService;
use Compta\Core\Config\AppConfig;
use Compta\Core\Http\Api\ApiException;
use Compta\Core\Http\Api\ApiResponse;
use Compta\Core\Http\Api\ApiRouteRegistry;
use Compta\Core\Security\Csrf;
use Compta\Core\Security\SessionStore;
use Compta\Modules\Compta\AccountingException;
use Compta\Modules\Compta\ReportingService;
use Compta\Modules\Configuration\Application\ModuleAccessService;
use Compta\Modules\Facturation\BillingException;
use Compta\Modules\Facturation\BillingService;
use Compta\Modules\Facturation\ContactService;
use Compta\Modules\Facturation\AttachmentService;
use Compta\Modules\Facturation\InvoicePdfService;
use Compta\Modules\Facturation\PaymentService;
use Compta\Modules\Salaires\PayrollCertificateService;
use Compta\Modules\Salaires\PayrollConfigurationService;
use Compta\Modules\Salaires\PayrollException;
use Compta\Modules\Salaires\PayrollImportService;
use Compta\Modules\Salaires\PayrollPaymentService;
use Compta\Modules\Salaires\PayrollService;
use Compta\Modules\Pedagogie\PedagogyConflictException;
use Compta\Modules\Pedagogie\PedagogyException;
use Compta\Modules\Pedagogie\PedagogyService;
use Compta\Modules\Shell\Http\ShellPageController;
use PDOException;
use Throwable;

final class WebApplication
{
    private Router $router;

    public function __construct(
        private readonly AppConfig $config,
        private readonly View $view,
        private readonly SessionStore $session,
        private readonly Csrf $csrf,
        private readonly AuthService $auth,
        private readonly AccessControl $access,
        private readonly AuditLogger $audit,
        private readonly ?ReportingService $reports = null,
        private readonly ?ContactService $contacts = null,
        private readonly ?BillingService $billing = null,
        private readonly ?PaymentService $payments = null,
        private readonly ?InvoicePdfService $invoicePdf = null,
        private readonly ?AttachmentService $attachments = null,
        private readonly ?PayrollConfigurationService $payrollConfiguration = null,
        private readonly ?PayrollService $payrolls = null,
        private readonly ?PayrollPaymentService $payrollPayments = null,
        private readonly ?PayrollCertificateService $payrollCertificates = null,
        private readonly ?PayrollImportService $payrollImports = null,
        private readonly ?PedagogyService $pedagogy = null,
        private readonly ?ApiRouteRegistry $apiRoutes = null,
        private readonly ?ShellPageController $shellPage = null,
        private readonly ?ModuleAccessService $moduleAccess = null,
    ) {
        $this->router = new Router();
        $this->routes();
    }

    public function handle(Request $request): Response
    {
        $moduleRefusal = $this->disabledModuleResponse($request);
        if ($moduleRefusal !== null) {
            return $this->withSecurityHeaders($moduleRefusal);
        }
        if (str_starts_with($request->path, '/api/v1')) {
            if (!$this->router->has($request->method, $request->path)) {
                return $this->withSecurityHeaders(ApiResponse::failure(
                    $request,
                    ApiException::notFound('Route API introuvable.')
                ));
            }
            return $this->withSecurityHeaders($this->router->dispatch($request));
        }
        try {
            $userId = $this->auth->userId();
            $organisationId = (int) $this->session->get('organisation_id', 0);
            $dossierId = (int) $this->session->get('dossier_id', 0);
            $visible = $userId === null ? null : $this->access->visibleDossier(
                $userId, $organisationId, $dossierId
            );
            $this->view->share('ui_context', $this->uiContext(
                $request,
                $userId,
                $organisationId,
                $dossierId,
                $visible
            ));
            $this->view->share('ui_csrf', $this->csrf->token());
            $this->view->share(
                'exercise_banner',
                $visible !== null && $visible['type'] === 'exercice'
            );
            return $this->withSecurityHeaders($this->router->dispatch($request));
        } catch (Throwable $e) {
            $message = $this->config->bool('debug') ? $e->getMessage() : 'Erreur interne';
            return $this->withSecurityHeaders(new Response(
                $this->view->render('error', ['message' => $message], 'Erreur'),
                500
            ));
        }
    }

    private function disabledModuleResponse(Request $request): ?Response
    {
        $module = $this->moduleAccess?->moduleForPath($request->path);
        $userId = $this->auth->userId();
        if ($module === null || $userId === null) {
            return null;
        }
        $organisationId = (int) $this->session->get('organisation_id', 0);
        $dossierId = (int) $this->session->get('dossier_id', 0);
        if (
            $organisationId < 1
            || $dossierId < 1
            || !$this->access->canViewDossier(
                $userId,
                $organisationId,
                $dossierId
            )
            || $this->moduleAccess?->isEnabled(
                $organisationId,
                $dossierId,
                $module
            )
        ) {
            return null;
        }
        if (str_starts_with($request->path, '/api/v1')) {
            return ApiResponse::failure(
                $request,
                ApiException::forbidden('Module désactivé pour ce dossier.')
            );
        }
        return new Response(
            $this->view->render(
                'error',
                ['message' => 'Ce module est désactivé pour le dossier sélectionné.'],
                'Module désactivé'
            ),
            403
        );
    }

    private function routes(): void
    {
        $this->router->add('GET', '/login', fn (): Response => new Response(
            $this->view->render('login', ['csrf' => $this->csrf->token()], 'Connexion')
        ));
        $this->router->add('POST', '/login', function (Request $request): Response {
            if (!$this->csrf->validate($request->post['_csrf'] ?? null)) {
                return $this->error('Jeton CSRF invalide.', 419);
            }
            $ok = $this->auth->attempt(
                $request->post['email'] ?? '',
                $request->post['password'] ?? '',
                $request->ip()
            );
            if (!$ok) {
                return new Response(
                    $this->view->render('login', [
                        'csrf' => $this->csrf->token(),
                        'error' => 'Connexion refusée.',
                    ], 'Connexion'),
                    401
                );
            }
            return Response::redirect($this->config->url('/'));
        });
        $this->router->add('POST', '/logout', function (Request $request): Response {
            if (!$this->csrf->validate($request->post['_csrf'] ?? null)) {
                return $this->error('Jeton CSRF invalide.', 419);
            }
            $this->auth->logout($request->ip());
            return Response::redirect($this->config->url('/login'));
        });
        $this->router->add('GET', '/', function (Request $request): Response {
            $userId = $this->auth->userId();
            if ($userId === null) {
                return Response::redirect($this->config->url('/login'), 302);
            }
            if (
                $this->config->bool('vue_shell_enabled')
                && ($request->query['legacy'] ?? '') !== '1'
            ) {
                return Response::redirect($this->config->url('/app'), 302);
            }
            return new Response($this->view->render('dashboard', [
                'csrf' => $this->csrf->token(),
                'dossiers' => $this->access->dossiersForUser($userId),
                'selected_dossier_id' => (int) $this->session->get('dossier_id', 0),
            ], 'Tableau de bord'));
        });
        $this->router->add('POST', '/context/dossier', function (Request $request): Response {
            $userId = $this->auth->userId();
            if ($userId === null) {
                return $this->error('Authentification requise.', 401);
            }
            if (!$this->csrf->validate($request->post['_csrf'] ?? null)) {
                return $this->error('Jeton CSRF invalide.', 419);
            }
            $parts = explode(':', $request->post['dossier_compose'] ?? '', 2);
            $organisationId = isset($parts[0]) ? (int) $parts[0] : 0;
            $dossierId = isset($parts[1]) ? (int) $parts[1] : 0;
            if (!$this->access->canViewDossier($userId, $organisationId, $dossierId)) {
                return $this->error('Accès au dossier refusé.', 403);
            }
            $this->session->set('organisation_id', $organisationId);
            $this->session->set('dossier_id', $dossierId);
            $this->audit->log(
                'contexte.dossier_selectionne',
                $userId,
                $organisationId,
                $dossierId,
                'dossier',
                (string) $dossierId,
                ip: $request->ip()
            );
            return Response::redirect($this->config->url('/'));
        });
        foreach ([
            '/compta' => '/app/compta',
            '/compta/saisie' => '/app/compta',
            '/compta/compte' => '/app/compta/extraits',
            '/compta/plan' => '/app/compta/plan',
            '/compta/journal' => '/app/compta',
            '/compta/grand-livre' => '/app/compta/etats',
            '/compta/balance' => '/app/compta/etats',
            '/compta/bilan' => '/app/compta/etats',
            '/compta/resultat' => '/app/compta/etats',
        ] as $legacyPath => $vuePath) {
            $this->router->add(
                'GET',
                $legacyPath,
                fn (): Response => Response::redirect($this->config->url($vuePath))
            );
        }
        if (
            $this->contacts !== null
            && $this->billing !== null
            && $this->payments !== null
            && $this->invoicePdf !== null
            && $this->attachments !== null
        ) {
            $this->router->add(
                'GET',
                '/facturation',
                fn (): Response => Response::redirect(
                    $this->config->url('/app/facturation')
                )
            );
        }
        if (
            $this->payrollConfiguration !== null
            && $this->payrolls !== null
            && $this->payrollPayments !== null
            && $this->payrollCertificates !== null
            && $this->payrollImports !== null
        ) {
            $this->router->add(
                'GET',
                '/salaires',
                fn (Request $request): Response => $this->payrollScreen($request)
            );
            $this->router->add(
                'POST',
                '/salaires/action',
                fn (Request $request): Response => $this->payrollMutation($request)
            );
            $this->router->add(
                'GET',
                '/salaires/fiche',
                fn (Request $request): Response => $this->payrollPrint($request)
            );
            $this->router->add(
                'GET',
                '/salaires/certificat.xml',
                fn (Request $request): Response => $this->payrollCertificateXml($request)
            );
        }
        if ($this->pedagogy !== null) {
            $this->router->add(
                'GET',
                '/pedagogie',
                fn (Request $request): Response => $this->pedagogyScreen($request)
            );
            $this->router->add(
                'POST',
                '/pedagogie/action',
                fn (Request $request): Response => $this->pedagogyMutation($request)
            );
        }
        $this->apiRoutes?->register($this->router);
        if ($this->shellPage !== null) {
            $this->router->addPrefix(
                'GET',
                '/app',
                $this->shellPage->show(...)
            );
        }
    }

    /**
     * @param array<string,mixed>|null $visible
     * @return array<string,mixed>
     */
    private function uiContext(
        Request $request,
        ?int $userId,
        int $organisationId,
        int $dossierId,
        ?array $visible,
    ): array {
        $path = $request->path;
        $module = match (true) {
            $path === '/login' => 'Connexion',
            $path === '/' => 'Tableau de bord',
            $path === '/compta' => 'Comptabilité',
            str_starts_with($path, '/compta/saisie') => 'Saisie comptable',
            str_starts_with($path, '/compta/compte') => 'Extrait de compte',
            str_starts_with($path, '/compta/plan') => 'Plan comptable',
            str_starts_with($path, '/compta/') => 'Rapports comptables',
            str_starts_with($path, '/facturation') => 'Facturation',
            str_starts_with($path, '/salaires') => 'Salaires',
            str_starts_with($path, '/pedagogie') => 'Enseignement',
            default => 'Compta',
        };
        $navigation = [];
        if ($userId !== null) {
            $navigation[] = ['path' => '/', 'label' => 'Tableau de bord'];
        }
        if ($userId !== null && $visible !== null) {
            if ($this->access->hasDossierPermission(
                $userId, $organisationId, $dossierId, 'compta.view'
            )) {
                $navigation[] = ['path' => '/compta', 'label' => 'Comptabilité'];
            }
            if ($this->access->hasDossierPermission(
                $userId, $organisationId, $dossierId, 'compta.edit'
            )) {
                $navigation[] = ['path' => '/compta/saisie', 'label' => 'Journalisation'];
            }
            if ($this->access->hasDossierPermission(
                $userId, $organisationId, $dossierId, 'compta.view'
            )) {
                $navigation[] = ['path' => '/compta/compte', 'label' => 'Extrait de compte'];
                $navigation[] = ['path' => '/compta/plan', 'label' => 'Plan comptable'];
            }
            foreach ([
                'facturation.view' => ['/facturation', 'Facturation'],
                'salaires.view' => ['/salaires', 'Salaires'],
                'pedagogie.view' => ['/pedagogie', 'Enseignement'],
            ] as $permission => [$target, $label]) {
                if ($this->access->hasDossierPermission(
                    $userId, $organisationId, $dossierId, $permission
                )) {
                    $navigation[] = ['path' => $target, 'label' => $label];
                }
            }
        }
        return [
            'authenticated' => $userId !== null,
            'path' => $path,
            'module' => $module,
            'instance' => $this->config->string('instance_id'),
            'organisation' => (string) ($visible['organisation_nom'] ?? ''),
            'dossier' => (string) ($visible['nom'] ?? ''),
            'exercise' => (string) ($visible['exercice_nom'] ?? ''),
            'dossier_type' => (string) ($visible['type'] ?? ''),
            'navigation' => $navigation,
        ];
    }

    private function pedagogyScreen(Request $request): Response
    {
        $context = $this->accountingContext('pedagogie.view');
        if ($context instanceof Response) {
            return $context;
        }
        [$userId, $organisationId] = $context;
        $manage = $this->access->hasDossierPermission(
            $userId,
            $organisationId,
            (int) $context[2],
            'pedagogie.manage'
        );
        $assignments = $this->pedagogy?->assignmentsForUser($userId) ?? [];
        $selected = (int) ($request->query['assignation'] ?? 0);
        if ($selected === 0 && $assignments !== []) {
            $selected = (int) $assignments[0]['id'];
        }
        return new Response($this->view->render('pedagogie/index', [
            'csrf' => $this->csrf->token(),
            'assignments' => $assignments,
            'selected_assignment' => $selected,
            'steps' => $selected > 0
                ? ($this->pedagogy?->steps($selected, $userId) ?? [])
                : [],
            'can_manage' => $manage,
            'can_reset' => $this->access->hasDossierPermission(
                $userId, $organisationId, (int) $context[2], 'pedagogie.reset'
            ),
            'teacher_rows' => $manage
                ? ($this->pedagogy?->dashboard($organisationId) ?? [])
                : [],
            'models' => $manage
                ? ($this->pedagogy?->models($organisationId) ?? [])
                : [],
            'groups' => $manage
                ? ($this->pedagogy?->groups($organisationId) ?? [])
                : [],
            'success' => (string) ($request->query['ok'] ?? ''),
            'error' => (string) ($request->query['erreur'] ?? ''),
        ], 'Enseignement'));
    }

    private function pedagogyMutation(Request $request): Response
    {
        $action = (string) ($request->post['action'] ?? '');
        $permission = in_array($action, [
            'model', 'group', 'member', 'assign_user', 'assign_group',
            'authorize', 'reset',
        ], true) ? 'pedagogie.manage' : 'pedagogie.work';
        $context = $this->accountingContext($permission);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->csrf->validate($request->post['_csrf'] ?? null)) {
            return $this->error('Jeton CSRF invalide.', 419);
        }
        [$userId, $organisationId, $dossierId] = $context;
        try {
            $message = match ($action) {
                'hint' => 'Indice ' . ($this->pedagogy?->nextHint(
                    $organisationId,
                    $dossierId,
                    $userId,
                    (int) ($request->post['etape_id'] ?? 0)
                )['niveau'] ?? 0) . ' affiché.',
                'attempt' => ($this->pedagogy?->attempt(
                    $organisationId,
                    $dossierId,
                    $userId,
                    (int) ($request->post['etape_id'] ?? 0),
                    (int) ($request->post['ecriture_id'] ?? 0) ?: null
                )['reussie'] ?? false) ? 'Étape validée.' : 'Nouvelle tentative enregistrée.',
                'authorize' => $this->pedagogyAuthorize(
                    $organisationId, (int) ($request->post['assignation_id'] ?? 0), $userId
                ),
                'reset' => $this->pedagogyReset(
                    $organisationId, $dossierId,
                    (int) ($request->post['assignation_id'] ?? 0), $userId
                ),
                'group' => $this->pedagogyGroup($request, $organisationId, $userId),
                'model' => $this->pedagogyModel(
                    $request, $organisationId, $dossierId, $userId
                ),
                'member' => $this->pedagogyMember($request, $organisationId, $userId),
                'assign_user' => $this->pedagogyAssign(
                    $request, $organisationId, $userId, false
                ),
                'assign_group' => $this->pedagogyAssign(
                    $request, $organisationId, $userId, true
                ),
                'replace_draft' => $this->pedagogyReplaceDraft(
                    $request, $organisationId, $dossierId, $userId
                ),
                default => throw new PedagogyException('Action pédagogique inconnue.'),
            };
            return $this->pedagogyRedirect($message);
        } catch (PedagogyConflictException $e) {
            return $this->error($e->getMessage(), 409);
        } catch (PedagogyException|AccountingException|PDOException $e) {
            if ($action === 'reset') {
                return $this->error($e->getMessage(), 403);
            }
            return $this->pedagogyRedirect($e->getMessage(), true);
        }
    }

    private function pedagogyAuthorize(
        int $organisationId,
        int $assignmentId,
        int $userId,
    ): string {
        $this->pedagogy?->authorizeCorrection($organisationId, $assignmentId, $userId);
        return 'Correction autorisée.';
    }

    private function pedagogyReset(
        int $organisationId,
        int $dossierId,
        int $assignmentId,
        int $userId,
    ): string {
        $this->pedagogy?->assertResetAllowed($organisationId, $dossierId);
        $newDossier = $this->pedagogy?->reset(
            $organisationId, $assignmentId, $userId
        ) ?? 0;
        $this->session->set('dossier_id', $newDossier);
        return 'Exercice réinitialisé atomiquement.';
    }

    private function pedagogyGroup(
        Request $request,
        int $organisationId,
        int $userId,
    ): string {
        $this->pedagogy?->createGroup(
            $organisationId, (string) ($request->post['nom'] ?? ''), $userId
        );
        return 'Groupe créé.';
    }

    private function pedagogyModel(
        Request $request,
        int $organisationId,
        int $sourceDossierId,
        int $userId,
    ): string {
        $steps = json_decode(
            (string) ($request->post['etapes_json'] ?? '[]'),
            true,
            64,
            JSON_THROW_ON_ERROR
        );
        $solution = json_decode(
            (string) ($request->post['solution_json'] ?? '{}'),
            true,
            64,
            JSON_THROW_ON_ERROR
        );
        $model = $this->pedagogy?->createModel(
            $organisationId,
            (string) ($request->post['titre'] ?? ''),
            (string) ($request->post['description'] ?? ''),
            $userId
        ) ?? 0;
        $this->pedagogy?->createVersion(
            $organisationId,
            $model,
            $sourceDossierId,
            (string) ($request->post['consignes'] ?? ''),
            is_array($steps) ? $steps : [],
            [],
            [],
            is_array($solution) ? $solution : [],
            (string) ($request->post['regle_correction'] ?? 'manuelle'),
            (string) ($request->post['valeur_correction'] ?? ''),
            $userId
        );
        return 'Modèle versionné et publié.';
    }

    private function pedagogyMember(
        Request $request,
        int $organisationId,
        int $userId,
    ): string {
        $this->pedagogy?->addMember(
            $organisationId,
            (int) ($request->post['groupe_id'] ?? 0),
            (int) ($request->post['utilisateur_id'] ?? 0),
            'membre',
            $userId
        );
        return 'Membre ajouté.';
    }

    private function pedagogyAssign(
        Request $request,
        int $organisationId,
        int $userId,
        bool $group,
    ): string {
        if ($group) {
            $this->pedagogy?->assignGroup(
                $organisationId,
                (int) ($request->post['version_id'] ?? 0),
                (int) ($request->post['groupe_id'] ?? 0),
                (string) ($request->post['nom'] ?? ''),
                $userId
            );
        } else {
            $this->pedagogy?->assignIndividual(
                $organisationId,
                (int) ($request->post['version_id'] ?? 0),
                (int) ($request->post['utilisateur_id'] ?? 0),
                (string) ($request->post['nom'] ?? ''),
                $userId
            );
        }
        return 'Exercice cloné et assigné.';
    }

    private function pedagogyReplaceDraft(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $lines = json_decode(
            (string) ($request->post['lignes_json'] ?? '[]'),
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        $this->pedagogy?->replaceDraft(
            $organisationId,
            $dossierId,
            $userId,
            (int) ($request->post['ecriture_id'] ?? 0),
            (int) ($request->post['version'] ?? 0),
            [
                'exercice_id' => (int) ($request->post['exercice_id'] ?? 0),
                'journal_id' => (int) ($request->post['journal_id'] ?? 0),
                'date_comptable' => (string) ($request->post['date_comptable'] ?? ''),
                'libelle' => (string) ($request->post['libelle'] ?? ''),
                'lignes' => is_array($lines) ? $lines : [],
            ]
        );
        return 'Brouillon collaboratif enregistré.';
    }

    private function pedagogyRedirect(string $message, bool $error = false): Response
    {
        return Response::redirect(
            $this->config->url('/pedagogie') . '?' . http_build_query([
                $error ? 'erreur' : 'ok' => $message,
            ])
        );
    }

    private function payrollScreen(Request $request): Response
    {
        $context = $this->accountingContext('salaires.view');
        if ($context instanceof Response) {
            return $context;
        }
        [$userId, $organisationId, $dossierId] = $context;
        $activeTab = in_array(
            (string) ($request->query['onglet'] ?? ''),
            ['fiches', 'employes', 'paiements', 'parametres'],
            true
        ) ? (string) $request->query['onglet'] : 'fiches';
        $pii = $this->access->hasDossierPermission(
            $userId,
            $organisationId,
            $dossierId,
            'salaires.pii'
        );
        $employer = [];
        $mapping = [];
        try {
            $employer = $this->payrollConfiguration?->employer(
                $organisationId,
                $dossierId
            ) ?? [];
        } catch (PayrollException) {
        }
        try {
            $mapping = $this->payrollConfiguration?->mapping(
                $organisationId,
                $dossierId
            ) ?? [];
        } catch (PayrollException) {
        }
        return new Response($this->view->render('salaires/index', [
            'csrf' => $this->csrf->token(),
            'active_tab' => $activeTab,
            'payrolls' => $this->payrolls?->payrolls(
                $organisationId,
                $dossierId,
                $pii
            ) ?? [],
            'employees' => $this->payrollConfiguration?->employees(
                $organisationId,
                $dossierId,
                $pii
            ) ?? [],
            'payments' => $this->payrollPayments?->payments(
                $organisationId,
                $dossierId
            ) ?? [],
            'liabilities' => $this->payrollPayments?->liabilities(
                $organisationId,
                $dossierId
            ) ?? [],
            'catalog' => $this->payrollConfiguration?->catalog(
                $organisationId,
                $dossierId
            ) ?? [],
            'employer' => $employer,
            'mapping' => $mapping,
            'can_manage' => $this->access->hasDossierPermission(
                $userId, $organisationId, $dossierId, 'salaires.manage'
            ),
            'can_validate' => $this->access->hasDossierPermission(
                $userId, $organisationId, $dossierId, 'salaires.validate'
            ),
            'can_post' => $this->access->hasDossierPermission(
                $userId, $organisationId, $dossierId, 'salaires.post'
            ),
            'can_pay' => $this->access->hasDossierPermission(
                $userId, $organisationId, $dossierId, 'salaires.pay'
            ),
            'can_export' => $this->access->hasDossierPermission(
                $userId, $organisationId, $dossierId, 'salaires.export'
            ),
            'can_pii' => $pii,
            'success' => (string) ($request->query['ok'] ?? ''),
            'error' => (string) ($request->query['erreur'] ?? ''),
        ], 'Salaires genevois'));
    }

    private function payrollMutation(Request $request): Response
    {
        $action = (string) ($request->post['action'] ?? '');
        $permission = match ($action) {
            'validate' => 'salaires.validate',
            'post', 'cancel' => 'salaires.post',
            'payment', 'allocate', 'post_payment' => 'salaires.pay',
            'email', 'import' => 'salaires.export',
            default => 'salaires.manage',
        };
        $context = $this->accountingContext($permission);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->csrf->validate($request->post['_csrf'] ?? null)) {
            return $this->error('Jeton CSRF invalide.', 419);
        }
        [$userId, $organisationId, $dossierId] = $context;
        $tab = (string) ($request->post['onglet'] ?? 'fiches');
        try {
            $message = match ($action) {
                'employer' => $this->savePayrollEmployer(
                    $request, $organisationId, $dossierId, $userId
                ),
                'employee' => $this->createPayrollEmployee(
                    $request, $organisationId, $dossierId, $userId
                ),
                'unit' => $this->addPayrollUnit(
                    $request, $organisationId, $dossierId
                ),
                'tariff' => $this->addPayrollTariff(
                    $request, $organisationId, $dossierId
                ),
                'mapping' => $this->savePayrollMapping(
                    $request, $organisationId, $dossierId, $userId
                ),
                'draft' => $this->createPayrollDraft(
                    $request, $organisationId, $dossierId, $userId
                ),
                'validate' => $this->validatePayroll(
                    $request, $organisationId, $dossierId, $userId
                ),
                'post' => $this->postPayroll(
                    $request, $organisationId, $dossierId, $userId
                ),
                'cancel' => $this->cancelPayroll(
                    $request, $organisationId, $dossierId, $userId
                ),
                'payment' => $this->createPayrollPayment(
                    $request, $organisationId, $dossierId, $userId
                ),
                'allocate' => $this->allocatePayrollPayment(
                    $request, $organisationId, $dossierId, $userId
                ),
                'post_payment' => $this->postPayrollPayment(
                    $request, $organisationId, $dossierId, $userId
                ),
                'email' => $this->queuePayrollEmail(
                    $request, $organisationId, $dossierId, $userId
                ),
                'import' => $this->importPayrollJson(
                    $request, $organisationId, $dossierId, $userId
                ),
                default => throw new PayrollException('Action salariale inconnue.'),
            };
            return $this->payrollRedirect($tab, $message);
        } catch (PayrollException|AccountingException|PDOException $e) {
            return $this->payrollRedirect($tab, $e->getMessage(), true);
        }
    }

    private function savePayrollEmployer(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $this->payrollConfiguration?->saveEmployer(
            $organisationId,
            $dossierId,
            [
                'nom' => $request->post['nom'] ?? '',
                'rue' => $request->post['rue'] ?? '',
                'npa' => $request->post['npa'] ?? '',
                'localite' => $request->post['localite'] ?? '',
                'pays' => 'CH',
                'telephone' => $request->post['telephone'] ?? '',
                'email' => $request->post['email'] ?? '',
                'heures_hebdo_milli' => $this->decimalToMilli(
                    (string) ($request->post['heures_hebdo'] ?? '40')
                ),
            ],
            $userId
        );
        return 'Employeur du dossier enregistré.';
    }

    private function createPayrollEmployee(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $this->payrollConfiguration?->createEmployee(
            $organisationId,
            $dossierId,
            [
                'prenom' => $request->post['prenom'] ?? '',
                'nom' => $request->post['nom'] ?? '',
                'email' => $request->post['email'] ?? '',
                'numero_avs' => $request->post['numero_avs'] ?? '',
                'date_naissance' => $request->post['date_naissance'] ?? '',
                'procedure' => $request->post['procedure'] ?? 'ordinaire',
                'supplement_vacances_ppm' => $this->percentToPpm(
                    (string) ($request->post['supplement_vacances'] ?? '8.33')
                ),
                'impot_source_ppm' => $this->percentToPpm(
                    (string) ($request->post['impot_source'] ?? '0')
                ),
            ],
            $userId
        );
        return 'Employé genevois créé.';
    }

    private function addPayrollUnit(
        Request $request,
        int $organisationId,
        int $dossierId,
    ): string {
        $this->payrollConfiguration?->addUnit(
            $organisationId,
            $dossierId,
            (string) ($request->post['libelle'] ?? ''),
            $this->decimalToMilli((string) ($request->post['heures'] ?? ''))
        );
        return 'Unité de prestation ajoutée.';
    }

    private function addPayrollTariff(
        Request $request,
        int $organisationId,
        int $dossierId,
    ): string {
        $this->payrollConfiguration?->addTariff(
            $organisationId,
            $dossierId,
            (string) ($request->post['libelle'] ?? ''),
            $this->francsToCents((string) ($request->post['montant'] ?? ''))
        );
        return 'Tarif salarial ajouté.';
    }

    private function savePayrollMapping(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $mapping = [];
        foreach (PayrollConfigurationService::MAPPING_FIELDS as $field) {
            $mapping[$field] = (int) ($request->post[$field] ?? 0);
        }
        $this->payrollConfiguration?->saveMapping(
            $organisationId,
            $dossierId,
            $mapping,
            $userId
        );
        return 'Mapping comptable salarial enregistré.';
    }

    private function createPayrollDraft(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $this->payrolls?->createDraft(
            $organisationId,
            $dossierId,
            (int) ($request->post['employe_id'] ?? 0),
            (int) ($request->post['annee'] ?? 0),
            (int) ($request->post['mois'] ?? 0),
            [[
                'libelle' => (string) ($request->post['libelle'] ?? ''),
                'unite_libelle' => (string) ($request->post['unite_libelle'] ?? 'Heure'),
                'heures_unite_milli' => $this->decimalToMilli(
                    (string) ($request->post['heures_unite'] ?? '1')
                ),
                'quantite_milli' => $this->decimalToMilli(
                    (string) ($request->post['quantite'] ?? '')
                ),
                'taux_horaire_centimes' => $this->francsToCents(
                    (string) ($request->post['taux_horaire'] ?? '')
                ),
            ]],
            null,
            null,
            $userId
        );
        return 'Brouillon de salaire calculé.';
    }

    private function validatePayroll(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $this->payrolls?->validate(
            $organisationId,
            $dossierId,
            (int) ($request->post['fiche_id'] ?? 0),
            (int) ($request->post['version'] ?? 0),
            $userId
        );
        return 'Fiche de salaire validée et figée.';
    }

    private function postPayroll(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $this->payrolls?->post(
            $organisationId,
            $dossierId,
            (int) ($request->post['fiche_id'] ?? 0),
            (int) ($request->post['exercice_id'] ?? 0),
            (int) ($request->post['journal_id'] ?? 0),
            (string) ($request->post['date_comptable'] ?? ''),
            $userId
        );
        return 'Fiche comptabilisée.';
    }

    private function cancelPayroll(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $this->payrolls?->cancel(
            $organisationId,
            $dossierId,
            (int) ($request->post['fiche_id'] ?? 0),
            (string) ($request->post['date_comptable'] ?? ''),
            $userId
        );
        return 'Fiche annulée; la contre-passation a été créée si nécessaire.';
    }

    private function createPayrollPayment(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $type = (string) ($request->post['beneficiaire_type'] ?? 'organisme');
        $this->payrollPayments?->create(
            $organisationId,
            $dossierId,
            $type,
            $type === 'employe'
                ? (int) ($request->post['employe_id'] ?? 0)
                : null,
            (string) ($request->post['date_paiement'] ?? ''),
            $this->francsToCents((string) ($request->post['montant'] ?? '')),
            (int) ($request->post['compte_tresorerie_id'] ?? 0),
            (string) ($request->post['reference'] ?? ''),
            $userId
        );
        return 'Paiement salarial saisi.';
    }

    private function allocatePayrollPayment(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $this->payrollPayments?->allocate(
            $organisationId,
            $dossierId,
            (int) ($request->post['paiement_id'] ?? 0),
            (int) ($request->post['dette_id'] ?? 0),
            $this->francsToCents((string) ($request->post['montant'] ?? '')),
            $userId
        );
        return 'Paiement alloué à la dette.';
    }

    private function postPayrollPayment(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $this->payrollPayments?->post(
            $organisationId,
            $dossierId,
            (int) ($request->post['paiement_id'] ?? 0),
            (int) ($request->post['exercice_id'] ?? 0),
            (int) ($request->post['journal_id'] ?? 0),
            $userId
        );
        return 'Paiement salarial comptabilisé.';
    }

    private function queuePayrollEmail(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $this->payrolls?->queueEmail(
            $organisationId,
            $dossierId,
            (int) ($request->post['fiche_id'] ?? 0),
            (string) ($request->post['destinataire'] ?? ''),
            $userId
        );
        return 'E-mail placé en file d’attente; aucun envoi n’est prétendu ici.';
    }

    private function importPayrollJson(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $upload = $request->files['fichier_json'] ?? null;
        if (
            $upload === null
            || $upload['error'] !== UPLOAD_ERR_OK
            || !is_uploaded_file($upload['tmp_name'])
        ) {
            throw new PayrollException('Fichier JSON salarial absent ou invalide.');
        }
        $json = file_get_contents($upload['tmp_name']);
        if (!is_string($json) || strlen($json) > 5_000_000) {
            throw new PayrollException('Fichier JSON salarial illisible ou trop volumineux.');
        }
        $simulation = ($request->post['simulation'] ?? '1') === '1';
        $result = $this->payrollImports?->import(
            $organisationId,
            $dossierId,
            $json,
            $simulation,
            $userId
        );
        return sprintf(
            '%s : %d créée(s), %d ignorée(s), %d erreur(s).',
            $simulation ? 'Simulation' : 'Import',
            count($result['crees'] ?? []),
            count($result['ignores'] ?? []),
            count($result['erreurs'] ?? [])
        );
    }

    private function payrollPrint(Request $request): Response
    {
        $context = $this->accountingContext('salaires.export');
        if ($context instanceof Response) {
            return $context;
        }
        [$userId, $organisationId, $dossierId] = $context;
        $pii = $this->access->hasDossierPermission(
            $userId, $organisationId, $dossierId, 'salaires.pii'
        );
        $id = (int) ($request->query['id'] ?? 0);
        return new Response($this->view->render('salaires/fiche', [
            'fiche' => $this->payrolls?->payroll(
                $organisationId, $dossierId, $id, $pii
            ) ?? [],
            'lignes' => $this->payrolls?->lines($id) ?? [],
            'composants' => $this->payrolls?->components($id) ?? [],
        ], 'Fiche de salaire'));
    }

    private function payrollCertificateXml(Request $request): Response
    {
        $context = $this->accountingContext('salaires.export');
        if ($context instanceof Response) {
            return $context;
        }
        [$userId, $organisationId, $dossierId] = $context;
        if (!$this->access->hasDossierPermission(
            $userId, $organisationId, $dossierId, 'salaires.pii'
        )) {
            return $this->error('Export nominatif non autorisé.', 403);
        }
        $xml = $this->payrollCertificates?->generateXml(
            $organisationId,
            $dossierId,
            (int) ($request->query['employe'] ?? 0),
            (int) ($request->query['annee'] ?? 0),
            $userId
        ) ?? '';
        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="certificat-salaire.xml"',
        ]);
    }

    private function payrollRedirect(
        string $tab,
        string $message,
        bool $error = false,
    ): Response {
        if (!in_array($tab, ['fiches', 'employes', 'paiements', 'parametres'], true)) {
            $tab = 'fiches';
        }
        return Response::redirect(
            $this->config->url('/salaires') . '?' . http_build_query([
                'onglet' => $tab,
                $error ? 'erreur' : 'ok' => $message,
            ])
        );
    }

    private function percentToPpm(string $percent): int
    {
        $normalized = str_replace(',', '.', trim($percent));
        if (
            preg_match('/^\d+(?:\.\d{1,4})?$/', $normalized) !== 1
            || (float) $normalized > 100
        ) {
            throw new PayrollException('Taux en pour-cent invalide.');
        }
        return (int) round((float) $normalized * 10000, 0, PHP_ROUND_HALF_UP);
    }

    private function decimalToMilli(string $value): int
    {
        $normalized = str_replace(',', '.', trim($value));
        if (
            preg_match('/^\d+(?:\.\d{1,3})?$/', $normalized) !== 1
            || (float) $normalized <= 0
        ) {
            throw new PayrollException('Quantité décimale invalide.');
        }
        return (int) round((float) $normalized * 1000, 0, PHP_ROUND_HALF_UP);
    }

    private function billingScreen(Request $request): Response
    {
        $context = $this->accountingContext('facturation.view');
        if ($context instanceof Response) {
            return $context;
        }
        if (($request->query['onglet'] ?? '') === 'contacts') {
            return Response::redirect(
                $this->config->url('/app/configuration/referentiels')
                . '?section=contacts'
            );
        }
        [$userId, $organisationId, $dossierId] = $context;
        $activeTab = in_array(
            (string) ($request->query['onglet'] ?? ''),
            ['contacts', 'documents', 'paiements'],
            true
        ) ? (string) $request->query['onglet'] : 'documents';
        return new Response($this->view->render('facturation/index', [
            'csrf' => $this->csrf->token(),
            'contacts' => $this->contacts?->all($organisationId, $dossierId) ?? [],
            'documents' => $this->billing?->documents($organisationId, $dossierId) ?? [],
            'payments' => $this->payments?->payments($organisationId, $dossierId) ?? [],
            'catalog' => $this->billing?->catalog($organisationId, $dossierId) ?? [],
            'creditor_profile' => $this->billing?->creditorProfile(
                $organisationId,
                $dossierId
            ) ?? [],
            'active_tab' => $activeTab,
            'can_manage' => $this->access->hasDossierPermission(
                $userId,
                $organisationId,
                $dossierId,
                'facturation.manage'
            ),
            'can_issue' => $this->access->hasDossierPermission(
                $userId,
                $organisationId,
                $dossierId,
                'facturation.issue'
            ),
            'can_post' => $this->access->hasDossierPermission(
                $userId,
                $organisationId,
                $dossierId,
                'facturation.post'
            ),
            'can_pay' => $this->access->hasDossierPermission(
                $userId,
                $organisationId,
                $dossierId,
                'facturation.pay'
            ),
            'success' => (string) ($request->query['ok'] ?? ''),
            'error' => (string) ($request->query['erreur'] ?? ''),
        ], 'Débiteurs et créanciers'));
    }

    private function billingMutation(Request $request): Response
    {
        $action = (string) ($request->post['action'] ?? '');
        $permission = match ($action) {
            'draft', 'credit', 'profile' => 'facturation.manage',
            'issue', 'pdf' => 'facturation.issue',
            'post' => 'facturation.post',
            'payment', 'allocate' => 'facturation.pay',
            'remind' => 'facturation.remind',
            default => 'facturation.manage',
        };
        $context = $this->accountingContext($permission);
        if ($context instanceof Response) {
            return $context;
        }
        if (!$this->csrf->validate($request->post['_csrf'] ?? null)) {
            return $this->error('Jeton CSRF invalide.', 419);
        }
        [$userId, $organisationId, $dossierId] = $context;
        $tab = (string) ($request->post['onglet'] ?? 'documents');
        try {
            $message = match ($action) {
                'profile' => $this->saveBillingProfile(
                    $request,
                    $organisationId,
                    $dossierId,
                    $userId
                ),
                'draft' => $this->createBillingDraft(
                    $request,
                    $organisationId,
                    $dossierId,
                    $userId
                ),
                'issue' => $this->issueBillingDocument(
                    $request,
                    $organisationId,
                    $dossierId,
                    $userId
                ),
                'post' => $this->postBillingDocument(
                    $request,
                    $organisationId,
                    $dossierId,
                    $userId
                ),
                'credit' => $this->createBillingCredit(
                    $request,
                    $organisationId,
                    $dossierId,
                    $userId
                ),
                'payment' => $this->createBillingPayment(
                    $request,
                    $organisationId,
                    $dossierId,
                    $userId
                ),
                'allocate' => $this->allocateBillingPayment(
                    $request,
                    $organisationId,
                    $dossierId,
                    $userId
                ),
                'remind' => $this->createBillingReminder(
                    $request,
                    $organisationId,
                    $dossierId,
                    $userId
                ),
                default => throw new BillingException('Action de facturation inconnue.'),
            };
            return $this->billingRedirect($tab, $message);
        } catch (BillingException|AccountingException|PDOException $e) {
            return $this->billingRedirect($tab, $e->getMessage(), true);
        }
    }

    private function saveBillingProfile(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $this->billing?->saveCreditorProfile(
            $organisationId,
            $dossierId,
            [
                'adresse_ligne1' => (string) ($request->post['adresse_ligne1'] ?? ''),
                'adresse_ligne2' => (string) ($request->post['adresse_ligne2'] ?? ''),
                'code_postal' => (string) ($request->post['code_postal'] ?? ''),
                'localite' => (string) ($request->post['localite'] ?? ''),
                'pays' => (string) ($request->post['pays'] ?? 'CH'),
                'iban_facturation' => (string) ($request->post['iban_facturation'] ?? ''),
            ],
            $userId
        );
        return 'Coordonnées PDF et QR-facture enregistrées pour l’organisation.';
    }

    private function createBillingDraft(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $date = (string) ($request->post['date_document'] ?? '');
        $attachmentId = null;
        $upload = $request->files['justificatif'] ?? null;
        if (
            $upload !== null
            && $upload['error'] !== UPLOAD_ERR_NO_FILE
        ) {
            if (
                $upload['error'] !== UPLOAD_ERR_OK
                || !is_uploaded_file($upload['tmp_name'])
            ) {
                throw new BillingException('Téléversement du justificatif invalide.');
            }
            $contents = file_get_contents($upload['tmp_name']);
            if (!is_string($contents)) {
                throw new BillingException('Justificatif illisible.');
            }
            $attachmentId = $this->attachments?->store(
                $organisationId,
                $dossierId,
                $upload['name'],
                $contents,
                $userId
            );
        }
        $this->billing?->createDraft(
            $organisationId,
            $dossierId,
            (string) ($request->post['type'] ?? ''),
            (int) ($request->post['contact_id'] ?? 0),
            $date,
            (string) ($request->post['date_echeance'] ?? ''),
            [[
                'libelle' => (string) ($request->post['libelle'] ?? ''),
                'quantite_milli' => 1000,
                'prix_unitaire_centimes' => $this->francsToCents(
                    (string) ($request->post['montant'] ?? '')
                ),
                'mode_saisie' => (string) ($request->post['mode_saisie'] ?? 'net'),
                'compte_id' => (int) ($request->post['compte_id'] ?? 0),
                'code_tva_id' => (int) ($request->post['code_tva_id'] ?? 0),
                'date_prestation' => (string) ($request->post['date_prestation'] ?? $date),
            ]],
            (int) ($request->post['compte_collectif_id'] ?? 0) ?: null,
            (string) ($request->post['numero_externe'] ?? ''),
            attachmentId: $attachmentId,
            actorId: $userId
        );
        return 'Brouillon créé sans consommation de numéro.';
    }

    private function issueBillingDocument(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $number = $this->billing?->issue(
            $organisationId,
            $dossierId,
            (int) ($request->post['document_id'] ?? 0),
            (int) ($request->post['version'] ?? 0),
            $userId
        );
        return 'Document émis sous le numéro ' . $number . '.';
    }

    private function postBillingDocument(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $entry = $this->billing?->post(
            $organisationId,
            $dossierId,
            (int) ($request->post['document_id'] ?? 0),
            (int) ($request->post['exercice_id'] ?? 0),
            (int) ($request->post['journal_id'] ?? 0),
            $userId
        );
        return 'Document comptabilisé dans l’écriture ' . $entry . '.';
    }

    private function createBillingCredit(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $id = $this->billing?->creditFrom(
            $organisationId,
            $dossierId,
            (int) ($request->post['document_id'] ?? 0),
            (string) ($request->post['date'] ?? date('Y-m-d')),
            $userId
        );
        return 'Brouillon d’avoir ' . $id . ' créé ; il devra être émis puis comptabilisé.';
    }

    private function createBillingPayment(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $id = $this->payments?->create(
            $organisationId,
            $dossierId,
            (int) ($request->post['contact_id'] ?? 0),
            (string) ($request->post['sens'] ?? ''),
            (string) ($request->post['date_paiement'] ?? ''),
            $this->francsToCents((string) ($request->post['montant'] ?? '')),
            (string) ($request->post['reference'] ?? ''),
            (int) ($request->post['compte_tresorerie_id'] ?? 0) ?: null,
            $userId
        );
        return 'Paiement ' . $id . ' saisi indépendamment des factures.';
    }

    private function allocateBillingPayment(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $this->payments?->allocatePayment(
            $organisationId,
            $dossierId,
            (int) ($request->post['paiement_id'] ?? 0),
            (int) ($request->post['document_id'] ?? 0),
            $this->francsToCents((string) ($request->post['montant'] ?? '')),
            $userId
        );
        return 'Paiement alloué à la facture.';
    }

    private function createBillingReminder(
        Request $request,
        int $organisationId,
        int $dossierId,
        int $userId,
    ): string {
        $this->billing?->remind(
            $organisationId,
            $dossierId,
            (int) ($request->post['document_id'] ?? 0),
            (int) ($request->post['niveau'] ?? 1),
            (string) ($request->post['canal'] ?? 'email'),
            (string) ($request->post['note'] ?? ''),
            $userId
        );
        return 'Rappel manuel tracé.';
    }

    private function billingPdf(Request $request): Response
    {
        $context = $this->accountingContext('facturation.issue');
        if ($context instanceof Response) {
            return $context;
        }
        [$userId, $organisationId, $dossierId] = $context;
        try {
            $documentId = (int) ($request->query['id'] ?? 0);
            $pdf = $this->invoicePdf?->archive(
                $organisationId,
                $dossierId,
                $documentId,
                $this->billing?->creditorProfile($organisationId, $dossierId) ?? [],
                $userId
            );
            return new Response(
                (string) $pdf,
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="document-' . $documentId . '.pdf"',
                ]
            );
        } catch (BillingException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    private function billingRedirect(
        string $tab,
        string $message,
        bool $error = false,
    ): Response {
        if (!in_array($tab, ['contacts', 'documents', 'paiements'], true)) {
            $tab = 'documents';
        }
        return Response::redirect(
            $this->config->url('/facturation') . '?' . http_build_query([
                'onglet' => $tab,
                $error ? 'erreur' : 'ok' => $message,
            ])
        );
    }

    /** @return array{int,int,int}|Response */
    private function accountingContext(string $permission): array|Response
    {
        $userId = $this->auth->userId();
        if ($userId === null) {
            return Response::redirect($this->config->url('/login'), 302);
        }
        $organisationId = (int) $this->session->get('organisation_id', 0);
        $dossierId = (int) $this->session->get('dossier_id', 0);
        if (
            $organisationId < 1
            || $dossierId < 1
            || !$this->access->canViewDossier(
                $userId,
                $organisationId,
                $dossierId
            )
            || !$this->access->hasDossierPermission(
                $userId,
                $organisationId,
                $dossierId,
                $permission
            )
        ) {
            return $this->error('Accès au dossier refusé.', 403);
        }
        return [$userId, $organisationId, $dossierId];
    }

    private function francsToCents(string $amount): int
    {
        $normalized = str_replace(
            ["'", '’', ' ', "\u{00A0}", "\u{202F}", ','],
            ['', '', '', '', '', '.'],
            trim($amount)
        );
        if ($normalized === '') {
            return 0;
        }
        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d{1,2}))?$/', $normalized, $parts)) {
            throw new AccountingException("Montant invalide : {$amount}.");
        }
        $cents = ((int) $parts[2] * 100)
            + (int) str_pad((string) ($parts[3] ?? ''), 2, '0');
        return ($parts[1] ?? '') === '-' ? -$cents : $cents;
    }

    private function error(string $message, int $status): Response
    {
        return new Response($this->view->render('error', ['message' => $message], 'Erreur'), $status);
    }

    private function withSecurityHeaders(Response $response): Response
    {
        return new Response($response->body, $response->status, $response->headers + [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => "default-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'",
            'Cache-Control' => 'no-store',
        ]);
    }
}
