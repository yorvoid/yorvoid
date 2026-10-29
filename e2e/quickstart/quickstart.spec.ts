import { readFileSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import { Authenticator } from '../tests/support.ts';

/**
 * Steps 8 and 9 of the Symfony quickstart, in a browser: claim the first account with a passkey, sign
 * out, sign in again.
 */
test('the quickstart\'s application claims, signs out and signs in with a passkey', async ({ page }) => {
  const authenticator = await Authenticator.on(page);
  const code = readFileSync('../var/quickstart/setup-code.txt', 'utf8').trim();

  await page.goto('/yorvoid/flows/yorvoid.setup');
  await page.getByLabel('Setup code').fill(code);
  await page.getByLabel('Email address').fill('ana@example.com');
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByLabel('Name it').fill('Laptop');
  await page.getByRole('button', { name: 'Add a passkey' }).click();
  await expect(page.locator('[data-yorvoid-recovery-codes] code')).toHaveCount(10);
  await page.getByLabel('I saved my recovery codes').check();
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page).toHaveURL('/');
  await expect(page.getByText('Signed in as ana@example.com.')).toBeVisible();
  expect(await authenticator.passkeys()).toBe(1);

  await page.getByRole('link', { name: 'Sign out' }).click();
  await expect(page.getByText('Not signed in.')).toBeVisible();

  // The authenticator answers the email field's passkey suggestion at once.
  await page.getByRole('link', { name: 'Sign in' }).click();
  await expect(page).toHaveURL('/');
  await expect(page.getByText('Signed in as ana@example.com.')).toBeVisible();
});
