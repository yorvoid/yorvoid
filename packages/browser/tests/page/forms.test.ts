import assert from 'node:assert/strict';
import { test } from 'node:test';
import { REQUEST_OPTIONS } from '../support.ts';
import { FakeForm, page, settle } from '../dom.ts';

// In a process of its own: the script runs by itself, once, when a page loads it.
test('runs by itself in a page, with the browser\'s client', async () => {
  const form = new FakeForm({ yorvoidCeremony: 'get', yorvoidOptions: JSON.stringify(REQUEST_OPTIONS), yorvoidMessages: JSON.stringify({ not_supported: 'Keine Passkeys hier.' }) });
  Object.defineProperty(globalThis, 'document', { value: page([form]), configurable: true });

  try {
    await import('../../src/forms.ts');
    form.send();
    await settle();
  } finally {
    Reflect.deleteProperty(globalThis, 'document');
  }

  assert.equal(form.error?.textContent, 'Keine Passkeys hier.', 'Without navigator.credentials here, it says passkeys aren\'t supported, in the page\'s language.');
  assert.equal(form.error?.hidden, false);
});
