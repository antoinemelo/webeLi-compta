<?php
declare(strict_types=1);

use Compta\Core\Support\Html;

$ui = is_array($ui_context ?? null) ? $ui_context : [];
$authenticated = ($ui['authenticated'] ?? false) === true;
$dossierType = (string) ($ui['dossier_type'] ?? '');
$band = match ($dossierType) {
    'reel' => ['context-real', 'DOSSIER RÉEL — DONNÉES DE PRODUCTION'],
    'demo' => ['context-demo', 'DÉMONSTRATION — DONNÉES FICTIVES'],
    'exercice' => ['context-exercise', 'EXERCICE — DONNÉES FICTIVES'],
    default => ['', ''],
};
$path = (string) ($ui['path'] ?? '');
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <title><?= Html::escape($title) ?> — Compta</title>
  <link rel="stylesheet"
    href="<?= Html::escape($config->url('/assets/vendor/bootstrap/bootstrap.min.css')) ?>">
  <link rel="stylesheet" href="<?= Html::escape($config->url('/assets/app.css')) ?>">
</head>
<body class="bg-body-tertiary">
  <a class="skip-link" href="#contenu">Aller au contenu</a>
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

  <?php if ($band[1] !== ''): ?>
    <div class="context-band <?= Html::escape($band[0]) ?>" role="status">
      <?= Html::escape($band[1]) ?>
    </div>
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

  <div class="<?= $authenticated ? 'app-shell' : 'container-xl py-4' ?>">
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
    <main id="contenu" class="<?= $authenticated ? 'app-main' : '' ?>" tabindex="-1">
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
