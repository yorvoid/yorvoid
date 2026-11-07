// Writes every request of the precedence model's "fixtures" run (up to 6 matching elements, each
// authority) with the outcome the model gives it, for the PHP evaluator to match
// (tests/Core/Authorization/PrecedenceFixturesTest.php):
//
//   node decisions/fixtures.mjs > decisions/fixtures.json
//
// Each fixture is [elements, authority, outcome]: an element is P (permit) or F (forbid) with + (holds),
// - (fails) or ? (unknown), e.g. "F?,P+", and the authority holds, fails or is unknown. Alloy enumerates
// every solution; requests that differ only in which atom is which are one fixture.
// check.sh fails when the file doesn't match the model any more.
import { execFileSync } from 'node:child_process';

const out = execFileSync('java', [
  '-jar', '/opt/alloy/alloy.jar', 'exec', '-q', '-c', 'fixtures', '-r', '0', '-o', '-', '-t', 'text',
  new URL('precedence.als', import.meta.url).pathname,
], { encoding: 'utf8', maxBuffer: 1 << 30, stdio: ['ignore', 'pipe', 'inherit'] });

const NAMES = {
  PermitEffect: 'P', ForbidEffect: 'F',
  True: '+', False: '-', Unknown: '?',
  Valid: 'holds', Invalid: 'fails', AuthorityUnknown: 'unknown',
  Permit: 'permit', Deny: 'deny', Indeterminate: 'indeterminate',
};
const atom = (a) => NAMES[a.replace(/\$\d+$/, '')] ?? a;
// "this/Element<:effect={Element$0->PermitEffect$0, ...}" as [[from, to], ...]
const pairs = (text, relation) => {
  const line = text.split('\n').find((l) => l.startsWith(`this/${relation}=`));
  const body = line.slice(line.indexOf('{') + 1, line.lastIndexOf('}')).trim();
  return body === '' ? [] : body.split(', ').map((p) => p.split('->'));
};

const fixtures = new Map();

for (const solution of out.split('---Trace---').slice(1)) {
  const effect = Object.fromEntries(pairs(solution, 'Element<:effect'));
  const condition = Object.fromEntries(pairs(solution, 'Element<:condition'));
  const elements = Object.keys(effect).map((e) => atom(effect[e]) + atom(condition[e])).sort().join(',');
  const fixture = [elements, atom(pairs(solution, 'Request<:authority')[0][1]), atom(pairs(solution, 'Request<:result')[0][1])];
  fixtures.set(`${fixture[0]}/${fixture[1]}`, fixture);
}

const sorted = [...fixtures.entries()].sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0)).map(([, f]) => JSON.stringify(f));
process.stdout.write(`[\n${sorted.join(',\n')}\n]\n`);
