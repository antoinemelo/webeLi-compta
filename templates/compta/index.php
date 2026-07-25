<?php
declare(strict_types=1);

use Compta\Core\Support\Html;

$money = static fn (int $cents): string => number_format(
    $cents / 100,
    2,
    ',',
    ' '
) . ' CHF';
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
  <div>
    <h1 class="h3 mb-1">Comptabilité</h1>
    <p class="text-body-secondary mb-0">
      <?= Html::escape($exercise['libelle']) ?>,
      du <?= Html::escape($exercise['date_debut']) ?>
      au <?= Html::escape($exercise['date_fin']) ?>
    </p>
  </div>
</div>

<section aria-labelledby="accounting-actions">
  <h2 class="h5 mb-3" id="accounting-actions">Travail comptable</h2>
  <div class="row g-3">
    <?php if ($can_edit): ?>
      <div class="col-12 col-md-6 col-xl-4">
        <article class="card dashboard-action">
          <div class="card-body">
            <h3 class="h6">Journalisation</h3>
            <p class="small text-body-secondary">
              Saisir rapidement un compte au débit, un compte au crédit,
              un libellé et un montant.
            </p>
            <a class="btn btn-primary"
              href="<?= Html::escape($config->url('/compta/saisie')) ?>">Ajouter une écriture</a>
          </div>
        </article>
      </div>
    <?php endif; ?>
    <div class="col-12 col-md-6 col-xl-4">
      <article class="card dashboard-action">
        <div class="card-body">
          <h3 class="h6">Extrait de compte</h3>
          <p class="small text-body-secondary">
            Consulter les mouvements en liste ou sous forme de compte en T.
          </p>
          <a class="btn btn-primary"
            href="<?= Html::escape($config->url('/compta/compte')) ?>">Choisir un compte</a>
        </div>
      </article>
    </div>
    <div class="col-12 col-md-6 col-xl-4">
      <article class="card dashboard-action">
        <div class="card-body">
          <h3 class="h6">Grand livre</h3>
          <p class="small text-body-secondary">
            Retrouver chaque compte avec ses débits, crédits et son solde.
          </p>
          <a class="btn btn-outline-primary"
            href="<?= Html::escape($config->url('/compta/grand-livre')) ?>">Ouvrir le grand livre</a>
        </div>
      </article>
    </div>
    <div class="col-12 col-md-6 col-xl-4">
      <article class="card dashboard-action">
        <div class="card-body">
          <h3 class="h6">Soldes initiaux</h3>
          <p class="small text-body-secondary">
            Préparer puis valider les soldes des comptes de bilan.
          </p>
          <a class="btn btn-outline-primary"
            href="<?= Html::escape($config->url('/compta/plan')
              . '?onglet=ouverture&exercice=' . (int) $exercise['id']) ?>">Gérer les soldes initiaux</a>
        </div>
      </article>
    </div>
    <div class="col-12 col-md-6 col-xl-4">
      <article class="card dashboard-action">
        <div class="card-body">
          <h3 class="h6">Bilan et résultat</h3>
          <p class="small text-body-secondary">
            Lire les états selon la structure du plan du dossier.
          </p>
          <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-primary"
              href="<?= Html::escape($config->url('/compta/bilan')) ?>">Bilan</a>
            <a class="btn btn-outline-primary"
              href="<?= Html::escape($config->url('/compta/resultat')) ?>">Résultat</a>
          </div>
        </div>
      </article>
    </div>
    <?php if ($can_setup): ?>
      <div class="col-12 col-md-6 col-xl-4">
        <article class="card dashboard-action">
          <div class="card-body">
            <h3 class="h6">Configuration</h3>
            <p class="small text-body-secondary">
              Plan, rubriques, sens de fonctionnement et ouvertures du dossier.
            </p>
            <a class="btn btn-outline-primary"
              href="<?= Html::escape($config->url('/compta/plan')) ?>">Configurer la comptabilité</a>
          </div>
        </article>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="card border-0 shadow-sm mt-4" aria-labelledby="recent-entries">
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <h2 class="h5 mb-0" id="recent-entries">Dernières écritures validées</h2>
      <a href="<?= Html::escape($config->url('/compta/journal')) ?>">Voir tout le journal</a>
    </div>
    <?php if ($recent_entries === []): ?>
      <p class="text-body-secondary mt-3 mb-0">Aucune écriture validée pour cet exercice.</p>
    <?php else: ?>
      <div class="table-responsive mt-3">
        <table class="table table-sm align-middle mb-0">
          <caption class="visually-hidden">Dernières écritures validées</caption>
          <thead>
            <tr>
              <th scope="col">Date</th>
              <th scope="col">N°</th>
              <th scope="col">Débit</th>
              <th scope="col">Crédit</th>
              <th scope="col">Libellé</th>
              <th scope="col" class="text-end">Montant</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent_entries as $entry): ?>
              <tr>
                <td><?= Html::escape((string) $entry['date_comptable']) ?></td>
                <td><?= Html::escape((string) $entry['numero']) ?></td>
                <td class="font-monospace"><?= Html::escape((string) $entry['comptes_debit']) ?></td>
                <td class="font-monospace"><?= Html::escape((string) $entry['comptes_credit']) ?></td>
                <td><?= Html::escape((string) $entry['libelle']) ?></td>
                <td class="text-end font-monospace">
                  <?= Html::escape($money((int) $entry['debit_centimes'])) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
