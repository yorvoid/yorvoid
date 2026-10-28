import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { MESSAGES, YorvoID, YorvoIDError, type YorvoIDOptions } from '../src/index.ts';
import { assertion, attestation, bytes, CREATION_OPTIONS, FakeCredentials, FakeServer, json, REQUEST_OPTIONS, statics, untilAborted } from './support.ts';

const SIGNALS = { rpId: 'example.com', userId: 'BwgJ', allAcceptedCredentialIds: ['AAE'], name: 'ana@example.com', displayName: 'Ana' };

function setUp(options: Partial<YorvoIDOptions> = {}) {
  const server = new FakeServer()
    .on('/yorvoid/sign-in/options', { ceremony: 'c1', options: REQUEST_OPTIONS })
    .on('/yorvoid/sign-in', { signed_in: true })
    .on('/yorvoid/passkeys/signals', SIGNALS);
  const credentials = new FakeCredentials();
  const publicKeyCredential = statics();
  const yorvoid = new YorvoID({ fetch: server.fetch, credentials, publicKeyCredential, ...options });

  return { server, credentials, publicKeyCredential, yorvoid };
}

async function refusal(promise: Promise<unknown>): Promise<YorvoIDError> {
  try {
    await promise;
  } catch (error) {
    assert.ok(error instanceof YorvoIDError, String(error));

    return error;
  }

  assert.fail('It wasn\'t refused.');
}

