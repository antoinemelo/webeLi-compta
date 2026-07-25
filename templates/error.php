<?php
declare(strict_types=1);

use Compta\Core\Support\Html;
?>
<section class="card border-0 shadow-sm app-narrow mx-auto">
  <div class="card-body p-4">
    <h1 class="h3">Erreur</h1>
    <div class="alert alert-danger" role="alert"><?= Html::escape($message) ?></div>
    <a class="btn btn-outline-primary" href="<?= Html::escape($config->url('/')) ?>">
      Retour à l’accueil
    </a>
  </div>
</section>
