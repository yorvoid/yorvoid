import assert from 'node:assert/strict';
import { test } from 'node:test';
import { browserErrorCode, MESSAGES, notPermitted, YorvoIDError } from '../src/errors.ts';

test('maps what the browser reports to codes', () => {
  const codes = Object.fromEntries(['NotAllowedError', 'AbortError', 'InvalidStateError', 'ConstraintError', 'NotSupportedError', 'SecurityError', 'UnknownError', 'toString', 'hasOwnProperty'].map((name) => [name, browserErrorCode(new DOMException('', name))]));

  assert.deepEqual(codes, {
    NotAllowedError: 'cancelled',
    AbortError: 'aborted',
    InvalidStateError: 'already_registered',
    ConstraintError: 'user_verification_unavailable',
    NotSupportedError: 'not_supported',
    SecurityError: 'wrong_site',
    UnknownError: 'unknown',
    toString: 'unknown',
    hasOwnProperty: 'unknown',
  });
  assert.equal(browserErrorCode(new TypeError('x')), 'unknown');
  assert.equal(browserErrorCode({ name: 'NotAllowedError' }), 'unknown', 'Only errors are errors.');
  assert.equal(browserErrorCode(undefined), 'unknown');
});

test('an error carries its code and details', () => {
  const cause = new Error('cause');
  const error = new YorvoIDError('step_up_required', 'Confirm it.', { status: 403, reason: 'r', retryAfter: 5, stepUp: { operation: 'a.b.c', within: '5m' }, cause });

  assert.ok(error instanceof Error);
  assert.deepEqual([error.name, error.code, error.message, error.status, error.reason, error.retryAfter, error.stepUp, error.cause], ['YorvoIDError', 'step_up_required', 'Confirm it.', 403, 'r', 5, { operation: 'a.b.c', within: '5m' }, cause]);

  const bare = new YorvoIDError('network', 'Offline.');
  assert.deepEqual([bare.status, bare.reason, bare.retryAfter, bare.stepUp, bare.notPermitted, bare.cause], [undefined, undefined, undefined, undefined, undefined, undefined]);

  const refused = new YorvoIDError('not_permitted', 'No.', { notPermitted: { verdict: 'deny', decision: null, remedies: [] } });
  assert.deepEqual(refused.notPermitted, { verdict: 'deny', decision: null, remedies: [] });
});

test('every code has a message, and they can\'t be changed', () => {
  assert.equal(Object.keys(MESSAGES).length, 14);
  assert.ok(Object.values(MESSAGES).every((message) => message.endsWith('.')));
  assert.ok(Object.isFrozen(MESSAGES));
});

test('reads a refusal\'s remedies, leaving out what isn\'t one', () => {
  const stepUp = { name: 'step_up', parameters: { max_age: 300, multi_factor: false, operation: null }, message: 'Confirm it\'s you.', action: { url: '/yorvoid/flows/yorvoid.step_up?within=300', label: 'Confirm' } };
  const wait = { name: 'wait', parameters: { until: '2026-11-10T15:00:00+00:00' }, message: 'In about 3 hours.', action: null };

  assert.deepEqual(notPermitted({ verdict: 'deny', decision: 'd-1', remedies: [wait, stepUp] }), { verdict: 'deny', decision: 'd-1', remedies: [wait, stepUp] });
  assert.deepEqual(notPermitted({ verdict: 'indeterminate', decision: null, remedies: [] }), { verdict: 'indeterminate', decision: null, remedies: [] });
  assert.deepEqual(notPermitted({}), { verdict: 'deny', decision: null, remedies: [] }, 'What\'s missing has its default.');
  assert.deepEqual(notPermitted({ remedies: [
    null,
    'wait',
    { ...wait, name: 3 },
    { ...wait, message: undefined },
    { ...wait, action: { url: '/x' } },
    { ...wait, action: { label: 'Go' } },
    { ...wait, action: 'x' },
    { ...wait, parameters: [1] },
    { ...wait, parameters: null },
  ] })?.remedies, [{ ...wait, parameters: {} }, { ...wait, parameters: {} }]);

  for (const value of [undefined, null, 'not_permitted', [], 3]) {
    assert.equal(notPermitted(value), undefined);
  }
});