describe('signing in', () => {
  test('signs in with a passkey, then tells the provider which passkeys work', async () => {
    const { server, credentials, publicKeyCredential, yorvoid } = setUp();

    await yorvoid.signIn();

    assert.deepEqual(server.paths(), ['/yorvoid/sign-in/options', '/yorvoid/sign-in', '/yorvoid/passkeys/signals']);
    assert.deepEqual(server.requests[0]?.init, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, credentials: 'same-origin', body: '{}' });
    assert.deepEqual(server.requests[1]?.body, {
      ceremony: 'c1',
      response: { id: 'AAE', rawId: 'AAE', type: 'public-key', clientExtensionResults: { credProps: { rk: true } }, authenticatorAttachment: 'platform', response: { clientDataJSON: 'AQ', authenticatorData: 'Ag', signature: 'Aw', userHandle: 'BA' } },
    });
    const call = credentials.calls[0];
    assert.equal(call?.kind, 'get');
    assert.equal(call?.options.mediation, 'optional');
    assert.deepEqual(call?.options.publicKey?.challenge, bytes(1, 2, 3));
    assert.ok(call?.options.signal instanceof AbortSignal);
    assert.deepEqual(publicKeyCredential.told, [
      ['accepted', { rpId: 'example.com', userId: 'BwgJ', allAcceptedCredentialIds: ['AAE'] }],
      ['user', { rpId: 'example.com', userId: 'BwgJ', name: 'ana@example.com', displayName: 'Ana' }],
    ]);
  });

  test('uses the routes where the application imported them', async () => {
    const server = new FakeServer().on('/auth/sign-in/options', { ceremony: 'c1', options: REQUEST_OPTIONS }).on('/auth/sign-in', { signed_in: true });

    await new YorvoID({ endpoint: '/auth//', fetch: server.fetch, credentials: new FakeCredentials(), publicKeyCredential: undefined }).signIn();

    assert.deepEqual(server.paths(), ['/auth/sign-in/options', '/auth/sign-in']);
  });

  test('offers passkeys in autofill where the browser can', async () => {
    const { credentials, yorvoid } = setUp();

    assert.equal(await yorvoid.isConditionalSignInAvailable(), true);
    await yorvoid.signIn({ conditional: true });

    assert.equal(credentials.calls[0]?.options.mediation, 'conditional');
  });

  test('says when autofill isn\'t available, before asking the server', async () => {
    const unavailable: YorvoIDOptions[] = [
      { publicKeyCredential: statics(false) },
      { publicKeyCredential: undefined },
      { publicKeyCredential: {} },
      { publicKeyCredential: { isConditionalMediationAvailable: async () => { throw new Error('No.'); } } },
      { publicKeyCredential: { isConditionalMediationAvailable: async () => 'yes' as unknown as boolean } },
      { credentials: undefined },
    ];

    for (const options of unavailable) {
      const { server, yorvoid } = setUp(options);

      assert.equal(await yorvoid.isConditionalSignInAvailable(), false);
      assert.equal((await refusal(yorvoid.signIn({ conditional: true }))).code, 'not_supported');
      assert.deepEqual(server.paths(), []);
    }
  });

  test('a new ceremony stops the one before, and only that one', async () => {
    const { credentials, yorvoid } = setUp();
    credentials.answer = () => untilAborted(credentials.calls.at(-1)?.options.signal);

    const autofill = refusal(yorvoid.signIn({ conditional: true }));
    await new Promise((resolve) => setTimeout(resolve, 10));
    const button = refusal(yorvoid.signIn());
    const stopped = await autofill;
    assert.equal(stopped.code, 'aborted');
    assert.equal(stopped.message, MESSAGES.aborted);
    assert.ok(stopped.cause instanceof DOMException);

    credentials.answer = async () => assertion();
    await yorvoid.signIn();
    assert.equal((await button).code, 'aborted', 'The stopped ceremony forgot the one after it.');
  });

  test('the page can stop a ceremony', async () => {
    const { credentials, yorvoid } = setUp();
    const controller = new AbortController();
    credentials.answer = () => untilAborted(credentials.calls.at(-1)?.options.signal);

    const signIn = refusal(yorvoid.signIn({ signal: controller.signal }));
    await new Promise((resolve) => setTimeout(resolve, 10));
    controller.abort();

    assert.equal((await signIn).code, 'aborted');
  });

  test('tells the provider about a passkey the site doesn\'t know', async () => {
    const { server, publicKeyCredential, yorvoid } = setUp();
    server.on('/yorvoid/sign-in', json({ error: 'That passkey didn\'t work.', unknown_credential: true }, 401));

    const error = await refusal(yorvoid.signIn());

    assert.deepEqual([error.code, error.message, error.status, error.reason], ['refused', 'That passkey didn\'t work.', 401, 'unknown_passkey']);
    assert.deepEqual(publicKeyCredential.told, [['unknown', { rpId: 'example.com', credentialId: 'AAE' }]]);
    assert.deepEqual(server.paths(), ['/yorvoid/sign-in/options', '/yorvoid/sign-in'], 'Signals after a refusal.');
  });

  test('a failing or missing signal doesn\'t change the answer', async () => {
    for (const publicKeyCredential of [{ signalUnknownCredential: async () => { throw new Error('No.'); } }, {}, undefined]) {
      const { server, yorvoid } = setUp({ publicKeyCredential });
      server.on('/yorvoid/sign-in', json({ error: 'No.', unknown_credential: true }, 401));

      assert.equal((await refusal(yorvoid.signIn())).reason, 'unknown_passkey');
    }

    const { server, publicKeyCredential, yorvoid } = setUp();
    server.on('/yorvoid/sign-in/options', { ceremony: 'c1', options: { ...REQUEST_OPTIONS, rpId: undefined } });
    server.on('/yorvoid/sign-in', json({ error: 'No.', unknown_credential: true }, 401));
    await refusal(yorvoid.signIn());
    assert.deepEqual(publicKeyCredential.told, [], 'Without the relying party there\'s nothing to tell.');

    const other = setUp();
    other.server.on('/yorvoid/sign-in', json({ error: 'No.', reason: 'invalid_response' }, 401));
    assert.equal((await refusal(other.yorvoid.signIn())).reason, 'invalid_response');
    assert.deepEqual(other.publicKeyCredential.told, [], 'Only an unknown passkey is signalled.');
  });

  test('signing in works when the signals don\'t', async () => {
    const { server, yorvoid } = setUp();
    server.on('/yorvoid/passkeys/signals', json({ error: 'Down.' }, 500));

    await yorvoid.signIn();
  });

  test('sends no signals when told not to', async () => {
    const { server, yorvoid } = setUp({ signals: false });

    await yorvoid.signIn();

    assert.deepEqual(server.paths(), ['/yorvoid/sign-in/options', '/yorvoid/sign-in']);
  });
});

