import { expect, test, type Locator, type Page } from '@playwright/test';

async function chooseAccount(
  control: Locator,
  query: string,
  commit: 'Enter' | 'Tab' = 'Enter'
): Promise<void> {
  await control.fill(query);
  await control.press(commit);
  await expect(control).toHaveValue(new RegExp(query, 'i'));
}

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

async function openScopeMenu(page: Page): Promise<void> {
  const button = page.getByRole('button', {
    name: 'Organisation, dossier et configuration'
  });
  if (await button.getAttribute('aria-expanded') !== 'true') await button.click();
}

async function selectDossier(page: Page, label: string): Promise<void> {
  await openScopeMenu(page);
  const organization = page.getByLabel('Organisation', { exact: true });
  const dossier = page.getByLabel('Dossier', { exact: true });
  const organizationValues = await organization.locator('option:not([disabled])').evaluateAll(
    (options) => options.map((option) => (option as HTMLOptionElement).value)
  );
  for (const value of organizationValues) {
    if (await dossier.locator('option').filter({ hasText: label }).count()) break;
    await organization.selectOption(value);
  }
  await dossier.selectOption({ label });
}

async function openConfiguration(page: Page): Promise<void> {
  await openScopeMenu(page);
  await page.getByRole('link', { name: 'Configuration', exact: true }).click();
}

async function logout(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Informations personnelles' }).click();
  await page.getByRole('button', { name: 'Déconnexion' }).click();
  await page.getByRole('button', { name: 'Se déconnecter' }).click();
}

test('connexion, changement de dossier, route profonde et déconnexion', async ({ page }) => {
  await login(page);

  await expect(
    page.getByRole('link', { name: 'Ouvrir le tableau de bord' })
  ).toContainText(/WebeLi.*Compta/);
  const scopeButton = page.getByRole('button', {
    name: 'Organisation, dossier et configuration'
  });
  await expect(scopeButton).toHaveCSS('color', 'rgb(255, 255, 255)');
  await expect(scopeButton).toHaveCSS('background-color', 'rgba(0, 0, 0, 0)');
  expect(await scopeButton.evaluate((button) => {
    const icon = button.querySelector('svg');
    if (!icon) return false;
    const buttonBox = button.getBoundingClientRect();
    const iconBox = icon.getBoundingClientRect();
    return iconBox.width === buttonBox.width && iconBox.height === buttonBox.height;
  })).toBe(true);
  await expect(scopeButton.locator('path')).toHaveAttribute(
    'd',
    /M8 16A8 8 0 1 0/
  );
  await openScopeMenu(page);
  await expect(page.getByRole('link', {
    name: 'Organisations et dossiers',
    exact: true
  })).toBeVisible();

  await selectDossier(page, 'Comptabilité principale');
  await expect(
    page.getByRole('link', { name: 'Ouvrir le tableau de bord' })
  ).toContainText(/Entreprise Alpha SA.*Comptabilité principale.*2026.*CHF/);
  await expect(page.locator('.context-band')).toHaveCount(0);
  await expect(
    page.getByLabel('Navigation principale').getByRole('link', {
      name: 'Tableau de bord',
      exact: true
    })
  ).toHaveCount(0);
  await expect(
    page.getByLabel('Navigation principale').getByRole('link', {
      name: 'Configuration',
      exact: true
    })
  ).toHaveCount(0);
  await expect(page.getByText('Chiffre d’affaires', { exact: true })).toBeVisible();
  await expect(page.getByText('Produits comptabilisés', { exact: true }).locator('..')).toContainText(
    /CHF\s+1.*200\.00/
  );
  await expect(page.getByRole('heading', { name: 'Trésorerie par compte' })).toBeVisible();
  await expect(page.getByText('Une lecture du grand livre')).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Aging des créances et dettes' })).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Dernières écritures' })).toHaveCount(0);

  await selectDossier(page, 'Démonstration guidée');
  await expect(
    page.getByRole('link', { name: 'Ouvrir le tableau de bord' })
  ).toContainText('École WebeLi');
  await expect(page.getByText(/DÉMONSTRATION — DONNÉES FICTIVES/)).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Aucune activité à cette date' })).toBeVisible();

  await page.getByRole('link', { name: 'Comptabilité', exact: true }).click();
  await page.getByRole('link', { name: 'États financiers' }).click();
  await expect(page).toHaveURL(/\/e2e\/app\/compta\/etats$/);
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Comptabilité', exact: true })).toBeVisible();

  await logout(page);
  await expect(page).toHaveURL(/\/e2e\/login$/);
});

