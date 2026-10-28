import assert from 'node:assert/strict';
import { test } from 'node:test';
import { assertionJson, creationOptions, registrationJson, requestOptions } from '../src/webauthn.ts';
import { assertion, attestation, bytes, CREATION_OPTIONS, REQUEST_OPTIONS } from './support.ts';

test('decodes the options for navigator.credentials.create(), keeping the rest', () => {
  const options = creationOptions(CREATION_OPTIONS);

  assert.deepEqual(options, {
    ...CREATION_OPTIONS,
    challenge: bytes(4, 5, 6),
    user: { id: bytes(7, 8, 9), name: 'ana@example.com', displayName: 'ana@example.com' },
    excludeCredentials: [{ type: 'public-key', id: bytes(10, 11, 12), transports: ['internal'] }],
  });
  assert.deepEqual(creationOptions({ ...CREATION_OPTIONS, excludeCredentials: undefined }).excludeCredentials, []);
});

test('decodes the options for navigator.credentials.get()', () => {
  assert.deepEqual(requestOptions(REQUEST_OPTIONS), { ...REQUEST_OPTIONS, challenge: bytes(1, 2, 3), allowCredentials: [] });
  assert.deepEqual(requestOptions({ challenge: 'AQID', allowCredentials: [{ type: 'public-key', id: 'AA' }] }).allowCredentials, [{ type: 'public-key', id: bytes(0) }]);
  assert.deepEqual(requestOptions({ challenge: 'AQID' }).allowCredentials, []);
});

test('refuses options that aren\'t WebAuthn\'s', () => {
  const cases: [() => unknown, string][] = [
    [() => requestOptions(null), 'The options isn\'t an object.'],
    [() => requestOptions([]), 'The options isn\'t an object.'],
    [() => requestOptions('x'), 'The options isn\'t an object.'],
    [() => requestOptions({}), 'The challenge isn\'t base64url.'],
    [() => requestOptions({ challenge: 5 }), 'The challenge isn\'t base64url.'],
    [() => requestOptions({ challenge: 'AQID', allowCredentials: {} }), 'The passkeys to allow isn\'t a list.'],
    [() => requestOptions({ challenge: 'AQID', allowCredentials: [null] }), 'The passkeys to allow isn\'t an object.'],
    [() => requestOptions({ challenge: 'AQID', allowCredentials: [{}] }), 'The passkeys to allow\' ID isn\'t base64url.'],
    [() => creationOptions(null), 'The options isn\'t an object.'],
    [() => creationOptions({ challenge: 'AQID' }), 'The user isn\'t an object.'],
    [() => creationOptions({ challenge: 'AQID', user: {} }), 'The user\'s ID isn\'t base64url.'],
    [() => creationOptions({ challenge: 'AQID', user: { id: 'AA' }, excludeCredentials: 'x' }), 'The passkeys to exclude isn\'t a list.'],
  ];

  for (const [run, message] of cases) {
    assert.throws(run, new TypeError(message));
  }
});

test('encodes a signature as YorvoID takes it', () => {
  assert.deepEqual(assertionJson(assertion()), {
    id: 'AAE',
    rawId: 'AAE',
    type: 'public-key',
    clientExtensionResults: { credProps: { rk: true } },
    authenticatorAttachment: 'platform',
    response: { clientDataJSON: 'AQ', authenticatorData: 'Ag', signature: 'Aw', userHandle: 'BA' },
  });

  const without = assertionJson(assertion({ userHandle: null, attachment: null }));
  assert.equal((without['response'] as Record<string, unknown>)['userHandle'], null);
  assert.ok(!('authenticatorAttachment' in without));
});

test('encodes a new passkey as YorvoID takes it', () => {
  assert.deepEqual(registrationJson(attestation(['internal', 'hybrid'])), {
    id: 'AAI',
    rawId: 'AAI',
    type: 'public-key',
    clientExtensionResults: {},
    authenticatorAttachment: 'cross-platform',
    response: { clientDataJSON: 'BQ', attestationObject: 'Bg', transports: ['internal', 'hybrid'] },
  });
  assert.deepEqual((registrationJson(attestation())['response'] as Record<string, unknown>)['transports'], [], 'Browsers without getTransports().');
});

test('refuses what isn\'t a passkey', () => {
  for (const credential of [null, { type: 'password', id: 'x' } as Credential]) {
    assert.throws(() => assertionJson(credential), new TypeError('The browser didn\'t give a passkey.'));
    assert.throws(() => registrationJson(credential), new TypeError('The browser didn\'t give a passkey.'));
  }
});
