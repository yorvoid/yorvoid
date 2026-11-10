import { expect, test } from '@playwright/test';
import { Authenticator, claim, expectAccessible, reset, signOut, withoutAutofill } from './support.ts';

// Acme Team, as the usability test's participants use it (M2-10): a refusal says what would satisfy
// it, and the person does it, comes back, and tries again.

test.beforeEach(async ({ request }) => {
  await reset(request);
});

test('deletes a project once it\'s confirmed it\'s them, from the refusal', async ({ page }) => {
  await Authenticator.on(page);
  await claim(page);
  await page.getByRole('navigation').getByRole('link', { name: 'Projects' }).click();
  await expect(page.getByRole('heading', { name: 'Projects' })).toBeVisible();
  await expectAccessible(page);

  // Signed in ten minutes ago: older than the 5 minutes deleting asks for.
  expect((await page.request.post('/_e2e/clock', { data: { seconds: 600 } })).status()).toBe(204);
  await page.getByRole('button', { name: 'Delete Old newsletter' }).click();
  await expect(page.getByRole('heading', { name: 'Before you can do this' })).toBeVisible();
  await expect(page.locator('[data-yorvoid-remedy="step_up"]')).toContainText('Confirm it\'s you, then try again. This confirms: deleting a project.');
  await expectAccessible(page);

  await page.getByRole('link', { name: 'Confirm it\'s you' }).click();
  await expect(page.getByRole('heading', { name: 'Confirm it\'s you' })).toBeVisible();
  await page.getByRole('button', { name: 'Confirm with a passkey' }).click();
  await expect(page).toHaveURL('/projects');

  await page.getByRole('button', { name: 'Delete Old newsletter' }).click();
  await expect(page.getByRole('status')).toHaveText('Removed “Old newsletter”.');
  await expect(page.getByRole('button', { name: 'Delete Old newsletter' })).toHaveCount(0);
});

test('just after a recovery, changing the email address waits', async ({ page }) => {
  await withoutAutofill(page);
  await Authenticator.on(page);
  const codes = await claim(page);
  await signOut(page);

  await page.goto('/yorvoid/flows/yorvoid.recover_with_code');
  await page.getByLabel('Email address').fill('ana@example.com');
  await page.getByLabel('Recovery code').fill(codes[0] ?? '');
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByRole('button', { name: 'Not now' }).click();

  await page.getByRole('navigation').getByRole('link', { name: 'Account' }).click();
  await expectAccessible(page);
  await page.getByLabel('New email address').fill('ana@example.org');
  await page.getByRole('button', { name: 'Change email address' }).click();

  await expect(page.getByRole('heading', { name: 'Before you can do this' })).toBeVisible();
  await expect(page.locator('[data-yorvoid-remedy="wait"] time')).toHaveText('You can do this again in about 1 day.');
  await expect(page.getByRole('link', { name: 'Confirm it\'s you' })).toHaveCount(0);
  await expect(page.getByText(/^Reference: /)).toBeVisible();
  await expectAccessible(page);

  await page.setViewportSize({ width: 320, height: 640 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
});

test('Acme Team\'s pages fit a 320 px phone', async ({ page }) => {
  await Authenticator.on(page);
  await claim(page);
  await page.setViewportSize({ width: 320, height: 640 });

  for (const path of ['/', '/projects', '/account']) {
    await page.goto(path);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
  }
});