describe('recovering', () => {
  test('with a code or a link', async () => {
    const { server, yorvoid } = setUp();
    server.on('/yorvoid/recover/code', { signed_in: true }).on('/yorvoid/recover/link', { signed_in: true });

    await yorvoid.recoverWithCode('ana@example.com', 'ABCD');
    await yorvoid.recoverWithLink('t0ken');

    assert.deepEqual(server.requests.map((request) => [request.path, request.body]), [['/yorvoid/recover/code', { email: 'ana@example.com', code: 'ABCD' }], ['/yorvoid/recover/link', { token: 't0ken' }]]);
  });

  test('refusals say why, as the server put it', async () => {
    const { server, yorvoid } = setUp();
    server.on('/yorvoid/recover/code', json({ error: 'That code didn\'t work.' }, 401));

    const error = await refusal(yorvoid.recoverWithCode('ana@example.com', 'x'));

    assert.deepEqual([error.code, error.message, error.status, error.reason], ['refused', 'That code didn\'t work.', 401, undefined]);
  });
});

describe('adding a passkey', () => {
  test('adds it, then tells the provider', async () => {
    const { server, credentials, publicKeyCredential, yorvoid } = setUp();
    server.on('/yorvoid/passkeys/options', { ceremony: 'c2', options: CREATION_OPTIONS }).on('/yorvoid/passkeys', json({ credential: 'cred-1', label: 'Laptop' }, 201));
    credentials.answer = async () => attestation(['usb']);

    assert.deepEqual(await yorvoid.addPasskey('Laptop'), { credential: 'cred-1', label: 'Laptop' });

    assert.equal(credentials.calls[0]?.kind, 'create');
    assert.deepEqual(credentials.calls[0]?.options.publicKey?.user?.id, bytes(7, 8, 9));
    assert.deepEqual(server.requests[1]?.body, {
      ceremony: 'c2',
      response: { id: 'AAI', rawId: 'AAI', type: 'public-key', clientExtensionResults: {}, authenticatorAttachment: 'cross-platform', response: { clientDataJSON: 'BQ', attestationObject: 'Bg', transports: ['usb'] } },
      label: 'Laptop',
    });
    assert.equal(server.paths().at(-1), '/yorvoid/passkeys/signals');
    assert.equal(publicKeyCredential.told.length, 2);
  });

  test('says when it needs a recent proof, and for what', async () => {
    const { server, yorvoid } = setUp();
    server.on('/yorvoid/passkeys/options', json({ error: 'Confirm it\'s you.', step_up: { operation: 'yorvoid.credential.add', within: '5m' } }, 403));

    const error = await refusal(yorvoid.addPasskey('Laptop'));

    assert.deepEqual([error.code, error.message, error.status, error.stepUp], ['step_up_required', 'Confirm it\'s you.', 403, { operation: 'yorvoid.credential.add', within: '5m' }]);

    server.on('/yorvoid/passkeys/options', json({ error: 'Confirm.', step_up: { operation: null, within: '15m' } }, 403));
    assert.deepEqual((await refusal(yorvoid.addPasskey('Laptop'))).stepUp, { operation: null, within: '15m' });
    server.on('/yorvoid/passkeys/options', json({ error: 'Confirm.', step_up: { within: '15m' } }, 403));
    assert.deepEqual((await refusal(yorvoid.addPasskey('Laptop'))).stepUp, { operation: null, within: '15m' });
  });

  test('the authenticator may have one already', async () => {
    const { server, credentials, yorvoid } = setUp();
    server.on('/yorvoid/passkeys/options', { ceremony: 'c2', options: CREATION_OPTIONS });
    credentials.answer = async () => { throw new DOMException('Excluded', 'InvalidStateError'); };

    const error = await refusal(yorvoid.addPasskey('Laptop'));

    assert.deepEqual([error.code, error.message], ['already_registered', MESSAGES.already_registered]);
    assert.equal(server.paths().length, 1);
  });
});

