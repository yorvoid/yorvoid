import { expect, test } from '@playwright/test';
import { Authenticator, claim, expectAccessible, reset, signOut, withoutAutofill } from './support.ts';

test.beforeEach(async ({ request }) => {
  await reset(request);
});

test('claims a fresh installation with a passkey and recovery codes', async ({ page }) => {
  const authenticator = await Authenticator.on(page);
  const code = await (await page.request.post('/_e2e/setup-code')).text();

  await page.goto('/yorvoid/flows/yorvoid.setup');
  await expect(page).toHaveTitle('Set up your account');
  await expectAccessible(page);

  await page.getByLabel('Setup code').fill('WRONG-CODE');
  await page.getByLabel('Email address').fill('ana@example.com');
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.locator('[data-yorvoid-problems]')).toBeFocused();
  await expect(page.getByRole('alert')).toHaveText('That setup code didn\'t work. Check it, or create a new one on the server.');
  await expect(page.getByLabel('Setup code')).toHaveAttribute('aria-invalid', 'true');
  await expectAccessible(page);

  await page.getByLabel('Setup code').fill(code);
  await page.getByLabel('Email address').fill('ana@example.com');
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByRole('heading', { name: 'Add a passkey' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Not now' })).toHaveCount(0);
  await expectAccessible(page);

  await page.getByLabel('Name it').fill('Laptop');
  await page.getByRole('button', { name: 'Add a passkey' }).click();
  await expect(page.getByRole('heading', { name: 'Save your recovery codes' })).toBeVisible();
  await expect(page.locator('[data-yorvoid-recovery-codes] code')).toHaveCount(10);
  await expectAccessible(page);
  expect(await authenticator.passkeys()).toBe(1);

  await page.getByLabel('I saved my recovery codes').check();
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page).toHaveURL('/');
  await expect(page.getByTestId('who')).toHaveText('Signed in as ana@example.com');
});

test('signs in with the passkey offered in autofill', async ({ page }) => {
  await Authenticator.on(page);
  await claim(page);
  await signOut(page);

  // The virtual authenticator picks the passkey from the email field's autofill at once.
  await page.goto('/yorvoid/flows/yorvoid.sign_in');

  await expect(page).toHaveURL('/');
  await expect(page.getByTestId('who')).toHaveText('Signed in as ana@example.com');
});

test('signs in with a passkey, with the button', async ({ page }) => {
  await withoutAutofill(page);
  await Authenticator.on(page);
  await claim(page);
  await signOut(page);

  await page.goto('/yorvoid/flows/yorvoid.sign_in');
  await expect(page.getByLabel('Email address')).toHaveAttribute('autocomplete', 'username webauthn');
  await expectAccessible(page);

  await page.getByRole('button', { name: 'Sign in with a passkey' }).click();
  await expect(page).toHaveURL('/');
  await expect(page.getByTestId('who')).toHaveText('Signed in as ana@example.com');
});

test('says what went wrong when the authenticator doesn\'t confirm it\'s you', async ({ page }) => {
  await withoutAutofill(page);
  const authenticator = await Authenticator.on(page);
  await claim(page);
  await signOut(page);
  await authenticator.verifies(false);

  await page.goto('/yorvoid/flows/yorvoid.sign_in');
  await page.getByRole('button', { name: 'Sign in with a passkey' }).click();

  await expect(page.locator('[data-yorvoid-error]')).toHaveText('That was cancelled or took too long. Please try again.');
  await expect(page).toHaveURL(/\/yorvoid\/flows\/yorvoid\.sign_in\//);
});

test('recovers with a code, and adds a passkey later', async ({ page }) => {
  await withoutAutofill(page);
  await Authenticator.on(page);
  const codes = await claim(page);
  await signOut(page);

  await page.goto('/yorvoid/flows/yorvoid.sign_in');
  await page.getByRole('link', { name: 'Lost access to your passkeys? Use a recovery code' }).click();
  await expect(page.getByRole('heading', { name: 'Use a recovery code' })).toBeVisible();
  await expectAccessible(page);

  await page.getByLabel('Email address').fill('ana@example.com');
  await page.getByLabel('Recovery code').fill(codes[0] ?? '');
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByRole('heading', { name: 'Add a passkey' })).toBeVisible();

  await page.getByRole('button', { name: 'Not now' }).click();
  await expect(page.getByTestId('who')).toHaveText('Signed in as ana@example.com');
});

test('recovers with a link, on a device that has a passkey already', async ({ page }) => {
  await Authenticator.on(page);
  await claim(page);
  const token = await (await page.request.post('/_e2e/recovery-link', { form: { email: 'ana@example.com' } })).text();
  await signOut(page);

  await page.goto(`/yorvoid/flows/yorvoid.recover_with_link?token=${encodeURIComponent(token)}`);
  await expect(page.getByRole('heading', { name: 'Get back into your account' })).toBeVisible();
  await expectAccessible(page);
  await page.getByRole('button', { name: 'Continue' }).click();

  await page.getByRole('button', { name: 'Add a passkey' }).click();
  await expect(page.locator('[data-yorvoid-error]')).toHaveText('This device already has a passkey for your account.');
  await page.getByRole('button', { name: 'Not now' }).click();
  await expect(page.getByTestId('who')).toHaveText('Signed in as ana@example.com');

  await page.goto(`/yorvoid/flows/yorvoid.recover_with_link?token=${encodeURIComponent(token)}`);
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByRole('alert')).toHaveText('This link doesn\'t work any more. Ask for a new one.');
});

test('steps up before an operation', async ({ page }) => {
  await Authenticator.on(page);
  await claim(page);

  await page.goto('/yorvoid/flows/yorvoid.step_up?operation=acme.site.remove&multi_factor=1');
  await expect(page.getByRole('heading', { name: 'Confirm it\'s you' })).toBeVisible();
  await expectAccessible(page);

  await page.getByRole('button', { name: 'Confirm with a passkey' }).click();
  await expect(page).toHaveURL('/');
  await expect(page.getByTestId('who')).toHaveText('Signed in as ana@example.com');
});

test('signs operations that the verifier checks with its own keys', async ({ page }) => {
  await Authenticator.on(page);
  await claim(page);

  await page.goto('/_e2e/verifier/add-key');
  await expect(page.locator('[data-yorvoid-summary]')).toHaveText('Allow the passkey "E2E passkey" to sign operations');
  await expectAccessible(page);
  await page.getByRole('button', { name: 'Sign with a passkey' }).click();
  await expect(page.getByTestId('signed')).toHaveText('Key added: E2E passkey');

  await page.goto('/_e2e/remove-site');
  await expect(page.locator('[data-yorvoid-summary]')).toHaveText('Remove the site jane');
  await expect(page.locator('[data-yorvoid-parameters] dd')).toHaveText(['jane']);
  await page.getByRole('button', { name: 'Sign with a passkey' }).click();
  await expect(page.getByTestId('signed')).toHaveText('Ran acme.site.remove: site="jane"');
});

test('pages fit a 320 px phone', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 640 });

  for (const flow of ['yorvoid.sign_in', 'yorvoid.setup', 'yorvoid.recover_with_code']) {
    await page.goto(`/yorvoid/flows/${flow}`);
    expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
  }
});
