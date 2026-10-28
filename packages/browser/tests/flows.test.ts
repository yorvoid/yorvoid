import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { YorvoID, YorvoIDError } from '../src/index.ts';
import { attestation, bytes, CREATION_OPTIONS, FakeCredentials, FakeServer, json, REQUEST_OPTIONS, statics } from './support.ts';

const STEP = { flow: 'yorvoid.sign_in', id: 'f1', status: 'in_progress', step: 'passkey', view: { options: REQUEST_OPTIONS }, problems: [], redirect: null };

function client(server: FakeServer, credentials = new FakeCredentials()): YorvoID {
  return new YorvoID({ fetch: server.fetch, credentials, publicKeyCredential: statics() });
}

describe('flows', () => {
  test('starts, shows and moves a flow on', async () => {
    const server = new FakeServer()
      .on('/yorvoid/flows/yorvoid.sign_in', STEP)
      .on('/yorvoid/flows/yorvoid.sign_in/f1', STEP)
      .on('/yorvoid/flows/yorvoid.sign_in/f1/passkey', { ...STEP, status: 'completed', step: null, view: null, redirect: '/' });
    const yorvoid = client(server);

    const started = await yorvoid.startFlow('yorvoid.sign_in');
    assert.deepEqual(started, { flow: 'yorvoid.sign_in', id: 'f1', status: 'in_progress', step: 'passkey', view: { options: REQUEST_OPTIONS }, problems: [], redirect: null, retryAfter: undefined });
    assert.deepEqual(server.requests[0]?.init, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, credentials: 'same-origin', body: '{}' });

    assert.deepEqual(await yorvoid.resumeFlow('yorvoid.sign_in', 'f1'), started);
    assert.deepEqual(server.requests[1]?.init, { method: 'GET', headers: { Accept: 'application/json' }, credentials: 'same-origin' });

    const done = await yorvoid.submitStep('yorvoid.sign_in', 'f1', 'passkey', { response: { id: 'x' } });
    assert.deepEqual([done.status, done.step, done.view, done.redirect], ['completed', null, null, '/']);
    assert.deepEqual(server.requests[2]?.body, { response: { id: 'x' } });
    assert.equal(server.requests[2]?.init.method, 'POST');
  });

  test('passes what a flow starts with, and encodes the address', async () => {
    const server = new FakeServer().on('/auth/flows/acme%2Fx/a%20b/c%3F', STEP).on('/auth/flows/yorvoid.invitation', STEP);
    const yorvoid = new YorvoID({ endpoint: '/auth', fetch: server.fetch, credentials: undefined });

    await yorvoid.startFlow('yorvoid.invitation', { token: 't0ken' });
    await yorvoid.submitStep('acme/x', 'a b', 'c?', {});

    assert.deepEqual(server.requests.map((request) => [request.path, request.body]), [['/auth/flows/yorvoid.invitation', { token: 't0ken' }], ['/auth/flows/acme%2Fx/a%20b/c%3F', {}]]);
  });

  test('refusals, failures, expiry and waiting are progress, not errors', async () => {
    const problem = { message: 'That code didn\'t work.', field: 'code', reason: null };
    const answers: [Response, Partial<Record<string, unknown>>][] = [
      [json({ ...STEP, problems: [problem] }, 422), { status: 'in_progress', problems: [problem], retryAfter: undefined }],
      [json({ ...STEP, problems: [problem] }, 429, { 'Retry-After': '60' }), { problems: [problem], retryAfter: 60 }],
      [json({ ...STEP, problems: [problem] }, 429, { 'Retry-After': 'soon' }), { retryAfter: undefined }],
      [json({ ...STEP, status: 'failed', step: null, view: null, problems: [problem] }, 410), { status: 'failed', step: null, view: null }],
      [json({ flow: 'yorvoid.sign_in', id: null, status: 'expired', step: null, view: null, problems: [problem], redirect: null }, 404), { id: null, status: 'expired' }],
      [json({ status: 'in_progress', flow: 5, view: [], problems: 'x', step: 3, redirect: 7 }), { flow: '5', view: null, problems: [], step: null, redirect: null, id: null }],
    ];

    for (const [answer, expected] of answers) {
      const progress = await client(new FakeServer().on('/yorvoid/flows/yorvoid.sign_in', answer)).startFlow('yorvoid.sign_in');

      for (const [key, value] of Object.entries(expected)) {
        assert.deepEqual(progress[key as keyof typeof progress], value, `${answer.status} ${key}`);
      }
    }
  });

  test('anything else is an error', async () => {
    for (const answer of [json({ error: 'Send a JSON object.' }, 400), json({ ...STEP }, 500), json({ ...STEP }, 403), new Response('<html>'), json({ flow: 'x' }), json([STEP])]) {
      const error = await client(new FakeServer().on('/yorvoid/flows/x', answer)).startFlow('x').catch((caught: unknown) => caught);

      assert.ok(error instanceof YorvoIDError);
      assert.deepEqual([error.code, error.status], ['server', answer.status]);
    }

    const offline = new TypeError('offline');
    const error = await new YorvoID({ fetch: async () => { throw offline; }, credentials: undefined }).resumeFlow('x', 'y').catch((caught: unknown) => caught);
    assert.ok(error instanceof YorvoIDError);
    assert.deepEqual([error.code, error.cause], ['network', offline]);
  });
});