test('refus UI et API sans fuite inter-dossiers', async ({ page }) => {
  await login(page);
  await selectDossier(page, 'Comptabilité principale');

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
  await selectDossier(page, 'Comptabilité principale');
  await expect(page.getByText('Chiffre d’affaires', { exact: true })).toBeVisible();
  await page.getByRole('link', { name: 'Liquidités', exact: true }).click();
  const tabs = page.getByLabel('Navigation des liquidités');
  await expect(tabs).toBeVisible();
  expect(await tabs.evaluate((element) => {
    const style = getComputedStyle(element);
    return {
      position: style.position,
      flexWrap: style.flexWrap,
      overflowX: style.overflowX
    };
  })).toEqual({ position: 'sticky', flexWrap: 'wrap', overflowX: 'visible' });
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  const tabsBox = await tabs.boundingBox();
  expect(tabsBox?.y).toBeGreaterThanOrEqual(68);
  expect(tabsBox?.y).toBeLessThan(180);
  expect((tabsBox?.y || 0) + (tabsBox?.height || 0)).toBeLessThan(780);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('apprentissage ciblé, feedback et correction protégée utilisent le moteur comptable', async ({
  page
}) => {
  await login(page);
  await selectDossier(page, 'Atelier débit-crédit');
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
  await selectDossier(page, 'Démonstration guidée');
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
  await selectDossier(page, 'Comptabilité principale');
  await openConfiguration(page);
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
  await expect(page.getByRole('heading', { name: 'Configuration', level: 1 })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Plan comptable', level: 2 })).toBeVisible();
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
  await expect(page.getByRole('heading', { name: 'Configuration', level: 1 })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Plan comptable', level: 2 })).toBeVisible();

  await page.getByRole('link', { name: 'Trésorerie', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Configuration', level: 1 })).toBeVisible();
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
    'Entité',
    'Modules',
    'Paiements',
    'Référentiels',
    'Salaires',
    'Accès',
    'Audit'
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
  await expect(page.getByRole('heading', { name: 'Accès aux structures' })).toBeVisible();
});

test('registre des organisations : création, historique et cycle de vie', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 900 });
  await loginAsAdministrator(page);
  await page.goto('/e2e/app/organisations-dossiers');
  await expect(page.getByRole('button', {
    name: 'Ouvrir la navigation'
  })).toHaveCSS('border-radius', '999px');
  await expect(page.getByRole('heading', {
    name: 'Organisations et dossiers',
    exact: true
  })).toBeVisible();

  await page.getByRole('button', { name: 'Créer une organisation' }).click();
  const creation = page.locator('form').filter({
    has: page.getByRole('heading', { name: 'Créer une organisation' })
  });
  const unsafeOrganisationName = 'Coopérative <script>window.compromised=true</script>';
  await creation.getByLabel('Nom usuel').fill(unsafeOrganisationName);
  await creation.getByLabel('Raison sociale').fill('Coopérative E2E SA');
  await creation.getByLabel('Source').fill('Extrait RC E2E');
  await creation.getByLabel('Forme juridique').fill('SA');
  await creation.getByLabel('IDE / UID').fill('CHE-555.444.333');
  await creation.getByRole('button', { name: 'Créer', exact: true }).click();

  await expect(page.getByRole('heading', {
    name: unsafeOrganisationName,
    exact: true
  })).toBeVisible();
  await expect(page.locator('script').filter({
    hasText: 'window.compromised=true'
  })).toHaveCount(0);
  expect(await page.evaluate(() => (
    window as Window & { compromised?: boolean }
  ).compromised)).toBeUndefined();
  await page.getByRole('button', { name: 'Informations', exact: true }).click();
  await expect(page.getByText('Extrait RC E2E', { exact: false })).toBeVisible();
  await page.getByLabel('Nom usuel').last().fill('Coopérative E2E Groupe');
  await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
  await expect(page.getByRole('heading', {
    name: 'Coopérative E2E Groupe',
    exact: true
  })).toBeVisible();

  await page.getByRole('button', { name: 'Archiver', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Réactiver', exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Réactiver', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Archiver', exact: true })).toBeVisible();
  await expect(page.getByText('Suppression protégée')).toBeVisible();
  await expect(page.getByRole('button', {
    name: 'Supprimer définitivement'
  })).toBeDisabled();

  await page.getByRole('button', { name: 'Créer une organisation' }).click();
  const emptyCreation = page.locator('form').filter({
    has: page.getByRole('heading', { name: 'Créer une organisation' })
  });
  await emptyCreation.getByLabel('Nom usuel').fill('Bac à sable E2E');
  await emptyCreation.getByLabel('Nature').selectOption('pedagogique');
  await emptyCreation.getByRole('button', { name: 'Créer', exact: true }).click();
  await expect(page.getByRole('heading', {
    name: 'Bac à sable E2E',
    exact: true
  })).toBeVisible();

  await page.getByRole('button', { name: 'Informations', exact: true }).click();
  await page.getByRole('button', { name: 'Supprimer définitivement' }).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toContainText('organisations vides');
  await dialog.getByRole('button', { name: 'Supprimer', exact: true }).click();
  await expect(page.getByRole('heading', {
    name: 'Bac à sable E2E',
    exact: true
  })).toHaveCount(0);
  await expect(page.getByText(
    'Organisation vide supprimée. Son audit est conservé.'
  )).toBeVisible();
});

test('deux dossiers réels sont créés, sélectionnés et archivés depuis Vue', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 900 });
  await loginAsAdministrator(page);
  await page.goto('/e2e/app/organisations-dossiers');

  await page.getByRole('button', { name: 'Créer une organisation' }).click();
  const organizationForm = page.locator('form').filter({
    has: page.getByRole('heading', { name: 'Créer une organisation' })
  });
  await organizationForm.getByLabel('Nom usuel').fill('Multi-dossiers E2E');
  await organizationForm.getByLabel('Nature').selectOption('pedagogique');
  await organizationForm.getByRole('button', { name: 'Créer', exact: true }).click();
  await expect(page.getByRole('heading', {
    name: 'Dossiers de Multi-dossiers E2E'
  })).toBeVisible();

  const createDossier = async (
    name: string,
    slug: string,
    currency: string,
    journalCode: string
  ): Promise<void> => {
    await page.getByRole('button', { name: 'Créer un dossier' }).click();
    const wizard = page.locator('form').filter({
      has: page.getByRole('heading', { name: 'Initialiser un dossier' })
    });
    await wizard.getByLabel('Nom').fill(name);
    await wizard.getByLabel('Slug unique').fill(slug);
    await wizard.getByLabel('Devise de base').fill(currency);
    await wizard.getByLabel('Code du journal général').fill(journalCode);
    await wizard.getByRole('button', { name: 'Créer et initialiser' }).click();
    await expect(page.getByText('Dossier créé et initialisé atomiquement.')).toBeVisible();
    await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
  };

  await createDossier('Comptabilité A E2E', 'comptabilite-a-e2e', 'CHF', 'ODA');
  await expect(page.getByText(/comptes/).last()).toBeVisible();
  await openScopeMenu(page);
  await page.getByLabel('Organisation', { exact: true }).selectOption({
    label: 'Multi-dossiers E2E'
  });
  await expect(page.getByLabel('Dossier', { exact: true })).toContainText(
    'Comptabilité A E2E'
  );
  await page.getByRole('button', {
    name: 'Organisation, dossier et configuration'
  }).click();

  await createDossier('Comptabilité B E2E', 'comptabilite-b-e2e', 'EUR', 'ODB');
  await openScopeMenu(page);
  await page.getByLabel('Organisation', { exact: true }).selectOption({
    label: 'Multi-dossiers E2E'
  });
  await expect(page.getByLabel('Dossier', { exact: true })).toContainText(
    'Comptabilité B E2E'
  );
  await page.getByRole('button', {
    name: 'Organisation, dossier et configuration'
  }).click();
  await expect(page.getByRole('button', { name: /Comptabilité A E2E/ })).toBeVisible();
  await expect(page.getByRole('button', { name: /Comptabilité B E2E/ })).toBeVisible();

  await page.getByRole('button', { name: /Comptabilité A E2E/ }).click();
  await page.getByRole('button', { name: 'Ouvrir la navigation' }).click();
  await selectDossier(page, 'Comptabilité A E2E');
  await page.getByRole('button', { name: 'Ouvrir la navigation' }).click();
  await page.getByRole('button', { name: 'Archiver le dossier' }).click();
  await expect(page.getByRole('button', { name: 'Réactiver le dossier' })).toBeVisible();
  await openScopeMenu(page);
  await expect(page.getByLabel('Dossier', { exact: true })).not.toContainText(
    'Comptabilité A E2E'
  );
  await page.getByRole('button', {
    name: 'Organisation, dossier et configuration'
  }).click();

  await page.getByRole('button', { name: 'Supprimer le dossier vide' }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByRole('button', { name: 'Supprimer', exact: true }).click();
  await expect(page.getByRole('button', { name: /Comptabilité A E2E/ })).toHaveCount(0);
});

test('gouvernance des accès et révocation multi-session', async ({ browser }) => {
  const adminContext = await browser.newContext();
  const readerContext = await browser.newContext();
  const adminPage = await adminContext.newPage();
  const readerPage = await readerContext.newPage();
  try {
    await login(readerPage);
    await selectDossier(readerPage, 'Comptabilité principale');
    await openScopeMenu(readerPage);
    await expect(readerPage.getByLabel('Dossier', { exact: true })).toHaveValue(/\d+/);
    await readerPage.getByRole('button', {
      name: 'Organisation, dossier et configuration'
    }).click();

    await loginAsAdministrator(adminPage);
    await adminPage.goto('/e2e/app/organisations-dossiers');
    await adminPage.getByRole('button', { name: /Entreprise Alpha SA/ }).click();
    await adminPage.getByRole('button', { name: /Comptabilité principale/ }).click();
    await adminPage.getByRole('button', { name: 'Accès', exact: true }).click();
    await adminPage.getByRole('button', {
      name: 'Accès du dossier sélectionné'
    }).click();
    await expect(adminPage.getByRole('heading', {
      name: 'Accès aux structures'
    })).toBeVisible();

    const readerRow = adminPage.getByRole('row').filter({
      hasText: 'lecteur@example.test'
    });
    await expect(readerRow).toContainText('Lecteur / auditeur');
    await readerRow.getByRole('button', { name: 'Modifier' }).click();
    await adminPage.getByLabel('Lecteur / auditeur').uncheck();
    await adminPage.getByRole('button', {
      name: 'Prévisualiser les permissions'
    }).click();
    await expect(adminPage.getByText(/Retirées :/)).toContainText('dossier.view');
    await adminPage.getByRole('button', {
      name: 'Confirmer cette matrice'
    }).click();
    await expect(adminPage.getByText(
      'Matrice d’accès mise à jour et auditée.'
    )).toBeVisible();

    await readerPage.reload();
    await expect(readerPage.getByRole('heading', {
      name: 'Sélectionnez un dossier depuis l’icône filtre en haut à droite'
    })).toBeVisible();
    await openScopeMenu(readerPage);
    await expect(readerPage.getByLabel('Dossier', { exact: true })).not.toContainText(
      'Comptabilité principale'
    );
    await readerPage.getByRole('button', {
      name: 'Organisation, dossier et configuration'
    }).click();

    const refreshedReaderRow = adminPage.getByRole('row').filter({
      hasText: 'lecteur@example.test'
    });
    await refreshedReaderRow.getByRole('button', { name: 'Modifier' }).click();
    await adminPage.getByLabel('Lecteur / auditeur').check();
    await adminPage.getByRole('button', {
      name: 'Prévisualiser les permissions'
    }).click();
    await adminPage.getByRole('button', {
      name: 'Confirmer cette matrice'
    }).click();
    await readerPage.reload();
    await openScopeMenu(readerPage);
    await expect(readerPage.getByLabel('Dossier', { exact: true })).toContainText(
      'Comptabilité principale'
    );
  } finally {
    await adminContext.close();
    await readerContext.close();
  }
});

test('journal, extrait et plan comptable de Configuration utilisent le parcours Vue unique', async ({
  page
}) => {
  await loginAsAdministrator(page);
  await selectDossier(page, 'Comptabilité principale');

  await page.getByRole('link', { name: 'Comptabilité', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Nouvelle écriture' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Écritures récentes' })).toBeVisible();
  const journalAccount = page.getByLabel('Compte ligne 1');
  await journalAccount.fill('cai');
  const accountListbox = page.getByRole('listbox');
  await expect(accountListbox).toBeVisible();
  const [accountInputBox, accountListboxBox] = await Promise.all([
    journalAccount.boundingBox(),
    accountListbox.boundingBox()
  ]);
  expect(accountInputBox).not.toBeNull();
  expect(accountListboxBox).not.toBeNull();
  expect(accountListboxBox!.width).toBeGreaterThanOrEqual(accountInputBox!.width);
  expect(accountListboxBox!.width).toBeGreaterThanOrEqual(480);
  expect(accountListboxBox!.x + accountListboxBox!.width).toBeLessThanOrEqual(
    page.viewportSize()!.width
  );
  await journalAccount.press('Enter');
  await expect(journalAccount).toHaveValue(/1000.*Caisse/i);
  await journalAccount.press('Escape');
  await expect(journalAccount).toHaveValue('');
  await chooseAccount(journalAccount, '1000');
  await journalAccount.press('Backspace');
  await expect(journalAccount).toHaveValue(/1000.*Caiss$/i);
  await journalAccount.selectText();
  await journalAccount.press('Backspace');
  await expect(journalAccount).toHaveValue('');
  await chooseAccount(journalAccount, '1000');
  await journalAccount.press('Home');
  await journalAccount.press('Delete');
  await expect(journalAccount).toHaveValue(/000.*Caisse$/i);
  await journalAccount.selectText();
  await journalAccount.press('Delete');
  await expect(journalAccount).toHaveValue('');
  await journalAccount.fill('ss');
  const initialActiveOption = await journalAccount.getAttribute('aria-activedescendant');
  await journalAccount.press('ArrowDown');
  await expect(journalAccount).not.toHaveAttribute(
    'aria-activedescendant',
    initialActiveOption || ''
  );
  await journalAccount.press('Enter');
  await expect(journalAccount).toHaveValue(/ss/i);

  await page.getByRole('link', { name: 'Extraits', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Extrait de compte' })).toBeVisible();
  const ledgerAccount = page.getByLabel('Compte', { exact: true });
  await chooseAccount(ledgerAccount, '1000');
  await expect(page.getByRole('columnheader', { name: 'Débit (+)' })).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'Crédit (-)' })).toBeVisible();
  await chooseAccount(ledgerAccount, '1609');
  await expect(page.getByRole('columnheader', { name: 'Débit (-)' })).toBeVisible();
  await expect(page.getByRole('columnheader', { name: 'Crédit (+)' })).toBeVisible();
  await page.getByRole('button', { name: 'Compte en T' }).click();
  await expect(page.getByRole('heading', { name: 'Débit (-)' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Crédit (+)' })).toBeVisible();

  await openConfiguration(page);
  await page.getByRole('link', { name: 'Référentiels', exact: true }).click();
  await expect(page).toHaveURL(/\/e2e\/app\/configuration\/referentiels\/plan$/);
  await expect(page.getByRole('heading', { name: 'Configuration', level: 1 })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Plan comptable', level: 2 })).toBeVisible();
  await expect(page.getByLabel('Sections du plan comptable')).toBeVisible();

  await page.getByRole('button', { name: 'Comptes', exact: true }).click();
  const accountRow = page.locator('.plan-workspace table tbody tr').first();
  const rubricOptions = await accountRow.locator('select').first().locator('option').allTextContents();
  expect(rubricOptions.length).toBeGreaterThan(1);
  expect(rubricOptions.every((label) => !label.includes('‹'))).toBe(true);
  expect(rubricOptions.slice(1).every((label) => label.includes(' — '))).toBe(true);
  expect(await accountRow.locator('select').nth(1).locator('option').allTextContents()).toEqual([
    'Automatique',
    '+/-',
    '-/+'
  ]);
  const saveAccounts = page.getByRole('button', { name: 'Enregistrer', exact: true });
  await expect(saveAccounts).toHaveCount(1);
  await expect(saveAccounts).toBeDisabled();
  const accountLabel = accountRow.locator('input').nth(1);
  const originalLabel = await accountLabel.inputValue();
  await accountLabel.fill(`${originalLabel} E2E`);
  await saveAccounts.click();
  await expect(page.getByText('1 compte(s) enregistré(s).')).toBeVisible();
  await accountRow.locator('input').nth(1).fill(originalLabel);
  await saveAccounts.click();

  await page.getByRole('button', { name: 'Ouverture', exact: true }).click();
  await expect(page.getByRole('columnheader', { name: 'Sens', exact: true })).toBeVisible();
  await expect(page.getByRole('cell', { name: '+/-', exact: true }).first()).toBeVisible();
  await expect(page.getByRole('button', {
    name: 'Enregistrer le brouillon',
    exact: true
  })).toHaveCount(1);

  const legacy = await page.request.get('/e2e/compta/plan', { maxRedirects: 0 });
  expect(legacy.status()).toBe(303);
  expect(legacy.headers().location).toBe('/e2e/app/configuration/referentiels/plan');
});

test('états, clôture et dossier fiscal utilisent le grand livre unique', async ({ page }) => {
  await loginAsAdministrator(page);
  await selectDossier(page, 'Comptabilité principale');
  await page.getByRole('link', { name: 'Comptabilité', exact: true }).click();

  await page.getByRole('link', { name: 'États financiers', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'États financiers' })).toBeVisible();
  await expect(page.getByText('Débit = crédit')).toBeVisible();
  await expect(page.getByText('Résultat réconcilié')).toBeVisible();
  await page.getByRole('button', { name: 'Bilan', exact: true }).click();
  const financialStatement = page.locator('.financial-report-panel');
  const statementTable = financialStatement.locator('.financial-statement-table');
  await expect(financialStatement.getByText(/BILAN AU/)).toBeVisible();
  expect(await statementTable.evaluate((element) =>
    getComputedStyle(element).fontFamily
  )).toContain('Courier New');
  const firstStatementAmount = statementTable.locator('.amount').last();
  expect(await firstStatementAmount.evaluate((element) =>
    getComputedStyle(element).textAlign
  )).toBe('right');
  await expect(financialStatement.getByText(/BILAN AU .* — CHF/)).toBeVisible();
  await expect(statementTable).not.toContainText('CHF');
  await expect(statementTable.getByText('Exercice précédent')).toHaveCount(0);
  await financialStatement.getByRole('button', { name: '%', exact: true }).click();
  await expect(statementTable.getByText(/100[,.]0\s*%/).first()).toBeVisible();
  await page.getByRole('button', { name: 'Compte de résultat', exact: true }).click();
  await expect(financialStatement.getByText(/RÉSULTAT DU .* AU .* — CHF/)).toBeVisible();
  await expect(page.getByRole('row', {
    name: /RÉSULTAT NET DE L.EXERCICE/
  })).toBeVisible();
  await page.getByRole('button', { name: 'Flux de trésorerie' }).click();
  await expect(page.getByRole('heading', { name: 'Flux de trésorerie' })).toBeVisible();
  await expect(page.getByText(/Méthode indirecte/)).toBeVisible();
  await expect(page.getByRole('row', {
    name: /VARIATION NETTE DE LA TRÉSORERIE/
  })).toBeVisible();
  await page.emulateMedia({ media: 'print' });
  await expect(page.locator('.topbar')).toBeHidden();
  await expect(page.locator('.compact-tabs')).toBeHidden();
  await expect(financialStatement).toBeVisible();
  await page.emulateMedia({ media: 'screen' });

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
  await selectDossier(page, 'Comptabilité principale');
  await page.getByRole('link', { name: 'Comptabilité', exact: true }).click();
  await page.getByRole('link', { name: 'Clôture', exact: true }).click();
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
    await chooseAccount(
      page.getByRole('combobox', { name: label, exact: true }),
      account
    );
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
  await selectDossier(page, 'Comptabilité principale');
  await page.getByRole('link', { name: 'Comptabilité', exact: true }).click();
  await page.getByRole('link', { name: 'Consolidation', exact: true }).click();
  await page.setViewportSize({ width: 360, height: 800 });
  await expect(page.getByRole('heading', { name: 'Agrégation interne', exact: true })).toBeVisible();
  await page.getByText('Aide : agrégation, consolidation et livres statutaires').click();
  await expect(page.getByText(/les livres statutaires restent indépendants/i)).toBeVisible();
  await expect(page.getByText('Formule vérifiée', { exact: true })).toBeVisible();
  const revenueRow = page.getByRole('row').filter({ hasText: '3400 — Produits' });
  await expect(revenueRow).toContainText(/CHF.*1.*200\.00/);
  await revenueRow.getByText('1 balance(s) source(s)').click();
  await expect(revenueRow).toContainText(
    /Entreprise Alpha SA\s*\/\s*Comptabilité principale/
  );
  const exportButton = page.getByRole('button', { name: 'Exporter l’agrégation' });
  await expect(exportButton).toBeEnabled();
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    exportButton.click()
  ]);
  expect(download.suggestedFilename()).toMatch(/^agregation-/);

  const balanceTab = page.getByRole('button', { name: 'Balance', exact: true });
  await balanceTab.focus();
  await page.keyboard.press('Tab');
  await expect(page.getByRole('button', { name: 'Groupe et mappings' })).toBeFocused();
  const hasGlobalOverflow = await page.evaluate(
    () => document.documentElement.scrollWidth > document.documentElement.clientWidth
  );
  expect(hasGlobalOverflow).toBe(false);
  await page.setViewportSize({ width: 1280, height: 800 });

  await page.getByRole('button', { name: 'Groupe et mappings' }).click();
  await page.getByRole('button', { name: '2. Dossiers membres' }).click();
  await expect(page.getByRole('heading', { name: 'Dossiers membres', exact: true })).toBeVisible();
  await expect(page.getByRole('row').filter({
    hasText: 'Entreprise Alpha SA — Comptabilité principale'
  })).toContainText('CHF');
  await expect(page.getByRole('row').filter({
    hasText: 'Entreprise Alpha SA — Reporting analytique'
  })).toContainText('CHF');

  await logout(page);
  await expect(page).toHaveURL(/\/e2e\/login$/);
  await login(page);
  await selectDossier(page, 'Comptabilité principale');
  await page.goto('/e2e/app/compta/consolidation');
  await page.getByRole('button', { name: 'Groupe et mappings' }).click();
  await expect(page.getByRole('button', { name: 'Créer le brouillon' })).toBeDisabled();

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
          mode: 'agregation_interne',
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

test('assistant de consolidation légale active et exporte deux organisations', async ({
  page
}) => {
  await loginAsAdministrator(page);
  await selectDossier(page, 'Reporting analytique');
  await page.getByRole('link', { name: 'Comptabilité', exact: true }).click();
  await page.getByRole('link', { name: 'Consolidation', exact: true }).click();
  await page.getByRole('button', { name: 'Groupe et mappings' }).click();

  const groupForm = page.locator('form').filter({
    has: page.getByRole('button', { name: 'Créer le brouillon' })
  });
  await groupForm.getByLabel('Usage').selectOption('consolidation_legale');
  await groupForm.getByLabel('Code').fill('LEGAL-E2E');
  await groupForm.getByLabel('Libellé').fill('Consolidation légale E2E');
  await groupForm.getByRole('button', { name: 'Créer le brouillon' }).click();

  await expect(page.getByRole('heading', {
    name: 'Entités juridiques et dossiers membres'
  })).toBeVisible();
  await page.getByLabel('Dossier visible').selectOption({
    label: 'Entreprise Confidentielle SA — Dossier inaccessible'
  });
  await page.getByRole('button', { name: 'Ajouter le membre' }).click();
  await expect(page.getByRole('row').filter({
    hasText: 'Entreprise Confidentielle SA — Dossier inaccessible'
  })).toBeVisible();
  await page.getByRole('button', { name: 'Continuer vers les ratios' }).click();

  const periodForm = page.locator('form').filter({
    has: page.getByRole('button', { name: 'Créer et figer' })
  });
  await periodForm.getByLabel('Libellé').fill('Exercice légal E2E');
  await periodForm.getByLabel('Début').fill('2026-01-01');
  await periodForm.getByLabel('Fin').fill('2026-12-31');
  await periodForm.getByRole('button', { name: 'Créer et figer' }).click();
  await page.getByRole('button', { name: 'Prévisualiser le résultat' }).click();
  await expect(page.getByText(
    'balances sources converties + éliminations = résultat du groupe'
  )).toBeVisible();
  await page.getByRole('button', { name: 'Confirmer et activer' }).click();
  await expect(page.getByText('Groupe activé après prévisualisation.')).toBeVisible();

  await page.getByRole('button', { name: 'Balance', exact: true }).click();
  await expect(page.getByRole('heading', {
    name: 'Balance consolidée',
    exact: true
  })).toBeVisible();
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.getByRole('button', { name: 'Exporter la consolidation' }).click()
  ]);
  expect(download.suggestedFilename()).toMatch(/^consolidation-/);
});

test('facturation client, contact 360 et aging utilisent le parcours Vue unique', async ({ page }) => {
  await loginAsAdministrator(page);
  await selectDossier(page, 'Comptabilité principale');
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
  await chooseAccount(collective, '1100', 'Tab');
  await page.getByLabel('Libellé', { exact: true }).fill('Prestation E2E');
  await page.getByLabel('Montant', { exact: true }).fill('100.00');
  const revenue = page.getByLabel('Compte', { exact: true });
  await revenue.fill('3400');
  await page.getByText('Ligne 1', { exact: true }).click();
  await expect(revenue).toHaveValue(/3400/);
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
  await expect(page.getByRole('heading', { name: 'Saisir un paiement' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Saisir un paiement' }).click();
  const paymentDialog = page.getByRole('dialog', { name: 'Saisir un paiement' });
  await expect(paymentDialog).toBeVisible();
  await paymentDialog.getByRole('button', { name: 'Fermer' }).click();
  await page.getByRole('button', { name: 'Allouer un paiement' }).click();
  await expect(page.getByRole('dialog', { name: 'Allouer un paiement' })).toBeVisible();
  await page.getByRole('dialog', { name: 'Allouer un paiement' })
    .getByRole('button', { name: 'Fermer' }).click();
  await page.getByRole('link', { name: 'Récurrences', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Factures récurrentes' })).toBeVisible();

  const legacy = await page.request.get('/e2e/facturation', { maxRedirects: 0 });
  expect(legacy.status()).toBe(303);
  expect(legacy.headers().location).toBe('/e2e/app/facturation');
});

test('dépense fournisseur approuvée et comptabilisée dans Vue', async ({ page }) => {
  await loginAsAdministrator(page);
  await selectDossier(page, 'Comptabilité principale');
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
  await chooseAccount(payable, '2000');
  await expect(page.getByLabel('Justificatif facultatif')).toBeVisible();
  await page.getByLabel('Libellé', { exact: true }).fill('Fournitures de bureau');
  await page.getByLabel('Montant', { exact: true }).fill('100.00');
  const expenseAccount = page.getByLabel('Compte de charge');
  await chooseAccount(expenseAccount, '6500', 'Tab');
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
  await selectDossier(page, 'Comptabilité principale');
  await page.getByRole('link', { name: 'Liquidités', exact: true }).click();
  await page.getByRole('link', { name: 'Rapprochement', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Rapprochement bancaire' })).toBeVisible();
  const bankSelect = page.getByLabel('Compte bancaire');
  await chooseAccount(bankSelect, 'Banque principale');
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
  await page.getByRole('button', { name: 'Associer banque et comptabilité' }).click();
  await expect(page.getByRole('button', { name: 'Confirmer le rapprochement' })).toBeDisabled();

  await page.getByRole('link', { name: 'Lettrage', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Lettrage des paiements' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Nouveau paiement' })).toBeVisible();
  await page.getByRole('button', { name: 'Allouer à un document ouvert' }).click();
  await expect(page.getByRole('heading', { name: 'Allouer à un document ouvert' })).toBeVisible();

  await page.getByRole('link', { name: 'Taux', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Taux de change' })).toBeVisible();
  await page.getByRole('button', { name: 'Taux d’intérêt' }).click();
  await expect(page.getByRole('heading', { name: 'Taux d’intérêt' })).toBeVisible();

  await page.getByRole('link', { name: 'Paiements', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Paiements sortants' })).toBeVisible();
  await expect(page.getByText('export pain.001 non transmis', { exact: false })).toBeVisible();
});

test('salaires horaires et mensuels utilisent le parcours Vue et l’import OCAS contrôlé', async ({
  page
}) => {
  await loginAsAdministrator(page);
  await selectDossier(page, 'Comptabilité principale');
  await page.getByRole('link', { name: 'Salaires', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Salaires', exact: true })).toBeVisible();
  await expect(page.getByLabel('Navigation des salaires')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Employés', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Historique des contrats' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Nouvel employé' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Nouvel employé' }).click();
  await expect(page.getByRole('dialog', { name: 'Nouvel employé' })).toBeVisible();
  await page.getByRole('dialog', { name: 'Nouvel employé' })
    .getByRole('button', { name: 'Fermer' }).click();
  await expect(page.getByText(/aucun envoi Swissdec/i)).toHaveCount(0);
  await expect(page.getByRole('heading', { name: /^Paie \d{4}$/ })).toHaveCount(0);

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
  await expect(page.getByRole('heading', { name: 'Paiements et lettrage' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Paiements et lettrage' }).click();
  const payrollPayments = page.getByRole('dialog', { name: 'Paiements et lettrage' });
  await expect(payrollPayments).toBeVisible();
  await expect(payrollPayments.getByRole('heading', { name: 'Saisir le paiement' })).toBeVisible();
  await expect(payrollPayments.getByRole('heading', { name: 'Allouer à une dette' })).toBeVisible();
  await payrollPayments.getByRole('button', { name: 'Fermer' }).click();
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
