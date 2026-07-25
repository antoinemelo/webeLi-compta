<?php
declare(strict_types=1);

use Compta\Core\Support\Html;
?>
<section class="card border-0 shadow-sm entry-screen">
  <div class="card-body p-3 p-md-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
      <div>
        <h1 class="h3 mb-1">Journalisation</h1>
        <p class="text-body-secondary mb-0">
          Une écriture simple porte un compte au débit, un compte au crédit,
          un libellé et un montant.
        </p>
      </div>
      <a class="btn btn-outline-primary no-print"
        href="<?= Html::escape($config->url('/compta/journal')) ?>">Consulter le journal</a>
    </div>

    <?php if ($success !== ''): ?>
      <div class="alert alert-success" role="status" tabindex="-1" data-auto-focus>
        <?= Html::escape($success) ?>
      </div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <div class="alert alert-danger" role="alert" tabindex="-1" data-auto-focus>
        <?= Html::escape($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($catalog['exercises'] === [] || $catalog['journals'] === []): ?>
      <div class="alert alert-info mb-0" role="status">
        Créez d’abord un exercice et un journal actifs pour saisir une écriture.
      </div>
    <?php else: ?>
      <form method="post" action="<?= Html::escape($config->url('/compta/saisie')) ?>"
        data-entry-form data-dirty-warning>
        <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
        <fieldset class="mb-4">
          <legend class="h5">En-tête de l’écriture</legend>
          <div class="row g-3">
            <div class="col-12 col-md-6 col-xl-3">
              <label class="form-label" for="entry-exercise">Exercice</label>
              <select class="form-select" id="entry-exercise" name="exercice_id" required>
                <?php foreach ($catalog['exercises'] as $exercise): ?>
                  <option value="<?= (int) $exercise['id'] ?>"
                    <?= (int) $exercise['id'] === $selected_exercise ? 'selected' : '' ?>>
                    <?= Html::escape($exercise['libelle'] . ' — ' . $exercise['statut']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
              <label class="form-label" for="entry-journal">Journal</label>
              <select class="form-select" id="entry-journal" name="journal_id" required>
                <?php foreach ($catalog['journals'] as $journal): ?>
                  <option value="<?= (int) $journal['id'] ?>">
                    <?= Html::escape($journal['code'] . ' — ' . $journal['libelle']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
              <label class="form-label" for="entry-date">Date comptable</label>
              <input class="form-control" id="entry-date" name="date_comptable"
                type="date" value="<?= Html::escape($default_date) ?>" required>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
              <label class="form-label" for="entry-reference">Référence</label>
              <input class="form-control" id="entry-reference" name="reference"
                autocomplete="off">
            </div>
            <div class="col-12 col-lg-8">
              <label class="form-label" for="entry-label">Libellé</label>
              <input class="form-control" id="entry-label" name="libelle"
                autocomplete="off" required>
            </div>
            <div class="col-12 col-lg-4">
              <label class="form-label" for="entry-piece">Pièce</label>
              <input class="form-control" id="entry-piece" name="piece" autocomplete="off">
            </div>
          </div>
        </fieldset>

        <fieldset class="quick-entry">
          <legend class="h5">Écriture à double entrée</legend>
          <div class="row g-3 align-items-end">
            <div class="col-12 col-lg">
              <label class="form-label" for="quick-debit">Compte au débit</label>
              <select class="form-select" id="quick-debit" name="compte_debit">
                <option value="">Choisir…</option>
                <?php foreach ($catalog['accounts'] as $account): ?>
                  <option value="<?= (int) $account['id'] ?>"
                    <?= $selected_account === (int) $account['id']
                      && $selected_side === 'debit' ? 'selected' : '' ?>>
                    <?= Html::escape($account['numero'] . ' — ' . $account['libelle']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-lg">
              <label class="form-label" for="quick-credit">Compte au crédit</label>
              <select class="form-select" id="quick-credit" name="compte_credit">
                <option value="">Choisir…</option>
                <?php foreach ($catalog['accounts'] as $account): ?>
                  <option value="<?= (int) $account['id'] ?>"
                    <?= $selected_account === (int) $account['id']
                      && $selected_side === 'credit' ? 'selected' : '' ?>>
                    <?= Html::escape($account['numero'] . ' — ' . $account['libelle']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
              <label class="form-label" for="quick-amount">Montant en CHF</label>
              <input class="form-control text-end font-monospace" id="quick-amount"
                name="montant" inputmode="decimal" autocomplete="off">
            </div>
          </div>
          <p class="small text-body-secondary mt-2 mb-0">
            Le débit et le crédit portent exactement le même montant.
          </p>
          <div class="d-flex flex-wrap gap-2 mt-3 no-print">
            <button class="btn btn-outline-primary" type="submit"
              name="action" value="quick_save">Enregistrer le brouillon</button>
            <?php if ($can_validate): ?>
              <button class="btn btn-primary" type="submit"
                name="action" value="quick_validate">Ajouter au journal</button>
            <?php endif; ?>
          </div>
        </fieldset>

        <details class="advanced-entry mt-4">
          <summary>Écriture composée — plusieurs lignes</summary>
          <div class="pt-3">
          <fieldset>
          <legend class="h5">Lignes de l’écriture composée</legend>
          <p class="small text-body-secondary" id="entry-help">
            Montants en CHF. Chaque ligne porte un débit ou un crédit, jamais les deux.
          </p>
          <div class="table-responsive entry-table-wrap">
            <table class="table table-sm align-middle entry-table">
              <caption class="visually-hidden">Lignes de l’écriture comptable</caption>
              <thead>
                <tr>
                  <th scope="col">Compte</th>
                  <th scope="col">Libellé de ligne</th>
                  <th scope="col">Débit CHF</th>
                  <th scope="col">Crédit CHF</th>
                </tr>
              </thead>
              <tbody>
                <?php for ($line = 1; $line <= 8; $line++): ?>
                  <tr>
                    <td>
                      <label class="visually-hidden" for="entry-account-<?= $line ?>">
                        Compte de la ligne <?= $line ?>
                      </label>
                      <select class="form-select form-select-sm" id="entry-account-<?= $line ?>"
                        name="compte_<?= $line ?>">
                        <option value="">Choisir…</option>
                        <?php foreach ($catalog['accounts'] as $account): ?>
                          <option value="<?= (int) $account['id'] ?>">
                            <?= Html::escape($account['numero'] . ' — ' . $account['libelle']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td>
                      <label class="visually-hidden" for="entry-line-label-<?= $line ?>">
                        Libellé de la ligne <?= $line ?>
                      </label>
                      <input class="form-control form-control-sm"
                        id="entry-line-label-<?= $line ?>"
                        name="libelle_ligne_<?= $line ?>" autocomplete="off">
                    </td>
                    <td>
                      <label class="visually-hidden" for="entry-debit-<?= $line ?>">
                        Débit de la ligne <?= $line ?> en francs
                      </label>
                      <input class="form-control form-control-sm text-end font-monospace"
                        id="entry-debit-<?= $line ?>" name="debit_<?= $line ?>"
                        inputmode="decimal" autocomplete="off" data-entry-debit>
                    </td>
                    <td>
                      <label class="visually-hidden" for="entry-credit-<?= $line ?>">
                        Crédit de la ligne <?= $line ?> en francs
                      </label>
                      <input class="form-control form-control-sm text-end font-monospace"
                        id="entry-credit-<?= $line ?>" name="credit_<?= $line ?>"
                        inputmode="decimal" autocomplete="off" data-entry-credit>
                    </td>
                  </tr>
                <?php endfor; ?>
              </tbody>
              <tfoot>
                <tr class="fw-semibold">
                  <th scope="row" colspan="2">Totaux</th>
                  <td class="text-end font-monospace" data-entry-debit-total>0.00</td>
                  <td class="text-end font-monospace" data-entry-credit-total>0.00</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </fieldset>

        <div class="entry-balance" role="status" aria-live="polite" aria-atomic="true">
          Différence : <strong data-entry-difference>0.00 CHF</strong>
          <span data-entry-state>— équilibrée</span>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3 no-print">
          <button class="btn btn-outline-primary" type="submit" name="action" value="save">
            Enregistrer le brouillon
          </button>
          <?php if ($can_validate): ?>
            <button class="btn btn-primary" type="submit" name="action" value="validate">
              Enregistrer et valider
            </button>
          <?php endif; ?>
        </div>
          </div>
        </details>
      </form>
    <?php endif; ?>
  </div>
</section>
