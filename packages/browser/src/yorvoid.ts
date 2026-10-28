import { browserErrorCode, MESSAGES, YorvoIDError, type ErrorCode, type ErrorDetails, type Messages } from './errors.ts';
import { assertionJson, creationOptions, registrationJson, requestOptions, type JsonObject, type PublicKeyCredentialStatics } from './webauthn.ts';

type Signals = Required<Omit<PublicKeyCredentialStatics, 'isConditionalMediationAvailable'>>;

export interface YorvoIDOptions {
  /** Where the application imported YorvoID's routes: "/yorvoid" by default */
  readonly endpoint?: string;
  /** Your translations of the messages, by error code; YorvoID's English otherwise */
  readonly messages?: Partial<Messages>;
  /**
   * Whether to tell the person's passkey provider which of their passkeys still work here, after they
   * sign in or add one (the WebAuthn signal API): true by default
   */
  readonly signals?: boolean;
  /** The browser's, by default; for tests */
  readonly fetch?: typeof fetch;
  /** navigator.credentials, by default; for tests */
  readonly credentials?: Pick<CredentialsContainer, 'create' | 'get'> | undefined;
  /** PublicKeyCredential, by default; for tests */
  readonly publicKeyCredential?: PublicKeyCredentialStatics | undefined;
}

export interface CeremonyOptions {
  /** Stops the ceremony, e.g. when the person leaves the page */
  readonly signal?: AbortSignal;
}

export interface SignInOptions extends CeremonyOptions {
  /**
   * Offer passkeys in the username field's autofill rather than in a dialog. The field needs
   * autocomplete="username webauthn". Start it when the page loads; signing in another way stops it.
   */
  readonly conditional?: boolean;
}

export interface StepUpOptions extends CeremonyOptions {
  /** The operation the proof is for, as the server named it in `step_up_required` */
  readonly operation?: string | null;
  /** Whether the authenticator must check it's its owner (PIN, fingerprint, face) */
  readonly multiFactor?: boolean;
}

export interface AddedPasskey {
  /** The passkey's ID in YorvoID's credential registry */
  readonly credential: string;
  readonly label: string;
}

/** What a flow answers with: a step, done; refused, gone or too soon (the progress says which). */
const FLOW_ANSWERS: readonly number[] = [200, 404, 410, 422, 429];

export type FlowStatus = 'in_progress' | 'completed' | 'failed' | 'expired';

export interface FlowProblem {
  /** What to show, in the person's language */
  readonly message: string;
  /** The input field it's about */
  readonly field: string | null;
  /** Why, for logs */
  readonly reason: string | null;
}

/**
 * Where a flow got to.
 */
export interface FlowProgress {
  readonly flow: string;
  /** Null when it expired */
  readonly id: string | null;
  readonly status: FlowStatus;
  /** The step to show, while in progress */
  readonly step: string | null;
  /** What to show for it: the step's view model */
  readonly view: JsonObject | null;
  /** Why the last answer was refused, why the flow failed, or that it expired */
  readonly problems: readonly FlowProblem[];
  /** Where to go, when it completed */
  readonly redirect: string | null;
  /** Seconds to wait, after too many attempts */
  readonly retryAfter: number | undefined;
}

/** Endpoints that need a session answer 401 when it has ended; the others, to refuse signing in. */
interface Endpoint {
  readonly session?: boolean;
}

/**
 * YorvoID's browser client: passkey ceremonies against the application's YorvoID routes, and
 * every error as a code to act on and a message to show.
 */
export class YorvoID {
  readonly #endpoint: string;
  readonly #messages: Partial<Messages>;
  readonly #signals: boolean;
  readonly #fetch: typeof fetch;
  readonly #credentials: Pick<CredentialsContainer, 'create' | 'get'> | undefined;
  readonly #statics: PublicKeyCredentialStatics | undefined;
  #pending: AbortController | undefined;

  constructor(options: YorvoIDOptions = {}) {
    this.#endpoint = (options.endpoint ?? '/yorvoid').replace(/\/+$/, '');
    this.#messages = options.messages ?? {};
    this.#signals = options.signals ?? true;
    this.#fetch = options.fetch ?? globalThis.fetch.bind(globalThis);
    this.#credentials = 'credentials' in options ? options.credentials : globalThis.navigator.credentials;
    this.#statics = 'publicKeyCredential' in options ? options.publicKeyCredential : (globalThis as { PublicKeyCredential?: PublicKeyCredentialStatics }).PublicKeyCredential;
  }

