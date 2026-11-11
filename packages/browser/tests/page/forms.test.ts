import assert from 'node:assert/strict';
import { test } from 'node:test';
import { REQUEST_OPTIONS } from '../support.ts';
import { FakeForm, page, settle } from '../dom.ts';

// In a process of its own: the script runs by itself, once, when a page loads it.
test('runs by itself in a page, with the browser\'s client', async () => {
  const form = new FakeForm({ yorvoidCeremony: 'get', yorvoidOptions: JSON.stringify(REQUEST_OPTIONS), yorvoidMessages: JSON.stringify({ not_supported: 'Keine Passkeys hier.' }) });
  const forms = page([form]);
  const save = { hidden: true, dataset: {}, querySelector: () => null };
  const document = {
    querySelector: (selector: string) => selector === '[data-yorvoid-save-codes]' ? save : forms.querySelector(selector),
    querySelectorAll: (selector: string) => selector === '[data-yorvoid-recovery-codes] code' ? [] : forms.querySelectorAll(selector),
  };
  Object.defineProperty(globalThis, 'document', { value: document, configurable: true });
  Object.defineProperty(globalThis, 'location', { value: { hostname: 'app.example.com' }, configurable: true });

  try {
    await import('../../src/forms.ts');
    form.send();
    await settle();
  } finally {
    Reflect.deleteProperty(globalThis, 'document');
    Reflect.deleteProperty(globalThis, 'location');
  }

  assert.equal(save.hidden, false, 'The recovery codes\' buttons are shown too.');

  assert.equal(form.error?.textContent, 'Keine Passkeys hier.', 'Without navigator.credentials here, it says passkeys aren\'t supported, in the page\'s language.');
  assert.equal(form.error?.hidden, false);
});
