import assert from 'node:assert/strict';
import { test } from 'node:test';
import { browserEnvironment, fileName, wireCodes, type CodesEnvironment } from '../src/codes.ts';
import { settle } from './dom.ts';

class FakeControl {
  hidden: boolean;
  textContent: string | null = null;
  clicks: (() => void)[] = [];

  constructor(hidden = false) {
    this.hidden = hidden;
  }

  addEventListener(type: string, listener: () => void): void {
    assert.equal(type, 'click');
    this.clicks.push(listener);
  }

  click(): void {
    for (const listener of this.clicks) {
      listener();
    }
  }
}

function codesPage(controls: Record<string, FakeControl | null>, heading: string | null = 'Recovery codes for') {
  const group = Object.assign(new FakeControl(true), {
    dataset: heading === null ? {} : { yorvoidHeading: heading },
    querySelector: (selector: string) => controls[selector] ?? null,
  });
  const codes = [' ABCD-EFGH ', 'IJKL-MNOP', null].map((textContent) => ({ textContent }));

  return {
    group,
    root: {
      querySelector: (selector: string) => selector === '[data-yorvoid-save-codes]' ? group : null,
      querySelectorAll: (selector: string) => {
        assert.equal(selector, '[data-yorvoid-recovery-codes] code');

        return codes;
      },
    } as unknown as ParentNode,
  };
}

function environment(copy: ((text: string) => Promise<void>) | null = null): CodesEnvironment & { saved: [string, string][]; printed: number } {
  const saved: [string, string][] = [];
  const env = {
    saved,
    printed: 0,
    hostname: 'App.Example.com',
    now: () => new Date('2026-11-11T09:00:00Z'),
    save: (name: string, text: string) => { saved.push([name, text]); },
    copy,
    print: () => { env.printed++; },
  };

  return env;
}

const TEXT = 'Recovery codes for App.Example.com, 2026-11-11\n\nABCD-EFGH\nIJKL-MNOP\n';

test('the codes can be downloaded, copied and printed, once the script shows the buttons', async () => {
  const controls = { '[data-yorvoid-download]': new FakeControl(), '[data-yorvoid-copy]': new FakeControl(), '[data-yorvoid-copied]': new FakeControl(true), '[data-yorvoid-print]': new FakeControl() };
  const { group, root } = codesPage(controls);
  const copied: string[] = [];
  const env = environment(async (text) => { copied.push(text); });

  wireCodes(root, () => env);
  assert.equal(group.hidden, false);

  controls['[data-yorvoid-download]'].click();
  controls['[data-yorvoid-print]'].click();
  controls['[data-yorvoid-copy]'].click();
  await settle();

  assert.deepEqual(env.saved, [['app.example.com-recovery-codes.txt', TEXT]]);
  assert.equal(env.printed, 1);
  assert.deepEqual(copied, [TEXT]);
  assert.equal(controls['[data-yorvoid-copied]'].hidden, false);
});

test('where the browser can\'t copy, there\'s no copy button; a failed copy says nothing', async () => {
  const without = { '[data-yorvoid-copy]': new FakeControl() };
  wireCodes(codesPage(without).root, () => environment(null));
  assert.equal(without['[data-yorvoid-copy]'].hidden, true);
  const { group } = codesPage({});
  wireCodes(codesPage({}).root, () => environment(null));
  assert.equal(group.hidden, true, 'Another page\'s group: untouched.');
  const buttonless = codesPage({});
  wireCodes(buttonless.root, () => environment(null));
  assert.equal(buttonless.group.hidden, false, 'A page of its own without a copy button is fine.');

  const failing = { '[data-yorvoid-copy]': new FakeControl(), '[data-yorvoid-copied]': new FakeControl(true) };
  wireCodes(codesPage(failing).root, () => environment(async () => { throw new Error('Denied'); }));
  failing['[data-yorvoid-copy]'].click();
  await settle();
  assert.equal(failing['[data-yorvoid-copied]'].hidden, true);

  const silent = { '[data-yorvoid-copy]': new FakeControl() };
  const copied: string[] = [];
  wireCodes(codesPage(silent, null).root, () => environment(async (text) => { copied.push(text); }));
  silent['[data-yorvoid-copy]'].click();
  await settle();
  assert.deepEqual(copied, [' App.Example.com, 2026-11-11\n\nABCD-EFGH\nIJKL-MNOP\n'], 'Without a heading, and with nowhere to say it was copied.');
});

test('a page without the codes is left alone, without asking the browser for anything', () => {
  wireCodes({ querySelector: () => null } as unknown as ParentNode, () => assert.fail('Asked.'));
});

test('the file is named after the site, with nothing else in its name', () => {
  assert.equal(fileName('app.example.com'), 'app.example.com');
  assert.equal(fileName('ÄPP/../x_y.Example.COM'), 'pp..xy.example.com');
  assert.equal(fileName('?'), 'site');
});

test('in a browser: a Blob through a link, the clipboard in a secure context, window.print()', async () => {
  const globals = globalThis as Record<string, unknown>;
  const saved = { location: globals['location'], window: globals['window'], document: globals['document'], navigator: Object.getOwnPropertyDescriptor(globalThis, 'navigator') };
  const { createObjectURL, revokeObjectURL } = URL;
  const links: { href: string; download: string; clicked: number }[] = [];
  const revoked: string[] = [];
  const blobs: Blob[] = [];
  let printed = 0;
  const written: string[] = [];

  try {
    globals['location'] = { hostname: 'app.example.com' };
    globals['window'] = { isSecureContext: true, print: () => { printed++; } };
    globals['document'] = { createElement: (tag: string) => { assert.equal(tag, 'a'); const link = { href: '', download: '', clicked: 0, click() { this.clicked++; } }; links.push(link); return link; } };
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { clipboard: { writeText: async (text: string) => { written.push(text); } } } });
    URL.createObjectURL = (blob: Blob) => { blobs.push(blob); return 'blob:1'; };
    URL.revokeObjectURL = (url: string) => { revoked.push(url); };

    const env = browserEnvironment();
    assert.equal(env.hostname, 'app.example.com');
    assert.ok(Math.abs(env.now().getTime() - Date.now()) < 1000);
    env.save('a.txt', 'codes');
    env.print();
    await env.copy?.('copied');
    await settle();

    assert.deepEqual(links.map((link) => [link.href, link.download, link.clicked]), [['blob:1', 'a.txt', 1]]);
    assert.equal(await blobs[0]?.text(), 'codes');
    assert.equal(blobs[0]?.type, 'text/plain;charset=utf-8');
    assert.deepEqual(revoked, ['blob:1']);
    assert.equal(printed, 1);
    assert.deepEqual(written, ['copied']);

    globals['window'] = { isSecureContext: false, print: () => undefined };
    assert.equal(browserEnvironment().copy, null, 'Not in an insecure context.');
    globals['window'] = { isSecureContext: true, print: () => undefined };
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: {} });
    assert.equal(browserEnvironment().copy, null, 'Not without a clipboard.');
  } finally {
    globals['location'] = saved.location;
    globals['window'] = saved.window;
    globals['document'] = saved.document;
    if (saved.navigator !== undefined) {
      Object.defineProperty(globalThis, 'navigator', saved.navigator);
    }
    URL.createObjectURL = createObjectURL;
    URL.revokeObjectURL = revokeObjectURL;
  }
});
