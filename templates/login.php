<?php
declare(strict_types=1);

use Compta\Core\Support\Html;
?>
<section class="card border-0 shadow-sm app-narrow mx-auto">
  <div class="card-body p-4 p-md-5">
    <h1 class="h3 mb-4">Connexion</h1>
    <?php if (isset($error)): ?>
      <div class="alert alert-danger" role="alert"><?= Html::escape($error) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= Html::escape($config->url('/login')) ?>">
      <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
      <div class="mb-3">
        <label class="form-label" for="email">Adresse e-mail</label>
        <input class="form-control" id="email" name="email" type="email"
          autocomplete="username" required autofocus>
      </div>
      <div class="mb-4">
        <label class="form-label" for="password">Mot de passe</label>
        <input class="form-control" id="password" name="password" type="password"
          autocomplete="current-password" required>
      </div>
      <button class="btn btn-primary w-100" type="submit">Se connecter</button>
    </form>
  </div>
</section>
