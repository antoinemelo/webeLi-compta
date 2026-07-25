<?php
declare(strict_types=1);

use Compta\Core\Support\Html;

$typeLabels = [];
foreach ($account_types as $accountType) {
    $typeLabels[$accountType['code']] = $accountType['libelle'];
}
$levelLabels = [
    'classe' => ['Classes', '1 chiffre'],
    'groupe_principal' => ['Groupes principaux', '2 chiffres'],
    'groupe' => ['Groupes', '3 chiffres'],
    'sous_groupe' => ['Sous-groupes', 'sans numéro'],
];
$parentLevels = [
    'classe' => null,
    'groupe_principal' => 'classe',
    'groupe' => 'groupe_principal',
    'sous_groupe' => 'groupe',
];
$senseLabels = [
    'automatique' => 'Automatique',
    'debit' => '++/--',
    'credit' => '--/++',
];
$formatCents = static fn (int $cents): string => $cents === 0
    ? ''
    : number_format($cents / 100, 2, '.', "'");
$openingEditable = in_array($opening['status'], ['absent', 'brouillon'], true);
$isActiveTab = static fn (string $tab): string => $active_tab === $tab ? 'active' : '';
$isActivePanel = static fn (string $tab): string => $active_tab === $tab ? 'show active' : '';
$rubricsByLevel = [];
foreach ($structure_levels as $level) {
    $rubricsByLevel[$level] = array_values(array_filter(
        $rubrics,
        static fn (array $rubric): bool => $rubric['niveau_structure'] === $level
    ));
}
$accountRubrics = array_values(array_filter(
    $rubrics,
    static fn (array $rubric): bool => in_array(
        $rubric['niveau_structure'],
        ['groupe_principal', 'groupe'],
        true
    )
));
usort(
    $accountRubrics,
    static fn (array $left, array $right): int => strcmp($left['code'], $right['code'])
);
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
  <div>
    <h1 class="h2 mb-1">Plan comptable</h1>
    <p class="text-body-secondary mb-0">
      Structure de bouclement, comptes et soldes d’ouverture —
      <?= Html::escape($exercise['libelle']) ?>
    </p>
  </div>
  <a class="btn btn-outline-secondary no-print"
    href="<?= Html::escape($config->url('/')) ?>">Retour au tableau de bord</a>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success" role="status" tabindex="-1"
    data-auto-focus><?= Html::escape($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-danger" role="alert" tabindex="-1"
    data-auto-focus><?= Html::escape($error) ?></div>
<?php endif; ?>
<?php if (!$can_setup): ?>
  <div class="alert alert-info" role="status">
    Le plan est en lecture seule : le droit « Configuration comptable » est
    nécessaire pour le modifier.
  </div>
<?php endif; ?>

<ul class="nav nav-tabs plan-main-tabs no-print" id="plan-tabs" role="tablist">
  <?php foreach ([
    'types' => '1. Types de comptes',
    'rubriques' => '2. Rubriques',
    'sens' => '3. Moins / plus standards',
    'comptes' => '4. Comptes',
    'ouverture' => '5. Ouverture',
  ] as $tab => $label): ?>
    <li class="nav-item" role="presentation">
      <button class="nav-link <?= $isActiveTab($tab) ?>" id="tab-<?= $tab ?>"
        data-bs-toggle="tab" data-bs-target="#panel-<?= $tab ?>" type="button"
        role="tab" aria-controls="panel-<?= $tab ?>"
        aria-selected="<?= $active_tab === $tab ? 'true' : 'false' ?>">
        <?= Html::escape($label) ?>
      </button>
    </li>
  <?php endforeach; ?>
</ul>

<div class="tab-content plan-tab-content">
  <section class="tab-pane fade <?= $isActivePanel('types') ?>" id="panel-types"
    role="tabpanel" aria-labelledby="tab-types" tabindex="0">
    <div class="card border-top-0 shadow-sm">
      <div class="card-body p-3 p-md-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
          <h2 class="h4 mb-1">Types de comptes</h2>
          <?php if ($can_setup): ?>
            <button class="btn btn-sm btn-outline-primary" type="submit"
              form="account-types-bulk" data-panel-submit disabled>Modifier</button>
          <?php endif; ?>
        </div>
        <p class="text-body-secondary">
          Le type est attribué aux classes. Les rubriques inférieures et les
          comptes héritent automatiquement du type de leur parent.
        </p>
        <?php if ($can_setup): ?>
          <form id="account-types-bulk" method="post" data-dirty-panel
            action="<?= Html::escape($config->url('/compta/plan/type')) ?>">
            <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
            <input type="hidden" name="exercice_id" value="<?= (int) $exercise['id'] ?>">
            <input type="hidden" name="onglet" value="types">
          </form>
        <?php endif; ?>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle">
            <thead class="table-light">
              <tr><th>Code</th><th>Libellé</th></tr>
            </thead>
            <tbody>
              <?php foreach ($account_types as $accountType): ?>
                <tr>
                  <td class="font-monospace"><?= Html::escape($accountType['code']) ?></td>
                  <td>
                    <input type="hidden" form="account-types-bulk"
                      name="types[<?= (int) $accountType['id'] ?>][version]"
                      value="<?= (int) $accountType['version'] ?>">
                    <input class="form-control form-control-sm" form="account-types-bulk"
                      name="types[<?= (int) $accountType['id'] ?>][libelle]"
                      aria-label="Libellé du type <?= Html::escape($accountType['code']) ?>"
                      value="<?= Html::escape($accountType['libelle']) ?>"
                      <?= !$can_setup ? 'disabled' : '' ?>>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <section class="tab-pane fade <?= $isActivePanel('sens') ?>" id="panel-sens"
    role="tabpanel" aria-labelledby="tab-sens" tabindex="0">
    <div class="card border-top-0 shadow-sm">
      <div class="card-body p-3 p-md-4">
        <h2 class="h4">Comptes fonctionnant par défaut en --/++</h2>
        <p class="text-body-secondary">
          Le crédit augmente les comptes correspondant à ces préfixes et le
          débit les diminue. Tous les autres comptes automatiques fonctionnent
          en plus / moins.
        </p>
        <form class="row g-3 align-items-end" method="post"
          action="<?= Html::escape($config->url('/compta/plan/sens')) ?>">
          <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
          <input type="hidden" name="exercice_id" value="<?= (int) $exercise['id'] ?>">
          <input type="hidden" name="onglet" value="sens">
          <div class="col-12 col-lg">
            <label class="form-label" for="prefixes_sens">Préfixes séparés par une virgule</label>
            <input class="form-control font-monospace" id="prefixes_sens"
              name="prefixes" value="<?= Html::escape(implode(', ', $prefixes)) ?>"
              placeholder="2, 3" <?= !$can_setup ? 'disabled' : '' ?>>
            <div class="form-text">
              Exemple suisse classique : <strong>2, 3</strong>. Les débits et
              crédits déjà comptabilisés ne sont jamais modifiés.
            </div>
          </div>
          <?php if ($can_setup): ?>
            <div class="col-12 col-lg-auto">
              <button class="btn btn-primary w-100" type="submit">Enregistrer</button>
            </div>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </section>

  <section class="tab-pane fade <?= $isActivePanel('rubriques') ?>" id="panel-rubriques"
    role="tabpanel" aria-labelledby="tab-rubriques" tabindex="0">
    <div class="card border-top-0 shadow-sm">
      <div class="card-body p-3 p-md-4">
        <h2 class="h4 mb-1">Rubriques et structure de bouclement</h2>
        <p class="text-body-secondary">
          Les quatre niveaux reprennent l’ordre des comptes annuels suisses.
          Une rubrique dépend du niveau immédiatement supérieur.
        </p>

        <ul class="nav nav-pills plan-level-tabs gap-1 mb-3" role="tablist">
          <?php foreach ($structure_levels as $level): ?>
            <li class="nav-item" role="presentation">
              <button class="nav-link <?= $active_level === $level ? 'active' : '' ?>"
                id="level-tab-<?= $level ?>" data-bs-toggle="pill"
                data-bs-target="#level-panel-<?= $level ?>" type="button" role="tab">
                <?= Html::escape($levelLabels[$level][0]) ?>
                <span class="badge text-bg-light ms-1"><?= count($rubricsByLevel[$level]) ?></span>
              </button>
            </li>
          <?php endforeach; ?>
        </ul>

        <div class="tab-content">
          <?php foreach ($structure_levels as $level): ?>
            <?php
              $levelRubrics = $rubricsByLevel[$level];
              $parentLevel = $parentLevels[$level];
              $parents = $parentLevel === null ? [] : $rubricsByLevel[$parentLevel];
              $bulkFormId = 'rubric-bulk-' . $level;
              $nextOrder = $levelRubrics === []
                  ? 10
                  : max(array_map(static fn (array $item): int => (int) $item['ordre'], $levelRubrics)) + 10;
            ?>
            <div class="tab-pane fade <?= $active_level === $level ? 'show active' : '' ?>"
              id="level-panel-<?= $level ?>" role="tabpanel"
              aria-labelledby="level-tab-<?= $level ?>" tabindex="0">
              <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                  <strong><?= Html::escape($levelLabels[$level][0]) ?></strong>
                  <span class="text-body-secondary">— <?= Html::escape($levelLabels[$level][1]) ?></span>
                </div>
                <?php if ($can_setup): ?>
                  <form id="<?= $bulkFormId ?>" method="post" data-dirty-panel
                    action="<?= Html::escape($config->url('/compta/plan/rubrique')) ?>">
                    <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                    <input type="hidden" name="exercice_id" value="<?= (int) $exercise['id'] ?>">
                    <input type="hidden" name="onglet" value="rubriques">
                    <input type="hidden" name="niveau" value="<?= Html::escape($level) ?>">
                    <input type="hidden" name="niveau_structure" value="<?= Html::escape($level) ?>">
                    <input type="hidden" name="action" value="bulk_save">
                    <input type="hidden" name="ordre_liste"
                      value="<?= Html::escape(implode(',', array_column($levelRubrics, 'id'))) ?>">
                    <button class="btn btn-sm btn-outline-primary" type="submit" disabled
                      data-panel-submit>Modifier</button>
                  </form>
                <?php endif; ?>
              </div>

              <?php if ($can_setup): ?>
                <form class="row g-2 align-items-end compact-create-form mb-3" method="post"
                  data-external-action
                  action="<?= Html::escape($config->url('/compta/plan/rubrique')) ?>">
                  <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                  <input type="hidden" name="exercice_id" value="<?= (int) $exercise['id'] ?>">
                  <input type="hidden" name="onglet" value="rubriques">
                  <input type="hidden" name="niveau" value="<?= Html::escape($level) ?>">
                  <input type="hidden" name="niveau_structure" value="<?= Html::escape($level) ?>">
                  <?php if ($level !== 'sous_groupe'): ?>
                    <div class="col-4 col-md-2">
                      <label class="form-label" for="new-code-<?= $level ?>">Compte</label>
                      <input class="form-control form-control-sm font-monospace"
                        id="new-code-<?= $level ?>" name="code" inputmode="numeric"
                        maxlength="<?= $level === 'classe' ? 1 : ($level === 'groupe_principal' ? 2 : 3) ?>"
                        required>
                    </div>
                  <?php else: ?>
                    <input type="hidden" name="code" value="">
                  <?php endif; ?>
                  <div class="col-8 col-md">
                    <label class="form-label" for="new-label-<?= $level ?>">Libellé</label>
                    <input class="form-control form-control-sm" id="new-label-<?= $level ?>"
                      name="libelle" required>
                  </div>
                  <?php if ($parentLevel !== null): ?>
                    <div class="col-12 col-md-3">
                      <label class="form-label" for="new-parent-<?= $level ?>">Parent</label>
                      <select class="form-select form-select-sm" id="new-parent-<?= $level ?>"
                        name="parent_id" required>
                        <option value="">Sélectionner…</option>
                        <?php foreach ($parents as $parent): ?>
                          <option value="<?= (int) $parent['id'] ?>">
                            <?= Html::escape($level === 'groupe'
                              ? $parent['libelle']
                              : trim($parent['code'] . ' ' . $parent['libelle'])) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  <?php endif; ?>
                  <?php if ($level === 'classe'): ?>
                    <div class="col-8 col-md-2">
                      <label class="form-label" for="new-type-<?= $level ?>">Type</label>
                      <select class="form-select form-select-sm" id="new-type-<?= $level ?>"
                        name="type" required>
                        <?php foreach ($types as $type): ?>
                          <option value="<?= Html::escape($type) ?>">
                            <?= Html::escape($typeLabels[$type] ?? $type) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                  <?php endif; ?>
                  <input type="hidden" name="ordre" value="<?= $nextOrder ?>">
                  <div class="col-4 col-md-auto">
                    <button class="btn btn-sm btn-primary w-100" type="submit">Ajouter</button>
                  </div>
                </form>
              <?php endif; ?>

              <div class="table-responsive">
                <table class="table table-sm table-hover align-middle rubric-table">
                  <thead class="table-light">
                    <tr>
                      <?php if ($can_setup): ?><th class="drag-column" scope="col"><span class="visually-hidden">Ordre</span></th><?php endif; ?>
                      <?php if ($level !== 'sous_groupe'): ?><th scope="col">Compte</th><?php endif; ?>
                      <th scope="col">Libellé</th>
                      <?php if ($parentLevel !== null): ?><th scope="col">Parent</th><?php endif; ?>
                      <?php if ($level === 'classe'): ?><th scope="col">Type</th><?php endif; ?>
                      <?php if ($can_setup): ?><th scope="col">Actions</th><?php endif; ?>
                    </tr>
                  </thead>
                  <tbody data-sortable
                    data-order-form="<?= $can_setup ? $bulkFormId : '' ?>">
                    <?php foreach ($levelRubrics as $rubric): ?>
                      <?php
                        $rubricId = (int) $rubric['id'];
                        $fieldPrefix = 'rubriques[' . $rubricId . ']';
                        $deleteFormId = 'rubric-delete-' . $rubricId;
                      ?>
                      <tr data-item-id="<?= $rubricId ?>">
                        <?php if ($can_setup): ?>
                          <td class="drag-column"><button class="drag-handle" type="button"
                            draggable="true" title="Déplacer par glisser-déposer"
                            aria-label="Déplacer, flèches haut et bas"
                            aria-keyshortcuts="ArrowUp ArrowDown">⋮</button>
                            <input type="hidden" form="<?= $bulkFormId ?>"
                              name="<?= $fieldPrefix ?>[version]"
                              value="<?= (int) $rubric['version'] ?>">
                            <input type="hidden" form="<?= $bulkFormId ?>"
                              name="<?= $fieldPrefix ?>[ordre]"
                              value="<?= (int) $rubric['ordre'] ?>">
                          </td>
                        <?php endif; ?>
                        <?php if ($level !== 'sous_groupe'): ?>
                          <td>
                            <input class="form-control form-control-sm font-monospace rubric-code"
                              form="<?= $bulkFormId ?>" name="<?= $fieldPrefix ?>[code]"
                              aria-label="Compte de la rubrique <?= Html::escape($rubric['libelle']) ?>"
                              value="<?= Html::escape($rubric['code']) ?>"
                              <?= !$can_setup ? 'disabled' : '' ?>>
                          </td>
                        <?php else: ?>
                          <td>
                            <input type="hidden" form="<?= $bulkFormId ?>"
                              name="<?= $fieldPrefix ?>[code]" value="">
                            <input class="form-control form-control-sm" form="<?= $bulkFormId ?>"
                              name="<?= $fieldPrefix ?>[libelle]"
                              aria-label="Libellé du sous-groupe"
                              value="<?= Html::escape($rubric['libelle']) ?>"
                              <?= !$can_setup ? 'disabled' : '' ?>>
                          </td>
                        <?php endif; ?>
                        <?php if ($level !== 'sous_groupe'): ?>
                          <td>
                            <input class="form-control form-control-sm" form="<?= $bulkFormId ?>"
                              name="<?= $fieldPrefix ?>[libelle]"
                              aria-label="Libellé de la rubrique <?= Html::escape($rubric['code']) ?>"
                              value="<?= Html::escape($rubric['libelle']) ?>"
                              <?= !$can_setup ? 'disabled' : '' ?>>
                          </td>
                        <?php endif; ?>
                        <?php if ($parentLevel !== null): ?>
                          <td>
                            <select class="form-select form-select-sm" form="<?= $bulkFormId ?>"
                              name="<?= $fieldPrefix ?>[parent_id]"
                              aria-label="Parent de <?= Html::escape($rubric['libelle']) ?>"
                              <?= !$can_setup ? 'disabled' : '' ?>>
                              <?php foreach ($parents as $parent): ?>
                                <option value="<?= (int) $parent['id'] ?>"
                                  <?= (int) $rubric['parent_id'] === (int) $parent['id'] ? 'selected' : '' ?>>
                                  <?= Html::escape($level === 'groupe'
                                    ? $parent['libelle']
                                    : trim($parent['code'] . ' ' . $parent['libelle'])) ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </td>
                        <?php endif; ?>
                        <?php if ($level === 'classe'): ?>
                          <td>
                            <select class="form-select form-select-sm" form="<?= $bulkFormId ?>"
                              name="<?= $fieldPrefix ?>[type]"
                              aria-label="Type de <?= Html::escape($rubric['libelle']) ?>"
                              <?= !$can_setup ? 'disabled' : '' ?>>
                              <?php foreach ($types as $type): ?>
                                <option value="<?= Html::escape($type) ?>"
                                  <?= $rubric['type'] === $type ? 'selected' : '' ?>>
                                  <?= Html::escape($typeLabels[$type] ?? $type) ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </td>
                        <?php endif; ?>
                        <?php if ($can_setup): ?>
                          <td class="text-nowrap">
                            <form id="<?= $deleteFormId ?>" method="post" data-external-action
                              action="<?= Html::escape($config->url('/compta/plan/rubrique')) ?>">
                              <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                              <input type="hidden" name="id" value="<?= $rubricId ?>">
                              <input type="hidden" name="exercice_id" value="<?= (int) $exercise['id'] ?>">
                              <input type="hidden" name="onglet" value="rubriques">
                              <input type="hidden" name="niveau" value="<?= Html::escape($level) ?>">
                              <input type="hidden" name="niveau_structure" value="<?= Html::escape($level) ?>">
                              <input type="hidden" name="action" value="delete">
                            </form>
                            <button class="btn btn-sm btn-outline-danger" type="submit"
                              form="<?= $deleteFormId ?>">Retirer</button>
                          </td>
                        <?php endif; ?>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <section class="tab-pane fade <?= $isActivePanel('comptes') ?>" id="panel-comptes"
    role="tabpanel" aria-labelledby="tab-comptes" tabindex="0">
    <div class="card border-top-0 shadow-sm">
      <div class="card-body p-3 p-md-4">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
          <h2 class="h4 mb-1">Comptes</h2>
          <?php if ($can_setup): ?>
            <button class="btn btn-sm btn-outline-primary" type="submit"
              form="accounts-bulk" data-panel-submit disabled>Modifier</button>
          <?php endif; ?>
        </div>
        <p class="text-body-secondary">
          Chaque compte à quatre chiffres est rattaché à sa rubrique
          structurelle réelle la plus proche. Son type est automatiquement
          hérité de ce parent.
        </p>
        <?php if ($can_setup): ?>
          <form id="accounts-bulk" method="post" data-dirty-panel
            action="<?= Html::escape($config->url('/compta/plan/compte')) ?>">
            <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
            <input type="hidden" name="exercice_id" value="<?= (int) $exercise['id'] ?>">
            <input type="hidden" name="onglet" value="comptes">
            <input type="hidden" name="action" value="bulk_save">
            <input type="hidden" name="ordre_liste"
              value="<?= Html::escape(implode(',', array_column($accounts, 'id'))) ?>">
          </form>
          <form class="row g-2 align-items-end compact-create-form mb-3" method="post"
            data-external-action
            action="<?= Html::escape($config->url('/compta/plan/compte')) ?>">
            <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
            <input type="hidden" name="exercice_id" value="<?= (int) $exercise['id'] ?>">
            <input type="hidden" name="onglet" value="comptes">
            <div class="col-4 col-md-1">
              <label class="form-label" for="new-account-number">Compte</label>
              <input class="form-control form-control-sm font-monospace"
                id="new-account-number" name="numero" inputmode="numeric"
                minlength="4" maxlength="4" required>
            </div>
            <div class="col-8 col-md">
              <label class="form-label" for="new-account-label">Libellé</label>
              <input class="form-control form-control-sm" id="new-account-label"
                name="libelle" required>
            </div>
            <div class="col-12 col-md-3">
              <label class="form-label" for="new-account-rubric">Parent</label>
              <select class="form-select form-select-sm" id="new-account-rubric"
                name="rubrique_id" required>
                <option value="">Sélectionner…</option>
                <?php foreach ($accountRubrics as $rubric): ?>
                  <option value="<?= (int) $rubric['id'] ?>">
                    <?= Html::escape(trim($rubric['code'] . ' ' . $rubric['libelle'])) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label" for="new-account-sense">Fonctionnement</label>
              <select class="form-select form-select-sm" id="new-account-sense" name="sens_mode">
                <?php foreach ($senseLabels as $mode => $label): ?>
                  <option value="<?= Html::escape($mode) ?>"><?= Html::escape($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-auto">
              <button class="btn btn-sm btn-primary w-100" type="submit">Ajouter</button>
            </div>
          </form>
        <?php endif; ?>

        <div class="table-responsive plan-accounts">
          <table class="table table-sm table-hover align-middle">
            <thead class="table-light">
              <tr>
                <?php if ($can_setup): ?><th class="drag-column"><span class="visually-hidden">Ordre</span></th><?php endif; ?>
                <th>Compte</th><th>Libellé</th><th>Parent</th>
                <th>Fonctionnement</th>
                <?php if ($can_setup): ?><th>Action</th><?php endif; ?>
              </tr>
            </thead>
            <tbody data-sortable data-order-form="<?= $can_setup ? 'accounts-bulk' : '' ?>">
              <?php foreach ($accounts as $account): ?>
                <?php
                  $accountId = (int) $account['id'];
                  $fieldPrefix = 'comptes[' . $accountId . ']';
                  $deleteFormId = 'account-delete-' . $accountId;
                ?>
                <tr data-item-id="<?= $accountId ?>"
                  class="<?= (int) $account['actif'] !== 1 ? 'table-secondary' : '' ?>">
                  <?php if ($can_setup): ?>
                    <td class="drag-column">
                      <button class="drag-handle" type="button" draggable="true"
                        title="Déplacer par glisser-déposer"
                        aria-label="Déplacer, flèches haut et bas"
                        aria-keyshortcuts="ArrowUp ArrowDown">⋮</button>
                      <input type="hidden" form="accounts-bulk"
                        name="<?= $fieldPrefix ?>[version]"
                        value="<?= (int) $account['version'] ?>">
                    </td>
                  <?php endif; ?>
                  <td>
                    <input class="form-control form-control-sm font-monospace account-number"
                      form="accounts-bulk" name="<?= $fieldPrefix ?>[numero]"
                      aria-label="Numéro du compte <?= Html::escape($account['numero']) ?>"
                      value="<?= Html::escape($account['numero']) ?>"
                      <?= !$can_setup ? 'disabled' : '' ?>>
                  </td>
                  <td>
                    <input class="form-control form-control-sm account-label"
                      form="accounts-bulk" name="<?= $fieldPrefix ?>[libelle]"
                      aria-label="Libellé du compte <?= Html::escape($account['numero']) ?>"
                      value="<?= Html::escape($account['libelle']) ?>"
                      <?= !$can_setup ? 'disabled' : '' ?>>
                    <?php if ((int) $account['actif'] !== 1): ?>
                      <span class="badge text-bg-secondary mt-1">Inactif</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <select class="form-select form-select-sm" form="accounts-bulk"
                      name="<?= $fieldPrefix ?>[rubrique_id]"
                      aria-label="Parent du compte <?= Html::escape($account['numero']) ?>"
                      <?= !$can_setup ? 'disabled' : '' ?>>
                      <?php foreach ($accountRubrics as $rubric): ?>
                        <option value="<?= (int) $rubric['id'] ?>"
                          <?= (int) $account['rubrique_id'] === (int) $rubric['id'] ? 'selected' : '' ?>>
                          <?= Html::escape(trim($rubric['code'] . ' ' . $rubric['libelle'])) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td>
                    <select class="form-select form-select-sm" form="accounts-bulk"
                      name="<?= $fieldPrefix ?>[sens_mode]"
                      aria-label="Fonctionnement du compte <?= Html::escape($account['numero']) ?>"
                      <?= !$can_setup ? 'disabled' : '' ?>>
                      <?php foreach ($senseLabels as $mode => $label): ?>
                        <option value="<?= Html::escape($mode) ?>"
                          <?= $account['sens_mode'] === $mode ? 'selected' : '' ?>>
                          <?= Html::escape($label) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <?php if ($can_setup): ?>
                    <td class="text-nowrap">
                      <form id="<?= $deleteFormId ?>" method="post" data-external-action
                        action="<?= Html::escape($config->url('/compta/plan/compte')) ?>">
                        <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                        <input type="hidden" name="id" value="<?= $accountId ?>">
                        <input type="hidden" name="exercice_id" value="<?= (int) $exercise['id'] ?>">
                        <input type="hidden" name="onglet" value="comptes">
                        <input type="hidden" name="action" value="delete">
                      </form>
                      <button class="btn btn-sm btn-outline-danger" type="submit"
                        form="<?= $deleteFormId ?>">Retirer</button>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>

  <section class="tab-pane fade <?= $isActivePanel('ouverture') ?>" id="panel-ouverture"
    role="tabpanel" aria-labelledby="tab-ouverture" tabindex="0">
    <div class="card border-top-0 shadow-sm">
      <div class="card-body p-3 p-md-4">
        <div class="d-flex flex-wrap justify-content-between gap-2">
          <div>
            <h2 class="h4 mb-1">Soldes d’ouverture</h2>
            <p class="text-body-secondary">
              Un montant positif suit le sens normal du compte ; un montant
              négatif utilise le côté inverse.
            </p>
          </div>
          <span class="badge align-self-start <?= $opening['status'] === 'validee'
            ? 'text-bg-success'
            : ($opening['status'] === 'brouillon' ? 'text-bg-warning' : 'text-bg-secondary') ?>">
            <?= Html::escape(match ($opening['status']) {
              'validee' => 'Validée ' . $opening['numero'],
              'contre_passee' => 'Contre-passée',
              'brouillon' => 'Brouillon',
              default => 'À préparer',
            }) ?>
          </span>
        </div>
        <?php if (!$openingEditable): ?>
          <div class="alert alert-info" role="status">
            Cette écriture est immuable. Toute correction passe par une contre-passation.
          </div>
        <?php endif; ?>
        <form method="post" action="<?= Html::escape($config->url('/compta/plan/ouverture')) ?>">
          <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
          <input type="hidden" name="exercice_id" value="<?= (int) $exercise['id'] ?>">
          <input type="hidden" name="onglet" value="ouverture">
          <div class="table-responsive opening-balances">
            <table class="table table-sm table-hover align-middle">
              <thead class="table-light">
                <tr><th>Compte</th><th>Libellé</th><th>Fonctionnement</th>
                  <th class="text-end">Solde initial (CHF)</th></tr>
              </thead>
              <tbody>
                <?php foreach ($accounts as $account): ?>
                  <?php if (
                    (int) $account['actif'] !== 1
                    || !in_array($account['type'], ['actif', 'passif'], true)
                  ) continue; ?>
                  <tr>
                    <td class="font-monospace"><?= Html::escape($account['numero']) ?></td>
                    <td><?= Html::escape($account['libelle']) ?></td>
                    <td><?= $account['sens_normal'] === 'credit' ? '--/++' : '++/--' ?></td>
                    <td>
                      <label class="visually-hidden" for="opening-<?= (int) $account['id'] ?>">
                        Solde initial <?= Html::escape($account['numero']) ?>
                      </label>
                      <input class="form-control form-control-sm text-end font-monospace"
                        id="opening-<?= (int) $account['id'] ?>"
                        name="solde_<?= (int) $account['id'] ?>" inputmode="decimal"
                        value="<?= Html::escape($formatCents(
                          (int) ($opening['soldes'][(int) $account['id']] ?? 0)
                        )) ?>" <?= (!$can_setup || !$openingEditable) ? 'disabled' : '' ?>>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr class="fw-semibold"><td colspan="3">Écriture préparée</td>
                  <td class="text-end font-monospace">
                    Débit <?= Html::escape($formatCents((int) $opening['total_debit_centimes']) ?: '0.00') ?>
                    / Crédit <?= Html::escape($formatCents((int) $opening['total_credit_centimes']) ?: '0.00') ?>
                  </td></tr>
              </tfoot>
            </table>
          </div>
          <?php if ($can_setup && $openingEditable): ?>
            <div class="d-flex flex-wrap justify-content-end gap-2">
              <button class="btn btn-outline-primary" type="submit"
                name="action" value="save">Enregistrer le brouillon</button>
              <?php if ($can_validate): ?>
                <button class="btn btn-primary" type="submit"
                  name="action" value="validate">Valider l’ouverture</button>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </form>
      </div>
    </div>
  </section>
</div>

<script defer src="<?= Html::escape($config->url('/assets/plan.js')) ?>"></script>
