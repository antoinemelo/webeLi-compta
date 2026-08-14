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

async function clickRowAction(
  page: Page,
  row: Locator,
  action: string
): Promise<void> {
  await row.getByRole('button', { name: /^Actions pour / }).click();
  await page.getByRole('menu').getByRole('button', {
    name: action,
    exact: true
  }).click();
}

async function login(page: Page): Promise<void> {
  await page.goto('/e2e/login');
  await page.getByLabel('Adresse e-mail').fill('lecteur@example.test');
  await page.getByRole('button', { name: 'Continuer' }).click();
  await page.locator('#password').fill('mot-de-passe-e2e');
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page).toHaveURL(/\/e2e\/app\/?$/);
  await expect(page.locator('main.main-content')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Tableau de bord' })).toHaveCount(0);
}

async function loginAsAdministrator(page: Page): Promise<void> {
  await page.goto('/e2e/login');
  await page.getByLabel('Adresse e-mail').fill('admin@example.test');
  await page.getByRole('button', { name: 'Continuer' }).click();
  await page.locator('#password').fill('mot-de-passe-e2e');
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

async function collapseSetupGuide(page: Page): Promise<void> {
  const button = page.getByRole('button', {
    name: 'Réduire la configuration initiale'
  });
  try {
    await button.waitFor({ state: 'visible', timeout: 3_000 });
    await button.click();
  } catch {
    // Le guide est absent lorsque le dossier est déjà configuré.
  }
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

test('parcours de configuration discret, progressif et reprenable', async ({ page }) => {
  await loginAsAdministrator(page);
  await selectDossier(page, 'Comptabilité principale');

  const guide = page.getByRole('complementary', {
    name: 'Parcours de configuration initiale'
  });
  await expect(guide).toBeVisible();
  await expect(guide).toContainText('Configuration initiale');
  await expect(guide).toContainText(/Étape \d+ sur \d+/);
  await expect(guide.getByRole('button', { name: 'Suivant' })).toBeVisible();
  await expect(guide.getByRole('button', { name: 'Précédent' })).toBeVisible();
  await guide.getByRole('button', { name: 'Suivant' }).click();
  await expect(guide.getByRole('button', {
    name: 'Annuler le parcours pour l’instant'
  })).toBeVisible();
  await expect(guide.getByRole('button', {
    name: /^Configuration initiale/
  })).toHaveCount(0);
  const stepLabel = await guide.getByText(/Étape \d+ sur \d+/).textContent();
  const stepPosition = stepLabel?.match(/Étape (\d+) sur (\d+)/);
  await guide.getByRole('button', {
    name: 'Réduire la configuration initiale'
  }).click();
  const compactGuide = guide.getByRole('button', {
    name: /Configuration initiale/
  });
  await expect(compactGuide).toBeVisible();
  await expect(compactGuide).toContainText(
    `${stepPosition?.[1]}/${stepPosition?.[2]}`
  );
  await compactGuide.click();
  expect(await guide.evaluate((element) => {
    const box = element.getBoundingClientRect();
    return {
      nearLeft: box.left < window.innerWidth / 4,
      nearBottom: window.innerHeight - box.bottom < 24
    };
  })).toEqual({ nearLeft: true, nearBottom: true });

  await guide.getByRole('button', {
    name: 'Annuler le parcours pour l’instant'
  }).click();
  await expect(guide).toBeHidden();
  await page.reload();
  await expect(guide).toBeHidden();
  await page.getByRole('button', {
    name: 'Organisation, dossier et configuration'
  }).click();
  await page.getByRole('button', {
    name: 'Reprendre la configuration initiale'
  }).click();
  await expect(guide).toBeVisible();
  await expect(page.getByRole('button', {
    name: 'Reprendre la configuration initiale'
  })).toHaveCount(0);
  await expect(guide).toContainText(/Obligatoire|Facultatif/);
});

test('récupération du mot de passe sans divulgation de compte', async ({ page }) => {
  await page.goto('/e2e/login');
  await page.getByRole('link', { name: 'Mot de passe oublié ?' }).click();
  await expect(page).toHaveURL(/\/e2e\/mot-de-passe-oublie$/);
  await expect(page.getByRole('heading', { name: 'Mot de passe oublié' })).toBeVisible();
  await expect(page.locator('body')).toHaveClass(/login-page/);
  await expect(page.locator('.login-shell')).toHaveCount(1);
  await expect(page.locator('#password-reset-form')).toBeVisible();
  const stageWidth = await page.locator('.login-stage').evaluate(
    (element) => element.getBoundingClientRect().width
  );
  const usableWidth = await page.evaluate(
    () => document.documentElement.clientWidth
  );
  expect(stageWidth).toBeGreaterThanOrEqual(usableWidth * 0.99);
  await page.getByLabel('Adresse e-mail').fill('adresse-absente@example.test');
  await page.getByRole('button', { name: 'Envoyer le lien sécurisé' }).click();
  await expect(
    page.getByText(/Si cette adresse correspond à un compte actif/)
  ).toBeVisible();
  await page.getByRole('link', { name: 'Revenir à la connexion' }).click();
  await expect(page).toHaveURL(/\/e2e\/login$/);
});

test('typographie du login stable avant, pendant et après le focus', async ({ page }) => {
  await page.goto('/e2e/login');
  await page.evaluate(() => document.fonts.ready);
  const emailFields = page.locator('label[for="email"], #email');
  const typography = async (fields: Locator) => fields.evaluateAll((elements) =>
    elements.map((element) => {
      const style = getComputedStyle(element);
      return {
        fontFamily: style.fontFamily,
        fontSize: style.fontSize,
        fontWeight: style.fontWeight,
        lineHeight: style.lineHeight,
        height: element.getBoundingClientRect().height
      };
    })
  );
  const emailBefore = await typography(emailFields);
  await page.getByLabel('Adresse e-mail').focus();
  expect(await typography(emailFields)).toEqual(emailBefore);
  await page.locator('.login-presentation').click();
  expect(await typography(emailFields)).toEqual(emailBefore);
  await page.getByLabel('Adresse e-mail').fill('lecteur@example.test');
  await page.getByRole('button', { name: 'Continuer' }).click();
  await expect(page.locator('#password')).toBeFocused();
  await page.evaluate(() => document.fonts.ready);
  const passwordFields = page.locator('label[for="password"], #password');
  const passwordBefore = await typography(passwordFields);
  await page.locator('#password').focus();
  expect(await typography(passwordFields)).toEqual(passwordBefore);
  await page.locator('.login-presentation').click();
  expect(await typography(passwordFields)).toEqual(passwordBefore);
});

test('connexion, changement de dossier, route profonde et déconnexion', async ({ page }) => {
  await login(page);

  await expect(
    page.getByRole('link', { name: 'Ouvrir le tableau de bord' })
  ).toContainText(/WebeLi.*Compta/);
  const scopeButton = page.getByRole('button', {
    name: 'Organisation, dossier et configuration'
  });
  await expect(scopeButton).toHaveCSS('color', 'rgb(32, 33, 78)');
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
  await expect(page.locator('#scope-menu label:not(.visually-hidden)')).toHaveCount(0);
  await expect(page.getByLabel('Organisation', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Dossier', { exact: true })).toBeVisible();
  expect(await page.locator('#scope-menu select').evaluateAll(
    (selects) => selects.map((select) => select.getBoundingClientRect().height)
  )).toEqual([36, 36]);
  const scopeMenuLayout = await page.locator('#scope-menu').evaluate((menu) => {
    const title = menu.querySelector('.popover-title');
    const structures = menu.querySelector('.scope-structure-link');
    const switcher = menu.querySelector('.dossier-switcher');
    const configuration = menu.querySelector('.scope-popover-footer');
    if (
      !(title instanceof HTMLElement)
      || !(structures instanceof HTMLElement)
      || !(switcher instanceof HTMLElement)
      || !(configuration instanceof HTMLElement)
    ) return null;
    const menuBox = menu.getBoundingClientRect();
    return {
      titleBottom: title.getBoundingClientRect().bottom,
      structuresTop: structures.getBoundingClientRect().top,
      structuresBottom: structures.getBoundingClientRect().bottom,
      switcherTop: switcher.getBoundingClientRect().top,
      configurationBottomGap: menuBox.bottom - configuration.getBoundingClientRect().bottom
    };
  });
  expect(scopeMenuLayout).not.toBeNull();
  expect(scopeMenuLayout?.structuresTop).toBeGreaterThanOrEqual(
    scopeMenuLayout?.titleBottom ?? 0
  );
  expect(scopeMenuLayout?.switcherTop).toBeGreaterThanOrEqual(
    scopeMenuLayout?.structuresBottom ?? 0
  );
  expect(scopeMenuLayout?.configurationBottomGap).toBeLessThanOrEqual(13);

  await page.getByRole('button', { name: 'Informations personnelles' }).click();
  await page.getByRole('button', { name: 'Sécurité du compte' }).click();
  const securityDialog = page.getByRole('dialog', { name: 'Sécurité du compte' });
  await expect(securityDialog).toBeVisible();
  await expect(securityDialog).toContainText('Mot de passe uniquement');
  await expect(
    securityDialog.getByRole('button', { name: 'Modifier le mot de passe' })
  ).toBeVisible();
  await securityDialog.getByRole('button', { name: 'Fermer' }).click();

  await selectDossier(page, 'Comptabilité principale');
  await expect(
    page.getByRole('link', { name: 'Ouvrir le tableau de bord' })
  ).toContainText(/Entreprise Alpha SA.*Comptabilité principale.*2026.*CHF/);
  await expect(page.locator('.context-band')).toHaveCount(0);
  await expect(page.locator('.sidebar')).toBeHidden();
  const alignedLayout = await page.locator('header.topbar').evaluate((header) => {
    const content = document.querySelector('main.main-content');
    const identity = header.querySelector('.brand-group');
    const actions = header.querySelector('.topbar-actions');
    if (
      !(content instanceof HTMLElement)
      || !(identity instanceof HTMLElement)
      || !(actions instanceof HTMLElement)
    ) return null;
    const headerBox = header.getBoundingClientRect();
    const contentBox = content.getBoundingClientRect();
    const identityBox = identity.getBoundingClientRect();
    const actionsBox = actions.getBoundingClientRect();
    return {
      headerWidth: headerBox.width / window.innerWidth,
      left: Math.abs(identityBox.left - contentBox.left),
      right: Math.abs(actionsBox.right - contentBox.right)
    };
  });
  expect(alignedLayout).not.toBeNull();
  expect(alignedLayout?.headerWidth).toBe(1);
  expect(alignedLayout?.left).toBeLessThan(1);
  expect(alignedLayout?.right).toBeLessThan(1);
  const desktopNavigation = page.getByLabel('Navigation principale');
  const billingMenu = desktopNavigation.getByRole('link', {
    name: 'Facturation',
    exact: true
  });
  await expect(billingMenu.locator('svg')).toBeVisible();
  await billingMenu.hover();
  const billingSubmenu = billingMenu.locator('..').getByRole('menu');
  await expect(billingSubmenu).toBeVisible();
  await expect(billingSubmenu.getByRole('menuitem').allTextContents())
    .resolves.toEqual([
      'Échéancier',
      'Offres',
      'Commandes',
      'Achats',
      'Ventes',
      'Récurrences',
      'Contacts'
    ]);
  await page.locator('main').hover();
  await expect(billingSubmenu).toBeHidden();
  const payrollMenu = desktopNavigation.getByRole('link', {
    name: 'Salaires',
    exact: true
  });
  await payrollMenu.hover();
  const payrollSubmenu = payrollMenu.locator('..').getByRole('menu');
  await expect(payrollSubmenu).toBeVisible();
  const payrollMenuBounds = await payrollSubmenu.evaluate((submenu) => ({
    right: submenu.getBoundingClientRect().right,
    viewport: window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth
  }));
  expect(payrollMenuBounds.right).toBeLessThanOrEqual(payrollMenuBounds.viewport);
  expect(payrollMenuBounds.scrollWidth).toBeLessThanOrEqual(payrollMenuBounds.viewport);
  await page.locator('main').hover();
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
    /1.*200\.00/
  );
  await expect(page.getByText('Produits comptabilisés', { exact: true }).locator('..'))
    .not.toContainText('CHF');
  await expect(page.getByRole('heading', { name: 'Trésorerie par compte' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Ouvrir le lettrage' })).toHaveAttribute(
    'href',
    '/e2e/app/liquidites/lettrage'
  );
  const payableSummary = page.locator('article.panel').filter({
    has: page.getByRole('heading', { name: 'Dettes ouvertes' })
  });
  await expect(payableSummary).toContainText(/\d+ documents?,\s+dont \d+ échus?\./);
  await expect(payableSummary).not.toContainText('0 échus pour');
  await expect(payableSummary).not.toContainText('(s)');
  const dashboardScope = page.getByLabel('Périmètre du calcul');
  await expect(dashboardScope.locator('.dashboard-scope-summary small').allTextContents())
    .resolves.toEqual(['Période', 'Devise de base']);
  await expect(dashboardScope.getByLabel('Exercice')).toBeVisible();
  await expect(dashboardScope.getByLabel('Date d’arrêté')).toBeVisible();
  expect(await dashboardScope.evaluate((scope) => {
    const summary = scope.querySelector('.dashboard-scope-summary');
    const filters = scope.querySelector('.dashboard-filters');
    if (!(summary instanceof HTMLElement) || !(filters instanceof HTMLElement)) return false;
    return Math.abs(
      summary.getBoundingClientRect().bottom - filters.getBoundingClientRect().bottom
    ) < 2;
  })).toBe(true);
  expect(await page.locator('main').evaluate((element) => {
    return element.getBoundingClientRect().width / window.innerWidth;
  })).toBeGreaterThanOrEqual(0.985);
  await expect(page.getByText('Une lecture du grand livre')).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Aging des créances et dettes' })).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Dernières écritures' })).toHaveCount(0);

  await selectDossier(page, 'Démonstration guidée');
  await expect(
    page.getByRole('link', { name: 'Ouvrir le tableau de bord' })
  ).toContainText('École WebeLi');
  await expect(page.getByText(/DÉMONSTRATION — DONNÉES FICTIVES/)).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Aucune activité à cette date' })).toBeVisible();

  const accountingMenu = page.getByLabel('Navigation principale').getByRole('link', {
    name: 'Comptabilité',
    exact: true
  });
  await accountingMenu.click();
  const accountingSubmenu = accountingMenu.locator('..').getByRole('menu');
  await expect(accountingSubmenu).toBeVisible();
  await accountingSubmenu.getByRole('menuitem', { name: 'États financiers' }).click();
  await expect(page).toHaveURL(/\/e2e\/app\/compta\/etats$/);
  await expect(accountingSubmenu).toBeVisible();
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Comptabilité', exact: true })).toHaveCount(0);
  await expect(page.getByLabel('Navigation comptable')).toBeVisible();
  await expect(page.getByLabel('Exercice consulté')).toBeVisible();

  await logout(page);
  await expect(page).toHaveURL(/\/e2e\/login$/);
});

test('la recherche globale retrouve menus, sous-menus et panneaux', async ({ page }) => {
  await loginAsAdministrator(page);
  await selectDossier(page, 'Comptabilité principale');

  const search = page.getByLabel('Rechercher dans la navigation');
  await search.fill('/paie');
  const results = page.getByRole('listbox');
  await expect(results).toBeVisible();
  await expect(results.getByRole('option')).toHaveCount(2);
  await expect(results.getByRole('option').locator('strong').allTextContents())
    .resolves.toEqual(['Paiements', 'Paiements']);
  await expect(results.getByRole('option').filter({
    hasText: 'Conditions de paiement'
  })).toHaveCount(0);
  await expect(results.getByRole('option').filter({
    hasText: 'Saisir un paiement'
  })).toHaveCount(0);
  const configurationPayments = results.locator('code').filter({
    hasText: '/configuration/paiements'
  }).first();
  await expect(configurationPayments).toBeVisible();
  await configurationPayments.locator('..').click();
  await expect(page).toHaveURL(/\/e2e\/app\/configuration\/paiements$/);

  await search.fill('paie');
  await expect(page.getByRole('listbox').getByRole('option').filter({
    hasText: 'Conditions de paiement'
  })).toBeVisible();
  await expect(page.getByRole('listbox').getByRole('option').filter({
    hasText: 'Saisir un paiement'
  })).toBeVisible();
  await expect(page.getByRole('listbox').getByRole('option').filter({
    hasText: 'Lettrage des paiements'
  })).toBeVisible();
  await expect(page.getByRole('listbox').getByRole('option').filter({
    hasText: 'Paiements sortants'
  })).toBeVisible();
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
  const mobileNavigation = page.getByLabel('Navigation mobile');
  await expect(mobileNavigation).toBeVisible();
  await expect(mobileNavigation.getByRole('link', { name: 'États financiers' })).toHaveCount(0);
  const mobileSearch = page.getByLabel('Rechercher dans la navigation');
  await expect(mobileSearch).toBeVisible();
  const mobileHeader = page.locator('header.topbar');
  expect(await mobileHeader.evaluate((header) => {
    const search = header.querySelector<HTMLInputElement>('#global-navigation-search');
    return search
      ? search.getBoundingClientRect().width / header.getBoundingClientRect().width
      : 0;
  })).toBeGreaterThan(0.98);
  await expect(mobileHeader).toHaveCSS('background-color', 'rgba(255, 255, 255, 0.96)');
  await selectDossier(page, 'Comptabilité principale');
  await expect(page.getByText('Chiffre d’affaires', { exact: true })).toBeVisible();
  await page.getByRole('link', { name: 'Liquidités', exact: true }).click();
  const tabs = page.getByLabel('Navigation des liquidités');
  await expect(tabs).toBeVisible();
  expect(await tabs.evaluate((element) => {
    const style = getComputedStyle(element);
    const links = element.querySelector('.compact-tab-links');
    return {
      position: style.position,
      flexDirection: style.flexDirection,
      overflowX: style.overflowX,
      linksWrap: links ? getComputedStyle(links).flexWrap : ''
    };
  })).toEqual({
    position: 'sticky',
    flexDirection: 'column',
    overflowX: 'visible',
    linksWrap: 'wrap'
  });
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  const tabsBox = await tabs.boundingBox();
  expect(tabsBox?.y).toBeGreaterThanOrEqual(68);
  expect(tabsBox?.y).toBeLessThan(340);
  expect((tabsBox?.y || 0) + (tabsBox?.height || 0)).toBeLessThan(780);
  const overflow = await page.evaluate(() => ({
    viewport: window.innerWidth,
    document: document.documentElement.scrollWidth,
    offenders: Array.from(document.querySelectorAll<HTMLElement>('body *'))
      .map((element) => ({
        selector: `${element.tagName.toLowerCase()}.${element.className}`,
        left: element.getBoundingClientRect().left,
        right: element.getBoundingClientRect().right
      }))
      .filter(({ right }) => right > window.innerWidth + 0.5)
      .slice(0, 10)
  }));
  expect(overflow).toEqual({ viewport: 360, document: 360, offenders: [] });
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
  await expect(page.getByLabel('Compte de facturation')).toBeVisible();

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
  await expect(page.getByRole('heading', { name: 'Nouvelle condition de paiement' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Nouvelle condition' }).click();
  const paymentTermDialog = page.getByRole('dialog', { name: 'Nouvelle condition de paiement' });
  await expect(paymentTermDialog).toBeVisible();
  await paymentTermDialog.getByRole('button', { name: 'Fermer' }).click();
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
  const currencyEditor = page.locator('.currency-editor');
  await expect(currencyEditor.getByLabel('Code ISO', { exact: true })).toBeVisible();
  await expect(currencyEditor.getByLabel(/Devise active/)).toBeChecked();
  expect(await currencyEditor.locator(':scope > *').evaluateAll((elements) => {
    const centers = elements.map((element) => {
      const box = element.getBoundingClientRect();
      return box.top + box.height / 2;
    });
    return Math.max(...centers) - Math.min(...centers);
  })).toBeLessThan(10);
  const configuredCurrencies = page.getByRole('list', { name: 'Devises configurées' });
  await expect(configuredCurrencies.getByText('CHF', { exact: true })).toBeVisible();
  await expect(configuredCurrencies.getByText('Devise de base', { exact: true })).toBeVisible();
  await expect(configuredCurrencies.getByText('Toujours active', { exact: true })).toBeVisible();
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
  await expect(page.getByText('Grand livre lié', { exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: 'Nouveau compte de trésorerie' }).click();
  const treasuryDialog = page.getByRole('dialog', { name: 'Nouveau compte de trésorerie' });
  await expect(treasuryDialog).toBeVisible();
  await treasuryDialog.getByRole('button', { name: 'Fermer' }).click();
  const treasuryRow = page.getByRole('row').filter({ hasText: 'Banque principale' });
  await treasuryRow.getByRole('button', { name: 'Modifier' }).click();
  const treasuryEditDialog = page.getByRole('dialog', {
    name: 'Modifier le compte de trésorerie'
  });
  await treasuryEditDialog.getByLabel('Libellé').fill('Banque principale modifiée');
  await treasuryEditDialog.getByRole('button', {
    name: 'Enregistrer le compte'
  }).click();
  await expect(treasuryRow).toContainText('Banque principale modifiée');

  await page.getByRole('link', { name: 'Débiteurs et créanciers' }).click();
  await expect(page.getByText('Registre partagé', { exact: true })).toHaveCount(0);
  await page.getByRole('button', { name: 'Nouveau débiteur ou créancier' }).click();
  const contactDialog = page.getByRole('dialog', { name: 'Nouveau débiteur ou créancier' });
  await expect(contactDialog).toBeVisible();
  await contactDialog.getByRole('button', { name: 'Fermer' }).click();

  await page.getByRole('link', { name: 'TVA', exact: true }).click();
  await expect(page.getByText('Valeurs datées', { exact: true })).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Avec ou sans TVA' })).toBeVisible();
  await expect(page.getByText('01-01-2026', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Modifier le régime TVA' }).click();
  const vatRegimeDialog = page.getByRole('dialog', { name: 'Configurer le régime TVA' });
  await expect(vatRegimeDialog.getByLabel('Traitement du dossier')).toBeVisible();
  await vatRegimeDialog.getByRole('button', { name: 'Fermer' }).click();
  await page.getByRole('button', { name: 'Nouveau code TVA' }).click();
  const newVatDialog = page.getByRole('dialog', { name: 'Nouveau code TVA' });
  await expect(newVatDialog).toBeVisible();
  await newVatDialog.getByRole('button', { name: 'Fermer' }).click();
  await expect(page.getByRole('heading', { name: 'Taux TVA suisses' })).toBeVisible();
  const vatRow = page.getByRole('row').filter({ hasText: 'VE81' });
  const vatActions = vatRow.getByRole('button', { name: 'Actions pour le code TVA VE81' });
  await vatActions.click();
  await page.getByRole('menu', { name: 'Actions pour le code TVA VE81' }).getByRole('button', { name: 'Modifier' }).click();
  await expect(page.getByRole('heading', { name: 'Modifier le code TVA' })).toBeVisible();
  await page.getByLabel('Libellé').fill('Ventes E2E modifiées');
  await page.getByRole('button', { name: 'Enregistrer les modifications' }).click();
  await expect(vatRow).toContainText('Ventes E2E modifiées');
  await vatActions.click();
  await page.getByRole('menu', { name: 'Actions pour le code TVA VE81' }).getByRole('button', { name: 'Désactiver' }).click();
  await expect(vatRow).toContainText('Inactif');
  await vatActions.click();
  await page.getByRole('menu', { name: 'Actions pour le code TVA VE81' }).getByRole('button', { name: 'Réactiver' }).click();
  await expect(vatRow).toContainText('Actif');
  await vatActions.click();
  await page.getByRole('menu', { name: 'Actions pour le code TVA VE81' }).getByRole('button', { name: 'Modifier' }).click();
  await page.getByLabel('Libellé').fill('Ventes 8,1 %');
  await page.getByRole('button', { name: 'Enregistrer les modifications' }).click();

  await page.getByRole('link', { name: 'Charges sociales' }).click();
  await expect(page.getByText(
    'Genève · valeurs en pourcentage',
    { exact: true }
  )).toHaveCount(0);
  await page.getByRole('button', { name: 'Taux annuels des charges sociales' }).click();
  const payrollRatesDialog = page.getByRole('dialog', {
    name: 'Taux annuels des charges sociales'
  });
  await expect(payrollRatesDialog).toBeVisible();
  await expect(payrollRatesDialog.getByText(/Salaires → Annuels/)).toHaveCount(0);
  await expect(payrollRatesDialog.getByLabel('LPP employeur (%)', { exact: true }))
    .toHaveValue('9');
  const downloadPromise = page.waitForEvent('download');
  await payrollRatesDialog.getByRole('button', { name: 'Exporter les taux CSV' }).click();
  expect((await downloadPromise).suggestedFilename()).toMatch(
    /^taux-charges-sociales-\d{4}-\d{2}-\d{2}\.csv$/
  );
  await payrollRatesDialog.getByLabel('Fichier CSV des taux annuels').setInputFiles({
    name: 'taux-sociaux-2027.csv',
    mimeType: 'text/csv',
    buffer: Buffer.from([
      'annee;source;verifie_le;avs_pct;ac_pct;amat_pct;laa_reduit_pct;laa_plein_pct;lpp_pct;emp_avs_pct;emp_ac_pct;emp_amat_pct;emp_af_pct;emp_laa_reduit_pct;emp_laa_plein_pct;emp_frais_pct;emp_cpe_pct;emp_lfp_pct;emp_lpp_pct',
      '2027;Référence E2E;2026-07-28;5,3;1,1;0,041;0,5;1;3,5;5,3;1,1;0,041;2,25;0,5;1;0,1;0,07;0,14;9'
    ].join('\n'))
  });
  await expect(page.getByText(
    '1 millésime(s) importé(s) : 2027.',
    { exact: true }
  )).toBeVisible();
  await expect(payrollRatesDialog).toBeHidden();
  await expect(page.getByRole('cell', { name: '2027', exact: true })).toBeVisible();
  await expect(page.getByRole('cell', { name: /Référence E2E/ })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Charger les valeurs OCAS 2026' })).toHaveCount(0);

  await page.getByRole('link', { name: 'Journaux' }).click();
  await expect(page.getByRole('heading', { name: 'Nouveau journal' })).toBeVisible();
  await page.getByRole('link', { name: 'Exercices et périodes' }).click();
  await expect(page.getByRole('heading', {
    name: 'Exercices comptables',
    exact: true
  })).toHaveCount(0);
  await page.getByRole('button', { name: 'Nouvel exercice comptable' }).click();
  const exerciseDialog = page.getByRole('dialog', { name: 'Nouvel exercice comptable' });
  await expect(exerciseDialog).toBeVisible();
  await exerciseDialog.getByLabel('Libellé').fill('2027 E2E');
  await exerciseDialog.getByLabel('Début').fill('2027-01-01');
  await exerciseDialog.getByLabel('Fin').fill('2027-12-31');
  await exerciseDialog.getByRole('button', { name: 'Créer l’exercice' }).click();
  await page.getByRole('button', { name: 'Nouvelle période' }).click();
  const periodDialog = page.getByRole('dialog', { name: 'Nouvelle période' });
  await expect(periodDialog).toBeVisible();
  await periodDialog.getByLabel('Exercice').selectOption({ label: '2027 E2E' });
  await periodDialog.getByLabel('Libellé').fill('Année 2027 E2E');
  await periodDialog.getByLabel('Début').fill('2027-01-01');
  await periodDialog.getByLabel('Fin').fill('2027-12-31');
  await periodDialog.getByRole('button', { name: 'Créer la période' }).click();
  const periodsPanel = page.locator('article.panel').filter({
    has: page.getByRole('heading', { name: 'Périodes comptables', exact: true })
  });
  const periodRow = periodsPanel.getByRole('row').filter({ hasText: 'Année 2027 E2E' });
  await periodRow.getByRole('button', { name: 'Fermer' }).click();
  await expect(periodRow).toContainText('Fermée');
  const exerciseRow = page.getByRole('row', { name: /^2027 E2E / });
  await exerciseRow.getByRole('button', { name: 'Fermer' }).click();
  await expect(exerciseRow).toContainText('Fermé');

  const configurationNavigation = page.getByLabel('Navigation Configuration');
  expect(await configurationNavigation.getByRole('link').allTextContents()).toEqual([
    'Modules',
    'Entité',
    'Paiements',
    'Référentiels',
    'Salaires',
    'Audit'
  ]);
  await configurationNavigation.getByRole('link', { name: 'Salaires', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Paramètres salariaux' })).toBeVisible();
  await expect(page.getByLabel('Heures hebdomadaires')).toBeVisible();
  await expect(page.getByLabel('Employeur')).toHaveCount(0);
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

  await expect(
    configurationNavigation.getByRole('link', { name: 'Accès', exact: true })
  ).toHaveCount(0);
  await page.goto('/e2e/app/configuration/acces');
  await expect(page).toHaveURL(/\/e2e\/app\/organisations-dossiers$/);
  await expect(page.getByRole('heading', {
    name: 'Organisations',
    exact: true
  })).toBeVisible();
  await expect(page.getByRole('heading', {
    name: 'Organisations et dossiers',
    exact: true
  })).toHaveCount(0);

  await page.goto('/e2e/app/compta/journalisation');
  const exerciseSelector = page.getByLabel('Exercice consulté');
  await expect(exerciseSelector.locator('option', {
    hasText: '2027 E2E — Fermé'
  })).toHaveCount(1);
  await exerciseSelector.selectOption({ label: '2027 E2E — Fermé' });
  await expect(page.getByLabel('Date')).toHaveValue('2027-01-01');
  await exerciseSelector.selectOption({ label: '2026 — Ouvert' });
  await expect(page.getByLabel('Date')).toHaveValue('2026-01-01');
});

test('registre des organisations : création, historique et cycle de vie', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 900 });
  await loginAsAdministrator(page);
  await page.goto('/e2e/app/organisations-dossiers');
  await expect(page.getByRole('button', {
    name: 'Ouvrir la navigation'
  })).toHaveCSS('border-radius', '50%');
  await expect(page.getByRole('heading', {
    name: 'Organisations',
    exact: true
  })).toBeVisible();
  await expect(page.getByRole('heading', {
    name: 'Versions et mise à jour',
    exact: true
  })).toBeVisible();
  await expect(page.getByText(
    'La synchronisation est déclenchée uniquement à votre demande.'
  )).toHaveCount(0);
  await expect(page.getByText(
    'Cette fonction est réservée aux administrateurs de l’installation.'
  )).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Dépôt source GitHub' })).toHaveCount(0);
  await expect(page.getByRole('heading', {
    name: 'Choisissez une organisation pour commencer'
  })).toHaveCount(0);
  const registrySearch = page.getByRole('search');
  await expect(registrySearch.getByRole('button', { name: 'Appliquer' })).toBeVisible();
  await expect(registrySearch.getByRole('button', {
    name: 'Créer une organisation'
  })).toBeVisible();
  await expect(page.locator('.organisation-registry-view')).toHaveCSS('gap', '16px');

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
  await page.getByLabel('Gestion de l’organisation')
    .getByRole('button', { name: 'Informations', exact: true })
    .click();
  await expect(page.getByRole('heading', {
    name: 'Historique juridique'
  })).toBeVisible();
  await page.locator('.history-entry-button')
    .filter({ hasText: 'Coopérative E2E SA' })
    .click();
  const historicalIdentity = page.getByRole('dialog');
  await expect(historicalIdentity).toContainText('Extrait RC E2E');
  await expect(historicalIdentity).toContainText('CHE-555.444.333');
  await historicalIdentity.getByRole('button', {
    name: 'Reprendre pour une nouvelle modification'
  }).click();
  await expect(page.getByLabel('Raison sociale').last()).toHaveValue(
    'Coopérative E2E SA'
  );
  await expect(page.getByLabel('Source').last()).toHaveValue('Extrait RC E2E');
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
    await expect(wizard.getByLabel('Variante du plan comptable')).toBeVisible();
    await expect(wizard.getByText('Variante du plan VEB')).toHaveCount(0);
    await expect(wizard.locator('.choice-option').first()).toHaveCSS('display', 'flex');
    await wizard.getByLabel('Nom').fill(name);
    await wizard.getByLabel('Slug unique').fill(slug);
    await wizard.getByLabel('Devise de base').fill(currency);
    await wizard.getByLabel('Code du journal général').fill(journalCode);
    await wizard.getByRole('button', { name: 'Créer et initialiser' }).click();
    await expect(page.getByText('Dossier créé et initialisé atomiquement.')).toBeVisible();
    await expect(page.getByRole('heading', { name, exact: true })).toBeVisible();
  };

  await createDossier('Comptabilité A E2E', 'test_a-2026', 'CHF', 'ODA');
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
    await expect(adminPage.getByRole('link', {
      name: 'Exporter utilisateurs.csv'
    })).not.toBeVisible();
    await adminPage.getByRole('button', {
      name: 'Importer / exporter les accès'
    }).click();
    await expect(adminPage.getByRole('link', {
      name: 'Exporter utilisateurs.csv'
    })).toBeVisible();
    await expect(adminPage.getByRole('link', {
      name: 'Exporter roles_acces.csv'
    })).toBeVisible();
    const usersCsvInput = adminPage.getByLabel('CSV utilisateurs');
    const accessCsvInput = adminPage.getByLabel('CSV rôles et accès');
    await usersCsvInput.setInputFiles({
      name: 'utilisateurs.csv',
      mimeType: 'text/csv',
      buffer: Buffer.from(
        'email;prenom;nom;actif;mot_de_passe\n'
        + 'admin@example.test;Alex;Administrateur;1;\n'
      )
    });
    await accessCsvInput.setInputFiles({
      name: 'roles_acces.csv',
      mimeType: 'text/csv',
      buffer: Buffer.from(
        'email;portee;organisation;dossier_slug;role\n'
        + 'admin@example.test;installation;;;administrateur\n'
      )
    });
    await adminPage.getByRole('button', {
      name: 'Vérifier les deux CSV'
    }).click();
    await expect(adminPage.getByText(
      /Les affectations sont remplacées uniquement/
    )).toBeVisible();
    await adminPage.getByRole('dialog', {
      name: 'Importer ou exporter les accès'
    }).getByRole('button', { name: 'Fermer' }).click();
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
    await adminPage.locator('fieldset.access-role-grid')
      .getByLabel('Lecteur / auditeur')
      .uncheck();
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
  test.slow();
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

  await journalAccount.selectText();
  await journalAccount.press('Backspace');
  await chooseAccount(journalAccount, '1000');
  await chooseAccount(page.getByLabel('Compte ligne 2'), '3000');
  await page.getByLabel('Référence', { exact: true }).fill('DRAFT-E2E');
  await page.getByLabel('Libellé', { exact: true }).fill('Brouillon E2E');
  const journalAmounts = page.getByPlaceholder('0.00');
  await journalAmounts.nth(0).fill('18.00');
  await journalAmounts.nth(3).fill('18.00');
  await page.getByRole('button', { name: 'Enregistrer le brouillon' }).click();
  await expect(page.getByText('Brouillon enregistré.')).toBeVisible();
  const draftRow = page.getByRole('row').filter({ hasText: 'Brouillon E2E' });
  await expect(draftRow).toHaveCount(1);
  await draftRow.getByRole('button', { name: 'Brouillon' }).click();
  await expect(page.getByRole('heading', { name: /Modifier le brouillon #/ })).toBeVisible();
  await page.getByLabel('Libellé', { exact: true }).fill('Brouillon E2E finalisé');
  await page.getByRole('button', { name: 'Finaliser et valider' }).click();
  await expect(page.getByText('Écriture finalisée et validée.')).toBeVisible();
  await expect(page.getByRole('row').filter({ hasText: 'Brouillon E2E finalisé' })).toHaveCount(1);

  await chooseAccount(page.getByLabel('Compte ligne 1'), '1000');
  await chooseAccount(page.getByLabel('Compte ligne 2'), '3000');
  await page.getByLabel('Référence', { exact: true }).fill('DRAFT-DELETE-E2E');
  await page.getByLabel('Libellé', { exact: true }).fill('Brouillon E2E à supprimer');
  await journalAmounts.nth(0).fill('9.00');
  await journalAmounts.nth(3).fill('8.00');
  await page.getByRole('button', { name: 'Enregistrer le brouillon' }).click();
  const deletableDraftRow = page.getByRole('row').filter({
    hasText: 'Brouillon E2E à supprimer'
  });
  await expect(deletableDraftRow.getByText('-,--', { exact: true })).toBeVisible();
  await deletableDraftRow.getByRole('button', { name: 'Brouillon' }).click();
  await page.getByRole('button', { name: 'Supprimer le brouillon' }).click();
  const draftDeletion = page.getByRole('dialog', {
    name: 'Supprimer définitivement ce brouillon ?'
  });
  await draftDeletion.getByRole('button', { name: 'Supprimer le brouillon' }).click();
  await expect(page.getByText('Brouillon supprimé.')).toBeVisible();
  await expect(deletableDraftRow).toHaveCount(0);

  const recentValidatedRow = page.getByRole('row').filter({
    hasText: 'Brouillon E2E finalisé'
  });
  await expect(recentValidatedRow.getByRole('button', { name: '1000' }))
    .toHaveAttribute('title', /Caisse/);
  await page.getByRole('button', { name: 'Actions du journal' }).click();
  await page.getByRole('menu').getByRole('button', {
    name: 'Voir tout le journal'
  }).click();
  const journalDialog = page.getByRole('dialog', {
    name: 'Journal détaillé de l’exercice'
  });
  await expect(journalDialog).toBeVisible();
  expect((await journalDialog.boundingBox())!.width).toBeGreaterThan(1100);
  expect((await journalDialog.getByRole('columnheader', {
    name: 'Compte', exact: true
  }).boundingBox())!.width).toBeGreaterThan(400);
  expect(await journalDialog.locator('.journal-detail-scroll').evaluate((element) =>
    element.scrollWidth - element.clientWidth
  )).toBeLessThanOrEqual(1);
  await expect(journalDialog.locator('tbody .table-cell-detail')).toHaveCount(0);
  await journalDialog.getByRole('button', { name: /Date/ }).click();
  await journalDialog.getByRole('button', { name: '1000', exact: true }).first().click();
  await expect(page).toHaveURL(/\/compta\/extraits/);

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
  const tAccount = page.locator('.t-account');
  expect(await tAccount.locator(':scope > div').evaluate((element) =>
    getComputedStyle(element).borderTopWidth
  )).toBe('0px');
  expect(await tAccount.locator('h4').first().evaluate((element) =>
    Number.parseFloat(getComputedStyle(element).fontSize)
  )).toBeLessThan(14);
  const tAccountAlignment = await tAccount.evaluate((element) => {
    const sections = element.querySelectorAll('section');
    const totals = element.querySelectorAll('footer b');
    if (sections.length !== 2 || totals.length !== 2) return null;
    const sectionRight = (section: Element) => {
      const box = section.getBoundingClientRect();
      return box.right - Number.parseFloat(getComputedStyle(section).paddingRight);
    };
    return [
      Math.abs(sectionRight(sections[0]) - totals[0].getBoundingClientRect().right),
      Math.abs(sectionRight(sections[1]) - totals[1].getBoundingClientRect().right)
    ];
  });
  expect(tAccountAlignment).not.toBeNull();
  expect(Math.max(...(tAccountAlignment || []))).toBeLessThan(1);

  await openConfiguration(page);
  await page.getByRole('link', { name: 'Référentiels', exact: true }).click();
  await expect(page).toHaveURL(/\/e2e\/app\/configuration\/referentiels\/plan$/);
  await expect(page.getByRole('heading', { name: 'Configuration', level: 1 })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Plan comptable', level: 2 })).toBeVisible();
  await expect(page.getByLabel('Sections du plan comptable')).toBeVisible();
  const savePlan = page.getByRole('button', { name: 'Enregistrer', exact: true });
  await expect(savePlan).toHaveCount(1);
  await expect(savePlan).toBeVisible();
  await expect(savePlan).toBeEnabled();
  const planTabs = page.getByLabel('Sections du plan comptable');
  await expect(planTabs.getByRole('button', { name: 'Exporter le plan' })).toBeVisible();
  await expect(planTabs.getByRole('button', { name: 'Importer un plan' })).toBeVisible();
  await expect(planTabs.getByRole('button', { name: 'Effacer le plan' })).toBeVisible();
  expect(await planTabs.evaluate((element) => getComputedStyle(element).position)).toBe('sticky');
  const [tabsBox, saveBox] = await Promise.all([
    planTabs.boundingBox(),
    savePlan.boundingBox()
  ]);
  expect(tabsBox).not.toBeNull();
  expect(saveBox).not.toBeNull();
  expect(saveBox!.x).toBeGreaterThan(tabsBox!.x + tabsBox!.width / 2);
  expect(await savePlan.evaluate((element) => getComputedStyle(element).backgroundColor))
    .toBe('rgb(32, 33, 78)');
  expect(await savePlan.evaluate((element) => getComputedStyle(element).color))
    .toBe('rgb(255, 255, 255)');
  await savePlan.click();
  await expect(page.getByText('Aucune modification à enregistrer pour « Types ».')).toBeVisible();
  await page.setViewportSize({ width: 360, height: 720 });
  expect(await planTabs.evaluate((element) => getComputedStyle(element).position)).toBe('sticky');
  await expect(savePlan).toBeVisible();
  await expect(planTabs.getByRole('button', { name: 'Exporter le plan' })).toBeVisible();
  await expect(planTabs.getByRole('button', { name: 'Importer un plan' })).toBeVisible();
  await expect(planTabs.getByRole('button', { name: 'Effacer le plan' })).toBeVisible();
  await page.setViewportSize({ width: 1280, height: 720 });
  await page.getByRole('button', { name: 'Sens', exact: true }).click();
  await expect(savePlan).toBeVisible();

  await page.getByRole('button', { name: 'Rubriques', exact: true }).click();
  await expect(savePlan).toContainText('Classes');
  await expect(page.getByRole('columnheader', { name: 'Sous-total' })).toBeVisible();
  const rubricRow = page.locator('.plan-workspace table tbody tr').first();
  const rubricLabel = rubricRow.locator('input').nth(1);
  const rubricSubtotal = rubricRow.getByRole('checkbox');
  await expect(rubricSubtotal).toBeVisible();
  const originalRubricLabel = await rubricLabel.inputValue();
  const originalRubricSubtotal = await rubricSubtotal.isChecked();
  await rubricLabel.fill(`${originalRubricLabel} E2E`);
  await rubricSubtotal.setChecked(!originalRubricSubtotal);
  await expect(savePlan).toBeEnabled();
  await savePlan.click();
  await expect(page.getByText(/1 rubrique\(s\) modifiée\(s\)/)).toBeVisible();
  await expect(rubricSubtotal).toBeChecked({ checked: !originalRubricSubtotal });
  await rubricRow.locator('input').nth(1).fill(originalRubricLabel);
  await rubricSubtotal.setChecked(originalRubricSubtotal);
  await savePlan.click();

  await page.getByRole('button', { name: 'Comptes', exact: true }).click();
  const accountRow = page.locator('.plan-workspace table tbody tr').first();
  const rubricOptions = await accountRow.locator('select').first().locator('option').allTextContents();
  expect(rubricOptions.length).toBeGreaterThan(1);
  expect(rubricOptions.every((label) => !label.includes('‹'))).toBe(true);
  expect(rubricOptions.slice(1).every((label) => label.includes(' — '))).toBe(true);
  expect(await accountRow.locator('select').nth(2).locator('option').allTextContents()).toEqual([
    'Automatique',
    '+/-',
    '-/+'
  ]);
  const saveAccounts = savePlan;
  await expect(saveAccounts).toHaveCount(1);
  await expect(saveAccounts).toBeEnabled();
  const accountLabel = accountRow.locator('input').nth(1);
  const originalLabel = await accountLabel.inputValue();
  await accountLabel.fill(`${originalLabel} E2E`);
  await saveAccounts.click();
  await expect(page.getByText('1 compte(s) enregistré(s).')).toBeVisible();
  await accountRow.locator('input').nth(1).fill(originalLabel);
  await saveAccounts.click();

  const chartCsvResponse = await page.request.get('/e2e/api/v1/accounting/chart/export');
  expect(chartCsvResponse.status()).toBe(200);
  expect(chartCsvResponse.headers()['content-type']).toContain('text/csv');
  const chartCsv = await chartCsvResponse.body();
  await page.getByLabel('Fichier du plan comptable').setInputFiles({
    name: 'plan-comptable.csv',
    mimeType: 'text/csv',
    buffer: chartCsv
  });
  await expect(page.getByRole('heading', {
    name: 'Importer un plan comptable CSV'
  })).toBeVisible();
  await expect(page.getByText('Aucune écriture ni aucun solde')).toBeVisible();
  await page.getByRole('button', { name: 'Confirmer l’import' }).click();
  await expect(page.getByText('Plan comptable importé après validation complète.')).toBeVisible();

  await page.getByRole('button', { name: 'Effacer le plan' }).click();
  await expect(page.getByRole('heading', {
    name: 'Effacer entièrement le plan comptable'
  })).toBeVisible();
  await expect(page.getByText('Effacement impossible.')).toBeVisible();
  await page.getByRole('button', { name: 'Fermer', exact: true }).click();

  await page.getByRole('button', { name: 'Ouverture', exact: true }).click();
  await expect(page.getByRole('columnheader', { name: 'Sens', exact: true })).toBeVisible();
  await expect(page.getByRole('cell', { name: '+/-', exact: true }).first()).toBeVisible();
  const openingTable = page.locator('.opening-balances-table');
  const openingAccountNumbers = (await openingTable.locator('tbody tr td:first-child').allTextContents())
    .map((label) => label.split('—')[0].trim());
  expect(openingAccountNumbers).toEqual([...openingAccountNumbers].sort((left, right) =>
    left.localeCompare(right, 'fr-CH', { numeric: true, sensitivity: 'base' })
  ));
  const focusedOpeningRow = openingTable.locator('tbody tr').first();
  await focusedOpeningRow.locator('input').focus();
  await expect.poll(() => focusedOpeningRow.locator('td').evaluateAll((cells) =>
    cells.map((cell) => getComputedStyle(cell).backgroundColor)
  )).toEqual(['rgb(236, 238, 242)', 'rgb(236, 238, 242)', 'rgb(236, 238, 242)', 'rgb(236, 238, 242)']);
  await expect(planTabs.getByRole('button', {
    name: 'Exporter les soldes d’ouverture'
  })).toBeVisible();
  await expect(planTabs.getByRole('button', {
    name: 'Importer les soldes d’ouverture'
  })).toBeVisible();
  await expect(planTabs.getByRole('button', {
    name: 'Effacer les soldes d’ouverture'
  })).toBeVisible();
  await expect(page.getByRole('button', {
    name: 'Enregistrer le brouillon',
    exact: true
  })).toBeEnabled();
  const validateOpening = planTabs.getByRole('button', {
    name: 'Valider l’ouverture',
    exact: true
  });
  await expect(validateOpening).toBeEnabled();
  expect(await validateOpening.evaluate((element) => getComputedStyle(element).backgroundColor))
    .toBe('rgb(32, 33, 78)');

  const legacy = await page.request.get('/e2e/compta/plan', { maxRedirects: 0 });
  expect(legacy.status()).toBe(303);
  expect(legacy.headers().location).toBe('/e2e/app/configuration/referentiels/plan');
});

test('états, clôture et dossier fiscal utilisent le grand livre unique', async ({ page }) => {
  test.setTimeout(60_000);
  await loginAsAdministrator(page);
  await selectDossier(page, 'Comptabilité principale');
  await page.getByRole('link', { name: 'Comptabilité', exact: true }).click();

  await page.getByRole('link', { name: 'États financiers', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'États financiers' })).toBeVisible();
  await expect(page.getByText('Débit = crédit')).toBeVisible();
  await expect(page.getByText('Résultat réconcilié')).toBeVisible();
  const firstReportedAccount = page.locator('.financial-ledger-table .account-link').first();
  const firstReportedAccountNumber = (await firstReportedAccount.textContent())?.trim() || '';
  await firstReportedAccount.click();
  await expect(page).toHaveURL(/\/compta\/extraits$/);
  await expect(page.getByLabel('Compte', { exact: true })).toHaveValue(
    new RegExp(firstReportedAccountNumber)
  );
  await page.getByRole('link', { name: 'États financiers', exact: true }).click();
  await page.getByRole('button', { name: 'Bilan', exact: true }).click();
  const financialStatement = page.locator('.financial-report-panel');
  const statementTable = financialStatement.locator('.financial-statement-table');
  await expect(financialStatement.locator('h3')).toHaveCount(0);
  await expect(financialStatement.getByText(/BILAN AU/)).toBeVisible();
  await expect(financialStatement.getByRole('button', { name: 'PDF', exact: true })).toBeVisible();
  expect(await statementTable.locator('thead th').first().textContent()).toBe('');
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
  const percentageButton = financialStatement.getByRole('button', { name: '%', exact: true });
  await percentageButton.click();
  await expect(percentageButton).toHaveCSS('background-color', 'rgb(32, 33, 78)');
  await expect(financialStatement.getByText(/BILAN AU .* — POURCENT/)).toBeVisible();
  await expect(statementTable.getByText(/^100[,.]0$/).first()).toBeVisible();
  expect((await statementTable.locator('.amount').allTextContents()).every(
    (value) => !value.includes('%')
  )).toBe(true);
  await page.getByRole('button', { name: 'Compte de résultat', exact: true }).click();
  await expect(financialStatement.getByText(/RÉSULTAT DU .* AU .* — POURCENT/)).toBeVisible();
  expect((await statementTable.locator('.amount').allTextContents()).every(
    (value) => !value.includes('%')
  )).toBe(true);
  await financialStatement.getByRole('button', { name: 'CHF', exact: true }).click();
  await expect(financialStatement.getByText(/RÉSULTAT DU .* AU .* — CHF/)).toBeVisible();
  await expect(page.getByRole('row', {
    name: /RÉSULTAT NET DE L.EXERCICE/
  })).toBeVisible();
  await page.getByRole('button', { name: 'Flux de trésorerie' }).click();
  await expect(financialStatement.getByText(/FLUX DE TRÉSORERIE ENTRE/)).toBeVisible();
  await expect(financialStatement.getByText(/Méthode indirecte/)).toHaveCount(0);
  await expect(page.getByRole('row', {
    name: /VARIATION NETTE DE LA TRÉSORERIE/
  })).toBeVisible();
  await page.emulateMedia({ media: 'print' });
  await expect(page.locator('.topbar')).toBeHidden();
  await expect(page.locator('.compact-tabs')).toBeHidden();
  await expect(financialStatement).toBeVisible();
  await page.emulateMedia({ media: 'screen' });

  await page.getByRole('link', { name: 'Clôture', exact: true }).click();
  const closingTabs = page.getByRole('navigation', {
    name: 'Sections de clôture'
  });
  await expect(closingTabs.getByRole('link')).toHaveText([
    'Amortissements',
    'TVA',
    'Contrôles',
    'Dossier fiscal'
  ]);
  await expect(closingTabs.locator('a.active')).toHaveCount(1);
  await expect(closingTabs.locator('a.active')).toHaveText('Amortissements');
  await expect(closingTabs.locator('a.active')).toHaveCSS(
    'border-bottom-color',
    'rgb(32, 33, 78)'
  );
  await closingTabs.getByRole('link', { name: 'Contrôles' }).click();
  await expect(page.getByRole('heading', { name: 'Clôture et verrouillage' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Contrôles documentés' })).toBeVisible();
  await page.getByRole('button', { name: 'Archiver la clôture' }).click();
  await expect(page.getByText('Archive financière immuable créée.')).toBeVisible();

  await page.getByRole('link', { name: 'États financiers', exact: true }).click();
  await page.getByRole('button', { name: 'Archives', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Archives financières' })).toBeVisible();
  await page.getByRole('button', { name: 'Consulter les états archivés' }).click();
  const archiveDialog = page.getByRole('dialog', { name: /Archive financière/ });
  await expect(archiveDialog.getByText(/BILAN ARCHIVÉ AU .* — CHF/)).toBeVisible();
  await archiveDialog.getByRole('button', { name: 'Compte de résultat' }).click();
  await expect(archiveDialog.getByText(/RÉSULTAT ARCHIVÉ DU .* AU .* — CHF/)).toBeVisible();
  await archiveDialog.getByRole('button', { name: 'Balances de vérification' }).click();
  await expect(archiveDialog.getByText(/BALANCES DE VÉRIFICATION ARCHIVÉES/)).toBeVisible();
  await archiveDialog.getByRole('button', { name: 'Journal complet' }).click();
  await expect(archiveDialog.getByText(/JOURNAL COMPLET ARCHIVÉ/)).toBeVisible();
  await expect(archiveDialog.getByText('Écritures')).toBeVisible();
  expect((await archiveDialog.boundingBox())!.width).toBeGreaterThan(1100);
  await archiveDialog.getByRole('button', { name: 'Fermer' }).click();
  await page.getByRole('button', { name: 'Supprimer', exact: true }).click();
  const archiveDeletion = page.getByRole('dialog', {
    name: 'Supprimer cette archive financière ?'
  });
  await archiveDeletion.getByRole('button', { name: 'Supprimer l’archive' }).click();
  await expect(page.getByText('Archive financière supprimée.')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Aucune archive financière' })).toBeVisible();

  await page.getByRole('link', { name: 'Clôture', exact: true }).click();
  await page.getByRole('link', { name: 'Dossier fiscal', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Dossier fiscal' })).toBeVisible();
  await expect(page.getByText(/aucun calcul fiscal officiel/i)).toBeVisible();

  const legacy = await page.request.get('/e2e/compta/bilan', { maxRedirects: 0 });
  expect(legacy.status()).toBe(303);
  expect(legacy.headers().location).toBe('/e2e/app/compta/etats');
});

test('registre, échéancier et dotation des immobilisations utilisent les modales', async ({ page }) => {
  test.setTimeout(60_000);
  await page.clock.setFixedTime(new Date('2026-08-01T10:00:00+02:00'));
  await loginAsAdministrator(page);
  await selectDossier(page, 'Comptabilité principale');
  await collapseSetupGuide(page);
  await page.getByRole('link', { name: 'Comptabilité', exact: true }).click();
  await page.getByRole('link', { name: 'Clôture', exact: true }).click();
  const defaultClosingTabs = page.getByRole('navigation', {
    name: 'Sections de clôture'
  });
  await expect(defaultClosingTabs.locator('a.active')).toHaveText('Amortissements');
  await expect(page.getByRole('heading', {
    name: 'Immobilisations et amortissements'
  })).toBeVisible();

  await page.getByRole('button', { name: 'Catégories', exact: true }).click();
  await page.getByRole('button', {
    name: 'Nouvelle catégorie d’amortissement'
  }).click();
  const categoryDialog = page.getByRole('dialog', {
    name: 'Nouvelle catégorie d’amortissement'
  });
  await categoryDialog.getByLabel('Code', { exact: true }).fill('INFO-E2E');
  await categoryDialog.getByLabel('Libellé', { exact: true }).fill('Informatique E2E');
  await categoryDialog.getByLabel('Durée utile (mois)').fill('36');
  for (const [label, account] of [
    ['Compte d’actif', '1520'],
    ['Amortissements cumulés', '1529'],
    ['Dotation', '6800'],
    ['Gain de cession', '8510'],
    ['Perte de cession', '8500']
  ] as const) {
    await chooseAccount(
      categoryDialog.getByRole('combobox', { name: label, exact: true }),
      account
    );
  }
  await categoryDialog.getByRole('button', { name: 'Créer la catégorie' }).click();
  const categoryRow = page.getByRole('row').filter({ hasText: 'INFO-E2E' });
  await expect(categoryRow).toBeVisible();
  await expect(categoryRow.getByRole('button', {
    name: 'Actions pour INFO-E2E'
  })).toBeVisible();

  await page.getByRole('button', { name: 'Registre', exact: true }).click();
  await page.getByRole('button', { name: 'Nouvelle immobilisation' }).click();
  const firstAssetDialog = page.getByRole('dialog', { name: 'Nouvelle immobilisation' });
  await expect(firstAssetDialog.getByLabel('Valeur résiduelle')).toHaveValue('0.01');
  await expect(firstAssetDialog.getByLabel('Note')).toHaveCount(0);
  await expect(firstAssetDialog.getByLabel('Durée utile (mois)')).toHaveCount(0);
  await firstAssetDialog.getByRole('combobox', {
    name: 'Catégorie', exact: true
  }).selectOption({
    label: 'INFO-E2E — Informatique E2E'
  });
  await expect(firstAssetDialog.getByText('Durée utile : 36 mois')).toBeVisible();
  await firstAssetDialog.getByLabel('Code', { exact: true }).fill('PC-E2E');
  await firstAssetDialog.getByLabel('Libellé', { exact: true }).fill('Poste de test E2E');
  await firstAssetDialog.getByLabel('Référence de pièce').fill('FAC-PC-E2E');
  await firstAssetDialog.getByLabel('Date d’acquisition').fill('2026-07-10');
  await firstAssetDialog.getByLabel('Mise en service').fill('2026-07-15');
  await firstAssetDialog.getByLabel('Valeur d’acquisition').fill('2400.01');
  await firstAssetDialog.getByRole('button', {
    name: 'Créer la fiche et l’échéancier'
  }).click();
  const assetRow = page.getByRole('row').filter({ hasText: 'PC-E2E' });
  await expect(assetRow).toContainText(/CHF 2\s400\.01/);
  await expect(assetRow.getByRole('button', { name: 'Actions pour PC-E2E' })).toBeVisible();

  await page.getByRole('button', { name: 'Nouvelle immobilisation' }).click();
  const secondAssetDialog = page.getByRole('dialog', { name: 'Nouvelle immobilisation' });
  await secondAssetDialog.getByRole('combobox', {
    name: 'Catégorie', exact: true
  }).selectOption({
    label: 'INFO-E2E — Informatique E2E'
  });
  await secondAssetDialog.getByLabel('Code', { exact: true }).fill('PC E2E/02');
  await secondAssetDialog.getByLabel('Libellé', { exact: true }).fill('Second poste de test E2E');
  await secondAssetDialog.getByLabel('Référence de pièce').fill('FAC-PC-E2E-02');
  await secondAssetDialog.getByLabel('Date d’acquisition').fill('2026-07-10');
  await secondAssetDialog.getByLabel('Mise en service').fill('2026-07-15');
  await secondAssetDialog.getByLabel('Valeur d’acquisition').fill('1200.00');
  await expect(secondAssetDialog.getByLabel('Valeur résiduelle')).toHaveValue('0.01');
  await secondAssetDialog.getByRole('button', {
    name: 'Créer la fiche et l’échéancier'
  }).click();
  await expect(page.getByRole('row').filter({ hasText: 'PC E2E/02' })).toContainText(
    /CHF 1\s200\.00/
  );

  await assetRow.getByRole('button', { name: 'PC-E2E', exact: true }).click();
  const assetViewer = page.getByRole('dialog', { name: 'Fiche PC-E2E' });
  await expect(assetViewer.getByRole('heading', {
    name: 'PC-E2E — Poste de test E2E'
  })).toBeVisible();
  await expect(assetViewer.getByText('36 mois', { exact: true })).toBeVisible();
  await assetViewer.getByRole('button', { name: 'Fermer' }).click();

  await page.getByRole('button', { name: 'Échéancier', exact: true }).click();
  await expect(page.getByText(
    'Dotations trimestrielles regroupées par compte d’actif, catégorie d’amortissement et période.'
  )).toBeVisible();
  const accountGroup = page.locator('.account-schedule-card').filter({
    hasText: '1520 — Machines de bureau, informatique, systèmes de communication'
  }).filter({ hasText: 'INFO-E2E' });
  await expect(accountGroup).toBeVisible();
  await expect(accountGroup.getByRole('table')).toHaveCount(0);
  await expect(accountGroup.getByText('0 à comptabiliser', { exact: true })).toBeVisible();
  await accountGroup.getByRole('button', {
    name: 'Déployer l’échéancier INFO-E2E'
  }).click();
  await expect(accountGroup.getByRole('button', {
    name: 'Réduire l’échéancier INFO-E2E'
  })).toHaveAttribute('aria-expanded', 'true');
  await expect(accountGroup.getByRole('tab', {
    name: /Échus à comptabiliser/
  })).toHaveAttribute('aria-selected', 'true');
  await expect(accountGroup.getByRole('heading', {
    name: 'Aucun amortissement échu à comptabiliser'
  })).toBeVisible();
  await accountGroup.getByRole('tab', { name: /À venir/ }).click();
  const firstScheduleRow = accountGroup.getByRole('row').filter({
    hasText: '2026-07-15 – 2026-09-30'
  });
  await expect(firstScheduleRow.getByRole('button', {
    name: 'PC-E2E', exact: true
  })).toBeVisible();
  await expect(firstScheduleRow.getByRole('button', {
    name: 'PC E2E/02', exact: true
  })).toBeVisible();
  await expect(firstScheduleRow.getByRole('button', {
    name: /Actions pour la période du 2026-09-30/
  })).toHaveCount(1);
  await firstScheduleRow.getByRole('button', {
    name: /Actions pour la période du 2026-09-30/
  }).click();
  await page.getByRole('menu').getByRole('button', {
    name: 'Comptabiliser', exact: true
  }).click();
  await expect(page.getByText(
    'Amortissements du groupe comptabilisés dans le grand livre.'
  )).toBeVisible();
  await expect(firstScheduleRow).toContainText('Comptabilisée');

  await page.getByRole('button', { name: 'Réconciliation', exact: true }).click();
  const reconciliationRow = page.getByRole('row').filter({
    hasText: '1520 — Machines de bureau, informatique, systèmes de communication'
  }).filter({ hasText: 'PC-E2E' });
  await reconciliationRow.getByRole('button', {
    name: /Afficher le détail du compte 1520/
  }).click();
  const reconciliationDetail = page.locator('.reconciliation-detail');
  await expect(reconciliationDetail.getByRole('button', {
    name: 'PC-E2E', exact: true
  })).toBeVisible();
  await expect(reconciliationDetail).toContainText('FAC-PC-E2E');
  await expect(reconciliationDetail).toContainText(/CHF 2\s400\.01/);
  await expect(reconciliationDetail.getByText(
    /Aucun mouvement validé n’a été retrouvé sur ce compte/
  )).toBeVisible();

  await page.getByRole('button', { name: 'Registre', exact: true }).click();
  await assetRow.getByRole('button', { name: 'Actions pour PC-E2E' }).click();
  await page.getByRole('menu').getByRole('button', { name: 'Corriger' }).click();
  const correctionDialog = page.getByRole('dialog', { name: 'Corriger l’immobilisation' });
  await correctionDialog.getByRole('button', { name: 'Enregistrer la correction' }).click();
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
  test.setTimeout(45_000);
  await loginAsAdministrator(page);
  await selectDossier(page, 'Comptabilité principale');
  await page.getByRole('link', { name: 'Facturation', exact: true }).click();
  await collapseSetupGuide(page);
  await expect(page.getByRole('heading', { name: 'Factures clients' })).toHaveCount(0);
  await expect(page.getByText(
    /Créances et dettes ouvertes, calculées au/
  )).toBeVisible();
  const billingTabs = page.getByLabel('Navigation de la facturation');
  await expect(billingTabs).toContainText('Récurrences');
  await expect(billingTabs.getByRole('link', { name: 'Exporter la vue CSV' })).toBeVisible();
  await expect(page.getByLabel('Date de référence')).toBeVisible();

  await page.getByRole('link', { name: 'Contacts', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Contacts et vue 360°' })).toHaveCount(0);
  await expect(page.getByText(
    'Un registre unique pour les rôles client et fournisseur.'
  )).toBeVisible();
  await page.getByRole('button', { name: 'Nouveau contact' }).click();
  await page.getByLabel('Raison sociale').fill('Client E2E SA');
  await page.getByLabel('Courriel').fill('client-e2e@example.test');
  await page.getByLabel('Adresse').fill('Rue du Client 1');
  await page.getByLabel('NPA').fill('1200');
  await page.getByLabel('Localité').fill('Genève');
  await page.getByRole('button', { name: 'Créer le contact' }).click();
  const contactRow = page.getByRole('row').filter({ hasText: 'Client E2E SA' });
  await expect(contactRow).toBeVisible();
  await clickRowAction(page, contactRow, 'Vue 360°');
  const contactDialog = page.getByRole('dialog', { name: 'Client E2E SA' });
  await expect(contactDialog.getByText('Solde net', { exact: true })).toHaveCount(0);
  await expect(contactDialog.getByRole('heading', { name: 'Factures' })).toBeVisible();
  await expect(contactDialog.getByRole('heading', { name: 'Paiements' })).toBeVisible();
  await contactDialog.getByRole('button', { name: 'Fermer' }).click();

  await page.getByRole('link', { name: 'Ventes', exact: true }).click();
  await page.getByRole('button', { name: 'Nouveau document' }).click();
  const newInvoiceDialog = page.getByRole('dialog', {
    name: 'Nouvelle facture client'
  });
  await expect(newInvoiceDialog).toBeVisible();
  await chooseAccount(
    newInvoiceDialog.getByLabel('Client', { exact: true }),
    'Client E2E SA'
  );
  await newInvoiceDialog.getByLabel('Date du document').fill('2026-07-20');
  await newInvoiceDialog.getByLabel('Échéance explicite').fill('2026-07-26');
  const collective = newInvoiceDialog.getByLabel('Compte de paiement');
  await chooseAccount(collective, '1100', 'Tab');
  await newInvoiceDialog.getByLabel('Libellé', { exact: true }).fill('Prestation E2E');
  await newInvoiceDialog.getByLabel('Prix unitaire', { exact: true }).fill('100.00');
  const revenue = newInvoiceDialog.getByLabel('Compte', { exact: true });
  await revenue.fill('3400');
  await page.getByText('Ligne 1', { exact: true }).click();
  await expect(revenue).toHaveValue(/3400/);
  await newInvoiceDialog.getByRole('button', { name: 'Enregistrer le brouillon' }).click();
  expect(await newInvoiceDialog.getByLabel('Code TVA').evaluate((element) =>
    (element as HTMLSelectElement).matches(':invalid')
  )).toBe(true);
  await newInvoiceDialog.getByLabel('Code TVA')
    .selectOption({ label: 'VE81 · Ventes 8,1 %' });
  await newInvoiceDialog.getByRole('button', { name: 'Enregistrer le brouillon' }).click();
  const invoice = page.getByRole('row').filter({ hasText: 'Client E2E SA' });
  await expect(invoice).toContainText('108.10 CHF');
  await clickRowAction(page, invoice, 'Modifier');
  const editDialog = page.getByRole('dialog', { name: /Modifier le brouillon/ });
  await expect(editDialog.getByLabel('Libellé', { exact: true }))
    .toHaveValue('Prestation E2E');
  await editDialog.getByLabel('Libellé', { exact: true }).fill('Prestation E2E corrigée');
  await editDialog.getByRole('button', { name: 'Enregistrer les modifications' }).click();
  await expect(page.getByText('Brouillon mis à jour.')).toBeVisible();
  await clickRowAction(page, invoice, 'Émettre');
  await expect(invoice).toContainText(/FV-2026-/);
  await clickRowAction(page, invoice, 'Comptabiliser');
  await expect(page.getByText('Document comptabilisé dans le grand livre.')).toBeVisible();
  await invoice.locator('.table-primary-link').click();
  const invoiceViewer = page.getByRole('dialog', { name: /FV-2026-/ });
  await expect(invoiceViewer.getByLabel('Résumé financier')).toBeVisible();
  await expect(invoiceViewer.getByText('Totaux', { exact: true })).toBeVisible();
  await expect(invoiceViewer.getByRole('columnheader', { name: 'Quantité' })).toBeVisible();
  await expect(invoiceViewer.getByRole('columnheader', { name: 'Prix unitaire' })).toBeVisible();
  await expect(invoiceViewer.getByRole('columnheader', { name: 'Mode du prix' })).toBeVisible();
  const invoiceLine = invoiceViewer.getByRole('row').filter({
    hasText: 'Prestation E2E corrigée'
  });
  const invoiceLineCells = invoiceLine.getByRole('cell');
  await expect(invoiceLineCells.nth(1)).toHaveText('1');
  await expect(invoiceLineCells.nth(2)).toHaveText('100.00 CHF');
  await expect(invoiceLineCells.nth(3)).toHaveText('Hors TVA');
  await expect(invoiceLineCells.nth(6)).toHaveText('108.10 CHF');
  await invoiceViewer.getByRole('button', { name: 'Fermer' }).click();

  await page.getByRole('button', { name: 'Nouveau document' }).click();
  const reversalInvoiceDialog = page.getByRole('dialog', {
    name: 'Nouvelle facture client'
  });
  await chooseAccount(
    reversalInvoiceDialog.getByLabel('Client', { exact: true }),
    'Client E2E SA'
  );
  await reversalInvoiceDialog.getByLabel('Date du document').fill('2026-07-21');
  await reversalInvoiceDialog.getByLabel('Échéance explicite').fill('2026-07-27');
  await chooseAccount(
    reversalInvoiceDialog.getByLabel('Compte de paiement'),
    '1100',
    'Tab'
  );
  await reversalInvoiceDialog.getByLabel('Libellé', { exact: true })
    .fill('Facture à extourner');
  await reversalInvoiceDialog.getByLabel('Prix unitaire', { exact: true })
    .fill('50.00');
  const reversalRevenue = reversalInvoiceDialog.getByLabel('Compte', {
    exact: true
  });
  await reversalRevenue.fill('3400');
  await reversalInvoiceDialog.getByText('Ligne 1', { exact: true }).click();
  await reversalInvoiceDialog.getByLabel('Code TVA')
    .selectOption({ label: 'VE81 · Ventes 8,1 %' });
  await reversalInvoiceDialog.getByRole('button', {
    name: 'Enregistrer le brouillon'
  }).click();
  const reversedInvoiceRow = page.getByRole('row').filter({ hasText: '54.05 CHF' });
  await clickRowAction(page, reversedInvoiceRow, 'Émettre');
  await clickRowAction(page, reversedInvoiceRow, 'Comptabiliser');
  await clickRowAction(page, reversedInvoiceRow, 'Extourner');
  const reversalDialog = page.getByRole('dialog', { name: 'Extourner la facture' });
  await expect(reversalDialog.getByLabel('Date de l’extourne')).toHaveValue(
    '2026-07-21'
  );
  await reversalDialog.getByRole('button', { name: 'Confirmer l’extourne' }).click();
  await expect(page.getByText(/seul le solde restant a été contre-passé/))
    .toBeVisible();
  await expect(reversedInvoiceRow).toContainText('Extournée');
  await expect(reversedInvoiceRow).toContainText('Soldé');
  await expect(reversedInvoiceRow).toContainText('0.00 CHF');
  await reversedInvoiceRow.locator('.table-primary-link').click();
  const reversedInvoiceViewer = page.getByRole('dialog', { name: /FV-2026-/ });
  await expect(reversedInvoiceViewer.getByText('Écriture d’extourne')).toBeVisible();
  await reversedInvoiceViewer.getByRole('button', { name: 'Fermer' }).click();

  const nonTaxableRegimeStatus = await page.evaluate(async () => {
    const context = await fetch('/e2e/api/v1/context').then((response) => response.json());
    const response = await fetch('/e2e/api/v1/configuration/references/vat-regimes', {
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
          status: 'non_assujetti',
          vat_number: '',
          method: 'effective',
          reporting_mode: 'convenues',
          frequency: 'annuelle',
          valid_from: '2026-07-22',
          valid_until: '',
          input_material_account_id: null,
          input_investment_account_id: null,
          vat_due_account_id: null,
          vat_settlement_account_id: null,
          corrections_account_id: null
        }
      })
    });
    return response.status;
  });
  expect(nonTaxableRegimeStatus).toBe(200);

  await page.getByRole('link', { name: 'Achats', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Factures fournisseurs' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Nouveau document' }).click();
  const purchaseDialog = page.getByRole('dialog', {
    name: 'Nouvelle facture fournisseur'
  });
  await expect(purchaseDialog.getByLabel('Compte de paiement')).toBeVisible();
  await expect(purchaseDialog.getByLabel('Référence fournisseur')).toBeVisible();
  await expect(purchaseDialog.getByLabel('Code TVA')).toBeVisible();
  await expect(purchaseDialog.getByLabel('Saisie')).toBeVisible();
  await expect(purchaseDialog.getByLabel('Saisie')).toHaveValue('brut');
  await purchaseDialog.getByLabel('Code TVA').selectOption({
    label: 'AM81 · Achats 8,1 %'
  });
  await purchaseDialog.getByLabel('Saisie').selectOption('net');
  await expect(purchaseDialog.getByLabel('Saisie')).toHaveValue('net');
  await purchaseDialog.getByRole('button', { name: 'Fermer' }).click();

  await page.getByRole('link', { name: 'Échéancier', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Échéancier et lettrage' })).toHaveCount(0);
  await expect(page.getByText(/Créances et dettes ouvertes, calculées au/)).toBeVisible();
  await expect(page.getByText('Créances nettes', { exact: true })).toBeVisible();
  const agingChart = page.getByRole('img', {
    name: 'Répartition graphique des créances et des dettes par ancienneté'
  });
  await expect(agingChart).toBeVisible();
  await expect(agingChart).not.toContainText('CHF');
  await expect(agingChart.getByText('Non échu', { exact: true })).toBeVisible();
  await expect(agingChart.getByText('Plus de 90 jours', { exact: true })).toBeVisible();
  await expect(agingChart.locator('.aging-chart-legend')).toHaveCount(0);
  await expect(agingChart.locator('.aging-chart-series strong')).toHaveCount(0);
  expect(await agingChart.locator('.aging-chart-bar').evaluateAll((bars) =>
    bars.some((bar) => (bar as HTMLElement).style.height === '100%')
  )).toBe(true);
  await expect(page.getByLabel('Tranches d’âge des créances et dettes'))
    .not.toContainText('CHF');
  await expect(page.getByRole('heading', { name: 'Saisir un paiement' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Saisir un paiement' }).click();
  const paymentDialog = page.getByRole('dialog', { name: 'Saisir un paiement' });
  await expect(paymentDialog).toBeVisible();
  await paymentDialog.getByLabel('Contact', { exact: true })
    .selectOption({ label: 'Client E2E SA' });
  await paymentDialog.getByLabel('Date').fill('2026-07-26');
  await paymentDialog.getByLabel('Montant CHF').fill('108.10');
  await paymentDialog.getByLabel('Référence').fill('REGLEMENT-E2E');
  await chooseAccount(paymentDialog.getByLabel('Compte de trésorerie'), '1020');
  await paymentDialog.getByRole('button', { name: 'Enregistrer' }).click();
  await expect(page.getByText('Paiement saisi indépendamment des factures.'))
    .toBeVisible();
  await page.getByRole('button', { name: 'Allouer un paiement' }).click();
  const allocationDialog = page.getByRole('dialog', {
    name: 'Allouer un paiement'
  });
  await allocationDialog.getByLabel('Paiement disponible').selectOption({ index: 1 });
  await allocationDialog.getByLabel('Facture compatible').selectOption({ index: 1 });
  await allocationDialog.getByLabel('Montant à allouer').fill('108.10');
  await allocationDialog.getByRole('button', {
    name: 'Lettrer et comptabiliser si soldé'
  }).click();
  await expect(page.getByText('Paiement intégralement lettré et comptabilisé.'))
    .toBeVisible();
  await page.getByRole('button', { name: 'Tracer un rappel' }).click();
  await expect(page.getByRole('dialog', { name: 'Tracer un rappel' })).toBeVisible();
  await page.getByRole('dialog', { name: 'Tracer un rappel' })
    .getByRole('button', { name: 'Fermer' }).click();
  await page.getByRole('link', { name: 'Récurrences', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Factures récurrentes' })).toHaveCount(0);
  await expect(page.getByText(
    'Chaque échéance crée un brouillon idempotent à contrôler avant émission.'
  )).toBeVisible();

  const legacy = await page.request.get('/e2e/facturation', { maxRedirects: 0 });
  expect(legacy.status()).toBe(303);
  expect(legacy.headers().location).toBe('/e2e/app/facturation');
});

test('dépense fournisseur approuvée et comptabilisée dans Vue', async ({ page }) => {
  test.setTimeout(60_000);
  await loginAsAdministrator(page);
  await selectDossier(page, 'Comptabilité principale');
  await page.getByRole('link', { name: 'Liquidités', exact: true }).click();
  await collapseSetupGuide(page);
  await expect(page.getByRole('heading', { name: 'Utilisation des liquidités' })).toHaveCount(0);
  await expect(page.getByText(
    'La création reste toujours en brouillon. Paiement et allocation sont séparés.'
  )).toBeVisible();
  await page.getByRole('button', { name: 'Nouvelle récurrence' }).click();
  const recurrenceDialog = page.getByRole('dialog', { name: 'Nouvelle récurrence' });
  await expect(recurrenceDialog).toBeVisible();
  await expect(recurrenceDialog.getByText('Contrepartie 1')).toBeVisible();
  await recurrenceDialog.getByRole('button', { name: 'Fermer' }).click();
  await page.getByRole('button', { name: 'Nouvelle dépense' }).click();
  const expenseDialog = page.getByRole('dialog', { name: 'Nouvelle dépense ponctuelle' });
  await expect(expenseDialog).toBeVisible();
  await expect(expenseDialog.getByText('Contrepartie 1')).toBeVisible();
  await expenseDialog.getByLabel('Fournisseur', { exact: true }).selectOption({
    label: 'Fournitures E2E SA'
  });
  await expenseDialog.getByLabel('Référence fournisseur').fill('E2E-DEP-001');
  await expenseDialog.getByLabel('Date du document').fill('2026-07-20');
  await expenseDialog.getByLabel('Échéance').fill('2026-08-19');
  const payable = expenseDialog.getByLabel('Paiement fournisseur');
  await chooseAccount(payable, '2000');
  await expect(expenseDialog.getByLabel('Justificatif facultatif')).toBeVisible();
  await expenseDialog.getByLabel('Libellé', { exact: true }).fill('Fournitures de bureau');
  await expenseDialog.getByLabel('Montant', { exact: true }).fill('100.00');
  const expenseAccount = expenseDialog.getByLabel('Compte de charge');
  await chooseAccount(expenseAccount, '6500', 'Tab');
  await expenseDialog.getByLabel('Code TVA').selectOption({ label: 'AM81 · Achats 8,1 %' });
  await expenseDialog.getByRole('button', { name: 'Enregistrer le brouillon' }).click();
  const row = page.getByRole('row').filter({ hasText: 'Fournitures E2E SA' });
  await expect(row).toContainText('108.10 CHF');
  await expect(row).toContainText('Brouillon');
  await row.getByRole('button', { name: /^Brouillon #/ }).click();
  const editExpenseDialog = page.getByRole('dialog', {
    name: /Modifier le brouillon #/
  });
  await expect(editExpenseDialog).toBeVisible();
  await expect(editExpenseDialog.getByLabel('Référence fournisseur')).toHaveValue(
    'E2E-DEP-001'
  );
  await editExpenseDialog.getByLabel('Libellé', { exact: true })
    .fill('Fournitures de bureau ajustées');
  await editExpenseDialog.getByRole('button', {
    name: 'Enregistrer le brouillon'
  }).click();
  await clickRowAction(page, row, 'Soumettre');
  await expect(row).toContainText('À approuver');
  await row.getByRole('button', { name: /^DEP-/ }).click();
  const detailDialog = page.getByRole('dialog').filter({
    hasText: 'Détail de la dépense'
  });
  const detail = detailDialog.getByRole('article');
  await expect(detail).toContainText('Référence fournisseur');
  await expect(detail).toContainText('E2E-DEP-001');
  await expect(detail).toContainText('2000 Dettes');
  await expect(detail).toContainText("6500 Charges d'administration");
  await expect(detail).toContainText('AM81');
  await detailDialog.getByRole('button', { name: 'Fermer' }).click();
  await clickRowAction(page, row, 'Approuver');
  await expect(row).toContainText('Approuvé');
  await clickRowAction(page, row, 'Comptabiliser');
  await expect(row).toContainText('Comptabilisé');
  await row.getByRole('button', { name: /^Actions pour / }).click();
  await expect(page.getByRole('menu').getByRole('button', {
    name: 'Annuler',
    exact: true
  })).toHaveCount(0);
});

test('rapprochement, lettrage et paiements sortants utilisent le parcours Vue', async ({ page }) => {
  await loginAsAdministrator(page);
  await selectDossier(page, 'Comptabilité principale');
  await page.getByRole('link', { name: 'Liquidités', exact: true }).click();
  await collapseSetupGuide(page);
  await page.getByRole('link', { name: 'Rapprochement', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Rapprochement bancaire' })).toHaveCount(0);
  await expect(page.getByText(
    'Le relevé, ses empreintes et le grand livre restent des sources distinctes.'
  )).toBeVisible();
  const bankSelect = page.getByLabel('Compte bancaire');
  await chooseAccount(bankSelect, '1020');
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
  await expect(page.getByRole('heading', { name: 'Lettrage des paiements' })).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Nouveau paiement' })).toHaveCount(0);
  await expect(page.getByText(
    'Un paiement reste indépendant et peut couvrir plusieurs documents.'
  )).toBeVisible();
  await expect(page.getByRole('heading', {
    name: 'Allouer à un document ouvert'
  })).toHaveCount(0);

  await page.getByRole('link', { name: 'Taux', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Taux de change' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Taux d’intérêt' }).click();
  await expect(page.getByRole('heading', { name: 'Taux d’intérêt' })).toHaveCount(0);
  await expect(page.getByText('Référentiel public partagé')).toHaveCount(0);

  await page.getByRole('link', { name: 'Paiements', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Paiements sortants' })).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Nouveau paiement' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Saisir un paiement' }).click();
  const liquidityPaymentDialog = page.getByRole('dialog', {
    name: 'Saisir un paiement'
  });
  await expect(liquidityPaymentDialog).toBeVisible();
  await expect(liquidityPaymentDialog.getByLabel('Compte de trésorerie')).toBeVisible();
  await liquidityPaymentDialog.getByRole('button', { name: 'Fermer' }).click();
  await page.getByRole('button', { name: 'Lots', exact: true }).click();
  await expect(page.getByText('export pain.001 non transmis', { exact: false })).toBeVisible();
});

test('offre acceptée, commande reliée et création directe restent facultatives', async ({
  page
}) => {
  test.setTimeout(45_000);
  await loginAsAdministrator(page);
  await selectDossier(page, 'Comptabilité principale');
  await page.getByRole('link', { name: 'Facturation', exact: true }).click();
  await page.getByRole('link', { name: 'Offres', exact: true }).click();
  await expect(page.getByRole('button', {
    name: 'Demander une offre à un fournisseur'
  })).toHaveCount(0);

  await page.getByRole('button', { name: 'Offre client', exact: true }).click();
  const offerDialog = page.getByRole('dialog', { name: 'Offre client' });
  await chooseAccount(
    offerDialog.getByLabel('Client', { exact: true }),
    'Client Commercial E2E SA'
  );
  await offerDialog.getByLabel('Date').fill('2026-07-20');
  await offerDialog.getByLabel('Valable jusqu’au').fill('2026-08-20');
  await offerDialog.getByLabel('Libellé').fill('Mission commerciale E2E');
  await offerDialog.getByLabel('Quantité').fill('2');
  await offerDialog.getByLabel('Prix unitaire').fill('250.00');
  await offerDialog.getByLabel('TVA').selectOption({
    label: 'VE81 · Ventes 8,1 %'
  });
  await offerDialog.getByRole('button', {
    name: 'Enregistrer le brouillon'
  }).click();
  await expect(page.getByText(
    'Document commercial enregistré en brouillon.'
  )).toBeVisible();

  for (const [index, reference] of ['FOURN-E2E-001', 'FOURN-E2E-002'].entries()) {
    await page.getByRole('button', { name: 'Offre fournisseur', exact: true }).click();
    const supplierOfferDialog = page.getByRole('dialog', {
      name: 'Offre fournisseur'
    });
    await chooseAccount(
      supplierOfferDialog.getByLabel('Fournisseur', { exact: true }),
      'Fournitures E2E SA'
    );
    await supplierOfferDialog.getByLabel('Date').fill(`2026-07-${21 + index}`);
    await supplierOfferDialog.getByLabel('Référence externe').fill(reference);
    await supplierOfferDialog.getByLabel('Libellé').fill(`Proposition fournisseur ${index + 1}`);
    await supplierOfferDialog.getByLabel('Quantité').fill('1');
    await supplierOfferDialog.getByLabel('Prix unitaire').fill('125.00');
    await supplierOfferDialog.getByLabel('TVA').selectOption({ index: 1 });
    await supplierOfferDialog.getByRole('button', {
      name: 'Enregistrer le brouillon'
    }).click();
  }
  await expect(page.getByRole('row').filter({
    hasText: 'Fournitures E2E SA'
  })).toHaveCount(2);

  let offerRow = page.getByRole('row').filter({
    hasText: 'Client Commercial E2E SA'
  });
  await offerRow.getByRole('button', {
    name: 'Client Commercial E2E SA',
    exact: true
  }).click();
  await expect(page).toHaveURL(/\/app\/facturation\/contacts\?contact=\d+$/);
  const contactDialog = page.getByRole('dialog', {
    name: 'Client Commercial E2E SA'
  });
  await expect(contactDialog).toBeVisible();
  await contactDialog.getByRole('button', { name: 'Fermer' }).click();
  await page.getByRole('link', { name: 'Offres', exact: true }).click();
  offerRow = page.getByRole('row').filter({
    hasText: 'Client Commercial E2E SA'
  });
  await clickRowAction(page, offerRow, 'Marquer envoyé');
  await expect(offerRow).toContainText(/OC-2026-/);
  await clickRowAction(page, offerRow, 'Accepter');
  await expect(offerRow).toContainText('Accepté');
  await clickRowAction(page, offerRow, 'Créer la commande');
  const conversionDialog = page.getByRole('dialog', {
    name: 'Créer le document suivant'
  });
  await conversionDialog.getByLabel('Date').fill('2026-07-22');
  await conversionDialog.getByRole('button', {
    name: 'Créer le brouillon relié'
  }).click();

  await expect(page).toHaveURL(/\/app\/facturation\/commandes$/);
  const orderRow = page.getByRole('row').filter({
    hasText: 'Client Commercial E2E SA'
  });
  await expect(orderRow).toContainText('Commande client');
  await expect(orderRow).toContainText(/OC-2026-/);
  await clickRowAction(page, orderRow, 'Marquer envoyée');
  await expect(orderRow).toContainText('Envoyée');
  await clickRowAction(page, orderRow, 'Confirmer la livraison');
  await expect(orderRow).toContainText('Livrée');
  await page.getByRole('button', { name: 'Commande client' }).click();
  await expect(page.getByRole('dialog', { name: 'Commande client' })).toBeVisible();
});

test('salaires horaires et mensuels utilisent le parcours Vue complet', async ({
  page
}) => {
  test.setTimeout(60_000);
  await loginAsAdministrator(page);
  await selectDossier(page, 'Comptabilité principale');
  await page.getByRole('link', { name: 'Salaires', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Salaires', exact: true })).toHaveCount(0);
  await expect(page.getByLabel('Navigation des salaires')).toBeVisible();
  await expect(page.getByLabel('Navigation des salaires').getByLabel('Année')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Employés', exact: true })).toBeVisible();
  await expect(page.getByRole('heading', { name: /Historique des contrats/ })).toHaveCount(0);
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
  await expect(adaEmployee.getByRole('button', { name: 'Actions pour Ada Martin' })).toBeVisible();
  await clickRowAction(page, adaEmployee, 'Modifier');
  await expect(page.getByRole('heading', { name: 'Modifier l’employé' })).toBeVisible();
  const employeeDialog = page.getByRole('dialog', { name: 'Modifier l’employé' });
  await expect(employeeDialog.getByRole('heading', { name: 'Identité' })).toBeVisible();
  await expect(employeeDialog.getByRole('heading', { name: 'Adresse' })).toBeVisible();
  await expect(employeeDialog.getByRole('heading', { name: 'Paramètres salariaux' })).toBeVisible();
  await page.getByLabel('E-mail', { exact: true }).fill('ada.modifiee@example.test');
  await page.getByRole('button', { name: 'Enregistrer les modifications' }).click();
  await expect(page.getByText('Données de l’employé mises à jour.')).toBeVisible();
  await expect(employeesPanel.getByText('ada.modifiee@example.test')).toBeVisible();

  await clickRowAction(page, adaEmployee, 'Historique des contrats');
  const adaContracts = page.getByRole('dialog', {
    name: 'Historique des contrats · Ada Martin'
  });
  await expect(adaContracts).toBeVisible();
  const adaContract = adaContracts.getByRole('row').filter({ hasText: 'mensuel' });
  await clickRowAction(page, adaContract, 'Modifier');
  await expect(page.getByRole('heading', { name: 'Modifier le contrat' })).toBeVisible();
  await page.getByLabel('Source', { exact: true }).fill('Contrat mensuel E2E corrigé');
  await page.getByRole('button', { name: 'Enregistrer les modifications' }).click();
  await expect(page.getByText('Contrat mis à jour.')).toBeVisible();
  await adaContracts.getByRole('button', { name: 'Fermer' }).click();

  const temporaryEmployee = employeesPanel.getByRole('row').filter({
    hasText: 'Jean Temporaire'
  });
  await clickRowAction(page, temporaryEmployee, 'Historique des contrats');
  const temporaryContracts = page.getByRole('dialog', {
    name: 'Historique des contrats · Jean Temporaire'
  });
  const temporaryContract = temporaryContracts.getByRole('row').filter({
    hasText: /horaire|mensuel/i
  });
  page.once('dialog', (dialog) => dialog.accept());
  await clickRowAction(page, temporaryContract, 'Supprimer');
  await expect(page.getByText('Contrat non utilisé supprimé.')).toBeVisible();
  await expect(temporaryContract).toHaveCount(0);
  await temporaryContracts.getByRole('button', { name: 'Fermer' }).click();
  page.once('dialog', (dialog) => dialog.accept());
  await clickRowAction(page, temporaryEmployee, 'Supprimer');
  await expect(page.getByText('Employé et contrats non utilisés supprimés.')).toBeVisible();
  await expect(temporaryEmployee).toHaveCount(0);

  await page.getByRole('link', { name: 'Calculs', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Préparer une fiche de salaire' })).toBeVisible();
  await expect(page.getByRole('group', { name: /Employé et période/ })).toBeVisible();
  await expect(page.getByRole('group', {
    name: /Contrat appliqué automatiquement/
  })).toBeVisible();
  await expect(page.locator('.payroll-step legend span').first()).toHaveCSS('align-items', 'center');
  await expect(page.locator('.payroll-step legend span').first()).toHaveCSS('justify-content', 'center');
  await expect(page.getByRole('heading', { name: 'Brouillons et calculs 2026' })).toBeVisible();
  await expect(page.getByRole('button', {
    name: 'Calculer et créer le brouillon'
  })).toBeEnabled();
  const calculationRow = page.locator('.payroll-calculation-table tbody tr').first();
  await clickRowAction(page, calculationRow, 'Aperçu');
  const preview = page.getByRole('dialog', { name: 'Fiche de salaire 07/2026' });
  await expect(preview).toBeVisible();
  await expect(preview.getByText('BROUILLON — À CONTRÔLER')).toBeVisible();
  await expect(preview.getByRole('heading', { name: 'Base salariale' })).toBeVisible();
  await expect(preview.locator('.payroll-slip-table')).toContainText('CHF');
  await expect(preview.getByText('Prime exceptionnelle')).toBeVisible();
  const salarySection = preview.getByRole('heading', { name: 'Salaire', exact: true }).locator('..');
  await expect(salarySection).toContainText('Salaire de base');
  await expect(salarySection).not.toContainText('CHF');
  const deductionsSection = preview.getByRole('heading', { name: 'Retenues employé' }).locator('..');
  await expect(deductionsSection).not.toContainText('−');
  await expect(deductionsSection).not.toContainText('CHF');
  const employerCharges = preview.locator('details.payroll-slip-employer');
  await expect(employerCharges.locator('.payroll-details-chevron')).toBeVisible();
  await employerCharges.locator('summary').click();
  await expect(employerCharges.locator('dt').first()).toHaveText('Salaire brut');
  await expect(employerCharges).not.toContainText('CHF');
  const netSalary = preview.locator('.payroll-slip-net');
  await expect(netSalary).toContainText('Salaire net à verser');
  await expect(netSalary).not.toContainText('CHF');
  await expect(preview.getByRole('button', { name: 'Valider cette fiche' })).toBeEnabled();
  await preview.getByRole('button', { name: 'Valider cette fiche' }).click();
  await expect(page.getByText('Fiche validée et figée.')).toBeVisible();
  await expect(preview).not.toBeVisible();

  await page.getByRole('link', { name: 'Fiches', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Fiches de salaire' })).toBeVisible();
  const salarySlipRow = page.locator('.payroll-slips-table tbody tr').first();
  await expect(salarySlipRow.getByRole('button', { name: /Actions pour la fiche/ })).toBeVisible();
  await clickRowAction(page, salarySlipRow, 'Comptabiliser');
  await expect(page.getByText('Fiche comptabilisée.')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Paiements et lettrage' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Paiements et lettrage' }).click();
  const payrollPayments = page.getByRole('dialog', { name: 'Paiements et lettrage' });
  await expect(payrollPayments).toBeVisible();
  await expect(payrollPayments.getByRole('heading', { name: 'Saisir le paiement' })).toBeVisible();
  await expect(payrollPayments.getByRole('heading', { name: 'Allouer à une dette' })).toBeVisible();
  await expect(payrollPayments.locator('.payroll-payments-table')).toBeVisible();
  const referenceDebt = payrollPayments.getByLabel('Dette de référence');
  const ocasDebtValue = await referenceDebt.locator('option').filter({ hasText: 'OCAS' })
    .first().getAttribute('value');
  expect(ocasDebtValue).toBeTruthy();
  await referenceDebt.selectOption(String(ocasDebtValue));
  await expect(payrollPayments.getByText('Bénéficiaire déterminé :')).toContainText('OCAS');
  await payrollPayments.getByRole('button', { name: 'Saisir le paiement' }).click();
  await expect(page.getByText('Paiement salarial saisi.')).toBeVisible();
  await expect(payrollPayments.locator('.payroll-payments-table tbody tr').filter({
    hasText: 'OCAS'
  })).toBeVisible();
  await payrollPayments.getByRole('button', { name: 'Fermer' }).click();

  await page.goto('/e2e/app/liquidites/paiements');
  const centralPayrollPayment = page.locator('table tbody tr').filter({ hasText: 'OCAS' });
  await expect(centralPayrollPayment).toContainText('Salaires');
  await page.goto('/e2e/app/liquidites/lettrage');
  await expect(page.getByLabel('Dette ouverte compatible')).toBeVisible();
  const centralPaymentSelect = page.getByLabel('Paiement', { exact: true });
  const salaryPaymentValue = await centralPaymentSelect.locator('option')
    .filter({ hasText: /Salaires.*OCAS/ }).first().getAttribute('value');
  expect(salaryPaymentValue).toBeTruthy();
  await centralPaymentSelect.selectOption(String(salaryPaymentValue));
  await expect(page.getByLabel('Dette ouverte compatible')).toContainText('OCAS');
  await page.getByLabel('Dette ouverte compatible').selectOption(`payroll:${ocasDebtValue}`);
  await expect(page.getByLabel('Montant alloué')).not.toHaveValue('');
  await page.getByRole('button', { name: 'Lettrer', exact: true }).click();
  await expect(page.getByText('Paiement lettré.')).toBeVisible();

  await page.goto('/e2e/app/liquidites/paiements');
  await page.getByLabel('Afficher').selectOption('allocated');
  const allocatedPayrollPayment = page.locator('table tbody tr').filter({ hasText: 'OCAS' });
  await expect(allocatedPayrollPayment).toContainText(/Dette comptabilisée · [A-Z0-9]+-/);
  await expect(allocatedPayrollPayment).toContainText('Décaissement à comptabiliser');
  await clickRowAction(page, allocatedPayrollPayment, 'Comptabiliser');
  await expect(page.getByText('Décaissement salarial comptabilisé.')).toBeVisible();
  await expect(allocatedPayrollPayment).toContainText('Comptabilisé');
  await expect(allocatedPayrollPayment).not.toContainText('Dette comptabilisée');

  await page.goto('/e2e/app/configuration/modules');
  const billingModule = page.locator('.module-card').filter({
    has: page.getByRole('heading', { name: 'Facturation', exact: true })
  });
  await billingModule.getByRole('button', { name: 'Désactiver' }).click();
  await expect(billingModule).toContainText('Inactif');
  await page.goto('/e2e/app/liquidites/lettrage');
  await expect(page.getByLabel('Dette salariale compatible')).toBeVisible();
  await expect(page.getByLabel('Facture compatible')).toHaveCount(0);
  await page.goto('/e2e/app/configuration/modules');
  await billingModule.getByRole('button', { name: 'Réactiver' }).click();
  await expect(billingModule).toContainText('Actif');

  await page.goto('/e2e/app/salaires/annuels');
  await expect(page.getByRole('heading', { name: 'Récapitulatifs et certificats' })).toBeVisible();
  const annualRow = page.locator('.payroll-annual-table tbody tr').first();
  await expect(annualRow.getByRole('button', { name: /Actions du certificat/ })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Import annuel OCAS' })).toHaveCount(0);
  await expect(page.getByRole('heading', {
    name: 'Paramétrage employeur et comptes'
  })).toHaveCount(0);

  const legacy = await page.request.get('/e2e/salaires', { maxRedirects: 0 });
  expect(legacy.status()).toBe(303);
  expect(legacy.headers().location).toBe('/e2e/app/salaires');
});
