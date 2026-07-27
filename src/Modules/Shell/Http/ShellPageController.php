<?php
declare(strict_types=1);

namespace Compta\Modules\Shell\Http;

use Compta\Core\Auth\AuthService;
use Compta\Core\Config\AppConfig;
use Compta\Core\Http\Request;
use Compta\Core\Http\Response;
use Compta\Core\Http\VueShellRenderer;

final class ShellPageController
{
    public function __construct(
        private readonly AppConfig $config,
        private readonly AuthService $auth,
        private readonly VueShellRenderer $renderer,
    ) {
    }

    public function show(Request $request): Response
    {
        if ($this->auth->userId() === null) {
            return Response::redirect($this->config->url('/login'), 302);
        }
        return $this->renderer->response();
    }
}
