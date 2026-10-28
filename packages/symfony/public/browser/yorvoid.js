import { browserErrorCode, MESSAGES, YorvoIDError } from './errors.js';
import { assertionJson, creationOptions, registrationJson, requestOptions } from './webauthn.js';
/** What a flow answers with: a step, done; refused, gone or too soon (the progress says which). */
const FLOW_ANSWERS = [200, 404, 410, 422, 429];
/**
 * YorvoID's browser client: passkey ceremonies against the application's YorvoID routes, and
 * every error as a code to act on and a message to show.
 */
export class YorvoID {
    #endpoint;
    #messages;
    #signals;
    #fetch;
    #credentials;
    #statics;
    #pending;
    constructor(options = {}) {
        this.#endpoint = (options.endpoint ?? '/yorvoid').replace(/\/+$/, '');
        this.#messages = options.messages ?? {};
        this.#signals = options.signals ?? true;
        this.#fetch = options.fetch ?? globalThis.fetch.bind(globalThis);
        this.#credentials = 'credentials' in options ? options.credentials : globalThis.navigator.credentials;
        this.#statics = 'publicKeyCredential' in options ? options.publicKeyCredential : globalThis.PublicKeyCredential;
    }
    /**
     * Whether this browser offers passkeys in autofill, for `signIn({ conditional: true })`.
     */
    async isConditionalSignInAvailable() {
        try {
            // A browser without it throws here.
            return this.#credentials !== undefined && await this.#statics.isConditionalMediationAvailable() === true;
        }
        catch {
            return false;
        }
    }
    /**
     * Signs in with a passkey; the session is then the application's.
     *
     * @throws YorvoIDError
     */
    async signIn(options = {}) {
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
        }
        catch (error) {
            // The provider can stop offering a passkey the site doesn't know any more.
            if (error instanceof YorvoIDError && error.reason === 'unknown_passkey' && publicKey.rpId !== undefined) {
                await this.#signal('signalUnknownCredential', { rpId: publicKey.rpId, credentialId: passkey['id'] });
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
    async recoverWithCode(email, code) {
        await this.#post('/recover/code', { email, code });
    }
    /**
     * Recovers the account with the token of a recovery link; every other session ends. Call it when the
     * person confirms on your recovery page, not when it loads: email scanners open links.
     *
     * @throws YorvoIDError
     */
    async recoverWithLink(token) {
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
    async addPasskey(label, options = {}) {
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
    async stepUp(options = {}) {
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
    async signIntent(options, ceremony = {}) {
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
    async authenticate(options, ceremony = {}) {
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
    async register(options, ceremony = {}) {
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
    async startFlow(flow, data = {}) {
        return this.#flow('POST', `/flows/${encodeURIComponent(flow)}`, data);
    }
    /**
     * The flow's current step, again.
     *
     * @throws YorvoIDError
     */
    async resumeFlow(flow, id) {
        return this.#flow('GET', `/flows/${encodeURIComponent(flow)}/${encodeURIComponent(id)}`);
    }
    /**
     * Sends the person's answer for a step: e.g. { response } from authenticate() or register(), or a
     * form's fields.
     *
     * @throws YorvoIDError
     */
    async submitStep(flow, id, step, input) {
        return this.#flow('POST', `/flows/${encodeURIComponent(flow)}/${encodeURIComponent(id)}/${encodeURIComponent(step)}`, input);
    }
    /**
     * Tells the person's passkey provider which of their passkeys still work here, and the name to show
     * them with (the WebAuthn signal API). Done after signing in and adding a passkey; call it after
     * removing one. Browsers without the signal API are left alone.
     *
     * @throws YorvoIDError
     */
    async syncPasskeys() {
        const statics = this.#statics;
        if (statics?.signalAllAcceptedCredentials === undefined && statics?.signalCurrentUserDetails === undefined) {
            return;
        }
        const signals = await this.#post('/passkeys/signals', {}, { session: true });
        // No passkey yet: nothing to tell.
        if (Object.keys(signals).length === 0) {
            return;
        }
        const { rpId, userId, allAcceptedCredentialIds, name, displayName } = signals;
        await this.#signal('signalAllAcceptedCredentials', { rpId, userId, allAcceptedCredentialIds });
        await this.#signal('signalCurrentUserDetails', { rpId, userId, name, displayName });
    }
    async #flow(method, path, body) {
        let response;
        try {
            response = await this.#fetch(this.#endpoint + path, {
                method,
                headers: { Accept: 'application/json', ...(body === undefined ? {} : { 'Content-Type': 'application/json' }) },
                credentials: 'same-origin',
                ...(body === undefined ? {} : { body: JSON.stringify(body) }),
            });
        }
        catch (error) {
            throw this.#error('network', { cause: error });
        }
        const json = await response.json().then((value) => isObject(value) ? value : null, () => null);
        if (json === null || typeof json['status'] !== 'string' || !FLOW_ANSWERS.includes(response.status)) {
            throw this.#error('server', { status: response.status });
        }
        const seconds = Number(response.headers.get('Retry-After') ?? Number.NaN);
        return {
            flow: String(json['flow']),
            id: typeof json['id'] === 'string' ? json['id'] : null,
            status: json['status'],
            step: typeof json['step'] === 'string' ? json['step'] : null,
            view: isObject(json['view']) ? json['view'] : null,
            problems: Array.isArray(json['problems']) ? json['problems'] : [],
            redirect: typeof json['redirect'] === 'string' ? json['redirect'] : null,
            retryAfter: Number.isInteger(seconds) ? seconds : undefined,
        };
    }
    #webAuthn() {
        return this.#credentials ?? (() => { throw this.#error('not_supported'); })();
    }
    /**
     * Tells the passkey provider something, where the browser can. It only helps: a browser without the
     * method throws here, and neither that nor a failure changes what the person did.
     */
    async #signal(method, options) {
        try {
            await this.#statics[method](options);
        }
        catch {
            // Nothing to do.
        }
    }
    async #afterChange() {
        try {
            if (this.#signals) {
                await this.syncPasskeys();
            }
        }
        catch {
            // It only helps: the person signed in, or added the passkey, all the same.
        }
    }
    /**
     * Runs one ceremony at a time: a new one stops the one before, e.g. autofill when the person chose
     * the button. Stopping one that's over does nothing.
     */
    async #ceremony(run, signal) {
        this.#pending?.abort();
        const controller = new AbortController();
        this.#pending = controller;
        return run(signal === undefined ? controller.signal : AbortSignal.any([controller.signal, signal]))
            .catch((error) => { throw this.#error(browserErrorCode(error), { cause: error }); });
    }
    /**
     * The WebAuthn options the server or the verifier gave.
     */
    #options(parse) {
        try {
            return parse();
        }
        catch (error) {
            throw this.#error('server', { cause: error });
        }
    }
    /**
     * @returns What the server answered; nothing, as {}, when it had nothing to say (204)
     */
    async #post(path, body, endpoint = {}) {
        let response;
        try {
            response = await this.#fetch(this.#endpoint + path, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
        }
        catch (error) {
            throw this.#error('network', { cause: error });
        }
        if (response.status === 204) {
            return {};
        }
        const json = await response.json().then((value) => isObject(value) ? value : null, () => null);
        if (response.ok && json !== null) {
            return json;
        }
        throw this.#refusal(response, json, endpoint);
    }
    #refusal(response, json, endpoint) {
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
    #error(code, details = {}, serverMessage) {
        return new YorvoIDError(code, this.#messages[code] ?? serverMessage ?? MESSAGES[code], details);
    }
}
function isObject(value) {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}
