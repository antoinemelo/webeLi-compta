<?php
declare(strict_types=1);

use Compta\Core\Support\Html;

$money = static fn (int $cents): string => number_format($cents / 100, 2, '.', ' ');
$percent = static fn (int $ppm): string => rtrim(rtrim(
    number_format($ppm / 10000, 4, '.', ''),
    '0'
), '.');
?>
<div class="d-flex justify-content-between align-items-start mb-4 no-print">
  <h1 class="h3">Fiche de salaire</h1>
  <span class="text-body-secondary">Utilisez la commande d’impression du navigateur.</span>
</div>
<section class="card border-0 shadow-sm mb-3"><div class="card-body">
  <div class="row">
    <div class="col"><strong><?= Html::escape($fiche['prenom'] . ' ' . $fiche['nom']) ?></strong><br>
      AVS : <?= Html::escape((string) $fiche['numero_avs']) ?></div>
    <div class="col text-end">Période :
      <?= sprintf('%02d/%04d', (int) $fiche['mois'], (int) $fiche['annee']) ?><br>
      Statut : <?= Html::escape((string) $fiche['statut']) ?></div>
  </div>
</div></section>
<section class="card border-0 shadow-sm mb-3"><div class="card-body">
  <h2 class="h5">Prestations</h2>
  <table class="table table-sm mb-0"><thead><tr><th>Désignation</th><th>Unité</th>
    <th class="text-end">Heures</th><th class="text-end">Montant</th></tr></thead><tbody>
  <?php foreach ($lignes as $line): ?><tr>
    <td><?= Html::escape((string) $line['libelle']) ?></td>
    <td><?= Html::escape((string) $line['unite_libelle_snapshot']) ?></td>
    <td class="text-end"><?= Html::escape((string) ((int) $line['nombre_heures_milli'] / 1000)) ?></td>
    <td class="text-end"><?= Html::escape($money((int) $line['montant_centimes'])) ?></td>
  </tr><?php endforeach; ?>
  </tbody></table>
</div></section>
<section class="card border-0 shadow-sm"><div class="card-body">
  <h2 class="h5">Calcul archivé</h2>
  <table class="table table-sm mb-0"><thead><tr><th>Composant</th><th>Catégorie</th>
    <th class="text-end">Taux</th><th class="text-end">Montant</th></tr></thead><tbody>
  <?php foreach ($composants as $component): ?><tr>
    <td><?= Html::escape((string) $component['libelle']) ?></td>
    <td><?= Html::escape((string) $component['categorie']) ?></td>
    <td class="text-end"><?= Html::escape($percent((int) $component['taux_ppm'])) ?> %</td>
    <td class="text-end"><?= Html::escape($money((int) $component['montant_centimes'])) ?></td>
  </tr><?php endforeach; ?>
  </tbody></table>
</div></section>
