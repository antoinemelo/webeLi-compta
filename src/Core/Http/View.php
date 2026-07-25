<?php
declare(strict_types=1);

namespace Compta\Core\Http;

use Compta\Core\Config\AppConfig;
use RuntimeException;

final class View
{
    /** @var array<string,mixed> */
    private array $shared = [];

    public function __construct(
        private readonly string $directory,
        private readonly AppConfig $config,
    ) {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], string $title = 'Compta'): string
    {
        $templatePath = $this->directory . '/' . $template . '.php';
        if (!is_file($templatePath)) {
            throw new RuntimeException("Vue introuvable : {$template}");
        }
        $config = $this->config;
        extract($this->shared + $data, EXTR_SKIP);
        ob_start();
        require $templatePath;
        $content = (string) ob_get_clean();
        ob_start();
        require $this->directory . '/layout.php';
        return (string) ob_get_clean();
    }
}
