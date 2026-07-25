<?php
declare(strict_types=1);

use Compta\Core\Support\Html;
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <meta name="compta-base-url" content="<?= Html::escape($config->string('base_url')) ?>">
  <meta name="compta-app-base-url" content="<?= Html::escape($config->url('/app')) ?>">
  <meta name="compta-api-base-url" content="<?= Html::escape($config->url('/api/v1')) ?>">
  <meta name="compta-login-url" content="<?= Html::escape($config->url('/login')) ?>">
  <meta name="compta-logout-url" content="<?= Html::escape($config->url('/logout')) ?>">
  <meta name="compta-legacy-url" content="<?= Html::escape($config->url('/')) ?>?legacy=1">
  <title>WebeLi Compta</title>
  <?php foreach ($styleUrls as $styleUrl): ?>
    <link rel="stylesheet" href="<?= Html::escape($styleUrl) ?>">
  <?php endforeach; ?>
  <script type="module" src="<?= Html::escape($scriptUrl) ?>"></script>
</head>
<body>
  <a class="skip-link" href="#contenu">Aller au contenu</a>
  <div id="app"></div>
  <noscript>Cette interface nécessite JavaScript. L’interface PHP classique reste disponible.</noscript>
</body>
</html>
