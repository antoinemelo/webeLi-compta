<?php
declare(strict_types=1);

use Compta\Core\Support\Html;

$hasError = isset($error) && trim((string) $error) !== '';
$hasChallenge = isset($challenge) && is_array($challenge);
$hasIdentification = !$hasChallenge
  && isset($identification)
  && is_array($identification);
?>
<section class="login-stage" aria-labelledby="login-presentation-title">
  <div class="container-fluid login-stage-inner">
    <div class="row g-5 align-items-center">
      <div class="col-lg-7 col-xl-8">
        <div class="login-presentation">
          <p class="login-eyebrow mb-3">Comptabilité suisse</p>
          <h1 class="login-display" id="login-presentation-title">
            Espace pour gérer ses finances.
          </h1>
          <p class="login-lead">
            Comptabilité, facturation, liquidités et salaires partagent le même
            contexte, les mêmes contrôles, avec une piste d’audit cohérente et opensource [<a href="https://github.com/antoinemelo/webeLi-compta" target="_blank" rel="noopener noreferrer">repository git</a>].
          </p>
          <ul class="login-features" aria-label="Fonctionnalités principales">
            <li>Travail isolé par organisation et par dossier</li>
            <li>Journal, états financiers et clôtures reliés</li>
            <li>Accès nominatifs et opérations sensibles tracées</li>
          </ul>
          <p class="login-security-note">
            <span aria-hidden="true">●</span>
            Connexion protégée et session renouvelée à l’authentification
          </p>
        </div>
      </div>
      <div class="col-lg-5 col-xl-4">
        <section class="card login-card shadow-lg" id="login-form"
          aria-labelledby="login-title">
          <div class="card-body p-4 p-sm-5">
            <p class="text-uppercase small fw-bold text-secondary mb-2">
              Espace sécurisé
            </p>
            <h2 class="h2 mb-2" id="login-title">
              <?= $hasChallenge ? 'Vérification' : ($hasIdentification ? 'Mot de passe' : 'Connexion') ?>
            </h2>
            <p class="text-body-secondary mb-4">
              <?php if ($hasChallenge && ($challenge['mode'] ?? '') === 'email'): ?>
                Saisissez le code à six chiffres envoyé à
                <strong><?= Html::escape((string) ($challenge['email_hint'] ?? '')) ?></strong>.
              <?php elseif ($hasChallenge): ?>
                Saisissez le code de votre application d’authentification
                ou un code de récupération.
              <?php elseif ($hasIdentification): ?>
                Saisissez le mot de passe associé à ce compte.
              <?php else: ?>
                Commencez par saisir l’adresse e-mail associée à votre compte.
              <?php endif; ?>
            </p>
            <?php if ($hasError): ?>
              <div class="alert alert-danger" role="alert" tabindex="-1"
                data-auto-focus><?= Html::escape((string) $error) ?></div>
            <?php endif; ?>
            <?php if ($hasChallenge): ?>
            <form method="post" action="<?= Html::escape($config->url('/login')) ?>">
              <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
              <input type="hidden" name="stage" value="mfa">
              <div class="mb-4">
                <label class="form-label fw-semibold" for="code">
                  Code de sécurité
                </label>
                <input class="form-control form-control-lg login-security-code"
                  id="code" name="code" type="text"
                  autocomplete="one-time-code"
                  inputmode="<?= ($challenge['mode'] ?? '') === 'email' ? 'numeric' : 'text' ?>"
                  autocapitalize="characters" spellcheck="false"
                  maxlength="16" required data-auto-focus>
              </div>
              <button class="btn btn-primary btn-lg w-100" type="submit">
                Vérifier et se connecter
              </button>
            </form>
            <form class="mt-3" method="post"
              action="<?= Html::escape($config->url('/login')) ?>">
              <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
              <input type="hidden" name="stage" value="cancel">
              <button class="btn btn-link w-100" type="submit">
                Revenir à l’identification
              </button>
            </form>
            <?php elseif ($hasIdentification): ?>
            <form method="post" action="<?= Html::escape($config->url('/login')) ?>">
              <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
              <input type="hidden" name="stage" value="password">
              <div class="login-identity mb-4">
                <span class="login-identity-label">Compte</span>
                <strong><?= Html::escape((string) ($identification['email'] ?? '')) ?></strong>
              </div>
              <input class="visually-hidden" name="username" type="email"
                value="<?= Html::escape((string) ($identification['email'] ?? '')) ?>"
                autocomplete="username" tabindex="-1" aria-hidden="true">
              <div class="mb-4">
                <label class="form-label fw-semibold" for="password">Mot de passe</label>
                <div class="login-password-field">
                  <input class="form-control form-control-lg" id="password"
                    name="password" type="password" autocomplete="current-password"
                    maxlength="4096" required>
                  <button class="login-password-toggle" type="button"
                    data-password-toggle="password"
                    aria-label="Afficher le mot de passe" aria-pressed="false">
                    <svg class="login-password-icon" viewBox="0 0 16 16"
                      aria-hidden="true" focusable="false">
                      <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8ZM1.173 8A13.133 13.133 0 0 1 8 3.5 13.133 13.133 0 0 1 14.827 8 13.133 13.133 0 0 1 8 12.5 13.133 13.133 0 0 1 1.173 8Z"/>
                      <path d="M8 5.5A2.5 2.5 0 1 1 8 10.5 2.5 2.5 0 0 1 8 5.5ZM8 6.5A1.5 1.5 0 1 0 8 9.5 1.5 1.5 0 0 0 8 6.5Z"/>
                    </svg>
                  </button>
                </div>
              </div>
              <button class="btn btn-primary btn-lg w-100" type="submit">
                Se connecter
              </button>
            </form>
            <form class="mt-3" method="post"
              action="<?= Html::escape($config->url('/login')) ?>">
              <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
              <input type="hidden" name="stage" value="cancel">
              <button class="btn btn-link w-100" type="submit">
                Utiliser une autre adresse e-mail
              </button>
            </form>
            <?php else: ?>
            <form method="post" action="<?= Html::escape($config->url('/login')) ?>">
              <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
              <input type="hidden" name="stage" value="identify">
              <div class="mb-4">
                <label class="form-label fw-semibold" for="email">Adresse e-mail</label>
                <input class="form-control form-control-lg" id="email" name="email"
                  type="email" value="<?= Html::escape((string) ($email ?? '')) ?>"
                  autocomplete="username" autocapitalize="none" spellcheck="false"
                  inputmode="email" maxlength="254" required
                  <?= $hasError ? '' : 'data-auto-focus' ?>>
              </div>
              <button class="btn btn-primary btn-lg w-100" type="submit">
                Continuer
              </button>
            </form>
            <?php endif; ?>
            <?php if (!$hasChallenge): ?>
              <a class="btn btn-link w-100 mt-2"
                href="<?= Html::escape($config->url('/mot-de-passe-oublie')) ?>">
                Mot de passe oublié ?
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