  /**
   * Whether this browser offers passkeys in autofill, for `signIn({ conditional: true })`.
   */
  async isConditionalSignInAvailable(): Promise<boolean> {
    try {
      // A browser without it throws here.
      return this.#credentials !== undefined && await (this.#statics as Required<PublicKeyCredentialStatics>).isConditionalMediationAvailable() === true;
    } catch {
      return false;
    }
  }

  /**
   * Signs in with a passkey; the session is then the application's.
   *
   * @throws YorvoIDError
   */
  async signIn(options: SignInOptions = {}): Promise<void> {
    const credentials = this.#webAuthn();
    const conditional = options.conditional === true;

    if (conditional && !await this.isConditionalSignInAvailable()) {
      throw this.#error('not_supported');
    }

    const begun = await this.#post('/sign-in/options', {});
    const publicKey = this.#options(() => requestOptions(begun['options']));
    const passkey = assertionJson(await this.#ceremony((signal) => credentials.get({ publicKey, signal, mediation: conditional ? 'conditional' : 'optional' }), options.signal));

    try {
      await this.#post('/sign-in', { ceremony: begun['ceremony'], response: passkey });
    } catch (error) {
      // The provider can stop offering a passkey the site doesn't know any more.
      if (error instanceof YorvoIDError && error.reason === 'unknown_passkey' && publicKey.rpId !== undefined) {
        await this.#signal('signalUnknownCredential', { rpId: publicKey.rpId, credentialId: passkey['id'] as string });
      }

      throw error;
    }

    await this.#afterChange();
  }

  /**
   * Recovers the account with one of its recovery codes; every other session ends.
   *
   * @throws YorvoIDError
   */
  async recoverWithCode(email: string, code: string): Promise<void> {
    await this.#post('/recover/code', { email, code });
  }

  /**
   * Recovers the account with the token of a recovery link; every other session ends. Call it when the
   * person confirms on your recovery page, not when it loads: email scanners open links.
   *
   * @throws YorvoIDError
   */
  async recoverWithLink(token: string): Promise<void> {
    await this.#post('/recover/link', { token });
  }

  /**
   * Adds a passkey to the signed-in person's account. It needs a recent proof that it's them:
   * on `step_up_required`, call `stepUp()` with the error's `stepUp`, then this again.
   *
   * @param label What the person calls it, e.g. "MacBook"
   *
   * @throws YorvoIDError
   */
  async addPasskey(label: string, options: CeremonyOptions = {}): Promise<AddedPasskey> {
    const credentials = this.#webAuthn();
    const begun = await this.#post('/passkeys/options', {}, { session: true });
    const publicKey = this.#options(() => creationOptions(begun['options']));
    const passkey = registrationJson(await this.#ceremony((signal) => credentials.create({ publicKey, signal }), options.signal));
    const added = await this.#post('/passkeys', { ceremony: begun['ceremony'], response: passkey, label }, { session: true });
    await this.#afterChange();

    return { credential: String(added['credential']), label: String(added['label']) };
  }

  /**
   * Confirms it's the person, with a passkey, for an operation that needs a recent proof.
   *
   * @throws YorvoIDError
   */
  async stepUp(options: StepUpOptions = {}): Promise<void> {
    const credentials = this.#webAuthn();
    const begun = await this.#post('/step-up/options', { operation: options.operation ?? null, multi_factor: options.multiFactor === true }, { session: true });
    const publicKey = this.#options(() => requestOptions(begun['options']));
    const passkey = assertionJson(await this.#ceremony((signal) => credentials.get({ publicKey, signal }), options.signal));
    await this.#post('/step-up', { ceremony: begun['ceremony'], response: passkey }, { session: true });
  }

  /**
   * Signs an intent the verifier prepared: pass its options, as your console received them, and send
   * what this gives back to the verifier the way your console talks to it. Show the person the intent's
   * summary first: it's the only place they see what they sign.
   *
   * @param options The SigningRequest's options, as JSON or parsed
   *
   * @throws YorvoIDError
   */
  async signIntent(options: string | JsonObject, ceremony: CeremonyOptions = {}): Promise<JsonObject> {
    return this.authenticate(options, ceremony);
  }

  /**
   * Runs navigator.credentials.get() with options a flow step or the verifier gave, and gives the
   * passkey's answer to send back: for your own pages.
   *
   * @param options The options, as JSON or parsed
   *
   * @throws YorvoIDError
   */
  async authenticate(options: string | JsonObject, ceremony: SignInOptions = {}): Promise<JsonObject> {
    const credentials = this.#webAuthn();
    const conditional = ceremony.conditional === true;

    if (conditional && !await this.isConditionalSignInAvailable()) {
      throw this.#error('not_supported');
    }

    const publicKey = this.#options(() => requestOptions(typeof options === 'string' ? JSON.parse(options) : options));

    return assertionJson(await this.#ceremony((signal) => credentials.get({ publicKey, signal, ...(conditional ? { mediation: 'conditional' } : {}) }), ceremony.signal));
  }

  /**
   * Runs navigator.credentials.create() with options a flow step gave, and gives the new passkey to
   * send back: for your own pages.
   *
   * @param options The options, as JSON or parsed
   *
   * @throws YorvoIDError
   */
  async register(options: string | JsonObject, ceremony: CeremonyOptions = {}): Promise<JsonObject> {
    const credentials = this.#webAuthn();
    const publicKey = this.#options(() => creationOptions(typeof options === 'string' ? JSON.parse(options) : options));

    return registrationJson(await this.#ceremony((signal) => credentials.create({ publicKey, signal }), ceremony.signal));
  }

  /**
   * Starts a flow (YorvoID's server-side steps): for applications rendering steps themselves. Show
   * the step with its view, and send the person's answer with submitStep().
   *
   * @param data What the flow starts with, e.g. { token } for an invitation
   *
   * @throws YorvoIDError When the server couldn't be reached, or answered something else than a flow
   */
  async startFlow(flow: string, data: JsonObject = {}): Promise<FlowProgress> {
    return this.#flow('POST', `/flows/${encodeURIComponent(flow)}`, data);
  }

  /**
   * The flow's current step, again.
   *
   * @throws YorvoIDError
   */
  async resumeFlow(flow: string, id: string): Promise<FlowProgress> {
    return this.#flow('GET', `/flows/${encodeURIComponent(flow)}/${encodeURIComponent(id)}`);
  }

  /**
   * Sends the person's answer for a step: e.g. { response } from authenticate() or register(), or a
   * form's fields.
   *
   * @throws YorvoIDError
   */
  async submitStep(flow: string, id: string, step: string, input: JsonObject): Promise<FlowProgress> {
    return this.#flow('POST', `/flows/${encodeURIComponent(flow)}/${encodeURIComponent(id)}/${encodeURIComponent(step)}`, input);
  }

  /**
   * Tells the person's passkey provider which of their passkeys still work here, and the name to show
   * them with (the WebAuthn signal API). Done after signing in and adding a passkey; call it after
   * removing one. Browsers without the signal API are left alone.
   *
   * @throws YorvoIDError
   */
  async syncPasskeys(): Promise<void> {
    const statics = this.#statics;

    if (statics?.signalAllAcceptedCredentials === undefined && statics?.signalCurrentUserDetails === undefined) {
      return;
    }

    const signals = await this.#post('/passkeys/signals', {}, { session: true });

    // No passkey yet: nothing to tell.
    if (Object.keys(signals).length === 0) {
      return;
    }

    const { rpId, userId, allAcceptedCredentialIds, name, displayName } = signals as { rpId: string; userId: string; allAcceptedCredentialIds: string[]; name: string; displayName: string };
    await this.#signal('signalAllAcceptedCredentials', { rpId, userId, allAcceptedCredentialIds });
    await this.#signal('signalCurrentUserDetails', { rpId, userId, name, displayName });
  }

  async #flow(method: 'GET' | 'POST', path: string, body?: JsonObject): Promise<FlowProgress> {
    let response: Response;

    try {
      response = await this.#fetch(this.#endpoint + path, {
        method,
        headers: { Accept: 'application/json', ...(body === undefined ? {} : { 'Content-Type': 'application/json' }) },
        credentials: 'same-origin',
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      });
    } catch (error) {
      throw this.#error('network', { cause: error });
    }

    const json = await response.json().then((value: unknown) => isObject(value) ? value : null, () => null);

    if (json === null || typeof json['status'] !== 'string' || !FLOW_ANSWERS.includes(response.status)) {
      throw this.#error('server', { status: response.status });
    }

    const seconds = Number(response.headers.get('Retry-After') ?? Number.NaN);

    return {
      flow: String(json['flow']),
      id: typeof json['id'] === 'string' ? json['id'] : null,
      status: json['status'] as FlowStatus,
      step: typeof json['step'] === 'string' ? json['step'] : null,
      view: isObject(json['view']) ? json['view'] : null,
      problems: Array.isArray(json['problems']) ? json['problems'] as FlowProblem[] : [],
      redirect: typeof json['redirect'] === 'string' ? json['redirect'] : null,
      retryAfter: Number.isInteger(seconds) ? seconds : undefined,
    };
  }

  #webAuthn(): Pick<CredentialsContainer, 'create' | 'get'> {
    return this.#credentials ?? (() => { throw this.#error('not_supported'); })();
  }

  /**
   * Tells the passkey provider something, where the browser can. It only helps: a browser without the
   * method throws here, and neither that nor a failure changes what the person did.
   */
  async #signal<M extends keyof Signals>(method: M, options: Parameters<Signals[M]>[0]): Promise<void> {
    try {
      await (this.#statics as Signals)[method](options as never);
    } catch {
      // Nothing to do.
    }
  }

  async #afterChange(): Promise<void> {
    try {
      if (this.#signals) {
        await this.syncPasskeys();
      }
    } catch {
      // It only helps: the person signed in, or added the passkey, all the same.
    }
  }

