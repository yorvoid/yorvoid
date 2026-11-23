// Writes the attenuation model's "fixtures" run (a root delegation and one passed on from it, over a
// small scope) with whether the model has the child within its parent, for the PHP check of one link
// to match (tests/Delegation/AttenuationFixturesTest.php):
//
//   node delegation/fixtures.mjs > delegation/fixtures.json
//
// The runs have more solutions than can be enumerated in a check's time, so it's the first 20 000 of
// "fixtures" and 10 000 of "fixturesTree" (a purpose under another), in Alloy's fixed order: the same sample each time, which check.sh compares with the committed file.
// Each fixture is [purposes, parent, child, within]: purposes as {name: above}, each delegation as
// {actions, redelegable, ids (null when the selector has no id atom), others, purpose, from, until,
// depth}, times as their index. Fixtures that differ only in which atom is which are one.
import { execFileSync } from 'node:child_process';

const run = (command, count) => execFileSync('java', [
  '-jar', '/opt/alloy/alloy.jar', 'exec', '-q', '-c', command, '-r', String(count), '-o', '-', '-t', 'text',
  new URL('attenuation.als', import.meta.url).pathname,
], { encoding: 'utf8', maxBuffer: 1 << 30, stdio: ['ignore', 'pipe', 'inherit'] });
const out = run('fixtures', 20000) + run('fixturesTree', 10000);

const pairs = (text, relation) => {
  const line = text.split('\n').find((l) => l.startsWith(`this/${relation}=`));
  const body = line.slice(line.indexOf('{') + 1, line.lastIndexOf('}')).trim();
  return body === '' ? [] : body.split(', ').map((p) => p.split('->'));
};
const many = (text, relation) => {
  const map = {};
  for (const [from, to] of pairs(text, relation)) (map[from] ??= []).push(to);
  return map;
};
const one = (text, relation) => Object.fromEntries(pairs(text, relation));
const index = (atom) => Number(atom.replace(/^.*\$/, ''));

const fixtures = new Map();

for (const solution of out.split('---Trace---').slice(1)) {
  const actions = many(solution, 'Delegation<:actions');
  const redelegable = many(solution, 'Delegation<:redelegable');
  const selector = one(solution, 'Delegation<:selector');
  const atoms = many(solution, 'Selector<:atoms');
  const ids = many(solution, 'IdsAtom<:ids');
  const purpose = one(solution, 'Delegation<:purpose');
  const from = one(solution, 'Delegation<:notBefore');
  const until = one(solution, 'Delegation<:expires');
  const depth = one(solution, 'Delegation<:depth');
  const above = one(solution, 'Purpose<:above');
  const name = (atom) => atom.replace(/\$/, '').toLowerCase();
  const delegation = (d) => {
    const own = atoms[selector[d]] ?? [];
    const idAtom = own.find((a) => a.startsWith('IdsAtom'));
    return {
      actions: (actions[d] ?? []).map(name).sort(),
      redelegable: (redelegable[d] ?? []).map(name).sort(),
      ids: idAtom === undefined ? null : (ids[idAtom] ?? []).map(name).sort(),
      others: own.filter((a) => a.startsWith('OtherAtom')).map(name).sort(),
      purpose: name(purpose[d]),
      from: index(from[d]),
      until: index(until[d]),
      depth: Number(depth[d]),
    };
  };
  const link = one(solution, 'Link<:child');
  const given = one(solution, 'Link<:given');
  const purposes = Object.fromEntries(pairs(solution, 'Purpose').length === 0 ? [] : solution.split('\n').find((l) => l.startsWith('this/Purpose=')).replace(/^.*\{|\}.*$/g, '').split(', ').filter((p) => p !== '').map((p) => [name(p), above[p] === undefined ? null : name(above[p])]));
  const fixture = [purposes, delegation(given.Link$0), delegation(link.Link$0), one(solution, 'Link<:verdict').Link$0.startsWith('Within')];
  fixtures.set(JSON.stringify(fixture), fixture);
}

const sorted = [...fixtures.keys()].sort();
process.stdout.write(`[\n${sorted.join(',\n')}\n]\n`);
