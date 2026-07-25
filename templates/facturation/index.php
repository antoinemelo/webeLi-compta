<?php
declare(strict_types=1);

use Compta\Core\Support\Html;

$today = date('Y-m-d');
$money = static fn (int $cents): string => number_format($cents / 100, 2, '.', ' ');
$contactName = static function (array $contact): string {
    $company = trim((string) ($contact['raison_sociale'] ?? ''));
    return $company !== ''
        ? $company
        : trim((string) ($contact['prenom'] ?? '') . ' ' . (string) ($contact['nom'] ?? ''));
};
$tabUrl = static fn (string $tab): string => $config->url('/facturation')
    . '?onglet=' . rawurlencode($tab);
$action = $config->url('/facturation/action');
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
  <div>
    <h1 class="h3 mb-1">Débiteurs et créanciers</h1>
    <p class="text-body-secondary mb-0">
      Contacts, factures, avoirs, paiements et allocations du dossier sélectionné.
    </p>
  </div>
  <a class="btn btn-outline-secondary" href="<?= Html::escape($config->url('/')) ?>">Tableau de bord</a>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success" role="status" tabindex="-1"
    data-auto-focus><?= Html::escape($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-danger" role="alert" tabindex="-1"
    data-auto-focus><?= Html::escape($error) ?></div>
<?php endif; ?>

<nav class="nav nav-tabs mb-3" aria-label="Facturation">
  <?php foreach (['documents' => 'Documents', 'contacts' => 'Contacts', 'paiements' => 'Paiements'] as $key => $label): ?>
    <a class="nav-link <?= $active_tab === $key ? 'active' : '' ?>"
      href="<?= Html::escape($tabUrl($key)) ?>"><?= Html::escape($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($active_tab === 'contacts'): ?>
  <?php if ($can_manage): ?>
    <section class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <h2 class="h5">Nouveau contact multi-rôles</h2>
        <form method="post" action="<?= Html::escape($action) ?>" class="row g-2">
          <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
          <input type="hidden" name="action" value="contact">
          <input type="hidden" name="onglet" value="contacts">
          <div class="col-md-2">
            <label class="form-label" for="type_personne">Type</label>
            <select class="form-select" id="type_personne" name="type_personne">
              <option value="entreprise">Entreprise</option>
              <option value="personne">Personne</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="raison_sociale">Raison sociale</label>
            <input class="form-control" id="raison_sociale" name="raison_sociale">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="prenom">Prénom</label>
            <input class="form-control" id="prenom" name="prenom">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="nom">Nom</label>
            <input class="form-control" id="nom" name="nom">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="email">E-mail</label>
            <input class="form-control" id="email" name="email" type="email">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="telephone">Téléphone</label>
            <input class="form-control" id="telephone" name="telephone">
          </div>
          <div class="col-md-5">
            <span class="form-label d-block">Rôles</span>
            <?php foreach (['client', 'fournisseur', 'employe', 'autre'] as $role): ?>
              <label class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox"
                  name="role_<?= Html::escape($role) ?>" value="1">
                <span class="form-check-label"><?= Html::escape(ucfirst($role)) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="col-md-5">
            <label class="form-label" for="ligne1">Adresse</label>
            <input class="form-control" id="ligne1" name="ligne1" required>
          </div>
          <div class="col-md-2">
            <label class="form-label" for="code_postal">NPA</label>
            <input class="form-control" id="code_postal" name="code_postal" required>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="localite">Localité</label>
            <input class="form-control" id="localite" name="localite" required>
          </div>
          <div class="col-md-2">
            <label class="form-label" for="pays">Pays</label>
            <input class="form-control" id="pays" name="pays" value="CH" maxlength="2" required>
          </div>
          <div class="col-md-auto align-self-end">
            <button class="btn btn-primary" type="submit">Créer</button>
          </div>
        </form>
      </div>
    </section>
  <?php endif; ?>
  <section class="card border-0 shadow-sm">
    <div class="card-body">
      <h2 class="h5">Contacts du dossier</h2>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <caption class="visually-hidden">Contacts du dossier</caption>
          <thead><tr><th>Contact</th><th>Rôles</th><th>Adresse</th><th>E-mail</th></tr></thead>
          <tbody>
          <?php foreach ($contacts as $contact): ?>
            <tr>
              <td><?= Html::escape($contactName($contact)) ?></td>
              <td><?= Html::escape(str_replace(',', ', ', (string) $contact['roles'])) ?></td>
              <td><?= Html::escape(trim(
                  (string) ($contact['ligne1'] ?? '') . ', '
                  . (string) ($contact['code_postal'] ?? '') . ' '
                  . (string) ($contact['localite'] ?? '')
              , ', ')) ?></td>
              <td><?= Html::escape((string) $contact['email']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($contacts === []): ?>
            <tr><td colspan="4" class="text-body-secondary">Aucun contact.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

<?php elseif ($active_tab === 'paiements'): ?>
  <?php if ($can_pay): ?>
    <section class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <h2 class="h5">Saisir un paiement indépendant</h2>
        <form method="post" action="<?= Html::escape($action) ?>" class="row g-2 align-items-end">
          <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
          <input type="hidden" name="action" value="payment">
          <input type="hidden" name="onglet" value="paiements">
          <div class="col-md-3">
            <label class="form-label" for="payment_contact">Contact</label>
            <select class="form-select" id="payment_contact" name="contact_id" required>
              <?php foreach ($contacts as $contact): ?>
                <option value="<?= (int) $contact['id'] ?>"><?= Html::escape($contactName($contact)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label" for="sens">Sens</label>
            <select class="form-select" id="sens" name="sens">
              <option value="encaissement">Encaissement</option>
              <option value="decaissement">Décaissement</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label" for="date_paiement">Date</label>
            <input class="form-control" id="date_paiement" name="date_paiement"
              type="date" value="<?= Html::escape($today) ?>" required>
          </div>
          <div class="col-md-2">
            <label class="form-label" for="payment_amount">Montant</label>
            <input class="form-control" id="payment_amount" name="montant"
              inputmode="decimal" required>
          </div>
          <div class="col-md-2">
            <label class="form-label" for="payment_reference">Référence</label>
            <input class="form-control" id="payment_reference" name="reference">
          </div>
          <div class="col-md-auto">
            <button class="btn btn-primary" type="submit">Saisir</button>
          </div>
        </form>
      </div>
    </section>
  <?php endif; ?>
  <section class="card border-0 shadow-sm">
    <div class="card-body">
      <h2 class="h5">Paiements et allocations N–N</h2>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <caption class="visually-hidden">Paiements et allocations</caption>
          <thead><tr><th>Date</th><th>Contact</th><th>Sens</th><th class="text-end">Montant</th>
            <th class="text-end">Non alloué</th><th>Allocation</th></tr></thead>
          <tbody>
          <?php foreach ($payments as $payment): ?>
            <tr>
              <td><?= Html::escape((string) $payment['date_paiement']) ?></td>
              <td><?= Html::escape($contactName($payment)) ?></td>
              <td><?= Html::escape((string) $payment['sens']) ?></td>
              <td class="text-end"><?= Html::escape($money((int) $payment['montant_centimes'])) ?></td>
              <td class="text-end"><?= Html::escape($money((int) $payment['non_alloue_centimes'])) ?></td>
              <td>
                <?php if ($can_pay && (int) $payment['non_alloue_centimes'] > 0): ?>
                  <form method="post" action="<?= Html::escape($action) ?>" class="d-flex gap-1">
                    <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                    <input type="hidden" name="action" value="allocate">
                    <input type="hidden" name="onglet" value="paiements">
                    <input type="hidden" name="paiement_id" value="<?= (int) $payment['id'] ?>">
                    <select class="form-select form-select-sm" name="document_id"
                      aria-label="Document à allouer" required>
                      <option value="">Facture…</option>
                      <?php foreach ($documents as $document): ?>
                        <?php if (in_array($document['type'], ['facture_client', 'facture_fournisseur'], true)
                            && in_array($document['statut'], ['emis', 'comptabilise'], true)): ?>
                          <option value="<?= (int) $document['id'] ?>">
                            <?= Html::escape(($document['numero'] ?: '#' . $document['id'])
                              . ' — ' . $money((int) $document['solde_centimes'])) ?>
                          </option>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </select>
                    <input class="form-control form-control-sm" name="montant"
                      placeholder="Montant" aria-label="Montant à allouer" required>
                    <button class="btn btn-sm btn-outline-primary" type="submit">Allouer</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($payments === []): ?>
            <tr><td colspan="6" class="text-body-secondary">Aucun paiement.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

<?php else: ?>
  <?php if ($can_manage): ?>
    <details class="card border-0 shadow-sm mb-3">
      <summary class="card-header bg-white fw-semibold">
        Coordonnées du créancier pour les PDF et QR-factures
      </summary>
      <div class="card-body">
        <form method="post" action="<?= Html::escape($action) ?>"
          class="row g-2 align-items-end">
          <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
          <input type="hidden" name="action" value="profile">
          <input type="hidden" name="onglet" value="documents">
          <div class="col-md-4">
            <label class="form-label" for="creditor_line1">Adresse</label>
            <input class="form-control" id="creditor_line1" name="adresse_ligne1"
              value="<?= Html::escape((string) ($creditor_profile['ligne1'] ?? '')) ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="creditor_line2">Complément</label>
            <input class="form-control" id="creditor_line2" name="adresse_ligne2"
              value="<?= Html::escape((string) ($creditor_profile['ligne2'] ?? '')) ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label" for="creditor_postal">NPA</label>
            <input class="form-control" id="creditor_postal" name="code_postal"
              value="<?= Html::escape((string) ($creditor_profile['code_postal'] ?? '')) ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="creditor_city">Localité</label>
            <input class="form-control" id="creditor_city" name="localite"
              value="<?= Html::escape((string) ($creditor_profile['localite'] ?? '')) ?>" required>
          </div>
          <div class="col-md-2">
            <label class="form-label" for="creditor_country">Pays</label>
            <input class="form-control" id="creditor_country" name="pays" maxlength="2"
              value="<?= Html::escape((string) ($creditor_profile['pays'] ?? 'CH')) ?>" required>
          </div>
          <div class="col-md-5">
            <label class="form-label" for="creditor_iban">IBAN CHF/EUR</label>
            <input class="form-control" id="creditor_iban" name="iban_facturation"
              value="<?= Html::escape((string) ($creditor_profile['iban'] ?? '')) ?>" required>
          </div>
          <div class="col-md-auto">
            <button class="btn btn-outline-primary" type="submit">Enregistrer</button>
          </div>
        </form>
      </div>
    </details>
    <section class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <h2 class="h5">Nouveau brouillon</h2>
        <form method="post" enctype="multipart/form-data"
          action="<?= Html::escape($action) ?>" class="row g-2 align-items-end">
          <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
          <input type="hidden" name="action" value="draft">
          <input type="hidden" name="onglet" value="documents">
          <div class="col-md-2">
            <label class="form-label" for="document_type">Type</label>
            <select class="form-select" id="document_type" name="type">
              <option value="facture_client">Facture client</option>
              <option value="facture_fournisseur">Facture fournisseur</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="document_contact">Contact</label>
            <select class="form-select" id="document_contact" name="contact_id" required>
              <?php foreach ($contacts as $contact): ?>
                <option value="<?= (int) $contact['id'] ?>"><?= Html::escape($contactName($contact)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label" for="date_document">Date</label>
            <input class="form-control" id="date_document" name="date_document"
              type="date" value="<?= Html::escape($today) ?>" required>
          </div>
          <div class="col-md-2">
            <label class="form-label" for="date_echeance">Échéance</label>
            <input class="form-control" id="date_echeance" name="date_echeance"
              type="date" value="<?= Html::escape($today) ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="numero_externe">N° fournisseur</label>
            <input class="form-control" id="numero_externe" name="numero_externe">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="document_label">Désignation</label>
            <input class="form-control" id="document_label" name="libelle" required>
          </div>
          <div class="col-md-2">
            <label class="form-label" for="document_amount">Prix</label>
            <input class="form-control" id="document_amount" name="montant"
              inputmode="decimal" required>
          </div>
          <div class="col-md-2">
            <label class="form-label" for="input_mode">Saisie</label>
            <select class="form-select" id="input_mode" name="mode_saisie">
              <option value="net">Net</option>
              <option value="brut">Brut</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label" for="vat_code">Code TVA</label>
            <select class="form-select" id="vat_code" name="code_tva_id" required>
              <?php foreach (($catalog['vat_codes'] ?? []) as $vat): ?>
                <option value="<?= (int) $vat['id'] ?>">
                  <?= Html::escape($vat['code'] . ' — ' . $vat['libelle']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label" for="service_date">Prestation</label>
            <input class="form-control" id="service_date" name="date_prestation"
              type="date" value="<?= Html::escape($today) ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="line_account">Compte de produit/charge</label>
            <select class="form-select" id="line_account" name="compte_id" required>
              <?php foreach (($catalog['accounts'] ?? []) as $account): ?>
                <option value="<?= (int) $account['id'] ?>">
                  <?= Html::escape($account['numero'] . ' — ' . $account['libelle']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="collective_account">Compte collectif</label>
            <select class="form-select" id="collective_account" name="compte_collectif_id" required>
              <?php foreach (($catalog['accounts'] ?? []) as $account): ?>
                <option value="<?= (int) $account['id'] ?>">
                  <?= Html::escape($account['numero'] . ' — ' . $account['libelle']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label" for="supplier_attachment">Justificatif fournisseur</label>
            <input class="form-control" id="supplier_attachment" name="justificatif"
              type="file" accept="application/pdf,image/jpeg,image/png,image/webp">
          </div>
          <div class="col-md-auto">
            <button class="btn btn-primary" type="submit">Créer le brouillon</button>
          </div>
        </form>
      </div>
    </section>
  <?php endif; ?>

  <section class="card border-0 shadow-sm">
    <div class="card-body">
      <h2 class="h5">Documents</h2>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <caption class="visually-hidden">Documents du dossier</caption>
          <thead><tr><th>N°</th><th>Contact</th><th>Type / état</th><th>Échéance</th>
            <th class="text-end">Total</th><th class="text-end">Solde</th><th>Actions</th></tr></thead>
          <tbody>
          <?php foreach ($documents as $document): ?>
            <tr>
              <td><?= Html::escape($document['numero'] ?: 'Brouillon #' . $document['id']) ?></td>
              <td><?= Html::escape($contactName($document)) ?></td>
              <td><?= Html::escape(str_replace('_', ' ', (string) $document['type'])) ?>
                <span class="badge text-bg-light"><?= Html::escape(
                    str_replace('_', ' ', (string) $document['etat_paiement'])
                ) ?></span>
              </td>
              <td><?= Html::escape((string) $document['date_echeance']) ?></td>
              <td class="text-end"><?= Html::escape($money((int) $document['total_brut_centimes'])) ?></td>
              <td class="text-end"><?= Html::escape($money((int) $document['solde_centimes'])) ?></td>
              <td>
                <div class="d-flex flex-wrap gap-1">
                  <?php if ($document['statut'] === 'brouillon' && $can_issue): ?>
                    <form method="post" action="<?= Html::escape($action) ?>">
                      <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                      <input type="hidden" name="action" value="issue">
                      <input type="hidden" name="onglet" value="documents">
                      <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                      <input type="hidden" name="version" value="<?= (int) $document['version'] ?>">
                      <button class="btn btn-sm btn-primary" type="submit">Émettre</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($document['statut'] === 'emis' && $can_post): ?>
                    <form method="post" action="<?= Html::escape($action) ?>" class="d-flex gap-1">
                      <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                      <input type="hidden" name="action" value="post">
                      <input type="hidden" name="onglet" value="documents">
                      <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                      <select class="form-select form-select-sm" name="exercice_id" aria-label="Exercice">
                        <?php foreach (($catalog['exercises'] ?? []) as $exercise): ?>
                          <option value="<?= (int) $exercise['id'] ?>"><?= Html::escape($exercise['libelle']) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <select class="form-select form-select-sm" name="journal_id" aria-label="Journal">
                        <?php foreach (($catalog['journals'] ?? []) as $journal): ?>
                          <option value="<?= (int) $journal['id'] ?>"><?= Html::escape($journal['code']) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="btn btn-sm btn-outline-primary" type="submit">Comptabiliser</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($document['statut'] !== 'brouillon' && $can_issue): ?>
                    <a class="btn btn-sm btn-outline-secondary"
                      href="<?= Html::escape($config->url('/facturation/pdf')
                        . '?id=' . (int) $document['id']) ?>">PDF</a>
                  <?php endif; ?>
                  <?php if (in_array($document['type'], ['facture_client', 'facture_fournisseur'], true)
                      && in_array($document['statut'], ['emis', 'comptabilise'], true)
                      && $can_manage): ?>
                    <form method="post" action="<?= Html::escape($action) ?>">
                      <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                      <input type="hidden" name="action" value="credit">
                      <input type="hidden" name="onglet" value="documents">
                      <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                      <input type="hidden" name="date" value="<?= Html::escape($today) ?>">
                      <button class="btn btn-sm btn-outline-danger" type="submit">Créer avoir</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($document['type'] === 'facture_client'
                      && in_array($document['statut'], ['emis', 'comptabilise'], true)): ?>
                    <form method="post" action="<?= Html::escape($action) ?>" class="d-flex gap-1">
                      <input type="hidden" name="_csrf" value="<?= Html::escape($csrf) ?>">
                      <input type="hidden" name="action" value="remind">
                      <input type="hidden" name="onglet" value="documents">
                      <input type="hidden" name="document_id" value="<?= (int) $document['id'] ?>">
                      <input type="hidden" name="niveau" value="1">
                      <input type="hidden" name="canal" value="email">
                      <button class="btn btn-sm btn-outline-warning" type="submit">Tracer rappel</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($documents === []): ?>
            <tr><td colspan="7" class="text-body-secondary">Aucun document.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
<?php endif; ?>
