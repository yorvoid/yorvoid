// One usability participant's browser (M2-10, docs/usability/m2-10/agent-method.md): a Chromium page
// with a virtual authenticator, driven over HTTP by the participant's tools (mcp.mjs) and by the
// moderator (ctl.mjs). Device prompts pause the page until the participant answers them; every action,
// and what the page then showed, goes to var/usability/<participant>/log.jsonl.
//
//   node usability/driver.mjs <participant> <app port> <driver port>
import { appendFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { chromium } from 'playwright';

const [participant, appPort, driverPort] = process.argv.slice(2);
const protocol = JSON.parse(readFileSync(new URL('./protocol.json', import.meta.url), 'utf8'));
const persona = protocol.personas[participant];
const device = protocol.devices[persona.device];
const origin = `http://localhost:${appPort}`;
const dir = `/app/var/usability/${participant}`;
mkdirSync(dir, { recursive: true });
const log = (entry) => appendFileSync(`${dir}/log.jsonl`, `${JSON.stringify({ at: new Date().toISOString(), ...entry })}\n`);

const browser = await chromium.launch();
let context;
let page;
let cdp;
let authenticator;
let devices = 0;
let pending = null;
const task = { n: 0, actions: 0, hinted: false, stopped: false, done: false, signedOut: false, cancelled: false, device: 0 };

async function newDevice() {
  await context?.close();
  context = await browser.newContext({ viewport: device.viewport, hasTouch: device.touch, isMobile: device.touch, baseURL: origin });
  page = await context.newPage();
  cdp = await context.newCDPSession(page);
  await cdp.send('WebAuthn.enable');
  ({ authenticatorId: authenticator } = await cdp.send('WebAuthn.addVirtualAuthenticator', {
    options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true },
  }));
  // The device's prompt: the page waits until the participant answers it.
  await page.exposeFunction('__yorvoidDevice', (kind) => new Promise((resolve) => { pending = { kind, resolve }; }));
  await page.addInitScript(() => {
    PublicKeyCredential.isConditionalMediationAvailable = async () => false;
    const credentials = navigator.credentials;
    for (const kind of ['create', 'get']) {
      const original = credentials[kind].bind(credentials);
      credentials[kind] = async (options) => {
        if (options?.mediation === 'conditional') {
          throw new DOMException('Not offered here.', 'NotSupportedError');
        }
        if (await window.__yorvoidDevice(kind) !== 'confirm') {
          throw new DOMException('The operation either timed out or was not allowed.', 'NotAllowedError');
        }
        return original(options);
      };
    }
  });
  page.on('request', (request) => {
    if (request.method() === 'POST' && new URL(request.url()).pathname === '/sign-out') {
      task.signedOut = true;
    }
  });
  pending = null;
  devices += 1;
}

async function passkeys() {
  return (await cdp.send('WebAuthn.getCredentials', { authenticatorId: authenticator })).credentials.length;
}

async function settle() {
  await page.waitForLoadState('networkidle', { timeout: 3000 }).catch(() => {});
  await page.waitForTimeout(300);
}

async function body() {
  return page.evaluate(() => document.body?.innerText ?? '').catch(() => '');
}

/** Whether the task's pre-registered success holds now. */
async function succeeded() {
  const text = await body();
  const signedIn = text.includes('Signed in as');
  switch (task.n) {
    case 1: return signedIn && await passkeys() > 0;
    case 2: return task.signedOut && signedIn;
    case 3: return task.cancelled && signedIn;
    case 4: {
      const file = `${dir}/app/projects.json`;
      return existsSync(file) && !('old-newsletter' in JSON.parse(readFileSync(file, 'utf8')));
    }
    case 5: return devices > task.device && signedIn;
    case 6: return devices > task.device && signedIn && await passkeys() > 0;
    case 7: return await page.locator('[data-yorvoid-remedy="wait"]').count() > 0;
    default: return false;
  }
}

/** What the participant sees: the device's prompt over the page, or the page. */
async function look() {
  if (pending !== null) {
    const kind = pending.kind === 'get' && await passkeys() === 0 ? 'none' : pending.kind;
    const text = kind === 'none' ? protocol.prompts.none : protocol.prompts[kind][persona.device];

    return { text: `${text}\n(Answer it with the device tool.)` };
  }

  const tree = await page.locator('body').ariaSnapshot().catch(() => '');
  let text = `Page title: ${await page.title()}\nAddress: ${page.url()}\n\n${tree}`;

  if (persona.device === 'keyboard') {
    const focus = await page.evaluate(() => {
      const element = document.activeElement;
      if (!element || element === document.body) return 'nothing (the page itself)';
      const name = element.getAttribute('aria-label') ?? element.labels?.[0]?.innerText ?? element.innerText ?? element.getAttribute('value') ?? '';
      return `${element.tagName.toLowerCase()}${element.type ? ` (${element.type})` : ''}: "${name.trim().slice(0, 80)}"`;
    });
    text += `\n\nThe focus is on: ${focus}`;

    return { text };
  }

  return { text, image: (await page.screenshot({ fullPage: true })).toString('base64') };
}

async function first(candidates) {
  for (const locator of candidates) {
    if (await locator.count() > 0) {
      return locator.first();
    }
  }
  return null;
}

