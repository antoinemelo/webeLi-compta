<?php
declare(strict_types=1);

use Compta\Core\Support\Html;
?>
<section class="card border-0 shadow-sm">
  <div class="card-body p-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
      <div>
        <h1 class="h3 mb-1">Tableau de bord</h1>
        <p class="text-body-secondary mb-0">
          Choisissez le contexte, puis poursuivez directement une tâche.
        </p>
      </div>
    </div>

    <?php if ($dossiers === []): ?>
      <div class="alert alert-info mb-0" role="status">Aucun dossier accessible.</div>
    <?php else: ?>
      <form class="row g-3 align-items-end"
        method="post" action="<?= Html::escape($config->url('/context/dossier')) ?>">
        <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
        <div class="col-12 col-lg">
          <label class="form-label" for="dossier">Organisation / dossier</label>
          <select class="form-select" id="dossier" name="dossier_compose" required>
            <option value="">Choisir…</option>
            <?php foreach ($dossiers as $dossier): ?>
              <option value="<?= (int) $dossier['organisation_id'] ?>:<?= (int) $dossier['id'] ?>"
                <?= (int) $selected_dossier_id === (int) $dossier['id'] ? 'selected' : '' ?>>
                <?= Html::escape($dossier['organisation_nom'] . ' — ' . $dossier['nom'] . ' [' . $dossier['type'] . ']') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-lg-auto">
          <button class="btn btn-primary w-100" type="submit">Ouvrir</button>
        </div>
      </form>
    <?php endif; ?>

    <?php if ((int) $selected_dossier_id > 0): ?>
      <hr class="my-4">
      <h2 class="h5 mb-3">Actions principales</h2>
      <div class="row g-3 mb-4">
        <div class="col-12 col-md-6 col-xl-3">
          <article class="card dashboard-action">
            <div class="card-body">
              <h3 class="h6">Comptabilité</h3>
              <p class="small text-body-secondary">
                Journalisation, extraits de comptes, grand livre et états.
              </p>
              <a class="btn btn-primary"
                href="<?= Html::escape($config->url('/compta')) ?>">Ouvrir la comptabilité</a>
            </div>
          </article>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
          <article class="card dashboard-action">
            <div class="card-body">
              <h3 class="h6">Plan comptable</h3>
              <p class="small text-body-secondary">
                Structure, comptes, fonctionnement et ouvertures.
              </p>
              <a class="btn btn-primary"
                href="<?= Html::escape($config->url('/app/compta/plan')) ?>">Plan comptable et ouvertures</a>
            </div>
          </article>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
          <article class="card dashboard-action">
            <div class="card-body">
              <h3 class="h6">Facturation</h3>
              <p class="small text-body-secondary">
                Débiteurs, créanciers, documents et paiements.
              </p>
              <a class="btn btn-primary"
                href="<?= Html::escape($config->url('/facturation')) ?>">Débiteurs et créanciers</a>
            </div>
          </article>
        </div>
        <div class="col-12 col-md-6 col-xl-3">
          <article class="card dashboard-action">
            <div class="card-body">
              <h3 class="h6">Salaires</h3>
              <p class="small text-body-secondary">
                Fiches, employés et paiements genevois.
              </p>
              <a class="btn btn-primary"
                href="<?= Html::escape($config->url('/salaires')) ?>">Salaires genevois</a>
            </div>
          </article>
        </div>
      </div>
      <div class="d-flex flex-wrap gap-2" aria-label="Rapports et enseignement">
        <a class="btn btn-outline-primary"
          href="<?= Html::escape($config->url('/app/compta')) ?>">Journal</a>
        <a class="btn btn-outline-primary"
          href="<?= Html::escape($config->url('/app/compta/etats')) ?>">Balance</a>
        <a class="btn btn-outline-primary"
          href="<?= Html::escape($config->url('/compta/bilan')) ?>">Bilan</a>
        <a class="btn btn-outline-primary"
          href="<?= Html::escape($config->url('/app/compta/etats')) ?>">Compte de résultat</a>
        <a class="btn btn-outline-primary"
          href="<?= Html::escape($config->url('/pedagogie')) ?>">Enseignement</a>
      </div>
    <?php endif; ?>
  </div>
</section>
