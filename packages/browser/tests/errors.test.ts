import assert from 'node:assert/strict';
import { test } from 'node:test';
import { browserErrorCode, MESSAGES, YorvoIDError } from '../src/errors.ts';

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
  assert.deepEqual([bare.status, bare.reason, bare.retryAfter, bare.stepUp, bare.cause], [undefined, undefined, undefined, undefined, undefined]);
});

test('every code has a message, and they can\'t be changed', () => {
  assert.equal(Object.keys(MESSAGES).length, 13);
  assert.ok(Object.values(MESSAGES).every((message) => message.endsWith('.')));
  assert.ok(Object.isFrozen(MESSAGES));
});