describe('stepping up', () => {
  test('for an operation, with the person\'s passkey', async () => {
    const { server, credentials, yorvoid } = setUp();
    server.on('/yorvoid/step-up/options', { ceremony: 'c3', options: REQUEST_OPTIONS }).on('/yorvoid/step-up', { stepped_up: true });

    await yorvoid.stepUp({ operation: 'acme.site.remove', multiFactor: true });
    await yorvoid.stepUp();

    assert.deepEqual(server.requests.map((request) => request.body).filter((_, i) => i % 2 === 0), [{ operation: 'acme.site.remove', multi_factor: true }, { operation: null, multi_factor: false }]);
    assert.equal((server.requests[1]?.body as Record<string, unknown>)['ceremony'], 'c3');
    assert.equal(credentials.calls[0]?.options.mediation, undefined);
    assert.deepEqual(credentials.calls[0]?.options.publicKey?.challenge, bytes(1, 2, 3));
    assert.ok(credentials.calls[0]?.options.signal instanceof AbortSignal);
    assert.equal(server.paths().includes('/yorvoid/passkeys/signals'), false);
  });

  test('a session that ended signs the person out', async () => {
    const { server, yorvoid } = setUp();
    server.on('/yorvoid/step-up/options', json({ error: 'Sign in first.' }, 401));

    const error = await refusal(yorvoid.stepUp());

    assert.deepEqual([error.code, error.message, error.status], ['signed_out', 'Sign in first.', 401]);
  });

  test('a refused passkey says why, for the logs', async () => {
    const { server, yorvoid } = setUp();
    server.on('/yorvoid/step-up/options', { ceremony: 'c3', options: REQUEST_OPTIONS }).on('/yorvoid/step-up', json({ error: 'That took too long.', reason: 'ceremony_expired' }, 400));

    const error = await refusal(yorvoid.stepUp());

    assert.deepEqual([error.code, error.message, error.status, error.reason], ['refused', 'That took too long.', 400, 'ceremony_expired']);
  });
});

describe('signing intents', () => {
  test('signs the options the verifier prepared, as JSON or parsed', async () => {
    const { server, credentials, yorvoid } = setUp();

    for (const options of [REQUEST_OPTIONS, JSON.stringify(REQUEST_OPTIONS)]) {
      const signed = await yorvoid.signIntent(options);

      assert.equal(signed['id'], 'AAE');
      assert.deepEqual((signed['response'] as Record<string, unknown>)['signature'], 'Aw');
    }

    assert.deepEqual(credentials.calls.map((call) => call.options.publicKey?.challenge), [bytes(1, 2, 3), bytes(1, 2, 3)]);
    assert.deepEqual(server.paths(), [], 'The console sends it to the verifier, not YorvoID.');
  });

  test('refuses options that aren\'t', async () => {
    const { yorvoid } = setUp();

    for (const [options, cause] of [['{', SyntaxError], ['[]', TypeError], ['{"challenge": "!"}', TypeError], [{}, TypeError]] as const) {
      const error = await refusal(yorvoid.signIntent(options));

      assert.equal(error.code, 'server');
      assert.ok(error.cause instanceof cause);
    }
  });
});

