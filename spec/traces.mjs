// Writes executions of a Quint model, as the checker gives them (ITF, with the action taken and its
// choices), for the PHP tests to replay against YorvoID's code (tests/Support/Itf):
//
//   node traces.mjs <model.qnt> <module> <traces> <steps> <seed> > <out.itf.json>
//
// The seed is fixed, so the file is the same on every run, with the Quint pinned in this image;
// check.sh fails when a committed file no longer matches its model. Only the states are kept, minified,
// with "#meta" dropped: the file stays small, and it's still the checker's own output.
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const [model, main, count, steps, seed] = process.argv.slice(2);

if (!model || !main || !count || !steps || !seed) {
  process.stderr.write('Usage: node traces.mjs <model.qnt> <module> <traces> <steps> <seed>\n');
  process.exit(2);
}

const dir = mkdtempSync(join(tmpdir(), 'itf-'));

execFileSync('quint', [
  'run', `--main=${main}`, '--mbt', `--max-steps=${steps}`, `--max-samples=${count}`, `--n-traces=${count}`, `--seed=${seed}`,
  `--out-itf=${join(dir, 'trace_{seq}.itf.json')}`, resolve(model),
], { stdio: ['ignore', 'ignore', 'inherit'] });

const traces = readdirSync(dir)
  .filter((file) => file.endsWith('.itf.json'))
  .sort((a, b) => Number(a.match(/\d+/)[0]) - Number(b.match(/\d+/)[0]))
  .map((file) => JSON.parse(readFileSync(join(dir, file), 'utf8')).states.map(({ '#meta': _, ...state }) => state));

rmSync(dir, { recursive: true });
process.stdout.write(JSON.stringify({ model, main, seed, traces }) + '\n');
