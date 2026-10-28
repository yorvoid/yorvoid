import AxeBuilder from '@axe-core/playwright';
import { expect, type APIRequestContext, type CDPSession, type Page } from '@playwright/test';

/**
 * A platform authenticator with a screen lock: it keeps passkeys, and confirms it's its owner.
 */
export class Authenticator {
  readonly #cdp: CDPSession;
  readonly #id: string;

  private constructor(cdp: CDPSession, id: string) {
    this.#cdp = cdp;
    this.#id = id;
  }

  static async on(page: Page): Promise<Authenticator> {
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('WebAuthn.enable');
    const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
      options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true },
    });

    return new Authenticator(cdp, authenticatorId);
  }

  async passkeys(): Promise<number> {
    return (await this.#cdp.send('WebAuthn.getCredentials', { authenticatorId: this.#id })).credentials.length;
  }

  /**
   * Whether it confirms it's its owner, e.g. with the right fingerprint.
   */
  async verifies(verified: boolean): Promise<void> {
    await this.#cdp.send('WebAuthn.setUserVerified', { authenticatorId: this.#id, isUserVerified: verified });
  }
}

/**
 * A fresh installation: an empty database, and no verifier keys.
 */
export async function reset(request: APIRequestContext): Promise<void> {
  expect((await request.post('/_e2e/reset')).status()).toBe(204);
}

/**
 * Claims the installation as its owner, with a passkey: gives their recovery codes.
 */
export async function claim(page: Page, email = 'ana@example.com'): Promise<string[]> {
  const code = await (await page.request.post('/_e2e/setup-code')).text();
  await page.goto('/yorvoid/flows/yorvoid.setup');
  await page.getByLabel('Setup code').fill(code);
  await page.getByLabel('Email address').fill(email);
  await page.getByRole('button', { name: 'Continue' }).click();
  await page.getByLabel('Name it').fill('Laptop');
  await page.getByRole('button', { name: 'Add a passkey' }).click();
  await expect(page.getByRole('heading', { name: 'Save your recovery codes' })).toBeVisible();
  const codes = await page.locator('[data-yorvoid-recovery-codes] code').allTextContents();
  await page.getByLabel('I saved my recovery codes').check();
  await page.getByRole('button', { name: 'Continue' }).click();
  await expect(page.getByTestId('who')).toHaveText(`Signed in as ${email}`);

  return codes;
}

/**
 * No WCAG 2.2 A or AA violations axe can find.
 */
export async function expectAccessible(page: Page): Promise<void> {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22a', 'wcag22aa']).analyze();

  expect(results.violations.map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(', ')}`)).toEqual([]);
}

/**
 * A browser that doesn't offer passkeys in autofill: Chromium's virtual authenticator answers an
 * autofill request at once, as if the person picked the passkey, so the page would sign in by itself.
 */
export async function withoutAutofill(page: Page): Promise<void> {
  await page.addInitScript(() => {
    PublicKeyCredential.isConditionalMediationAvailable = async () => false;
  });
}

/**
 * Signs out: the browser forgets its cookies; the authenticator keeps its passkeys.
 */
export async function signOut(page: Page): Promise<void> {
  await page.context().clearCookies();
  await page.goto('/');
  await expect(page.getByTestId('who')).toHaveText('Not signed in');
}
