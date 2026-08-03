<?php
declare(strict_types=1);

use Compta\Core\Support\Html;

$success = ($success ?? false) === true;
$valid = ($valid ?? false) === true;
$hasError = isset($error) && trim((string) $error) !== '';
?>
<section class="login-stage" aria-labelledby="new-password-presentation-title">
  <div class="container-fluid login-stage-inner">
    <div class="row g-5 align-items-center">
      <div class="col-lg-7 col-xl-8">
        <div class="login-presentation">
          <p class="login-eyebrow mb-3">Sécurité du compte</p>
          <h1 class="login-display" id="new-password-presentation-title">
            Un nouveau mot de passe, toutes les anciennes sessions révoquées.
          </h1>
          <p class="login-lead">
            Choisissez une phrase secrète unique d’au moins douze caractères.
            L’application d’authentification ou le code e-mail reste requis s’il
            était déjà activé.
          </p>
        </div>
      </div>
      <div class="col-lg-5 col-xl-4">
        <section class="card login-card shadow-lg" id="password-reset-form"
          aria-labelledby="new-password-title">
          <div class="card-body p-4 p-sm-5">
            <p class="text-uppercase small fw-bold text-secondary mb-2">
              Lien à usage unique
            </p>
            <h2 class="h2 mb-2" id="new-password-title">
              <?= $success ? 'Mot de passe modifié' : 'Nouveau mot de passe' ?>
            </h2>

            <?php if ($success): ?>
              <div class="alert alert-success mt-4" role="status">
                Le mot de passe est remplacé et les sessions précédentes ne sont
                plus valables.
              </div>
              <a class="btn btn-primary btn-lg w-100 mt-3"
                href="<?= Html::escape($config->url('/login')) ?>">
                Se connecter
              </a>
            <?php elseif (!$valid): ?>
              <div class="alert alert-danger mt-4" role="alert">
                <?= Html::escape(
                    $hasError
                        ? (string) $error
                        : 'Ce lien est invalide, expiré ou déjà utilisé.'
                ) ?>
              </div>
              <a class="btn btn-primary w-100 mt-3"
                href="<?= Html::escape($config->url('/mot-de-passe-oublie')) ?>">
                Demander un nouveau lien
              </a>
            <?php else: ?>
              <p class="text-body-secondary mb-4">
                Le lien ne sera consommé qu’après validation du nouveau mot de passe.
              </p>
              <?php if ($hasError): ?>
                <div class="alert alert-danger" role="alert">
                  <?= Html::escape((string) $error) ?>
                </div>
              <?php endif; ?>
              <form method="post"
                action="<?= Html::escape($config->url('/reinitialiser-mot-de-passe')) ?>">
                <input type="hidden" name="_csrf" value="<?= Html::escape((string) $csrf) ?>">
                <input type="hidden" name="selector"
                  value="<?= Html::escape((string) ($selector ?? '')) ?>">
                <input type="hidden" name="token"
                  value="<?= Html::escape((string) ($token ?? '')) ?>">
                <div class="mb-3">
                  <label class="form-label fw-semibold" for="new-password">
                    Nouveau mot de passe
                  </label>
                  <input class="form-control form-control-lg" id="new-password"
                    name="password" type="password" autocomplete="new-password"
                    minlength="12" maxlength="4096" required data-auto-focus>
                </div>
                <div class="mb-4">
                  <label class="form-label fw-semibold" for="new-password-confirmation">
                    Confirmation
                  </label>
                  <input class="form-control form-control-lg"
                    id="new-password-confirmation" name="password_confirmation"
                    type="password" autocomplete="new-password"
                    minlength="12" maxlength="4096" required>
                </div>
                <button class="btn btn-primary btn-lg w-100" type="submit">
                  Modifier le mot de passe
                </button>
              </form>
            <?php endif; ?>

            <?php if (!$success): ?>
              <a class="btn btn-link w-100 mt-3"
                href="<?= Html::escape($config->url('/login')) ?>">
                Revenir à la connexion
              </a>
            <?php endif; ?>
            <p class="login-instance mb-0 mt-4">
              Instance
              <strong><?= Html::escape((string) ($ui_context['instance'] ?? '')) ?></strong>
            </p>
          </div>
        </section>
      </div>
    </div>
  </div>
</section>
