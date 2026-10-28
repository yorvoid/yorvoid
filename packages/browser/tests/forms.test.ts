import assert from 'node:assert/strict';
import { test } from 'node:test';
import { MESSAGES, YorvoID, type Messages } from '../src/index.ts';
import { wireForms } from '../src/forms.ts';
import { FakeForm, page, settle } from './dom.ts';
import { assertion, attestation, CREATION_OPTIONS, FakeCredentials, FakeServer, REQUEST_OPTIONS, statics, untilAborted } from './support.ts';

function wire(forms: FakeForm[], credentials: FakeCredentials, conditional = true, problems: { focused: boolean } | null = null): Partial<Messages>[] {
  const given: Partial<Messages>[] = [];
  wireForms(page(forms, problems), (messages) => {
    given.push(messages);

    return new YorvoID({ messages, fetch: new FakeServer().fetch, credentials, publicKeyCredential: statics(conditional) });
  });

  return given;
}

test('a sign-in form runs the ceremony when sent, and sends the answer', async () => {
  const credentials = new FakeCredentials();
  const form = new FakeForm({ yorvoidCeremony: 'get', yorvoidOptions: JSON.stringify(REQUEST_OPTIONS) });
  wire([form], credentials);

  const event = form.send();
  await settle();

  assert.equal(event.prevented, true);
  assert.equal(form.submitted, 1);
  assert.equal(JSON.parse(form.response?.value ?? '')['id'], 'AAE');
  assert.deepEqual(credentials.calls.map((call) => [call.kind, call.options.mediation]), [['get', undefined]]);
});

test('a form adding a passkey creates one', async () => {
  const credentials = new FakeCredentials(async () => attestation());
  const form = new FakeForm({ yorvoidCeremony: 'create', yorvoidOptions: JSON.stringify(CREATION_OPTIONS) });
  wire([form], credentials);

  form.send();
  await settle();

  assert.equal(JSON.parse(form.response?.value ?? '')['id'], 'AAI');
  assert.deepEqual(credentials.calls.map((call) => call.kind), ['create']);
});

test('offers passkeys in autofill, until the button is used', async () => {
  const credentials = new FakeCredentials();
  credentials.answer = () => untilAborted(credentials.calls.at(-1)?.options.signal);
  const form = new FakeForm({ yorvoidCeremony: 'get', yorvoidOptions: JSON.stringify(REQUEST_OPTIONS), yorvoidConditional: '' });
  wire([form], credentials);
  await settle();
  assert.deepEqual(credentials.calls.map((call) => call.options.mediation), ['conditional']);

  credentials.answer = async () => assertion();
  form.send();
  await settle();

  assert.equal(form.submitted, 1, 'Once: the autofill was stopped, silently.');
  assert.equal(form.error?.hidden, true);
});

test('autofill that answers sends the form', async () => {
  const form = new FakeForm({ yorvoidCeremony: 'get', yorvoidOptions: JSON.stringify(REQUEST_OPTIONS), yorvoidConditional: '' });
  wire([form], new FakeCredentials());
  await settle();

  assert.equal(form.submitted, 1);
});

test('without autofill, nothing happens until the button', async () => {
  const credentials = new FakeCredentials();
  const form = new FakeForm({ yorvoidCeremony: 'get', yorvoidOptions: JSON.stringify(REQUEST_OPTIONS), yorvoidConditional: '' });
  wire([form], credentials, false);
  await settle();

  assert.deepEqual([form.submitted, credentials.calls.length, form.error?.hidden], [0, 0, true]);
});

test('"Not now" sends the form without a ceremony', async () => {
  const credentials = new FakeCredentials();
  const form = new FakeForm({ yorvoidCeremony: 'create', yorvoidOptions: JSON.stringify(CREATION_OPTIONS) });
  wire([form], credentials);

  const skip = form.send('action');
  const other = new FakeForm({ yorvoidCeremony: 'create', yorvoidOptions: JSON.stringify(CREATION_OPTIONS) });
  wire([other], credentials);
  const named = other.send('label');
  await settle();

  assert.equal(skip.prevented, false);
  assert.equal(named.prevented, true);
  assert.equal(credentials.calls.length, 1);
});

test('shows what went wrong, in the page\'s language', async () => {
  const credentials = new FakeCredentials(async () => { throw new DOMException('Timed out', 'NotAllowedError'); });
  const form = new FakeForm({ yorvoidCeremony: 'get', yorvoidOptions: JSON.stringify(REQUEST_OPTIONS), yorvoidMessages: JSON.stringify({ cancelled: 'Abgebrochen.' }) });
  const given = wire([form], credentials);

  form.send();
  await settle();

  assert.deepEqual(given, [{ cancelled: 'Abgebrochen.' }]);
  assert.deepEqual(form.error, { textContent: 'Abgebrochen.', hidden: false });
  assert.equal(form.submitted, 0);
});

test('what can\'t be shown is left alone', async () => {
  const aborted = new FakeForm({ yorvoidCeremony: 'get', yorvoidOptions: JSON.stringify(REQUEST_OPTIONS) });
  wire([aborted], new FakeCredentials(async () => { throw new DOMException('Stopped', 'AbortError'); }));
  aborted.send();

  const nowhere = new FakeForm({ yorvoidCeremony: 'get', yorvoidOptions: JSON.stringify(REQUEST_OPTIONS) }, { error: false });
  wire([nowhere], new FakeCredentials(async () => { throw new DOMException('No', 'NotAllowedError'); }));
  nowhere.send();

  const odd = new FakeForm({ yorvoidCeremony: 'get', yorvoidOptions: JSON.stringify(REQUEST_OPTIONS) });
  wire([odd], new FakeCredentials(async () => null));
  odd.send();
  await settle();

  assert.deepEqual(aborted.error, { textContent: '', hidden: true });
  assert.deepEqual(odd.error, { textContent: 'The browser didn\'t give a passkey.', hidden: false });
});

test('sends the form even without a response field, and reads odd messages as none', async () => {
  const form = new FakeForm({ yorvoidCeremony: 'get', yorvoidOptions: JSON.stringify(REQUEST_OPTIONS), yorvoidMessages: '{' }, { response: false });
  const given = wire([form], new FakeCredentials());

  form.send();
  await settle();

  assert.equal(form.submitted, 1);
  assert.deepEqual(given, [{}]);
  assert.deepEqual(wire([new FakeForm({ yorvoidCeremony: 'get', yorvoidMessages: '["a"]' })], new FakeCredentials()), [{}]);
  assert.deepEqual(wire([new FakeForm({ yorvoidCeremony: 'get', yorvoidMessages: 'null' })], new FakeCredentials()), [{}]);
  assert.deepEqual(wire([new FakeForm({ yorvoidCeremony: 'get', yorvoidMessages: '5' })], new FakeCredentials()), [{}]);
  assert.deepEqual(wire([new FakeForm({ yorvoidCeremony: 'get' })], new FakeCredentials()), [{}]);
});

test('a form without options refuses, and says so', async () => {
  const form = new FakeForm({ yorvoidCeremony: 'get' });
  wire([form], new FakeCredentials());

  form.send();
  await settle();

  assert.deepEqual(form.error, { textContent: MESSAGES.server, hidden: false });
});

test('the page\'s problems get the focus', () => {
  const problems = { focused: false };
  wire([], new FakeCredentials(), true, problems);

  assert.equal(problems.focused, true);
  wire([], new FakeCredentials(), true, null);
});
