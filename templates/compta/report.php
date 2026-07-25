<?php
declare(strict_types=1);

use Compta\Core\Support\Html;

$query = array_filter([
    'exercice' => $exercise['id'],
    'date_debut' => $filters['date_debut'],
    'date_fin' => $filters['date_fin'],
    'texte' => $filters['texte'],
    'statut' => $filters['statut'],
    'journal' => $filters['journal_id'],
    'compte' => $filters['compte_id'],
], static fn (mixed $value): bool => $value !== '' && $value !== 0);
$basePath = '/compta/' . str_replace('_', '-', $report);
$money = static function (int $cents): string {
    $formatted = number_format(abs($cents) / 100, 2, ',', ' ') . ' CHF';
    return $cents < 0 ? '(' . $formatted . ')' : $formatted;
};
?>
<section class="card border-0 shadow-sm report">
  <div class="card-body p-3 p-md-4">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
      <h1 class="h3 mb-1"><?= Html::escape($report_title) ?></h1>
      <p class="text-body-secondary mb-0"><?= Html::escape($exercise['libelle']) ?>,
        du <?= Html::escape($filters['date_debut']) ?>
        au <?= Html::escape($filters['date_fin']) ?></p>
    </div>
    <nav class="d-flex flex-wrap gap-2 no-print" aria-label="Actions du rapport">
      <a class="btn btn-outline-primary"
        href="<?= Html::escape($config->url($basePath) . '?' . http_build_query($query + ['format' => 'csv'])) ?>">Exporter CSV</a>
      <a class="btn btn-outline-secondary"
        href="<?= Html::escape($config->url($basePath) . '?' . http_build_query($query + ['impression' => 1])) ?>">Vue imprimable</a>
    </nav>
  </div>

  <form class="row g-3 align-items-end mb-4 no-print" method="get">
    <div class="col-12 col-sm-6 col-lg">
      <label class="form-label" for="date_debut">Date de début</label>
      <input class="form-control" id="date_debut" type="date" name="date_debut"
        value="<?= Html::escape($filters['date_debut']) ?>">
    </div>
    <div class="col-12 col-sm-6 col-lg">
      <label class="form-label" for="date_fin">Date de fin</label>
      <input class="form-control" id="date_fin" type="date" name="date_fin"
        value="<?= Html::escape($filters['date_fin']) ?>">
    </div>
    <?php if ($report === 'journal'): ?>
      <div class="col-12 col-lg">
        <label class="form-label" for="texte">Texte</label>
        <input class="form-control" id="texte" name="texte"
          value="<?= Html::escape($filters['texte']) ?>">
      </div>
      <div class="col-12 col-sm-6 col-lg">
        <label class="form-label" for="statut">Statut</label>
        <select class="form-select" id="statut" name="statut">
          <?php foreach (['comptabilisee', 'brouillon', 'validee', 'contre_passee'] as $status): ?>
            <option value="<?= Html::escape($status) ?>"
              <?= $filters['statut'] === $status ? 'selected' : '' ?>>
              <?= Html::escape($status) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="col-12 col-sm-auto">
      <button class="btn btn-primary w-100" type="submit">Filtrer</button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-striped table-hover align-middle">
      <caption class="visually-hidden"><?= Html::escape($report_title) ?></caption>
      <thead class="table-light"><tr>
        <?php foreach ($columns as $label): ?>
          <th scope="col"><?= Html::escape($label) ?></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
        <?php if ($report_data['items'] === []): ?>
          <tr><td colspan="<?= count($columns) ?>" class="text-body-secondary">
            Aucune donnée.
          </td></tr>
        <?php endif; ?>
        <?php foreach ($report_data['items'] as $row): ?>
          <tr>
            <?php foreach (array_keys($columns) as $key): ?>
              <td class="<?= str_ends_with($key, '_centimes') ? 'text-end font-monospace' : '' ?>">
                <?= Html::escape(str_ends_with($key, '_centimes')
                  ? $money((int) ($row[$key] ?? 0))
                  : (string) ($row[$key] ?? '')) ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (isset($report_data['total'])): ?>
    <p class="small text-body-secondary"><?= (int) $report_data['total'] ?> résultat(s),
      page <?= (int) $report_data['page'] ?> / <?= (int) $report_data['pages'] ?>.</p>
  <?php endif; ?>
  <?php if ($report === 'balance'): ?>
    <p class="alert alert-light border"><strong>Totaux :</strong>
      débit <?= Html::escape($money((int) $report_data['total_debit_centimes'])) ?>,
      crédit <?= Html::escape($money((int) $report_data['total_credit_centimes'])) ?> —
      <?= $report_data['equilibree'] ? 'équilibrée' : 'déséquilibrée' ?>.</p>
  <?php elseif ($report === 'bilan'): ?>
    <p class="alert alert-light border"><strong>Actifs :</strong>
      <?= Html::escape($money((int) $report_data['total_actif_centimes'])) ?> —
      <strong>Passifs et fonds propres :</strong>
      <?= Html::escape($money((int) $report_data['total_passif_centimes'])) ?>.</p>
  <?php elseif ($report === 'resultat'): ?>
    <p class="alert alert-light border"><strong>Produits :</strong>
      <?= Html::escape($money((int) $report_data['produits_centimes'])) ?> —
      <strong>Charges :</strong>
      <?= Html::escape($money((int) $report_data['charges_centimes'])) ?> —
      <strong>Résultat :</strong>
      <?= Html::escape($money((int) $report_data['resultat_centimes'])) ?>.</p>
  <?php endif; ?>

  <p class="small text-body-secondary mt-4 mb-0">Structure du plan : Plan comptable suisse PME,
    version officielle du 12 août 2024 — © veb.ch, Zürich.</p>
  </div>
</section>
