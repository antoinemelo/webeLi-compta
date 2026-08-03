<?php
declare(strict_types=1);

use Compta\Core\Support\Html;

$submitted = ($submitted ?? false) === true;
$hasError = isset($error) && trim((string) $error) !== '';
?>
<section class="login-stage" aria-labelledby="password-reset-presentation-title">
  <div class="container-fluid login-stage-inner">
    <div class="row g-5 align-items-center">
      <div class="col-lg-7 col-xl-8">
        <div class="login-presentation">
          <p class="login-eyebrow mb-3">Sécurité du compte</p>
          <h1 class="login-display" id="password-reset-presentation-title">
            Retrouver l’accès sans affaiblir la protection.
          </h1>
          <p class="login-lead">
            Le lien envoyé est aléatoire, utilisable une seule fois et valable
            quinze minutes. Le second facteur éventuellement configuré reste actif.
          </p>
          <ul class="login-features" aria-label="Garanties de récupération">
            <li>Réponse identique pour toutes les adresses</li>
            <li>Anciennes sessions révoquées après la modification</li>
            <li>Demande et utilisation consignées dans l’audit</li>
          </ul>
        </div>
      </div>
      <div class="col-lg-5 col-xl-4">
        <section class="card login-card shadow-lg" id="password-reset-form"
          aria-labelledby="password-reset-title">
          <div class="card-body p-4 p-sm-5">
            <p class="text-uppercase small fw-bold text-secondary mb-2">
              Récupération sécurisée
            </p>
            <h2 class="h2 mb-2" id="password-reset-title">
              <?= $submitted ? 'Consultez votre messagerie' : 'Mot de passe oublié' ?>
            </h2>

            <?php if ($submitted): ?>
              <div class="alert alert-success mt-4" role="status">
                Si cette adresse correspond à un compte actif et que la messagerie
                est configurée, un lien de réinitialisation vient d’être envoyé.
              </div>
              <p class="text-body-secondary">
                Le message peut prendre quelques minutes. Pensez également à
                consulter le dossier des courriers indésirables.
              </p>
            <?php else: ?>
              <p class="text-body-secondary mb-4">
                Saisissez l’adresse associée au compte. Aucune information sur
                l’existence du compte ne sera affichée ici.
              </p>
              <?php if ($hasError): ?>
                <div class="alert alert-danger" role="alert">
                  <?= Html::escape((string) $error) ?>
                </div>
              <?php endif; ?>
              <form method="post"
                action="<?= Html::escape($config->url('/mot-de-passe-oublie')) ?>">
                <input type="hidden" name="_csrf" value="<?= Html::escape((string) $csrf) ?>">
                <div class="mb-4">
                  <label class="form-label fw-semibold" for="reset-email">
                    Adresse e-mail
                  </label>
                  <input class="form-control form-control-lg" id="reset-email"
                    name="email" type="email"
                    value="<?= Html::escape((string) ($email ?? '')) ?>"
                    autocomplete="username" autocapitalize="none" spellcheck="false"
                    inputmode="email" maxlength="254" required data-auto-focus>
                </div>
                <button class="btn btn-primary btn-lg w-100" type="submit">
                  Envoyer le lien sécurisé
                </button>
              </form>
            <?php endif; ?>

            <a class="btn btn-link w-100 mt-3"
              href="<?= Html::escape($config->url('/login')) ?>">
              Revenir à la connexion
            </a>
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