describe('the signal API', () => {
  test('isn\'t asked for when the browser can\'t use it', async () => {
    for (const publicKeyCredential of [{ isConditionalMediationAvailable: async () => true }, undefined]) {
      const { server, yorvoid } = setUp({ publicKeyCredential });

      await yorvoid.syncPasskeys();

      assert.deepEqual(server.paths(), []);
    }
  });

  test('a failing signal changes nothing', async () => {
    const told: unknown[] = [];
    const { yorvoid } = setUp({ publicKeyCredential: { signalAllAcceptedCredentials: async () => { throw new Error('No.'); }, signalCurrentUserDetails: async (o: unknown) => { told.push(o); } } });

    await yorvoid.syncPasskeys();

    assert.equal(told.length, 1);
  });

  test('tells what the browser can hear', async () => {
    const told: unknown[] = [];

    for (const publicKeyCredential of [{ signalAllAcceptedCredentials: async (o: unknown) => { told.push(o); } }, { signalCurrentUserDetails: async (o: unknown) => { told.push(o); } }]) {
      await setUp({ publicKeyCredential }).yorvoid.syncPasskeys();
    }

    assert.deepEqual(told, [{ rpId: 'example.com', userId: 'BwgJ', allAcceptedCredentialIds: ['AAE'] }, { rpId: 'example.com', userId: 'BwgJ', name: 'ana@example.com', displayName: 'Ana' }]);
  });

  test('tells nothing without a passkey', async () => {
    const { server, publicKeyCredential, yorvoid } = setUp();
    server.on('/yorvoid/passkeys/signals', new Response(null, { status: 204 }));

    await yorvoid.syncPasskeys();

    assert.deepEqual(publicKeyCredential.told, []);
  });
});

describe('errors', () => {
  test('a browser without passkeys', async () => {
    const { server, yorvoid } = setUp({ credentials: undefined });

    for (const attempt of [yorvoid.signIn(), yorvoid.addPasskey('x'), yorvoid.stepUp(), yorvoid.signIntent(REQUEST_OPTIONS)]) {
      const error = await refusal(attempt);
      assert.deepEqual([error.code, error.message], ['not_supported', MESSAGES.not_supported]);
    }

    assert.deepEqual(server.paths(), []);
  });

  test('what the browser reports', async () => {
    const { credentials, yorvoid } = setUp();
    const cause = new DOMException('Timed out', 'NotAllowedError');
    credentials.answer = async () => { throw cause; };

    const error = await refusal(yorvoid.signIn());

    assert.deepEqual([error.code, error.message, error.cause, error.status], ['cancelled', MESSAGES.cancelled, cause, undefined]);

    credentials.answer = async () => null;
    await assert.rejects(yorvoid.signIn(), new TypeError('The browser didn\'t give a passkey.'));
  });

  test('a server that can\'t be reached, or answers otherwise', async () => {
    const offline = new TypeError('Failed to fetch');
    const network = await refusal(new YorvoID({ fetch: async () => { throw offline; }, credentials: new FakeCredentials() }).signIn());
    assert.deepEqual([network.code, network.message, network.cause], ['network', MESSAGES.network, offline]);

    const answers: Response[] = [
      json({ error: 'Boom.' }, 500),
      new Response('<html>', { status: 200 }),
      json(['not', 'an', 'object']),
      json(null),
      json({ error: 'Send JSON.' }, 415),
      json({}, 401),
      json({ error: 5 }, 400),
      json({ error: 'Forbidden.' }, 403),
      json({ error: 'Forbidden.', step_up: 'x' }, 403),
      json({ error: 'Forbidden.', step_up: null }, 403),
      json({ error: 'Forbidden.', step_up: [] }, 403),
    ];

    for (const answer of answers) {
      const { server, yorvoid } = setUp();
      server.on('/yorvoid/sign-in/options', answer);
      const error = await refusal(yorvoid.signIn());

      assert.deepEqual([error.code, error.message, error.status], ['server', MESSAGES.server, answer.status], await answer.clone().text());
    }

    const { server, yorvoid } = setUp();
    server.on('/yorvoid/sign-in/options', { ceremony: 'c1', options: { challenge: 7 } });
    const malformed = await refusal(yorvoid.signIn());
    assert.deepEqual([malformed.code, malformed.status], ['server', undefined]);
    assert.ok(malformed.cause instanceof TypeError);
  });

  test('too many attempts, and when to try again', async () => {
    for (const [header, retryAfter] of [['30', 30], [null, undefined], ['soon', undefined], ['1.5', undefined]] as const) {
      const { server, yorvoid } = setUp();
      server.on('/yorvoid/sign-in/options', json({ error: 'Too many.' }, 429, header === null ? {} : { 'Retry-After': header }));

      const error = await refusal(yorvoid.signIn());

      assert.deepEqual([error.code, error.message, error.status, error.retryAfter], ['too_many_attempts', 'Too many.', 429, retryAfter]);
    }

    const { server, yorvoid } = setUp();
    server.on('/yorvoid/sign-in/options', json({}, 429));
    assert.equal((await refusal(yorvoid.signIn())).message, MESSAGES.too_many_attempts);
  });

  test('a 401 is a refusal where people sign in, and a session that ended elsewhere', async () => {
    const attempts: [string, (yorvoid: YorvoID) => Promise<unknown>, string][] = [
      ['/yorvoid/sign-in/options', (yorvoid) => yorvoid.signIn(), 'refused'],
      ['/yorvoid/sign-in', (yorvoid) => yorvoid.signIn(), 'refused'],
      ['/yorvoid/recover/code', (yorvoid) => yorvoid.recoverWithCode('a', 'b'), 'refused'],
      ['/yorvoid/recover/link', (yorvoid) => yorvoid.recoverWithLink('t'), 'refused'],
      ['/yorvoid/passkeys/options', (yorvoid) => yorvoid.addPasskey('x'), 'signed_out'],
      ['/yorvoid/passkeys', (yorvoid) => yorvoid.addPasskey('x'), 'signed_out'],
      ['/yorvoid/step-up/options', (yorvoid) => yorvoid.stepUp(), 'signed_out'],
      ['/yorvoid/step-up', (yorvoid) => yorvoid.stepUp(), 'signed_out'],
      ['/yorvoid/passkeys/signals', (yorvoid) => yorvoid.syncPasskeys(), 'signed_out'],
    ];

    for (const [path, attempt, code] of attempts) {
      const { server, credentials, yorvoid } = setUp();
      server.on('/yorvoid/passkeys/options', { ceremony: 'c2', options: CREATION_OPTIONS }).on('/yorvoid/step-up/options', { ceremony: 'c3', options: REQUEST_OPTIONS });
      server.on(path, json({ error: 'No.' }, 401));
      credentials.answer = async () => path.startsWith('/yorvoid/passkeys') ? attestation() : assertion();

      const error = await refusal(attempt(yorvoid));

      assert.deepEqual([path, error.code, error.message], [path, code, 'No.']);
    }
  });

  test('your translations win', async () => {
    const { server, yorvoid } = setUp({ messages: { refused: 'Das hat nicht geklappt.', server: 'Fehler.' } });
    server.on('/yorvoid/sign-in', json({ error: 'That passkey didn\'t work.' }, 401));

    assert.equal((await refusal(yorvoid.signIn())).message, 'Das hat nicht geklappt.');

    server.on('/yorvoid/sign-in/options', json({}, 500));
    assert.equal((await refusal(yorvoid.signIn())).message, 'Fehler.');
  });
});

