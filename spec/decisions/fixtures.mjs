// Writes every request of the precedence model's "fixtures" run (up to 6 matching elements, each
// authority) with the outcome the model gives it, for the PHP evaluator to match
// (tests/Core/Authorization/PrecedenceFixturesTest.php):
//
//   node decisions/fixtures.mjs > decisions/fixtures.json                    the default configuration
//   node decisions/fixtures.mjs extended > decisions/fixtures-extended.json  the extended one (XP-16)
//   node decisions/fixtures.mjs ordered > decisions/fixtures-ordered.json    ordered conditions (XP-15)
//
// Each fixture is [elements, authority, outcome]: an element is P (permit) or F (forbid) with + (holds),
// - (fails) or ? (unknown), e.g. "F?,P+", and the authority holds, fails or is unknown. In the extended
// configuration, ~ is unknown because a store couldn't be reached, the authority can be unreachable
// too, and each fixture has what the offline decider says before the outcome: [elements, authority,
// decider, outcome], the outcome possibly permit_offline. Alloy enumerates every solution; requests that
// differ only in which atom is which are one fixture. Ordered conditions: an element may be a rule on
// the ordered fact, e.g. "F>=2" or "P<=3"; the fact is known ("2"), or unknown with the last known value
// ("?1") or none ("?"); and each fixture has both configurations' outcomes: [elements, authority, fact,
// default, extended]. check.sh fails when a file doesn't match the model
// any more.
import { execFileSync } from 'node:child_process';

const mode = process.argv[2] ?? 'default';
const extended = mode === 'extended';
const ordered = mode === 'ordered';
const out = execFileSync('java', [
  '-jar', '/opt/alloy/alloy.jar', 'exec', '-q', '-c', { default: 'fixtures', extended: 'fixturesExtended', ordered: 'fixturesOrdered' }[mode], '-r', '0', '-o', '-', '-t', 'text',
  new URL('precedence.als', import.meta.url).pathname,
], { encoding: 'utf8', maxBuffer: 1 << 30, stdio: ['ignore', 'pipe', 'inherit'] });

const NAMES = {
  PermitEffect: 'P', ForbidEffect: 'F',
  True: '+', False: '-', Unknown: '?',
  Valid: 'holds', Invalid: 'fails', AuthorityUnknown: 'unknown',
  Permit: 'permit', Deny: 'deny', Indeterminate: 'indeterminate', PermitOffline: 'permit_offline',
  On: 'holds', Off: 'fails',
  AtLeast: '>=', AtMost: '<=', L0: '0', L1: '1', L2: '2', L3: '3',
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

  if (ordered) {
    const direction = Object.fromEntries(pairs(solution, 'Ordered<:direction'));
    const bound = Object.fromEntries(pairs(solution, 'Ordered<:bound'));
    const elements = Object.keys(effect).map((e) => atom(effect[e]) + (e in direction ? atom(direction[e]) + atom(bound[e]) : atom(condition[e]))).sort().join(',');
    const now = pairs(solution, 'OrderedRequest<:now');
    const last = pairs(solution, 'OrderedRequest<:last');
    const fact = now.length > 0 ? atom(now[0][1]) : '?' + (last.length > 0 ? atom(last[0][1]) : '');
    const fixture = [elements, atom(pairs(solution, 'OrderedRequest<:authority')[0][1]), fact, atom(pairs(solution, 'OrderedRequest<:default')[0][1]), atom(pairs(solution, 'OrderedRequest<:extended')[0][1])];
    fixtures.set(`${fixture[0]}/${fixture[1]}/${fixture[2]}`, fixture);
    continue;
  }

  if (!extended) {
    const elements = Object.keys(effect).map((e) => atom(effect[e]) + atom(condition[e])).sort().join(',');
    const fixture = [elements, atom(pairs(solution, 'Request<:authority')[0][1]), atom(pairs(solution, 'Request<:result')[0][1])];
    fixtures.set(`${fixture[0]}/${fixture[1]}`, fixture);
    continue;
  }

  const unreachable = new Set(pairs(solution, 'ExtendedRequest<:unreachable').map(([, e]) => e));
  const elements = Object.keys(effect).map((e) => atom(effect[e]) + (unreachable.has(e) ? '~' : atom(condition[e]))).sort().join(',');
  const authority = atom(pairs(solution, 'ExtendedRequest<:authority')[0][1]);
  const authorityUnreachable = atom(pairs(solution, 'ExtendedRequest<:authorityUnreachable')[0][1]) === 'holds';
  const fixture = [elements, authorityUnreachable ? 'unreachable' : authority, atom(pairs(solution, 'ExtendedRequest<:holds')[0][1]), atom(pairs(solution, 'ExtendedRequest<:result')[0][1])];
  fixtures.set(`${fixture[0]}/${fixture[1]}/${fixture[2]}`, fixture);
}

const sorted = [...fixtures.entries()].sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0)).map(([, f]) => JSON.stringify(f));
process.stdout.write(`[\n${sorted.join(',\n')}\n]\n`);
