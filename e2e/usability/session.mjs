// One participant's session of M2-10's simulated usability test (docs/usability/m2-10/agent-method.md),
// moderated by the protocol alone: every moderator turn is a text from protocol.json, logged as said.
//
// The participant is a Claude session with no built-in tools, no settings, no project, and only the
// acme-browser MCP server: it can't read the repository, the app or this script. Its working directory
// is outside the repository.
//
//   node e2e/usability/session.mjs <participant> <working directory outside the repository>
import { execFileSync, spawnSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';

const [participant, workdir] = process.argv.slice(2);
const protocol = JSON.parse(readFileSync(new URL('./protocol.json', import.meta.url), 'utf8'));
const persona = protocol.personas[participant];
const n = Number(participant.slice(1));
const appPort = 8100 + n;
const driverPort = 9100 + n;
const container = `yorvoid-usability-${participant}`;
const app = 'yorvoid-e2e-app-1';
const data = `/app/var/usability/${participant}/app`;

const docker = (...args) => execFileSync('docker', args, { encoding: 'utf8' }).trim();
const ctl = (name, args = {}) => JSON.parse(docker('exec', container, 'node', 'usability/ctl.mjs', String(driverPort), name, JSON.stringify(args)));
const log = (entry) => ctl('log', { entry });

// The participant's copy of Acme Team, and their browser.
docker('exec', '-d', '-e', `YORVOID_E2E_ORIGIN=http://localhost:${appPort}`, '-e', `YORVOID_E2E_DATA=${data}`, '-e', 'PHP_CLI_SERVER_WORKERS=4', app,
  'sh', '-c', `rm -rf ${data} && mkdir -p ${data}/sessions && exec php -S 127.0.0.1:${appPort} e2e/app/router.php`);
spawnSync('docker', ['rm', '-f', container]);
docker('compose', '--profile', 'e2e', 'run', '-d', '--name', container, 'e2e', 'node', 'usability/driver.mjs', participant, String(appPort), String(driverPort));
for (let i = 0; ; i++) {
  try { ctl('state'); break; } catch { if (i > 60) throw new Error('The driver didn\'t start.'); execFileSync('sleep', ['1']); }
}
ctl('reset');
ctl('start');

mkdirSync(workdir, { recursive: true });
writeFileSync(`${workdir}/mcp.json`, JSON.stringify({ mcpServers: { acme: { command: 'docker', args: ['exec', '-i', container, 'node', 'usability/mcp.mjs', participant, String(driverPort)] } } }));
const system = `${protocol.rules}\n\nWho you are:\n${persona.text}`;
let session = null;

/** One moderator turn, and the participant's answer. */
function say(text, task = null) {
  log({ who: 'moderator', task, said: text });
  const args = ['-p', text, '--output-format', 'json', '--model', persona.model, '--tools', '', '--strict-mcp-config', '--mcp-config', `${workdir}/mcp.json`,
    '--allowedTools', 'mcp__acme', '--system-prompt', system, '--setting-sources', '', '--disable-slash-commands', '--max-turns', '120'];
  if (session !== null) args.push('--resume', session);
  const run = spawnSync('claude', args, { cwd: workdir, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, env: { ...process.env, CLAUDE_CODE_DISABLE_AUTO_MEMORY: '1' } });
  let answer;
  try {
    answer = JSON.parse(run.stdout);
  } catch {
    log({ who: 'driver', task, broken: true, stderr: run.stderr.slice(-2000), stdout: run.stdout.slice(-2000) });
    throw new Error(`The participant's session broke: ${run.stderr.slice(-500)}`);
  }
  session = answer.session_id;
  log({ who: 'participant', task, said: answer.result ?? '', models: Object.keys(answer.modelUsage ?? {}), turns: answer.num_turns, stop: answer.subtype });

  return answer.result ?? '';
}

say(protocol.moderator.intro);
const fill = (text, values) => text.replace(/\{(\w+)\}/g, (_, key) => String(values[key]));
const values = { port: appPort, email: persona.email, newEmail: persona.email.replace('@', '.new@') };

protocol.moderator.tasks.forEach((task, i) => {
  const number = i + 1;
  if (number === 1) values.setupCode = ctl('setup-code').code;
  if (task.before === 'clock') ctl('clock', { seconds: 600 });
  if (task.before === 'new-device' || task.before === 'new-device-and-link') ctl('new-device');
  if (task.before === 'new-device-and-link') values.link = ctl('recovery-link', { email: persona.email }).link;
  ctl('task', { n: number });

  say(task.message ? `${fill(task.message, values)}\n\n${fill(task.card, values)}` : fill(task.card, values), number);
  const state = ctl('state');
  log({ who: 'driver', task: number, outcome: state.task.done ? (state.task.hinted ? 'assisted' : 'unaided') : 'failed', actions: state.task.actions, hinted: state.task.hinted, stopped: state.task.stopped });
  say(protocol.moderator.seq, number);
  if (protocol.moderator.after[number]) say(protocol.moderator.after[number], number);
});

say(protocol.moderator.sus);
say(protocol.moderator.debrief);
log({ who: 'driver', end: true });
docker('rm', '-f', container);
console.log(`${participant} done`);