describe('in the browser', () => {
  test('uses the browser\'s fetch, navigator.credentials and PublicKeyCredential', async (context) => {
    const server = new FakeServer().on('/yorvoid/sign-in/options', { ceremony: 'c1', options: REQUEST_OPTIONS }).on('/yorvoid/sign-in', { signed_in: true }).on('/yorvoid/passkeys/signals', SIGNALS);
    const credentials = new FakeCredentials();
    const publicKeyCredential = statics();
    let self: unknown;
    context.mock.method(globalThis, 'fetch', function (this: unknown, input: RequestInfo | URL, init?: RequestInit) {
      self = this;

      return server.fetch(input, init);
    });
    Object.defineProperty(globalThis.navigator, 'credentials', { value: credentials, configurable: true });
    Object.defineProperty(globalThis, 'PublicKeyCredential', { value: publicKeyCredential, configurable: true });
    context.after(() => {
      Reflect.deleteProperty(globalThis.navigator, 'credentials');
      Reflect.deleteProperty(globalThis, 'PublicKeyCredential');
    });

    await new YorvoID().signIn({ conditional: true });

    assert.equal(self, globalThis, 'fetch() must be called on the window.');
    assert.equal(credentials.calls.length, 1);
    assert.equal(publicKeyCredential.told.length, 2);
  });
});
