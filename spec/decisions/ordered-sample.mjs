// Writes a seeded sample of ordered-condition requests of 3 and 4 matching elements, with the outcome
// the precedence model gives each in both configurations, for tests/Policy/OrderedFixturesTest.php,
// beside decisions/fixtures-ordered.json's every request of up to 2 (M2-11; 4 elements would be about
// 1.4M requests to enumerate):
//
//   node decisions/ordered-sample.mjs > decisions/fixtures-ordered-sample.json
//
// The requests are drawn with a fixed seed, so the file is the same each time; the outcomes are the
// model's own: each request is a command that pins it in the model's OrderedRequest, which Alloy solves
// with decideOrdered. The format is fixtures-ordered.json's: [elements, authority, fact, default, extended].
// check.sh fails when the file doesn't match the model any more.
import { execFile } from 'node:child_process';
import { copyFileSync, mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir, availableParallelism } from 'node:os';
import { join } from 'node:path';
import { promisify } from 'node:util';

const SEED = 0x0bde4ed;
const COUNT = 10000;

// mulberry32: small, and the same everywhere.
let state = SEED;
const random = () => {
  state = (state + 0x6d2b79f5) | 0;
  let t = Math.imul(state ^ (state >>> 15), 1 | state);
  t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
  return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
};
const pick = (list) => list[Math.floor(random() * list.length)];

const EFFECTS = { P: 'PermitEffect', F: 'ForbidEffect' };
const VALUES = { '+': 'True', '-': 'False', '?': 'Unknown' };
const AUTHORITIES = { holds: 'Valid', fails: 'Invalid', unknown: 'AuthorityUnknown' };
const LEVELS = ['0', '1', '2', '3'];
const NAMES = { Permit: 'permit', Deny: 'deny', Indeterminate: 'indeterminate', PermitOffline: 'permit_offline' };

// An element: plain ("P+", "F?") half the time, a rule on the fact ("F>=2", "P<=3") otherwise.
const element = () => pick(['P', 'F']) + (random() < 0.5 ? pick(['+', '-', '?']) : pick(['>=', '<=']) + pick(LEVELS));
const facts = [...LEVELS, ...LEVELS.map((l) => '?' + l), '?'];

const requests = new Map();

while (requests.size < COUNT) {
  const elements = Array.from({ length: random() < 0.5 ? 3 : 4 }, element).sort();

  // At least one rule on the fact: without one, it's the table, which fixtures.json covers.
  if (!elements.some((e) => /[<>]=/.test(e))) {
    continue;
  }

  const request = [elements.join(','), pick(Object.keys(AUTHORITIES)), pick(facts)];
  requests.set(request.join('/'), request);
}

// One command for each request, at exactly its number of elements: the model's own OrderedRequest
// decides it (orderedFixture), with each element pinned. Small commands solve fast; one with every
// request would ground each against every element.
const pinned = (e, name) => {
  const [, effect, rest] = e.match(/^([PF])(.*)$/);
  const rule = rest.match(/^([<>]=)(\d)$/);

  return rule
    ? `${name}.effect = ${EFFECTS[effect]} and ${name} in Ordered and ${name}.direction = ${rule[1] === '>=' ? 'AtLeast' : 'AtMost'} and ${name}.bound = L${rule[2]}`
    : `${name}.effect = ${EFFECTS[effect]} and ${name} not in Ordered and ${name}.condition = ${VALUES[rest]}`;
};

const command = ([list, authority, fact], n) => {
  const elements = list.split(',');
  const names = elements.map((_, i) => `e${i}`);
  const now = /^\d$/.test(fact) ? `OrderedRequest.now = L${fact}` : 'no OrderedRequest.now';
  const last = /^\?\d$/.test(fact) ? `OrderedRequest.last = L${fact[1]}` : 'no OrderedRequest.last';

  return `run r${n} { one OrderedRequest and no Request and no ExtendedRequest
    some disj ${names.join(', ')}: Element | ${elements.map((e, i) => pinned(e, names[i])).join(' and ')}
    OrderedRequest.authority = ${AUTHORITIES[authority]} and ${now} and ${last} } for exactly ${elements.length} Element
`;
};

const outcome = (trace, relation) => {
  const found = trace.match(new RegExp(`OrderedRequest<:${relation}=\\{[^}]*->precedence/(\\w+?)\\$\\d+\\}`));

  if (null === found) {
    throw new Error(`No ${relation} outcome in a solution.`);
  }

  return NAMES[found[1]];
};

const all = [...requests.values()];
const directory = mkdtempSync(join(tmpdir(), 'ordered-sample-'));
const parts = Math.max(1, Math.min(8, availableParallelism()));
const size = Math.ceil(all.length / parts);

try {
  copyFileSync(new URL('precedence.als', import.meta.url).pathname, join(directory, 'precedence.als'));
  const decided = await Promise.all(Array.from({ length: parts }, async (_, p) => {
    const part = all.slice(p * size, (p + 1) * size);
    const file = join(directory, `sample${p}.als`);
    writeFileSync(file, 'module sample\nopen precedence\n\n' + part.map(command).join(''));
    const { stdout } = await promisify(execFile)('java', ['-jar', '/opt/alloy/alloy.jar', 'exec', '-q', '-c', '*', '-r', '1', '-o', '-', '-t', 'text', file], { encoding: 'utf8', maxBuffer: 1 << 30 });
    const traces = stdout.split('---Trace---').slice(1);

    // Every command has exactly its one solution, in order: otherwise an outcome would land on another request.
    if (traces.length !== part.length) {
      throw new Error(`${part.length} requests, but ${traces.length} solutions.`);
    }

    return part.map((request, i) => [...request, outcome(traces[i], 'default'), outcome(traces[i], 'extended')]);
  }));
  const sorted = decided.flat().sort((a, b) => { const x = a.slice(0, 3).join('/'); const y = b.slice(0, 3).join('/'); return x < y ? -1 : x > y ? 1 : 0; }).map((f) => JSON.stringify(f));
  process.stdout.write(`[\n${sorted.join(',\n')}\n]\n`);
} finally {
  rmSync(directory, { recursive: true, force: true });
}
