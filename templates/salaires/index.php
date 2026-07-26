<?php
declare(strict_types=1);

use Compta\Core\Support\Html;

$money = static fn (int $cents): string => number_format($cents / 100, 2, '.', ' ');
$percent = static fn (int $ppm): string => rtrim(rtrim(
    number_format($ppm / 10000, 4, '.', ''),
    '0'
), '.');
$actionUrl = $config->url('/salaires/action');
$tabUrl = static fn (string $tab): string => $config->url('/salaires')
    . '?onglet=' . rawurlencode($tab);
$today = date('Y-m-d');
$year = (int) date('Y');
$mappingLabels = [
    'charge_salaires_id' => 'Charge salaires',
    'charge_ocas_id' => 'Charge OCAS',
    'charge_laa_id' => 'Charge LAA',
    'charge_lpp_id' => 'Charge LPP',
    'dette_net_id' => 'Dette salaire net',
    'dette_ocas_id' => 'Dette OCAS',
    'dette_laa_id' => 'Dette LAA',
    'dette_lpp_id' => 'Dette LPP',
    'dette_impot_id' => 'Dette impôt source',
];
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
  <div>
    <h1 class="h3 mb-1">Salaires genevois</h1>
    <p class="text-body-secondary mb-0">
      Calcul, validation, comptabilisation et règlement dans le dossier sélectionné.
    </p>
  </div>
  <a class="btn btn-outline-secondary" href="<?= Html::escape($config->url('/')) ?>">
    Tableau de bord
  </a>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success" role="status" tabindex="-1"
    data-auto-focus><?= Html::escape($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-danger" role="alert" tabindex="-1"
    data-auto-focus><?= Html::escape($error) ?></div>
<?php endif; ?>

<div class="alert alert-info py-2" role="status">
  Périmètre actuel : canton de Genève. Les taux annuels, la LPP et l’impôt à la source
  doivent être vérifiés et saisis explicitement. L’export annuel est interne, à contrôler
  et non transmis.
</div>

<nav class="nav nav-tabs mb-3" aria-label="Salaires">
  <?php foreach ([
      'fiches' => 'Fiches',
      'employes' => 'Employés',
      'paiements' => 'Paiements',
      'parametres' => 'Paramètres',
  ] as $key => $label): ?>
    <a class="nav-link <?= $active_tab === $key ? 'active' : '' ?>"
      href="<?= Html::escape($tabUrl($key)) ?>"><?= Html::escape($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($active_tab === 'employes'): ?>
  <?php if ($can_manage): ?>
    <section class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <h2 class="h5">Nouvel employé — Genève</h2>
        <form method="post" action="<?= Html::escape($actionUrl) ?>" class="row g-2 align-items-end">
          <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
          <input type="hidden" name="action" value="employee">
          <input type="hidden" name="onglet" value="employes">
          <div class="col-md-2"><label class="form-label" for="emp_prenom">Prénom</label>
            <input class="form-control" id="emp_prenom" name="prenom" required></div>
          <div class="col-md-2"><label class="form-label" for="emp_nom">Nom</label>
            <input class="form-control" id="emp_nom" name="nom" required></div>
          <div class="col-md-3"><label class="form-label" for="emp_avs">N° AVS</label>
            <input class="form-control" id="emp_avs" name="numero_avs"
              placeholder="756.0000.0000.00" required></div>
          <div class="col-md-2"><label class="form-label" for="emp_birth">Naissance</label>
            <input class="form-control" id="emp_birth" name="date_naissance" type="date"></div>
          <div class="col-md-3"><label class="form-label" for="emp_email">E-mail</label>
            <input class="form-control" id="emp_email" name="email" type="email"></div>
          <div class="col-md-3"><label class="form-label" for="emp_procedure">Procédure</label>
            <select class="form-select" id="emp_procedure" name="procedure">
              <option value="ordinaire">Ordinaire</option>
              <option value="simplifiee">Simplifiée</option>
              <option value="ordinaire_impot_source">Ordinaire avec impôt source</option>
            </select></div>
          <div class="col-md-2"><label class="form-label" for="emp_vac">Vacances %</label>
            <input class="form-control" id="emp_vac" name="supplement_vacances"
              inputmode="decimal" value="8.33" required></div>
          <div class="col-md-2"><label class="form-label" for="emp_tax">Impôt source %</label>
            <input class="form-control" id="emp_tax" name="impot_source"
              inputmode="decimal" value="0" required></div>
          <div class="col-md-auto"><button class="btn btn-primary" type="submit">Créer</button></div>
        </form>
      </div>
    </section>
  <?php endif; ?>
  <section class="card border-0 shadow-sm">
    <div class="card-body">
      <h2 class="h5">Employés du dossier</h2>
      <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Employés du dossier</caption>
        <thead><tr><th>Nom</th><th>AVS</th><th>Procédure</th><th>Vacances</th><th>Impôt source</th></tr></thead>
        <tbody>
        <?php foreach ($employees as $employee): ?>
          <tr>
            <td><?= Html::escape($employee['prenom'] . ' ' . $employee['nom']) ?></td>
            <td><?= Html::escape((string) $employee['numero_avs']) ?></td>
            <td><?= Html::escape((string) $employee['procedure']) ?></td>
            <td><?= Html::escape($percent((int) $employee['supplement_vacances_ppm'])) ?> %</td>
            <td><?= Html::escape($percent((int) $employee['impot_source_ppm'])) ?> %</td>
          </tr>
        <?php endforeach; ?>
        <?php if ($employees === []): ?><tr><td colspan="5" class="text-body-secondary">Aucun employé.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </section>

<?php elseif ($active_tab === 'paiements'): ?>
  <?php if ($can_pay): ?>
    <section class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <h2 class="h5">Nouveau décaissement</h2>
        <form method="post" action="<?= Html::escape($actionUrl) ?>" class="row g-2 align-items-end">
          <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
          <input type="hidden" name="action" value="payment">
          <input type="hidden" name="onglet" value="paiements">
          <div class="col-md-2"><label class="form-label" for="pay_type">Bénéficiaire</label>
            <select class="form-select" id="pay_type" name="beneficiaire_type">
              <option value="employe">Employé</option><option value="organisme">Organisme</option>
            </select></div>
          <div class="col-md-2"><label class="form-label" for="pay_employee">Employé si applicable</label>
            <select class="form-select" id="pay_employee" name="employe_id">
              <option value="">—</option>
              <?php foreach ($employees as $employee): ?>
                <option value="<?= (int) $employee['id'] ?>"><?= Html::escape($employee['prenom'] . ' ' . $employee['nom']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="col-md-2"><label class="form-label" for="pay_date">Date</label>
            <input class="form-control" id="pay_date" name="date_paiement" type="date"
              value="<?= Html::escape($today) ?>" required></div>
          <div class="col-md-2"><label class="form-label" for="pay_amount">Montant CHF</label>
            <input class="form-control" id="pay_amount" name="montant" required></div>
          <div class="col-md-2"><label class="form-label" for="pay_bank">Trésorerie</label>
            <select class="form-select" id="pay_bank" name="compte_tresorerie_id" required>
              <?php foreach (($catalog['accounts'] ?? []) as $account): ?>
                <option value="<?= (int) $account['id'] ?>"><?= Html::escape($account['numero'] . ' ' . $account['libelle']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="col-md-2"><label class="form-label" for="pay_ref">Référence</label>
            <input class="form-control" id="pay_ref" name="reference"></div>
          <div class="col-md-auto"><button class="btn btn-primary" type="submit">Saisir</button></div>
        </form>
      </div>
    </section>
  <?php endif; ?>
  <section class="card border-0 shadow-sm">
    <div class="card-body">
      <h2 class="h5">Paiements et allocations</h2>
      <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Paiements et allocations des salaires</caption>
        <thead><tr><th>Date</th><th>Bénéficiaire</th><th class="text-end">Montant</th>
          <th class="text-end">Non alloué</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $payment): ?>
          <tr>
            <td><?= Html::escape((string) $payment['date_paiement']) ?></td>
            <td><?= Html::escape($payment['beneficiaire_type'] === 'employe'
                ? trim((string) $payment['prenom'] . ' ' . (string) $payment['nom'])
                : 'Organisme') ?></td>
            <td class="text-end"><?= Html::escape($money((int) $payment['montant_centimes'])) ?></td>
            <td class="text-end"><?= Html::escape($money((int) $payment['non_alloue_centimes'])) ?></td>
            <td>
              <?php if ($can_pay && (int) $payment['non_alloue_centimes'] > 0): ?>
                <form method="post" action="<?= Html::escape($actionUrl) ?>" class="d-flex flex-wrap gap-1">
                  <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                  <input type="hidden" name="action" value="allocate">
                  <input type="hidden" name="onglet" value="paiements">
                  <input type="hidden" name="paiement_id" value="<?= (int) $payment['id'] ?>">
                  <select class="form-select form-select-sm w-auto" name="dette_id"
                    aria-label="Dette à allouer" required>
                    <option value="">Dette…</option>
                    <?php foreach ($liabilities as $debt): ?>
                      <?php if ((int) $debt['solde_centimes'] > 0): ?>
                        <option value="<?= (int) $debt['id'] ?>">
                          <?= Html::escape(sprintf(
                              '%02d/%d %s %s — %s',
                              (int) $debt['mois'],
                              (int) $debt['annee'],
                              $debt['nom'],
                              $debt['type'],
                              $money((int) $debt['solde_centimes'])
                          )) ?>
                        </option>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </select>
                  <input class="form-control form-control-sm w-auto" name="montant"
                    aria-label="Montant à allouer"
                    placeholder="CHF" required>
                  <button class="btn btn-sm btn-outline-primary" type="submit">Allouer</button>
                </form>
              <?php elseif ($can_pay && $payment['ecriture_id'] === null): ?>
                <form method="post" action="<?= Html::escape($actionUrl) ?>" class="d-flex gap-1">
                  <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                  <input type="hidden" name="action" value="post_payment">
                  <input type="hidden" name="onglet" value="paiements">
                  <input type="hidden" name="paiement_id" value="<?= (int) $payment['id'] ?>">
                  <select class="form-select form-select-sm w-auto" name="exercice_id"
                    aria-label="Exercice comptable" required>
                    <?php foreach (($catalog['exercises'] ?? []) as $exercise): ?>
                      <option value="<?= (int) $exercise['id'] ?>"><?= Html::escape((string) $exercise['libelle']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <select class="form-select form-select-sm w-auto" name="journal_id"
                    aria-label="Journal comptable" required>
                    <?php foreach (($catalog['journals'] ?? []) as $journal): ?>
                      <option value="<?= (int) $journal['id'] ?>"><?= Html::escape((string) $journal['code']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-outline-primary" type="submit">Comptabiliser</button>
                </form>
              <?php else: ?>Comptabilisé<?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if ($payments === []): ?><tr><td colspan="5" class="text-body-secondary">Aucun paiement.</td></tr><?php endif; ?>
        </tbody>
      </table>
      </div>
    </div>
  </section>

<?php elseif ($active_tab === 'parametres'): ?>
  <?php if (!$can_manage): ?>
    <div class="alert alert-secondary" role="status">Paramètres visibles uniquement avec le droit de gestion.</div>
  <?php else: ?>
    <section class="card border-0 shadow-sm mb-3"><div class="card-body">
      <h2 class="h5">Employeur du dossier</h2>
      <form method="post" action="<?= Html::escape($actionUrl) ?>" class="row g-2 align-items-end">
        <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
        <input type="hidden" name="action" value="employer"><input type="hidden" name="onglet" value="parametres">
        <div class="col-md-3"><label class="form-label" for="org_name">Nom</label>
          <input class="form-control" id="org_name" name="nom" value="<?= Html::escape((string) ($employer['nom'] ?? '')) ?>" required></div>
        <div class="col-md-3"><label class="form-label" for="org_street">Rue</label>
          <input class="form-control" id="org_street" name="rue" value="<?= Html::escape((string) ($employer['rue'] ?? '')) ?>"></div>
        <div class="col-md-1"><label class="form-label" for="org_npa">NPA</label>
          <input class="form-control" id="org_npa" name="npa" value="<?= Html::escape((string) ($employer['npa'] ?? '')) ?>"></div>
        <div class="col-md-2"><label class="form-label" for="org_city">Localité</label>
          <input class="form-control" id="org_city" name="localite" value="<?= Html::escape((string) ($employer['localite'] ?? '')) ?>"></div>
        <div class="col-md-2"><label class="form-label" for="org_hours">Heures/semaine</label>
          <input class="form-control" id="org_hours" name="heures_hebdo"
            value="<?= Html::escape(isset($employer['heures_hebdo_milli']) ? (string) ((int) $employer['heures_hebdo_milli'] / 1000) : '40') ?>" required></div>
        <div class="col-md-auto"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
      </form>
    </div></section>

    <section class="card border-0 shadow-sm mb-3"><div class="card-body">
      <h2 class="h5">Taux annuels des charges sociales</h2>
      <p class="mb-2">
        Ce référentiel est désormais géré dans l’interface Vue de Configuration.
      </p>
      <a class="btn btn-outline-primary"
        href="<?= Html::escape($config->url('/app/configuration/referentiels') . '?section=payroll') ?>">
        Ouvrir les taux dans Configuration
      </a>
    </div></section>

    <section class="card border-0 shadow-sm mb-3"><div class="card-body">
      <h2 class="h5">Unités et tarifs</h2>
      <div class="row g-3">
        <form method="post" action="<?= Html::escape($actionUrl) ?>" class="col-md-6 row g-2 align-items-end">
          <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
          <input type="hidden" name="action" value="unit"><input type="hidden" name="onglet" value="parametres">
          <div class="col"><label class="form-label" for="unit_label">Unité</label>
            <input class="form-control" id="unit_label" name="libelle" required></div>
          <div class="col"><label class="form-label" for="unit_hours">Heures</label>
            <input class="form-control" id="unit_hours" name="heures" required></div>
          <div class="col-auto"><button class="btn btn-outline-primary" type="submit">Ajouter</button></div>
        </form>
        <form method="post" action="<?= Html::escape($actionUrl) ?>" class="col-md-6 row g-2 align-items-end">
          <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
          <input type="hidden" name="action" value="tariff"><input type="hidden" name="onglet" value="parametres">
          <div class="col"><label class="form-label" for="tariff_label">Tarif</label>
            <input class="form-control" id="tariff_label" name="libelle" required></div>
          <div class="col"><label class="form-label" for="tariff_amount">CHF/heure</label>
            <input class="form-control" id="tariff_amount" name="montant" required></div>
          <div class="col-auto"><button class="btn btn-outline-primary" type="submit">Ajouter</button></div>
        </form>
      </div>
    </div></section>

    <section class="card border-0 shadow-sm"><div class="card-body">
      <h2 class="h5">Mapping comptable</h2>
      <form method="post" action="<?= Html::escape($actionUrl) ?>" class="row g-2 align-items-end">
        <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
        <input type="hidden" name="action" value="mapping"><input type="hidden" name="onglet" value="parametres">
        <?php foreach ($mappingLabels as $field => $label): ?>
          <div class="col-md-4"><label class="form-label" for="map_<?= Html::escape($field) ?>"><?= Html::escape($label) ?></label>
            <select class="form-select" id="map_<?= Html::escape($field) ?>" name="<?= Html::escape($field) ?>" required>
              <option value="">Choisir…</option>
              <?php foreach (($catalog['accounts'] ?? []) as $account): ?>
                <option value="<?= (int) $account['id'] ?>"
                  <?= (int) ($mapping[$field] ?? 0) === (int) $account['id'] ? 'selected' : '' ?>>
                  <?= Html::escape($account['numero'] . ' ' . $account['libelle']) ?>
                </option>
              <?php endforeach; ?>
            </select></div>
        <?php endforeach; ?>
        <div class="col-md-auto"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
      </form>
    </div></section>
  <?php endif; ?>

<?php else: ?>
  <?php if ($can_manage): ?>
    <section class="card border-0 shadow-sm mb-3"><div class="card-body">
      <h2 class="h5">Nouvelle fiche</h2>
      <form method="post" action="<?= Html::escape($actionUrl) ?>" class="row g-2 align-items-end">
        <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
        <input type="hidden" name="action" value="draft"><input type="hidden" name="onglet" value="fiches">
        <div class="col-md-3"><label class="form-label" for="sheet_employee">Employé</label>
          <select class="form-select" id="sheet_employee" name="employe_id" required>
            <?php foreach ($employees as $employee): ?>
              <option value="<?= (int) $employee['id'] ?>"><?= Html::escape($employee['prenom'] . ' ' . $employee['nom']) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="col-md-1"><label class="form-label" for="sheet_month">Mois</label>
          <input class="form-control" id="sheet_month" name="mois" type="number" min="1" max="12" value="<?= (int) date('n') ?>" required></div>
        <div class="col-md-1"><label class="form-label" for="sheet_year">Année</label>
          <input class="form-control" id="sheet_year" name="annee" type="number" value="<?= $year ?>" required></div>
        <div class="col-md-2"><label class="form-label" for="sheet_label">Prestation</label>
          <input class="form-control" id="sheet_label" name="libelle" required></div>
        <div class="col-md-1"><label class="form-label" for="sheet_unit">Unité</label>
          <input class="form-control" id="sheet_unit" name="unite_libelle" value="Heure" required></div>
        <input type="hidden" name="heures_unite" value="1">
        <div class="col-md-1"><label class="form-label" for="sheet_qty">Quantité</label>
          <input class="form-control" id="sheet_qty" name="quantite" required></div>
        <div class="col-md-2"><label class="form-label" for="sheet_rate">CHF/heure</label>
          <input class="form-control" id="sheet_rate" name="taux_horaire" required></div>
        <div class="col-md-auto"><button class="btn btn-primary" type="submit">Calculer</button></div>
      </form>
    </div></section>
  <?php endif; ?>

  <?php if ($can_export): ?>
    <details class="card border-0 shadow-sm mb-3"><summary class="card-header bg-white fw-semibold">
      Import JSON — simulation obligatoire recommandée
    </summary><div class="card-body">
      <form method="post" enctype="multipart/form-data" action="<?= Html::escape($actionUrl) ?>" class="row g-2 align-items-end">
        <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
        <input type="hidden" name="action" value="import"><input type="hidden" name="onglet" value="fiches">
        <div class="col-md-6"><label class="form-label" for="salary_json">Fichier JSON</label>
          <input class="form-control" id="salary_json" name="fichier_json" type="file"
            accept="application/json,.json" required></div>
        <div class="col-md-3"><label class="form-label" for="salary_sim">Mode</label>
          <select class="form-select" id="salary_sim" name="simulation">
            <option value="1">Simulation</option><option value="0">Appliquer</option>
          </select></div>
        <div class="col-md-auto"><button class="btn btn-outline-primary" type="submit">Exécuter</button></div>
      </form>
    </div></details>
  <?php endif; ?>

  <section class="card border-0 shadow-sm"><div class="card-body">
    <h2 class="h5">Fiches du dossier</h2>
    <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Fiches de salaire du dossier</caption>
      <thead><tr><th>Période</th><th>Employé</th><th>Statut</th>
        <th class="text-end">Brut</th><th class="text-end">Net</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($payrolls as $sheet): ?>
        <tr>
          <td><?= sprintf('%02d/%04d', (int) $sheet['mois'], (int) $sheet['annee']) ?></td>
          <td><?= Html::escape($sheet['prenom'] . ' ' . $sheet['nom']) ?></td>
          <td><span class="badge text-bg-secondary"><?= Html::escape((string) $sheet['statut']) ?></span></td>
          <td class="text-end"><?= Html::escape($money((int) $sheet['brut_centimes'])) ?></td>
          <td class="text-end"><?= Html::escape($money((int) $sheet['net_centimes'])) ?></td>
          <td class="d-flex flex-wrap gap-1">
            <?php if ($can_validate && $sheet['statut'] === 'brouillon'): ?>
              <form method="post" action="<?= Html::escape($actionUrl) ?>">
                <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                <input type="hidden" name="action" value="validate"><input type="hidden" name="onglet" value="fiches">
                <input type="hidden" name="fiche_id" value="<?= (int) $sheet['id'] ?>">
                <input type="hidden" name="version" value="<?= (int) $sheet['version'] ?>">
                <button class="btn btn-sm btn-outline-primary" type="submit">Valider</button>
              </form>
            <?php endif; ?>
            <?php if ($can_post && $sheet['statut'] === 'validee'): ?>
              <form method="post" action="<?= Html::escape($actionUrl) ?>" class="d-flex gap-1">
                <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                <input type="hidden" name="action" value="post"><input type="hidden" name="onglet" value="fiches">
                <input type="hidden" name="fiche_id" value="<?= (int) $sheet['id'] ?>">
                <input type="date" class="form-control form-control-sm w-auto"
                  name="date_comptable" aria-label="Date comptable"
                  value="<?= Html::escape($today) ?>" required>
                <select class="form-select form-select-sm w-auto" name="exercice_id"
                  aria-label="Exercice comptable" required>
                  <?php foreach (($catalog['exercises'] ?? []) as $exercise): ?><option value="<?= (int) $exercise['id'] ?>"><?= Html::escape((string) $exercise['libelle']) ?></option><?php endforeach; ?>
                </select>
                <select class="form-select form-select-sm w-auto" name="journal_id"
                  aria-label="Journal comptable" required>
                  <?php foreach (($catalog['journals'] ?? []) as $journal): ?><option value="<?= (int) $journal['id'] ?>"><?= Html::escape((string) $journal['code']) ?></option><?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-outline-primary" type="submit">Comptabiliser</button>
              </form>
            <?php endif; ?>
            <?php if ($can_post && in_array($sheet['statut'], ['brouillon', 'validee', 'comptabilisee'], true)): ?>
              <form method="post" action="<?= Html::escape($actionUrl) ?>" class="d-flex gap-1">
                <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                <input type="hidden" name="action" value="cancel"><input type="hidden" name="onglet" value="fiches">
                <input type="hidden" name="fiche_id" value="<?= (int) $sheet['id'] ?>">
                <input type="date" class="form-control form-control-sm w-auto"
                  name="date_comptable" aria-label="Date d’annulation"
                  value="<?= Html::escape($today) ?>" required>
                <button class="btn btn-sm btn-outline-danger" type="submit">Annuler</button>
              </form>
            <?php endif; ?>
            <?php if ($can_export): ?>
              <a class="btn btn-sm btn-outline-secondary" target="_blank"
                href="<?= Html::escape($config->url('/salaires/fiche') . '?id=' . (int) $sheet['id']) ?>">Imprimer</a>
              <?php if ($can_pii): ?>
                <a class="btn btn-sm btn-outline-secondary"
                  href="<?= Html::escape($config->url('/salaires/certificat.xml') . '?employe='
                    . (int) $sheet['employe_id'] . '&annee=' . (int) $sheet['annee']) ?>">Certificat XML</a>
              <?php endif; ?>
              <?php if (in_array($sheet['statut'], ['validee', 'comptabilisee', 'payee'], true)): ?>
                <form method="post" action="<?= Html::escape($actionUrl) ?>" class="d-flex gap-1">
                  <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                  <input type="hidden" name="action" value="email"><input type="hidden" name="onglet" value="fiches">
                  <input type="hidden" name="fiche_id" value="<?= (int) $sheet['id'] ?>">
                  <input class="form-control form-control-sm w-auto" name="destinataire"
                    type="email" placeholder="E-mail"
                    aria-label="Destinataire du certificat" required>
                  <button class="btn btn-sm btn-outline-secondary" type="submit">Mettre en attente</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($payrolls === []): ?><tr><td colspan="6" class="text-body-secondary">Aucune fiche.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div></section>
<?php endif; ?>
