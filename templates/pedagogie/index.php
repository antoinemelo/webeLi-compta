<?php
declare(strict_types=1);

use Compta\Core\Support\Html;

$action = $config->url('/pedagogie/action');
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
  <div>
    <h1 class="h3 mb-1">Enseignement</h1>
    <p class="text-body-secondary mb-0">
      Exercices isolés, collaboration de groupe, progression et validations comptables.
    </p>
  </div>
  <a class="btn btn-outline-secondary" href="<?= Html::escape($config->url('/')) ?>">
    Tableau de bord
  </a>
</div>
<?php if ($success !== ''): ?><div class="alert alert-success" role="status"
  tabindex="-1" data-auto-focus><?= Html::escape($success) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"
  tabindex="-1" data-auto-focus><?= Html::escape($error) ?></div><?php endif; ?>

<nav class="nav nav-tabs mb-3" role="tablist">
  <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#travail"
    type="button">Mon travail</button>
  <?php if ($can_manage): ?>
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#suivi"
      type="button">Suivi formateur</button>
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#configuration"
      type="button">Modèles et groupes</button>
  <?php endif; ?>
</nav>

<div class="tab-content">
  <section class="tab-pane fade show active" id="travail">
    <?php foreach ($assignments as $assignment): ?>
      <article class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="d-flex justify-content-between gap-3">
          <div>
            <h2 class="h5"><?= Html::escape((string) $assignment['modele_titre']) ?></h2>
            <p><?= nl2br(Html::escape((string) $assignment['consignes'])) ?></p>
            <small class="text-body-secondary">
              <?= Html::escape((string) $assignment['dossier_nom']) ?>
              — progression <?= (int) $assignment['etapes_validees'] ?>/<?= (int) $assignment['nombre_etapes'] ?>
            </small>
          </div>
          <a class="btn btn-sm btn-outline-primary align-self-start"
            href="<?= Html::escape($config->url('/pedagogie') . '?assignation=' . (int) $assignment['id']) ?>">
            Ouvrir
          </a>
        </div>
      </div></article>
    <?php endforeach; ?>
    <?php if ($assignments === []): ?>
      <div class="alert alert-secondary" role="status">Aucun exercice actif ne vous est assigné.</div>
    <?php endif; ?>

    <?php if ($selected_assignment > 0): ?>
      <section class="card border-0 shadow-sm"><div class="card-body">
        <h2 class="h5">Étapes</h2>
        <?php foreach ($steps as $step): ?>
          <div class="border rounded p-3 mb-2">
            <div class="d-flex justify-content-between">
              <strong><?= Html::escape((string) $step['titre']) ?></strong>
              <span class="badge text-bg-secondary"><?= Html::escape((string) $step['statut']) ?></span>
            </div>
            <p class="mb-2"><?= nl2br(Html::escape((string) $step['consigne'])) ?></p>
            <div class="d-flex flex-wrap gap-2">
              <form method="post" action="<?= Html::escape($action) ?>" class="d-flex gap-1">
                <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                <input type="hidden" name="action" value="attempt">
                <input type="hidden" name="etape_id" value="<?= (int) $step['id'] ?>">
                <input class="form-control form-control-sm w-auto" name="ecriture_id"
                  type="number" min="1" placeholder="N° écriture"
                  aria-label="Identifiant de l’écriture">
                <button class="btn btn-sm btn-primary" type="submit">Vérifier</button>
              </form>
              <form method="post" action="<?= Html::escape($action) ?>">
                <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                <input type="hidden" name="action" value="hint">
                <input type="hidden" name="etape_id" value="<?= (int) $step['id'] ?>">
                <button class="btn btn-sm btn-outline-secondary" type="submit">Indice suivant</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div></section>
    <?php endif; ?>
  </section>

  <?php if ($can_manage): ?>
    <section class="tab-pane fade" id="suivi">
      <div class="card border-0 shadow-sm"><div class="card-body">
        <h2 class="h5">Progression des copies pédagogiques</h2>
        <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <caption class="visually-hidden">Progression des copies pédagogiques</caption>
          <thead><tr><th>Modèle</th><th>Copie</th><th>Cible</th>
            <th>Progression</th><th>Tentatives</th><th>Contributeurs</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($teacher_rows as $row): ?><tr>
            <td><?= Html::escape((string) $row['titre']) ?></td>
            <td><?= Html::escape((string) $row['dossier_nom']) ?></td>
            <td><?= Html::escape((string) ($row['groupe_nom'] ?: $row['apprenant'])) ?></td>
            <td><?= (int) $row['validees'] ?>/<?= (int) $row['etapes'] ?></td>
            <td><?= (int) $row['tentatives'] ?></td><td><?= (int) $row['contributeurs'] ?></td>
            <td class="d-flex gap-1">
              <form method="post" action="<?= Html::escape($action) ?>">
                <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                <input type="hidden" name="action" value="authorize">
                <input type="hidden" name="assignation_id" value="<?= (int) $row['id'] ?>">
                <button class="btn btn-sm btn-outline-primary" type="submit">Autoriser correction</button>
              </form>
              <?php if ($can_reset): ?><form method="post" action="<?= Html::escape($action) ?>">
                <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="assignation_id" value="<?= (int) $row['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit">Réinitialiser</button>
              </form><?php endif; ?>
            </td>
          </tr><?php endforeach; ?>
          <?php if ($teacher_rows === []): ?><tr><td colspan="7">Aucune assignation.</td></tr><?php endif; ?>
          </tbody>
        </table>
        </div>
      </div></div>
    </section>

    <section class="tab-pane fade" id="configuration">
      <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <h2 class="h5">Nouveau modèle depuis le dossier sélectionné</h2>
        <form method="post" action="<?= Html::escape($action) ?>" class="row g-2">
          <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
          <input type="hidden" name="action" value="model">
          <div class="col-md-4"><label class="form-label" for="model-title">Titre</label>
            <input class="form-control" id="model-title" name="titre" required></div>
          <div class="col-md-8"><label class="form-label" for="model-instructions">Consignes</label>
            <input class="form-control" id="model-instructions" name="consignes" required></div>
          <div class="col-md-6"><label class="form-label" for="model-steps">Étapes JSON</label>
            <textarea class="form-control font-monospace" id="model-steps"
              name="etapes_json" rows="4"
              required>[{"code":"E1","titre":"Saisie","consigne":"Saisir l’écriture","indices":[],"regles":[]}]</textarea></div>
          <div class="col-md-6"><label class="form-label" for="model-solution">Solution JSON — jamais envoyée avant autorisation</label>
            <textarea class="form-control font-monospace" id="model-solution"
              name="solution_json" rows="4">{}</textarea></div>
          <div class="col-md-3"><label class="form-label" for="model-correction">Correction</label>
            <select class="form-select" id="model-correction" name="regle_correction">
              <option value="manuelle">Manuelle</option>
              <option value="apres_tentatives">Après N tentatives</option>
              <option value="date">À une date</option>
            </select></div>
          <div class="col-md-3"><label class="form-label" for="model-correction-value">Valeur</label>
            <input class="form-control" id="model-correction-value"
              name="valeur_correction"></div>
          <div class="col-md-auto align-self-end"><button class="btn btn-primary" type="submit">Publier la version 1</button></div>
        </form>
      </div></div>

      <div class="row g-3">
        <div class="col-lg-5"><div class="card border-0 shadow-sm"><div class="card-body">
          <h2 class="h5">Groupes</h2>
          <form method="post" action="<?= Html::escape($action) ?>" class="d-flex gap-2 mb-3">
            <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
            <input type="hidden" name="action" value="group">
            <input class="form-control" name="nom" placeholder="Nom du groupe"
              aria-label="Nom du groupe" required>
            <button class="btn btn-outline-primary" type="submit">Créer</button>
          </form>
          <?php foreach ($groups as $group): ?>
            <div><?= Html::escape((string) $group['nom']) ?> — <?= (int) $group['membres'] ?> membre(s)</div>
          <?php endforeach; ?>
        </div></div></div>
        <div class="col-lg-7"><div class="card border-0 shadow-sm"><div class="card-body">
          <h2 class="h5">Assigner une copie isolée</h2>
          <form method="post" action="<?= Html::escape($action) ?>" class="row g-2 align-items-end">
            <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
            <input type="hidden" name="action" value="assign_user">
            <div class="col"><label class="form-label" for="assignment-version">Version</label><select
              class="form-select" id="assignment-version" name="version_id">
              <?php foreach ($models as $model): ?><option value="<?= (int) $model['version_id'] ?>"><?= Html::escape((string) $model['titre']) ?></option><?php endforeach; ?>
            </select></div>
            <div class="col"><label class="form-label" for="assignment-user">ID apprenant</label><input
              class="form-control" id="assignment-user" name="utilisateur_id"
              type="number" required></div>
            <div class="col"><label class="form-label" for="assignment-name">Nom de copie</label><input
              class="form-control" id="assignment-name" name="nom" required></div>
            <div class="col-auto"><button class="btn btn-primary" type="submit">Assigner</button></div>
          </form>
        </div></div></div>
      </div>
    </section>
  <?php endif; ?>
</div>
