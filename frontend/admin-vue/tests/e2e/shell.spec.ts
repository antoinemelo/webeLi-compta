import { expect, test, type Page } from '@playwright/test';

async function login(page: Page): Promise<void> {
  await page.goto('/e2e/login');
  await page.getByLabel('Adresse e-mail').fill('lecteur@example.test');
  await page.getByLabel('Mot de passe').fill('mot-de-passe-e2e');
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page).toHaveURL(/\/e2e\/app\/?$/);
  await expect(page.getByRole('heading', { name: 'Tableau de bord' })).toBeVisible();
}

async function loginAsAdministrator(page: Page): Promise<void> {
  await page.goto('/e2e/login');
  await page.getByLabel('Adresse e-mail').fill('admin@example.test');
  await page.getByLabel('Mot de passe').fill('mot-de-passe-e2e');
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page).toHaveURL(/\/e2e\/app\/?$/);
}

test('connexion, changement de dossier, route profonde et déconnexion', async ({ page }) => {
  await login(page);

  await page.getByLabel('Dossier', { exact: true }).selectOption({ label: 'Comptabilité principale' });
  await expect(page.locator('.context-band')).toContainText('DOSSIER RÉEL');
  await expect(
    page.getByLabel('Contexte de travail').getByText('Entreprise Alpha SA', { exact: true })
  ).toBeVisible();
  await expect(page.getByText('Chiffre d’affaires', { exact: true })).toBeVisible();
  await expect(page.getByText('Produits comptabilisés', { exact: true }).locator('..')).toContainText(
    /CHF\s+1.*200\.00/
  );
  await expect(page.getByRole('heading', { name: 'Trésorerie par compte' })).toBeVisible();

  await page.getByLabel('Dossier', { exact: true }).selectOption({ label: 'Démonstration guidée' });
  await expect(page.locator('.context-band')).toContainText('DÉMONSTRATION');
  await expect(
    page.getByLabel('Contexte de travail').getByText('École WebeLi', { exact: true })
  ).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Aucune activité à cette date' })).toBeVisible();

  await page.getByRole('link', { name: 'Comptabilité', exact: true }).click();
  await page.getByRole('link', { name: 'États financiers' }).click();
  await expect(page).toHaveURL(/\/e2e\/app\/compta\/etats$/);
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Comptabilité', exact: true })).toBeVisible();

  await page.getByRole('button', { name: 'Déconnexion' }).click();
  await expect(page.getByRole('dialog')).toBeVisible();
  await page.getByRole('button', { name: 'Se déconnecter' }).click();
  await expect(page).toHaveURL(/\/e2e\/login$/);
});

test('refus UI et API sans fuite inter-dossiers', async ({ page }) => {
  await login(page);
  await page.getByLabel('Dossier', { exact: true }).selectOption({ label: 'Comptabilité principale' });

  await page.goto('/e2e/app/configuration');
  await expect(page.getByRole('alert')).toContainText('Accès refusé');

  const result = await page.evaluate(async () => {
    const context = await fetch('/e2e/api/v1/context', {
      headers: { Accept: 'application/json' }
    }).then((response) => response.json());
    const response = await fetch('/e2e/api/v1/context/dossier', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': context.data.csrf_token,
        'X-Contract-Version': 'compta-api-v1'
      },
      body: JSON.stringify({ data: { organisation_id: 2, dossier_id: 2 } })
    });
    return { status: response.status, body: await response.text() };
  });
  expect(result.status).toBe(403);
  expect(result.body).not.toContain('Entreprise Confidentielle SA');
  expect(result.body).not.toContain('Dossier inaccessible');
});