describe('ceremonies for your own pages', () => {
  test('authenticates with a step\'s options, as JSON or parsed', async () => {
    const credentials = new FakeCredentials();
    const yorvoid = client(new FakeServer(), credentials);

    for (const options of [REQUEST_OPTIONS, JSON.stringify(REQUEST_OPTIONS)]) {
      assert.equal((await yorvoid.authenticate(options))['id'], 'AAE');
    }

    await yorvoid.authenticate(REQUEST_OPTIONS, { conditional: true });

    assert.deepEqual(credentials.calls.map((call) => [call.kind, call.options.mediation, call.options.publicKey?.challenge]), [['get', undefined, bytes(1, 2, 3)], ['get', undefined, bytes(1, 2, 3)], ['get', 'conditional', bytes(1, 2, 3)]]);
    assert.ok(credentials.calls.every((call) => call.options.signal instanceof AbortSignal));
  });

  test('autofill only where the browser offers it', async () => {
    const credentials = new FakeCredentials();
    const yorvoid = new YorvoID({ fetch: new FakeServer().fetch, credentials, publicKeyCredential: statics(false) });

    const error = await yorvoid.authenticate(REQUEST_OPTIONS, { conditional: true }).catch((caught: unknown) => caught);

    assert.ok(error instanceof YorvoIDError);
    assert.equal(error.code, 'not_supported');
    assert.deepEqual(credentials.calls, []);
  });

  test('registers with a step\'s options', async () => {
    const credentials = new FakeCredentials(async () => attestation(['usb']));
    const yorvoid = client(new FakeServer(), credentials);

    for (const options of [CREATION_OPTIONS, JSON.stringify(CREATION_OPTIONS)]) {
      const added = await yorvoid.register(options);

      assert.deepEqual((added['response'] as Record<string, unknown>)['transports'], ['usb']);
    }

    assert.deepEqual(credentials.calls.map((call) => [call.kind, call.options.publicKey?.user?.id]), [['create', bytes(7, 8, 9)], ['create', bytes(7, 8, 9)]]);
    assert.ok(credentials.calls.every((call) => call.options.signal instanceof AbortSignal));

    const broken = await yorvoid.register('{').catch((caught: unknown) => caught);
    assert.ok(broken instanceof YorvoIDError);
    assert.equal(broken.code, 'server');

    const unsupported = await new YorvoID({ credentials: undefined }).register(CREATION_OPTIONS).catch((caught: unknown) => caught);
    assert.ok(unsupported instanceof YorvoIDError);
    assert.equal(unsupported.code, 'not_supported');
  });
});