const actions = {
  async click({ name }) {
    const element = await first([
      ...['button', 'link', 'checkbox', 'radio', 'tab', 'menuitem'].map((role) => page.getByRole(role, { name, exact: true })),
      ...['button', 'link'].map((role) => page.getByRole(role, { name })),
      page.getByText(name, { exact: true }),
      page.getByText(name),
    ]);
    if (element === null) return `There's nothing called "${name}" on the page to ${device.touch ? 'tap' : 'click'}.`;
    await (device.touch ? element.tap({ timeout: 3000 }) : element.click({ timeout: 3000 })).catch(() => {});
    return null;
  },
  async fill({ label, text }) {
    const element = await first([page.getByLabel(label, { exact: true }), page.getByLabel(label), page.getByPlaceholder(label)]);
    if (element === null) return `There's no field called "${label}" on the page.`;
    await element.fill(text, { timeout: 3000 }).catch(() => {});
    return null;
  },
  async check({ label }) {
    const element = await first([page.getByLabel(label, { exact: true }), page.getByLabel(label)]);
    if (element === null) return `There's no checkbox called "${label}" on the page.`;
    await element.check({ timeout: 3000 }).catch(() => {});
    return null;
  },
  async go({ address }) {
    await page.goto(address, { timeout: 5000 }).catch(() => {});
    return null;
  },
  async back() {
    await page.goBack({ timeout: 5000 }).catch(() => {});
    return null;
  },
  async tab() { await page.keyboard.press('Tab'); return null; },
  async shift_tab() { await page.keyboard.press('Shift+Tab'); return null; },
  async enter() { await page.keyboard.press('Enter'); return null; },
  async space() { await page.keyboard.press('Space'); return null; },
  async type({ text }) { await page.keyboard.type(text); return null; },
  async device({ answer }) {
    if (pending === null) return 'Your device isn\'t asking anything.';
    const kind = pending.kind === 'get' && await passkeys() === 0 ? 'none' : pending.kind;
    const confirmed = answer === 'confirm' && kind !== 'none';
    if (!confirmed) task.cancelled = true;
    const { resolve } = pending;
    pending = null;
    resolve(confirmed ? 'confirm' : 'cancel');
    return kind === 'none' && answer === 'confirm' ? 'Nothing happens: there\'s no passkey on this device. The window closes.' : null;
  },
};

/** A participant's action, counted, settled, and checked against the task's success. */
async function act(name, args) {
  if (name === 'look') {
    const seen = await look();
    log({ who: 'participant', action: 'look', saw: seen.text });
    return seen;
  }

  if (name === 'notes_write') {
    appendFileSync(`${dir}/notes.txt`, `${args.text}\n`);
    log({ who: 'participant', action: name, args });
    return { text: 'Written in your notes.' };
  }

  if (name === 'notes_read') {
    const notes = existsSync(`${dir}/notes.txt`) && task.n !== 6 ? readFileSync(`${dir}/notes.txt`, 'utf8') : '';
    log({ who: 'participant', action: name });
    return { text: notes === '' ? (task.n === 6 ? 'Your notes are at home.' : 'Your notes are empty.') : notes };
  }

  if (task.stopped) {
    return { text: protocol.moderator.stop };
  }

  if (!(name in actions)) {
    return { text: `You can't do "${name}".` };
  }

  task.actions += 1;
  const problem = await actions[name](args ?? {});
  await settle();
  if (!task.done && await succeeded()) {
    task.done = true;
    log({ who: 'driver', task: task.n, success: true, actions: task.actions });
  }
  const seen = await look();
  let text = problem === null ? seen.text : `${problem}\n\n${seen.text}`;
  log({ who: 'participant', action: name, args, problem, saw: seen.text, count: task.actions });

  if (!task.done && task.actions >= protocol.stops.stopAfterActions) {
    task.stopped = true;
    log({ who: 'moderator', task: task.n, said: protocol.moderator.stop, rule: 'stop' });
    text += `\n\n${protocol.moderator.stop}`;
  } else if (!task.done && !task.hinted && task.actions >= protocol.stops.hintAfterActions) {
    task.hinted = true;
    log({ who: 'moderator', task: task.n, said: protocol.moderator.hint, rule: 'hint' });
    text += `\n\n${protocol.moderator.hint}`;
  }

  return { ...seen, text };
}

/** The moderator's side: what the protocol has them do between tasks. */
const moderator = {
  async reset() {
    const response = await page.request.post('/_e2e/reset');
    return { status: response.status() };
  },
  async start() {
    await page.goto('/');
    return {};
  },
  async task({ n }) {
    Object.assign(task, { n, actions: 0, hinted: false, stopped: false, done: false, signedOut: false, cancelled: false, device: devices });
    return {};
  },
  async 'setup-code'() {
    return { code: await (await page.request.post('/_e2e/setup-code')).text() };
  },
  async clock({ seconds }) {
    return { status: (await page.request.post('/_e2e/clock', { data: { seconds } })).status() };
  },
  async 'recovery-link'({ email }) {
    const path = await (await page.request.post('/_e2e/recovery-link', { form: { email } })).text();
    return { link: path.startsWith('http') ? path : `${origin}/yorvoid/flows/yorvoid.recover_with_link?token=${encodeURIComponent(path)}` };
  },
  async 'new-device'() {
    await newDevice();
    await page.goto('about:blank');
    return { devices };
  },
  async state() {
    return { task: { ...task }, devices, passkeys: await passkeys(), url: page.url() };
  },
  async log({ entry }) {
    log(entry);
    return {};
  },
};

await newDevice();
writeFileSync(`${dir}/driver.pid`, String(process.pid));

createServer(async (request, response) => {
  let body = '';
  for await (const chunk of request) body += chunk;
  const { name, args } = JSON.parse(body || '{}');
  try {
    const result = request.url === '/act' ? await act(name, args) : await moderator[name](args ?? {});
    response.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify(result));
  } catch (error) {
    log({ who: 'driver', error: String(error) });
    response.writeHead(500, { 'Content-Type': 'application/json' }).end(JSON.stringify({ text: `Something went wrong with the browser: ${error.message}` }));
  }
}).listen(Number(driverPort), '127.0.0.1', () => console.log(`ready ${participant} ${origin}`));
