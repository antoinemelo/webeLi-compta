import { expect, test, type Page } from '@playwright/test';

async function login(page: Page): Promise<void> {
  await page.goto('/e2e/login');
  await page.getByLabel('Adresse e-mail').fill('lecteur@example.test');
  await page.getByLabel('Mot de passe').fill('mot-de-passe-e2e');
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page).toHaveURL(/\/e2e\/app\/?$/);
  await expect(page.getByRole('heading', { name: 'Tableau de bord' })).toBeVisible();
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
