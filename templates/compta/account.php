<?php
declare(strict_types=1);

use Compta\Core\Support\Html;

$money = static fn (int $cents): string => number_format(
    abs($cents) / 100,
    2,
    ',',
    ' '
) . ' CHF';
$account = is_array($statement) ? $statement['account'] : null;
$baseQuery = [
    'compte' => $account_id,
    'exercice' => $exercise['id'],
    'date_debut' => $date_start,
    'date_fin' => $date_end,
];
?>
<section class="card border-0 shadow-sm">
  <div class="card-body p-3 p-md-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
      <div>
        <h1 class="h3 mb-1">Extrait de compte</h1>
        <p class="text-body-secondary mb-0">
          Consultation du grand livre par compte, en liste ou en compte en T.
        </p>
      </div>
      <?php if ($can_edit && is_array($account)): ?>
        <a class="btn btn-primary no-print"
          href="<?= Html::escape($config->url('/compta/saisie') . '?' . http_build_query([
            'compte' => $account_id,
            'cote' => $account['sens_normal'] === 'credit' ? 'credit' : 'debit',
            'exercice' => $exercise['id'],
          ])) ?>">Nouvelle opération liée à ce compte</a>
      <?php endif; ?>
    </div>

    <?php if ($error !== ''): ?>
      <div class="alert alert-danger" role="alert"><?= Html::escape($error) ?></div>
    <?php endif; ?>

    <form class="row g-3 align-items-end no-print" method="get">
      <div class="col-12 col-lg-5">
        <label class="form-label" for="account-select">Compte</label>
        <select class="form-select" id="account-select" name="compte" required>
          <?php foreach ($catalog['accounts'] as $item): ?>
            <option value="<?= (int) $item['id'] ?>"
              <?= (int) $item['id'] === $account_id ? 'selected' : '' ?>>
              <?= Html::escape($item['numero'] . ' — ' . $item['libelle']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-lg-2">
        <label class="form-label" for="account-date-start">Du</label>
        <input class="form-control" id="account-date-start" name="date_debut"
          type="date" value="<?= Html::escape($date_start) ?>">
      </div>
      <div class="col-6 col-lg-2">
        <label class="form-label" for="account-date-end">Au</label>
        <input class="form-control" id="account-date-end" name="date_fin"
          type="date" value="<?= Html::escape($date_end) ?>">
      </div>
      <input type="hidden" name="exercice" value="<?= (int) $exercise['id'] ?>">
      <input type="hidden" name="vue" value="<?= Html::escape($view_mode) ?>">
      <div class="col-12 col-lg-auto">
        <button class="btn btn-primary w-100" type="submit">Afficher</button>
      </div>
    </form>

    <?php if (is_array($statement) && is_array($account)): ?>
      <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mt-4">
        <div>
          <h2 class="h5 mb-1">
            <span class="font-monospace"><?= Html::escape((string) $account['numero']) ?></span>
            — <?= Html::escape((string) $account['libelle']) ?>
          </h2>
          <p class="small text-body-secondary mb-0">
            Fonctionnement <?= $account['sens_normal'] === 'credit' ? '--/++' : '++/--' ?>
          </p>
        </div>
        <nav class="nav nav-pills no-print" aria-label="Présentation de l’extrait">
          <a class="nav-link <?= $view_mode === 'liste' ? 'active' : '' ?>"
            <?= $view_mode === 'liste' ? 'aria-current="page"' : '' ?>
            href="<?= Html::escape($config->url('/compta/compte') . '?'
              . http_build_query($baseQuery + ['vue' => 'liste'])) ?>">Liste</a>
          <a class="nav-link <?= $view_mode === 't' ? 'active' : '' ?>"
            <?= $view_mode === 't' ? 'aria-current="page"' : '' ?>
            href="<?= Html::escape($config->url('/compta/compte') . '?'
              . http_build_query($baseQuery + ['vue' => 't'])) ?>">Compte en T</a>
        </nav>
      </div>

      <?php if ($statement['items'] === []): ?>
        <div class="alert alert-info mt-3 mb-0" role="status">
          Aucun mouvement comptabilisé sur ce compte pour la période.
        </div>
      <?php elseif ($view_mode === 'liste'): ?>
        <div class="table-responsive mt-3">
          <table class="table table-sm table-striped align-middle">
            <caption class="visually-hidden">
              Extrait du compte <?= Html::escape((string) $account['numero']) ?>
            </caption>
            <thead>
              <tr>
                <th scope="col">Date</th>
                <th scope="col">N°</th>
                <th scope="col">Journal</th>
                <th scope="col">Libellé</th>
                <th scope="col" class="text-end">Débit</th>
                <th scope="col" class="text-end">Crédit</th>
                <th scope="col" class="text-end">Solde</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($statement['items'] as $line): ?>
                <tr>
                  <td><?= Html::escape((string) $line['date_comptable']) ?></td>
                  <td><?= Html::escape((string) $line['numero']) ?></td>
                  <td><?= Html::escape((string) $line['journal']) ?></td>
                  <td><?= Html::escape((string) $line['libelle']) ?></td>
                  <td class="text-end font-monospace">
                    <?= (int) $line['debit_centimes'] > 0
                      ? Html::escape($money((int) $line['debit_centimes'])) : '—' ?>
                  </td>
                  <td class="text-end font-monospace">
                    <?= (int) $line['credit_centimes'] > 0
                      ? Html::escape($money((int) $line['credit_centimes'])) : '—' ?>
                  </td>
                  <td class="text-end font-monospace">
                    <?= (int) $line['solde_centimes'] < 0 ? '(' : '' ?>
                    <?= Html::escape($money((int) $line['solde_centimes'])) ?>
                    <?= (int) $line['solde_centimes'] < 0 ? ')' : '' ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="t-account mt-3">
          <section aria-labelledby="t-debit">
            <h3 class="h6 text-center" id="t-debit">Débit</h3>
            <ol class="list-unstyled">
              <?php foreach ($statement['items'] as $line): ?>
                <?php if ((int) $line['debit_centimes'] > 0): ?>
                  <li>
                    <span><?= Html::escape($line['date_comptable'] . ' · ' . $line['numero']) ?></span>
                    <strong class="font-monospace"><?= Html::escape($money((int) $line['debit_centimes'])) ?></strong>
                  </li>
                <?php endif; ?>
              <?php endforeach; ?>
            </ol>
          </section>
          <section aria-labelledby="t-credit">
            <h3 class="h6 text-center" id="t-credit">Crédit</h3>
            <ol class="list-unstyled">
              <?php foreach ($statement['items'] as $line): ?>
                <?php if ((int) $line['credit_centimes'] > 0): ?>
                  <li>
                    <span><?= Html::escape($line['date_comptable'] . ' · ' . $line['numero']) ?></span>
                    <strong class="font-monospace"><?= Html::escape($money((int) $line['credit_centimes'])) ?></strong>
                  </li>
                <?php endif; ?>
              <?php endforeach; ?>
            </ol>
          </section>
        </div>
      <?php endif; ?>

      <dl class="account-totals">
        <div><dt>Total débit</dt><dd><?= Html::escape($money((int) $statement['total_debit_centimes'])) ?></dd></div>
        <div><dt>Total crédit</dt><dd><?= Html::escape($money((int) $statement['total_credit_centimes'])) ?></dd></div>
        <div><dt>Solde final</dt><dd>
          <?= (int) $statement['solde_centimes'] < 0 ? '(' : '' ?>
          <?= Html::escape($money((int) $statement['solde_centimes'])) ?>
          <?= (int) $statement['solde_centimes'] < 0 ? ')' : '' ?>
        </dd></div>
      </dl>
    <?php endif; ?>
  </div>
</section>
