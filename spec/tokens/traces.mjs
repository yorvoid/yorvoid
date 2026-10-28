// Writes executions of the one-time token model (atomic module) as a compact file that the PHP tests
// replay against YorvoID's implementation (tests/Authn/Token/OneTimeTokenTracesTest.php):
//
//   node tokens/traces.mjs > tokens/traces.json
//
// Each step is the model's action and, afterwards, the time and which tokens work. The seed is fixed, so
// the file is the same on every run; check.sh fails when it doesn't match the model any more.
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const TRACES = 100;
const dir = mkdtempSync(join(tmpdir(), 'itf-'));

execFileSync('quint', [
  'run', '--main=atomic', '--mbt', '--max-steps=12', `--max-samples=${TRACES}`, `--n-traces=${TRACES}`, '--seed=0x5e171e1',
  `--out-itf=${join(dir, 'trace_{seq}.itf.json')}`, new URL('one_time_token.qnt', import.meta.url).pathname,
], { stdio: ['ignore', 'ignore', 'inherit'] });

const value = (v) => (v && typeof v === 'object' && '#bigint' in v ? Number(v['#bigint']) : v);
const map = (v) => Object.fromEntries(v['#map'].map(([k, x]) => [k, value(x)]));
const pick = (v) => (v.tag === 'Some' ? v.value : null);
const name = (key) => key.split('::').pop();

const traces = readdirSync(dir)
  .filter((file) => file.endsWith('.itf.json'))
  .sort((a, b) => Number(a.match(/\d+/)[0]) - Number(b.match(/\d+/)[0]))
  .map((file) => JSON.parse(readFileSync(join(dir, file), 'utf8')).states.slice(1).map((state) => {
    const vars = Object.fromEntries(Object.entries(state).map(([k, v]) => [name(k), v]));
    const states = map(vars.state);
    const expiry = map(vars.expiry);
    const now = value(vars.now);
    const picks = vars.nondetPicks;

    return {
      action: vars.actionTaken,
      token: pick(picks.t),
      now,
      works: Object.keys(states).filter((t) => states[t].tag === 'Valid' && now < expiry[t]).sort(),
    };
  }));

rmSync(dir, { recursive: true });
process.stdout.write(JSON.stringify({ model: 'spec/tokens/one_time_token.qnt (atomic)', lifetime: 2, traces }) + '\n');