test('navigation clavier et largeur 360 px', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 780 });
  await login(page);
  await page.keyboard.press('Tab');
  await expect(page.getByRole('link', { name: 'Aller au contenu' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'Ouvrir la navigation' })).toBeFocused();
  await page.keyboard.press('Enter');
  await page.getByLabel('Dossier', { exact: true }).selectOption({ label: 'Comptabilité principale' });
  await expect(page.getByText('Chiffre d’affaires', { exact: true })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('apprentissage ciblé, feedback et correction protégée utilisent le moteur comptable', async ({
  page
}) => {
  await login(page);
  await page.getByLabel('Dossier', { exact: true }).selectOption({
    label: 'Atelier débit-crédit'
  });
  await page.getByRole('link', { name: 'Apprentissage', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Apprentissage', exact: true })).toBeVisible();
  await expect(page.getByLabel('Navigation Apprentissage')).toContainText('Catalogue');
  await expect(page.getByText('Débit / crédit', { exact: true })).toBeVisible();
  await expect(page.getByText('TVA', { exact: true })).toBeVisible();
  await expect(page.getByText('Lecture d’états', { exact: true })).toBeVisible();

  await page.getByRole('link', { name: 'Exercices', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Mes exercices' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Vente au comptant' }).last()).toBeVisible();
  await expect(page.getByRole('button', { name: 'Afficher la correction' })).toHaveCount(0);

  await page.getByRole('button', { name: 'Vérifier ma réponse' }).click();
  await expect(page.getByText(/À reprendre\./)).toBeVisible();
  await expect(page.getByText(
    'Recontrôlez le sens de la caisse et du produit.',
    { exact: true }
  ).last()).toBeVisible();

  await page.getByLabel('Écriture à vérifier').selectOption({ index: 1 });
  await page.getByRole('button', { name: 'Vérifier ma réponse' }).click();
  await expect(page.getByText(/Étape validée\./)).toBeVisible();
  await expect(page.getByText(
    'La caisse augmente au débit et le produit au crédit.',
    { exact: true }
  ).last()).toBeVisible();
  await expect(page.getByText('100/100')).toBeVisible();

  await page.getByRole('button', { name: 'Afficher la correction' }).click();
  await expect(page.getByRole('heading', { name: 'Correction autorisée' })).toBeVisible();
  await expect(page.getByText(/Débit 1000 Caisse/)).toBeVisible();

  const legacy = await page.request.get('/e2e/pedagogie', { maxRedirects: 0 });
  expect(legacy.status()).toBe(303);
  expect(legacy.headers().location).toBe('/e2e/app/apprentissage');
});

test('suivi formateur expose assignation, score, contributeurs et export', async ({ page }) => {
  await loginAsAdministrator(page);
  await page.getByLabel('Dossier', { exact: true }).selectOption({
    label: 'Démonstration guidée'
  });
  await page.getByRole('link', { name: 'Apprentissage', exact: true }).click();
  await expect(page.locator('p.eyebrow').filter({
    hasText: 'Lecture d’états'
  })).toBeVisible();
  await page.getByRole('link', { name: 'Suivi', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Tableau de suivi' })).toBeVisible();
  await expect(page.getByRole('row').filter({ hasText: 'Vente au comptant' })).toContainText(
    'lecteur@example.test'
  );
  await expect(page.getByRole('link', { name: 'Exporter en CSV' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Groupes' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Assigner une copie isolée' })).toBeVisible();
});

test('configuration des modules et référentiels', async ({ page }) => {
  test.setTimeout(90_000);
  await loginAsAdministrator(page);
  await page.getByLabel('Dossier', { exact: true }).selectOption({
    label: 'Comptabilité principale'
  });
  await page.getByRole('link', { name: 'Configuration', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Configuration', exact: true })).toBeVisible();
  await expect(page.getByLabel('Navigation Configuration')).toBeVisible();
  await expect(page.getByLabel('IBAN de facturation')).toBeVisible();

  await page.getByRole('link', { name: 'Modules', exact: true }).click();
  const learningCard = page.getByRole('heading', { name: 'Apprentissage' }).locator('..').locator('..');
  await expect(learningCard).toContainText('Actif');
  await learningCard.getByRole('button', { name: 'Désactiver' }).click();
  await expect(learningCard).toContainText('Inactif');
  await expect(
    page.getByLabel('Navigation principale').getByRole('link', { name: 'Apprentissage' })
  ).toHaveCount(0);
  const refusal = await page.evaluate(async () =>
    (await fetch('/e2e/api/v1/pedagogie')).status
  );
  expect(refusal).toBe(403);

  await learningCard.getByRole('button', { name: 'Réactiver' }).click();
  await expect(learningCard).toContainText('Actif');
  await expect(
    page.getByLabel('Navigation principale').getByRole('link', { name: 'Apprentissage' })
  ).toBeVisible();

  await page.getByRole('link', { name: 'Paiements', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Nouvelle condition de paiement' })).toBeVisible();
  await page.getByRole('link', { name: 'Référentiels', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Plan comptable', level: 1 })).toBeVisible();
  await expect(page.getByText('Vue d’ensemble', { exact: true })).toHaveCount(0);
  await expect(
    page.getByLabel('Navigation Configuration').getByRole('link', {
      name: 'Référentiels',
      exact: true
    })
  ).toHaveAttribute('aria-current', 'page');

  const referenceNavigation = page.getByLabel('Référentiels gérés');
  expect(await referenceNavigation.getByRole('link').allTextContents()).toEqual([
    'Plan comptable',
    'Trésorerie',
    'Devises et change',
    'Débiteurs et créanciers',
    'TVA',
    'Charges sociales',
    'Journaux',
    'Exercices et périodes'
  ]);
  await referenceNavigation.getByRole('link', {
    name: 'Devises et change',
    exact: true
  }).click();
  await expect(page).toHaveURL(/\/configuration\/referentiels\/currencies$/);
  await expect(page.getByRole('heading', { name: 'Devises autorisées' })).toBeVisible();
  await expect(page.getByRole('heading', {
    name: 'Comptes des différences de change'
  })).toBeVisible();
  await expect(page.getByRole('link', {
    name: 'Devises et change',
    exact: true
  })).toHaveAttribute('aria-current', 'page');

  await page.getByRole('link', { name: 'Plan comptable', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Plan comptable', level: 1 })).toBeVisible();

  await page.getByRole('link', { name: 'Trésorerie', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Nouveau compte de trésorerie' })).toBeVisible();

  await page.getByRole('link', { name: 'Débiteurs et créanciers' }).click();
  await expect(page.getByRole('heading', { name: 'Nouveau débiteur ou créancier' })).toBeVisible();

  await page.getByRole('link', { name: 'TVA', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Nouveau code TVA' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Taux TVA suisses' })).toBeVisible();
  const vatRow = page.getByRole('row').filter({ hasText: 'VE81' });
  await vatRow.getByRole('button', { name: 'Modifier' }).click();
  await expect(page.getByRole('heading', { name: 'Modifier le code TVA' })).toBeVisible();
  await page.getByLabel('Libellé').fill('Ventes E2E modifiées');
  await page.getByRole('button', { name: 'Enregistrer les modifications' }).click();
  await expect(vatRow).toContainText('Ventes E2E modifiées');
  await vatRow.getByRole('button', { name: 'Désactiver' }).click();
  await expect(vatRow).toContainText('Inactif');
  await vatRow.getByRole('button', { name: 'Réactiver' }).click();
  await expect(vatRow).toContainText('Actif');
  await vatRow.getByRole('button', { name: 'Modifier' }).click();
  await page.getByLabel('Libellé').fill('Ventes 8,1 %');
  await page.getByRole('button', { name: 'Enregistrer les modifications' }).click();

  await page.getByRole('link', { name: 'Charges sociales' }).click();
  await expect(page.getByRole('heading', { name: 'Taux annuels des charges sociales' })).toBeVisible();
  await expect(page.getByText(/Salaires → Annuels/)).toBeVisible();
  await expect(page.getByRole('button', { name: 'Charger les valeurs OCAS 2026' })).toHaveCount(0);

  await page.getByRole('link', { name: 'Journaux' }).click();
  await expect(page.getByRole('heading', { name: 'Nouveau journal' })).toBeVisible();
  await page.getByRole('link', { name: 'Exercices et périodes' }).click();
  await expect(page.getByRole('heading', { name: 'Nouvel exercice comptable' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Nouvelle période' })).toBeVisible();

  const configurationNavigation = page.getByLabel('Navigation Configuration');
  expect(await configurationNavigation.getByRole('link').allTextContents()).toEqual([
    'Entité', 'Modules', 'Paiements', 'Référentiels', 'Salaires', 'Accès', 'Audit'
  ]);
  await configurationNavigation.getByRole('link', { name: 'Salaires', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Paramètres salariaux' })).toBeVisible();
  await expect(page.getByLabel('Employeur')).toHaveValue('Entreprise Alpha SA');
  await expect(page.getByLabel('Employeur')).toHaveAttribute('readonly', '');
  await expect(page.getByText(/Configuration → Entité/)).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Comptes de salaires' })).toBeVisible();
  await page.getByRole('button', {
    name: 'Enregistrer les paramètres employeur'
  }).click();
  await expect(page.getByText(
    'Paramètres de l’employeur salarial enregistrés.'
  )).toBeVisible();
  await page.getByRole('button', {
    name: 'Enregistrer les comptes de salaires'
  }).click();
  await expect(page.getByText(
    'Mapping comptable des salaires enregistré.'
  )).toBeVisible();

  await page.getByRole('link', { name: 'Accès', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Rôles du dossier' })).toBeVisible();
});

test('journal, extrait et plan comptable de Configuration utilisent le parcours Vue unique', async ({
  page
}) => {
  await loginAsAdministrator(page);
  await page.getByLabel('Dossier', { exact: true }).selectOption({
    label: 'Comptabilité principale'
  });

  await page.getByRole('link', { name: 'Comptabilité', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Nouvelle écriture' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Écritures récentes' })).toBeVisible();

  await page.getByRole('link', { name: 'Extraits', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Extrait de compte' })).toBeVisible();

  await page.getByRole('link', { name: 'Configuration', exact: true }).click();
  await page.getByRole('link', { name: 'Référentiels', exact: true }).click();
  await expect(page).toHaveURL(/\/e2e\/app\/configuration\/referentiels\/plan$/);
  await expect(page.getByRole('heading', { name: 'Plan comptable', level: 1 })).toBeVisible();
  await expect(page.getByLabel('Sections du plan comptable')).toBeVisible();

  const legacy = await page.request.get('/e2e/compta/plan', { maxRedirects: 0 });
  expect(legacy.status()).toBe(303);
  expect(legacy.headers().location).toBe('/e2e/app/configuration/referentiels/plan');
});

test('états, clôture et dossier fiscal utilisent le grand livre unique', async ({ page }) => {
  await loginAsAdministrator(page);
  await page.getByLabel('Dossier', { exact: true }).selectOption({
    label: 'Comptabilité principale'
  });
  await page.getByRole('link', { name: 'Comptabilité', exact: true }).click();

  await page.getByRole('link', { name: 'États financiers', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'États financiers' })).toBeVisible();
  await expect(page.getByText('Débit = crédit')).toBeVisible();
  await expect(page.getByText('Résultat réconcilié')).toBeVisible();
  await page.getByRole('button', { name: 'Flux de trésorerie' }).click();
  await expect(page.getByRole('heading', { name: 'Flux de trésorerie' })).toBeVisible();
  await expect(page.getByText(/Méthode directe/)).toBeVisible();

  await page.getByRole('link', { name: 'Clôture', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Clôture et verrouillage' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Contrôles documentés' })).toBeVisible();

  await page.getByRole('link', { name: 'Dossier fiscal', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Dossier fiscal' })).toBeVisible();
  await expect(page.getByText(/aucun calcul fiscal officiel/i)).toBeVisible();

  const legacy = await page.request.get('/e2e/compta/bilan', { maxRedirects: 0 });
  expect(legacy.status()).toBe(303);
  expect(legacy.headers().location).toBe('/e2e/app/compta/etats');
});

test('registre, plan et dotation des immobilisations utilisent le grand livre unique', async ({ page }) => {
  await loginAsAdministrator(page);
  await page.getByLabel('Dossier', { exact: true }).selectOption({
    label: 'Comptabilité principale'
  });
  await page.getByRole('link', { name: 'Comptabilité', exact: true }).click();
  await page.getByRole('link', { name: 'Amortissements', exact: true }).click();
  await expect(page.getByRole('heading', {
    name: 'Immobilisations et amortissements'
  })).toBeVisible();

  await page.getByRole('button', { name: 'Catégories', exact: true }).click();
  await page.getByLabel('Code', { exact: true }).fill('INFO-E2E');
  await page.getByLabel('Libellé', { exact: true }).fill('Informatique E2E');
  await page.getByLabel('Durée proposée (mois)').fill('36');
  for (const [label, account] of [
    ['Compte d’actif', '1520'],
    ['Amortissements cumulés', '1529'],
    ['Dotation', '6800'],
    ['Gain de cession', '8510'],
    ['Perte de cession', '8500']
  ] as const) {
    const select = page.getByRole('combobox', { name: label, exact: true });
    await expect(select).toContainText(account);
    const value = await select.evaluate((element, accountCode) => {
      const option = Array.from((element as HTMLSelectElement).options)
        .find((candidate) => candidate.textContent?.trim().startsWith(accountCode));
      return option?.value ?? '';
    }, account);
    expect(value).not.toBe('');
    await select.selectOption(String(value));
  }
  await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
  await expect(page.getByRole('row').filter({ hasText: 'INFO-E2E' })).toBeVisible();

  await page.getByRole('button', { name: 'Registre', exact: true }).click();
  await page.getByRole('combobox', { name: 'Catégorie', exact: true }).selectOption({
    label: 'INFO-E2E — Informatique E2E'
  });
  await page.getByLabel('Code', { exact: true }).fill('PC-E2E');
  await page.getByLabel('Libellé', { exact: true }).fill('Poste de test E2E');
  await page.getByLabel('Référence de pièce').fill('FAC-PC-E2E');
  await page.getByLabel('Date d’acquisition').fill('2026-07-10');
  await page.getByLabel('Mise en service').fill('2026-07-15');
  await page.getByLabel('Valeur d’acquisition').fill('2400.01');
  await page.getByLabel('Valeur résiduelle').fill('0.01');
  await page.getByRole('button', { name: 'Créer la fiche et le plan' }).click();
  const assetRow = page.getByRole('row').filter({ hasText: 'PC-E2E' });
  await expect(assetRow).toContainText(/CHF 2\s400\.01/);
  await assetRow.getByRole('button', { name: 'Ouvrir' }).click();
  await expect(page.getByRole('heading', {
    name: 'PC-E2E — Poste de test E2E'
  })).toBeVisible();
  await page.getByRole('button', { name: 'Comptabiliser', exact: true }).first().click();
  await expect(page.getByText('Dotation comptabilisée dans le grand livre.')).toBeVisible();

  await page.getByRole('button', { name: 'Registre', exact: true }).click();
  await assetRow.getByRole('button', { name: 'Corriger' }).click();
  await page.getByRole('button', { name: 'Enregistrer la correction' }).click();
  await expect(page.getByText(/corrige par contre-passation/i)).toBeVisible();
});

test('balance consolidée drillable et refus de mutation sans droit sur chaque membre', async ({
  page
}) => {
  await loginAsAdministrator(page);
  await page.getByLabel('Dossier', { exact: true }).selectOption({
    label: 'Comptabilité principale'
  });
  await page.getByRole('link', { name: 'Comptabilité', exact: true }).click();
  await page.getByRole('link', { name: 'Consolidation', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Consolidation', exact: true })).toBeVisible();
  await expect(page.getByText('Formule vérifiée', { exact: true })).toBeVisible();
  const revenueRow = page.getByRole('row').filter({ hasText: '3400 — Produits' });
  await expect(revenueRow).toContainText(/CHF.*1.*200\.00/);
  await revenueRow.getByText('1 balance(s) source(s)').click();
  await expect(revenueRow.getByText(/Entreprise Alpha SA.*Comptabilité principale/)).toBeVisible();
  await expect(page.getByRole('button', { name: 'Exporter la piste JSON' })).toBeEnabled();

  await page.getByRole('button', { name: 'Groupe et mappings' }).click();
  await expect(page.getByRole('heading', { name: 'Membres du groupe' })).toBeVisible();
  await expect(page.getByRole('row').filter({ hasText: 'Entreprise Alpha SA' })).toContainText(
    'CHF'
  );

  await page.getByRole('button', { name: 'Déconnexion' }).click();
  await page.getByRole('button', { name: 'Se déconnecter' }).click();
  await login(page);
  await page.getByLabel('Dossier', { exact: true }).selectOption({
    label: 'Comptabilité principale'
  });
  await page.goto('/e2e/app/compta/consolidation');
  await page.getByRole('button', { name: 'Groupe et mappings' }).click();
  await expect(page.getByRole('button', { name: 'Créer', exact: true })).toBeDisabled();

  const refusal = await page.evaluate(async () => {
    const context = await fetch('/e2e/api/v1/context').then((response) => response.json());
    return fetch('/e2e/api/v1/consolidation/groups', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': context.data.csrf_token,
        'X-Contract-Version': 'compta-api-v1'
      },
      body: JSON.stringify({
        data: {
          code: 'REFUSE',
          label: 'Refusé',
          currency: 'CHF',
          valid_from: '2026-01-01'
        }
      })
    }).then((response) => response.status);
  });
  expect(refusal).toBe(403);
});

test('facturation client, contact 360 et aging utilisent le parcours Vue unique', async ({ page }) => {
  await loginAsAdministrator(page);
  await page.getByLabel('Dossier', { exact: true }).selectOption({
    label: 'Comptabilité principale'
  });
  await page.getByRole('link', { name: 'Facturation', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Factures clients' })).toBeVisible();
  await expect(page.getByLabel('Navigation de la facturation')).toContainText('Récurrences');
  await expect(page.getByLabel('Date de référence')).toBeVisible();

  await page.getByRole('link', { name: 'Contacts', exact: true }).click();
  await page.getByRole('button', { name: 'Nouveau contact' }).click();
  await page.getByLabel('Raison sociale').fill('Client E2E SA');
  await page.getByLabel('Courriel').fill('client-e2e@example.test');
  await page.getByLabel('Adresse').fill('Rue du Client 1');
  await page.getByLabel('NPA').fill('1200');
  await page.getByLabel('Localité').fill('Genève');
  await page.getByRole('button', { name: 'Ajouter au registre' }).click();
  await expect(page.getByRole('row').filter({ hasText: 'Client E2E SA' })).toBeVisible();

  await page.getByRole('link', { name: 'Ventes', exact: true }).click();
  await page.getByRole('button', { name: 'Nouveau document' }).click();
  await page.getByLabel('Client').selectOption({ label: 'Client E2E SA' });
  await page.getByLabel('Date du document').fill('2026-07-20');
  await page.getByLabel('Échéance explicite').fill('2026-07-26');
  const collective = page.getByLabel('Compte collectif');
  const receivableValue = await collective.locator('option').filter({ hasText: '1100' })
    .getAttribute('value');
  await collective.selectOption(String(receivableValue));
  await page.getByLabel('Libellé', { exact: true }).fill('Prestation E2E');
  await page.getByLabel('Montant', { exact: true }).fill('100.00');
  const revenue = page.getByLabel('Compte', { exact: true });
  const revenueValue = await revenue.locator('option').filter({ hasText: '3400' })
    .getAttribute('value');
  await revenue.selectOption(String(revenueValue));
  await page.getByRole('button', { name: 'Enregistrer le brouillon' }).click();
  expect(await page.getByLabel('Code TVA').evaluate((element) =>
    (element as HTMLSelectElement).matches(':invalid')
  )).toBe(true);
  await page.getByLabel('Code TVA').selectOption({ label: 'VE81 · Ventes 8,1 %' });
  await page.getByRole('button', { name: 'Enregistrer le brouillon' }).click();
  const invoice = page.getByRole('row').filter({ hasText: 'Client E2E SA' });
  await expect(invoice).toContainText('108.10 CHF');
  await invoice.getByRole('button', { name: 'Émettre' }).click();
  await expect(invoice).toContainText(/F-2026-/);
  await invoice.getByRole('button', { name: 'Comptabiliser' }).click();
  await expect(page.getByText('Document comptabilisé dans le grand livre.')).toBeVisible();

  await page.getByRole('link', { name: 'Échéancier', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Échéancier et lettrage' })).toBeVisible();
  await expect(page.getByText('Créances nettes', { exact: true })).toBeVisible();
  await page.getByRole('link', { name: 'Récurrences', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Factures récurrentes' })).toBeVisible();

  const legacy = await page.request.get('/e2e/facturation', { maxRedirects: 0 });
  expect(legacy.status()).toBe(303);
  expect(legacy.headers().location).toBe('/e2e/app/facturation');
});

test('dépense fournisseur approuvée et comptabilisée dans Vue', async ({ page }) => {
  await loginAsAdministrator(page);
  await page.getByLabel('Dossier', { exact: true }).selectOption({
    label: 'Comptabilité principale'
  });
  await page.getByRole('link', { name: 'Liquidités', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Utilisation des liquidités' })).toBeVisible();
  await page.getByRole('button', { name: 'Nouvelle dépense' }).click();
  await page.getByLabel('Fournisseur', { exact: true }).selectOption({
    label: 'Fournitures E2E SA'
  });
  await page.getByLabel('Numéro fournisseur').fill('E2E-DEP-001');
  await page.getByLabel('Date du document').fill('2026-07-20');
  await page.getByLabel('Échéance').fill('2026-08-19');
  const payable = page.getByLabel('Compte collectif fournisseur');
  const payableValue = await payable.locator('option').filter({ hasText: '2000' }).getAttribute('value');
  await payable.selectOption(String(payableValue));
  await expect(page.getByLabel('Justificatif facultatif')).toBeVisible();
  await page.getByLabel('Libellé', { exact: true }).fill('Fournitures de bureau');
  await page.getByLabel('Montant', { exact: true }).fill('100.00');
  const expenseAccount = page.getByLabel('Compte de charge');
  const expenseValue = await expenseAccount.locator('option').filter({ hasText: '6500' }).getAttribute('value');
  await expenseAccount.selectOption(String(expenseValue));
  await page.getByLabel('Code TVA').selectOption({ label: 'AM81 · Achats 8,1 %' });
  await page.getByRole('button', { name: 'Enregistrer le brouillon' }).click();
  const row = page.getByRole('row').filter({ hasText: 'Fournitures E2E SA' });
  await expect(row).toContainText('108.10 CHF');
  await expect(row).toContainText('Brouillon');
  await row.getByRole('button', { name: 'Soumettre' }).click();
  await expect(row).toContainText('À approuver');
  await row.getByRole('button', { name: 'Approuver' }).click();
  await expect(row).toContainText('Approuvé');
  await row.getByRole('button', { name: 'Comptabiliser' }).click();
  await expect(row).toContainText('Comptabilisé');
});

test('rapprochement, lettrage et paiements sortants utilisent le parcours Vue', async ({ page }) => {
  await loginAsAdministrator(page);
  await page.getByLabel('Dossier', { exact: true }).selectOption({
    label: 'Comptabilité principale'
  });
  await page.getByRole('link', { name: 'Liquidités', exact: true }).click();
  await page.getByRole('link', { name: 'Rapprochement', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Rapprochement bancaire' })).toBeVisible();
  const bankSelect = page.getByLabel('Compte bancaire');
  const bankValue = await bankSelect.locator('option').filter({
    hasText: 'Banque principale'
  }).getAttribute('value');
  await bankSelect.selectOption(String(bankValue));
  await page.getByLabel('Relevé CAMT ou PostFinance').setInputFiles({
    name: 'postfinance-e2e.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from(
      [
        'Date de début:;="01.07.2026"',
        'Date de fin:;="31.07.2026"',
        'Genre:;="Compte commercial"',
        'Compte:;="CH9300762011623852957"',
        'Monnaie:;="CHF"',
        '',
        'Date;Texte de notification;Crédit en CHF;Débit en CHF;Valeur;Solde en CHF',
        '',
        '26.07.2026;"FRAIS BANCAIRES";;-5;26.07.2026;495',
        'Disclaimer:'
      ].join('\n')
    )
  });
  await page.getByRole('button', { name: 'Prévisualiser' }).click();
  await expect(page.getByRole('heading', {
    name: 'Prévisualisation sans comptabilisation'
  })).toBeVisible();
  await expect(page.getByText('postfinance_csv', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Confirmer l’import' }).click();
  await expect(page.getByText('Relevé confirmé, source et empreinte conservées.')).toBeVisible();
  await expect(page.getByRole('button', { name: 'Confirmer le rapprochement' })).toBeDisabled();

  await page.getByRole('link', { name: 'Lettrage', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Lettrage des paiements' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Nouveau paiement' })).toBeVisible();

  await page.getByRole('link', { name: 'Paiements', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Paiements sortants' })).toBeVisible();
  await expect(page.getByText('export pain.001 non transmis', { exact: false })).toBeVisible();
});

test('salaires horaires et mensuels utilisent le parcours Vue et l’import OCAS contrôlé', async ({
  page
}) => {
  await loginAsAdministrator(page);
  await page.getByLabel('Dossier', { exact: true }).selectOption({
    label: 'Comptabilité principale'
  });
  await page.getByRole('link', { name: 'Salaires', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Salaires', exact: true })).toBeVisible();
  await expect(page.getByLabel('Navigation des salaires')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Employés', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Historique des contrats' })).toBeVisible();
  await expect(page.getByText('PII autorisées', { exact: true })).toBeVisible();

  const employeesPanel = page.locator('section.panel').filter({
    has: page.getByRole('heading', { name: 'Employés', exact: true })
  });
  const adaEmployee = employeesPanel.getByRole('row').filter({ hasText: 'Ada Martin' });
  await adaEmployee.getByRole('button', { name: 'Modifier' }).click();
  await expect(page.getByRole('heading', { name: 'Modifier l’employé' })).toBeVisible();
  await page.getByLabel('E-mail', { exact: true }).fill('ada.modifiee@example.test');
  await page.getByRole('button', { name: 'Enregistrer les modifications' }).click();
  await expect(page.getByText('Données de l’employé mises à jour.')).toBeVisible();
  await expect(employeesPanel.getByText('ada.modifiee@example.test')).toBeVisible();

  const contractsPanel = page.locator('section.panel').filter({
    has: page.getByRole('heading', { name: 'Historique des contrats' })
  });
  const adaContract = contractsPanel.getByRole('row').filter({ hasText: 'Ada Martin' });
  await adaContract.getByRole('button', { name: 'Modifier' }).click();
  await expect(page.getByRole('heading', { name: 'Modifier le contrat' })).toBeVisible();
  await page.getByLabel('Source', { exact: true }).fill('Contrat mensuel E2E corrigé');
  await page.getByRole('button', { name: 'Enregistrer les modifications' }).click();
  await expect(page.getByText('Contrat mis à jour.')).toBeVisible();

  const temporaryContract = contractsPanel.getByRole('row').filter({
    hasText: 'Jean Temporaire'
  });
  page.once('dialog', (dialog) => dialog.accept());
  await temporaryContract.getByRole('button', { name: 'Supprimer' }).click();
  await expect(page.getByText('Contrat non utilisé supprimé.')).toBeVisible();
  await expect(temporaryContract).toHaveCount(0);

  const temporaryEmployee = employeesPanel.getByRole('row').filter({
    hasText: 'Jean Temporaire'
  });
  page.once('dialog', (dialog) => dialog.accept());
  await temporaryEmployee.getByRole('button', { name: 'Supprimer' }).click();
  await expect(page.getByText('Employé et contrats non utilisés supprimés.')).toBeVisible();
  await expect(temporaryEmployee).toHaveCount(0);

  await page.getByRole('link', { name: 'Calculs', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Préparer une fiche de salaire' })).toBeVisible();
  await expect(page.getByRole('group', { name: /Employé et période/ })).toBeVisible();
  await expect(page.getByRole('group', {
    name: /Contrat appliqué automatiquement/
  })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Brouillons et calculs 2026' })).toBeVisible();
  await expect(page.getByRole('button', {
    name: 'Calculer et créer le brouillon'
  })).toBeEnabled();
  await page.getByRole('button', { name: 'Aperçu', exact: true }).click();
  const preview = page.getByRole('dialog', { name: 'Fiche de salaire 07/2026' });
  await expect(preview).toBeVisible();
  await expect(preview.getByText('BROUILLON — À CONTRÔLER')).toBeVisible();
  await expect(preview.getByRole('heading', { name: 'Base salariale' })).toBeVisible();
  await expect(preview.getByText('Prime exceptionnelle')).toBeVisible();
  await expect(preview.getByText('Salaire net à verser')).toBeVisible();
  await expect(preview.getByRole('button', { name: 'Valider cette fiche' })).toBeEnabled();
  await preview.getByRole('button', { name: 'Fermer' }).click();

  await page.getByRole('link', { name: 'Fiches', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Fiches de salaire' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Voir et valider' })).toBeVisible();
  await page.getByRole('link', { name: 'Annuels', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Récapitulatifs et certificats' })).toBeVisible();
  await expect(page.getByRole('heading', {
    name: 'Paramétrage employeur et comptes'
  })).toHaveCount(0);

  await page.getByRole('button', { name: 'Prévisualiser sans écrire' }).click();
  await expect(page.getByText('Source OCAS absente : aucun millésime n’est inventé.')).toBeVisible();

  const legacy = await page.request.get('/e2e/salaires', { maxRedirects: 0 });
  expect(legacy.status()).toBe(303);
  expect(legacy.headers().location).toBe('/e2e/app/salaires');
});