  /**
   * Runs one ceremony at a time: a new one stops the one before, e.g. autofill when the person chose
   * the button. Stopping one that's over does nothing.
   */
  async #ceremony(run: (signal: AbortSignal) => Promise<Credential | null>, signal: AbortSignal | undefined): Promise<Credential | null> {
    this.#pending?.abort();
    const controller = new AbortController();
    this.#pending = controller;

    return run(signal === undefined ? controller.signal : AbortSignal.any([controller.signal, signal]))
      .catch((error: unknown) => { throw this.#error(browserErrorCode(error), { cause: error }); });
  }

  /**
   * The WebAuthn options the server or the verifier gave.
   */
  #options<T>(parse: () => T): T {
    try {
      return parse();
    } catch (error) {
      throw this.#error('server', { cause: error });
    }
  }

  /**
   * @returns What the server answered; nothing, as {}, when it had nothing to say (204)
   */
  async #post(path: string, body: JsonObject, endpoint: Endpoint = {}): Promise<JsonObject> {
    let response: Response;

    try {
      response = await this.#fetch(this.#endpoint + path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(body),
      });
    } catch (error) {
      throw this.#error('network', { cause: error });
    }

    if (response.status === 204) {
      return {};
    }

    const json = await response.json().then((value: unknown) => isObject(value) ? value : null, () => null);

    if (response.ok && json !== null) {
      return json;
    }

    throw this.#refusal(response, json, endpoint);
  }

  #refusal(response: Response, json: JsonObject | null, endpoint: Endpoint): YorvoIDError {
    const status = response.status;
    const message = typeof json?.['error'] === 'string' ? json['error'] : undefined;
    const reason = json?.['unknown_credential'] === true ? 'unknown_passkey' : typeof json?.['reason'] === 'string' ? json['reason'] : undefined;
    const stepUp = json?.['step_up'];

    if (status === 429) {
      const seconds = Number(response.headers.get('Retry-After') ?? Number.NaN);

      return this.#error('too_many_attempts', { status, retryAfter: Number.isInteger(seconds) ? seconds : undefined }, message);
    }

    if (isObject(stepUp)) {
      return this.#error('step_up_required', { status, stepUp: { operation: typeof stepUp['operation'] === 'string' ? stepUp['operation'] : null, within: String(stepUp['within']) } }, message);
    }

    if (status === 401 && endpoint.session === true) {
      return this.#error('signed_out', { status }, message);
    }

    if ((status === 400 || status === 401) && message !== undefined) {
      return this.#error('refused', { status, reason }, message);
    }

    return this.#error('server', { status });
  }

  /**
   * Your translation of the code's message, or else the server's message, or else YorvoID's.
   */
  #error(code: ErrorCode, details: ErrorDetails = {}, serverMessage?: string): YorvoIDError {
    return new YorvoIDError(code, this.#messages[code] ?? serverMessage ?? MESSAGES[code], details);
  }
}

function isObject(value: unknown): value is JsonObject {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
