<?php
declare(strict_types=1);

use Compta\Core\Support\Html;

$ui = is_array($ui_context ?? null) ? $ui_context : [];
$authenticated = ($ui['authenticated'] ?? false) === true;
$path = (string) ($ui['path'] ?? '');
$accountAccessPage = !$authenticated && in_array(
    $path,
    ['/login', '/mot-de-passe-oublie', '/reinitialiser-mot-de-passe'],
    true
);
$accountAccessTarget = $path === '/login'
    ? '#login-form'
    : '#password-reset-form';
$accountAccessLabel = $path === '/login'
    ? 'Connexion'
    : 'Récupération du mot de passe';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <title><?= Html::escape($title) ?> — Compta</title>
  <?php if ($accountAccessPage): ?>
    <link rel="preload"
      href="<?= Html::escape($config->url('/assets/fonts/montserrat/Montserrat-Variable.ttf')) ?>"
      as="font" type="font/ttf" crossorigin>
    <link rel="preload"
      href="<?= Html::escape($config->url('/assets/fonts/raleway/Raleway-Variable.ttf')) ?>"
      as="font" type="font/ttf" crossorigin>
  <?php endif; ?>
  <link rel="stylesheet"
    href="<?= Html::escape($config->url('/assets/vendor/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= Html::escape($config->url('/assets/app.css')) ?>">
</head>
<body class="<?= $accountAccessPage ? 'login-page' : 'bg-body-tertiary' ?>">
  <a class="skip-link" href="#contenu">Aller au contenu</a>
  <?php if ($accountAccessPage): ?>
    <header class="login-header no-print">
      <div class="container-fluid login-header-inner">
        <a class="login-brand" href="<?= Html::escape($config->url('/')) ?>">
          WebeLi <strong>Compta</strong>
        </a>
        <a class="login-shortcut" href="<?= $accountAccessTarget ?>"
          aria-label="<?= $accountAccessLabel ?>"
          title="<?= $accountAccessLabel ?>">
          <svg class="login-shortcut-icon" viewBox="0 0 16 16"
            aria-hidden="true" focusable="false">
            <path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6m2-3a2 2 0 1 1-4 0 2 2 0 0 1 4 0m4 8c0 1-1 1-1 1H3s-1 0-1-1 1-4 6-4 6 3 6 4m-1-.004c-.001-.246-.154-.986-.832-1.664C11.516 10.68 10.289 10 8 10s-3.516.68-4.168 1.332c-.678.678-.83 1.418-.832 1.664z"/>
          </svg>
        </a>
      </div>
    </header>
  <?php else: ?>
    <header class="app-header no-print">
      <div class="container-fluid app-header-inner">
        <a class="navbar-brand fw-semibold" href="<?= Html::escape($config->url('/')) ?>">
          WebeLi Compta
        </a>
        <div class="d-flex align-items-center gap-2">
          <span class="instance-badge">
            Instance <strong><?= Html::escape((string) ($ui['instance'] ?? $config->string('instance_id'))) ?></strong>
          </span>
          <?php if ($authenticated): ?>
          <form method="post" action="<?= Html::escape($config->url('/logout')) ?>">
            <input type="hidden" name="_csrf" value="<?= Html::escape((string) ($ui_csrf ?? '')) ?>">
            <button class="btn btn-sm btn-header" type="submit">Déconnexion</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </header>
    <div class="brand-accent no-print" aria-hidden="true"></div>
  <?php endif; ?>

  <?php if ($authenticated && ($ui['dossier'] ?? '') !== ''): ?>
    <section class="context-strip no-print" aria-label="Contexte de travail">
      <dl class="container-fluid context-list">
        <div><dt>Organisation</dt><dd><?= Html::escape((string) $ui['organisation']) ?></dd></div>
        <div><dt>Dossier</dt><dd><?= Html::escape((string) $ui['dossier']) ?></dd></div>
        <div><dt>Exercice</dt><dd><?= Html::escape((string) ($ui['exercise'] ?: 'Non défini')) ?></dd></div>
        <div><dt>Module</dt><dd><?= Html::escape((string) $ui['module']) ?></dd></div>
      </dl>
    </section>
  <?php endif; ?>

  <div class="<?= $authenticated ? 'app-shell' : ($accountAccessPage ? 'login-shell' : 'container-xl py-4') ?>">
    <?php if ($authenticated): ?>
      <aside class="app-sidebar no-print" aria-label="Navigation principale">
        <details class="app-menu">
          <summary>Menu</summary>
          <div class="app-menu-body">
            <nav aria-label="Modules">
              <ul class="app-nav">
                <?php foreach (($ui['navigation'] ?? []) as $item): ?>
                  <?php
                  $target = (string) $item['path'];
                  $active = in_array($target, ['/', '/compta'], true)
                      ? $path === $target
                      : str_starts_with($path, $target);
                  ?>
                  <li>
                    <a href="<?= Html::escape($config->url($target)) ?>"
                      <?= $active ? 'aria-current="page"' : '' ?>>
                      <?= Html::escape((string) $item['label']) ?>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            </nav>
          </div>
        </details>
      </aside>
    <?php endif; ?>
    <main id="contenu"
      class="<?= $authenticated ? 'app-main' : ($accountAccessPage ? 'login-main' : '') ?>"
      tabindex="-1">
      <?= $content ?>
    </main>
  </div>
  <script defer
    src="<?= Html::escape($config->url('/assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
  <div class="visually-hidden" aria-live="polite" aria-atomic="true"
    data-ui-announcer></div>
  <script defer src="<?= Html::escape($config->url('/assets/app.js')) ?>"></script>
</body>
</html>
