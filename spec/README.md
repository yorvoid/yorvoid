# Formal models

YorvoID's security-critical semantics are specified as formal models and checked by machine (decision D-15 of the requirements). Tests then check the PHP code against these models, so the code isn't tested against itself.

```bash
docker compose --profile spec run --rm spec ./check.sh
```

The `spec` container has every tool, pinned and checksum-verified: Alloy 6.2.0, and Quint 0.33.0 with Apalache 0.62.1 and its Rust evaluator. CI runs the same script.

**Which language for what:** Alloy for structure (relations, sets, what holds in every configuration), Quint for behaviour over time (state machines, concurrency). Quint was chosen over TLA+ after writing the token model in both (decision D-27): both found the race, but Quint's executions export as ITF JSON, which the PHP tests replay, and its syntax is easier for PHP developers to read.

## Models

| Model | What it specifies | Checked |
|---|---|---|
| [`decisions/precedence.als`](decisions/precedence.als) | The outcome of a decision (Permit, Deny, Indeterminate) from the matching policy elements and the authority (requirements 06 §3) | Alloy: exhaustively, for up to 6 matching elements. Its 2772 requests and their outcomes ([`decisions/fixtures.json`](decisions/fixtures.json), from `decisions/fixtures.mjs`) are decided by the PHP evaluator in `tests/Core/Authorization/PrecedenceFixturesTest.php`. Permit-offline (P1′) comes only from an Indeterminate whose every reason is an unreachable store, with a decider, for an operation that accepts it, and never makes or takes away a Permit |
| [`offline/elapsed.qnt`](offline/elapsed.qnt) | An offline limit (XP-14): a node permits offline only while less than the limit has really passed since its link was lost, whatever the wall clock and reboots do | Apalache: every execution up to 12 steps; simulation: 20 000 random runs. Its wall-clock variant must fail, and only by the clock being set back; replayed against `WithinOfflineLimit` |
| [`tokens/one_time_token.qnt`](tokens/one_time_token.qnt) | One-time tokens: at most once, never after expiry or revocation, newest only (ATN-030), with 3 consumers racing for 3 tokens | Apalache: every execution up to 10 steps, beyond the longest (9); simulation: 20 000 random runs; replayed against the PHP code |
| [`verifier/key_registry.qnt`](verifier/key_registry.qnt) | The intent verifier's keys: the first with the bootstrap secret, every later change signed, delayed and cancellable, never the last one (INT-005, 006, 013, 016, 017), with 3 keys | Apalache: every execution up to 10 steps; simulation: 20 000 random runs; replayed against `KeyRegistry` |
| [`setup/setup_claim.qnt`](setup/setup_claim.qnt) | Claiming a realm with its setup code: one owner, losers keep nothing (ATN-020), the owner is the realm's first principal (ATN-034), with 3 claimers and an invitation accepted at once | Apalache: every execution up to 12 steps, beyond the longest (10); simulation: 20 000 random runs; replayed against `SetupCodes`, by commit order |

### Properties of decisions

- **A Permit never depends on an unknown:** however the unknown conditions and authority turn out, it stays a Permit, so no fallback that guesses can turn Indeterminate into Permit.
- **Monotonicity:** adding a forbid, or making a permit's condition less certain, never turns a refusal into a Permit.
- **A Permit needs everything:** a validated authority, a permit whose condition holds, and no forbid that holds or might.
- **Every outcome is reachable**, so the table has no dead rows.
- **Documented counterexample:** Indeterminate isn't always "the unknowns could have permitted". An unknown forbid with no permit that could apply gives Indeterminate, although the result is a refusal either way. This follows the table as specified; whether to change it is open question Q-09.

### Properties of one-time tokens

- A token is used **at most once**, also when several processes present it at the same moment.
- Nobody is let through with a token that was revoked, used or expired at that moment.
- Only the newest token for a purpose works.

### Properties of the verifier's keys

- Once the verifier has a key, it **always has one**, whatever removals and cancellations come in whatever order: removing counts the keys already leaving.
- **Only the first key arrives at once**; every later one waits the delay, while any key can cancel it.
- No key is added twice, nor is it both coming and leaving.

### Properties of the setup claim

- A realm has **one first owner**, also when several people enter the code at the same moment.
- Whoever loses the race keeps **no principal**: their transaction rolled back.
- The owner is **the realm's first principal**: a claim never succeeds after someone became active another way, e.g. by an invitation accepted at the same moment. The claim locks the realm's log before it checks.

Each model also contains a **deliberately broken variant**, e.g. the naive "read, then delete" token consumption, a removal that counts only the current keys, a claim without a compare-and-swap, or one that checks without the log's lock, and the script requires the checker to find what each breaks. A checker that stopped finding problems would therefore fail the build too.

## Bounds

Model checking is exhaustive within its bounds, not beyond them. The bounds are chosen so that every interesting interleaving fits: 3 consumers racing for 3 tokens, with a clock that lets tokens expire. A Quint model's step bound must be at least its longest execution (its diameter), measured when the model is written; the token model's is 9, and it's checked up to 10. A property that needs larger bounds to fail is possible in principle; the PHP tests (concurrency, mutation testing) are the second line.

## The implementation, against the models

Every behaviour model is replayed against the PHP code, so the code isn't tested against itself (A-8). `traces.mjs` writes a Quint model's executions as the checker gives them, in ITF with the action each step took and its choices, with a fixed seed. The PHP tests read them with one reader (`tests/Support/Itf`), run each action against the code, and compare what the code shows with the model's state after every step. `check.sh` regenerates every file and fails if one no longer matches its model, so changing a model means regenerating its file with the command `check.sh` names (`TRACES_*`). Quint is pinned in the spec image, which keeps the files byte for byte the same.

| Model | Executions | Size | Replayed by | Against |
|---|---|---|---|---|
| tokens (`atomic`) | 100 × 12 steps | 0.9 MB | `tests/Authn/Token/OneTimeTokenTracesTest.php`, on every database | `OneTimeTokens` |
| verifier keys (`replayed`: YorvoID's way, with a clock that runs to 8, so more changes apply) | 60 × 24 steps | 0.7 MB | `tests/IntentVerifier/KeyRegistryTracesTest.php` | `KeyRegistry` |
| setup claim (`atomic`, `LOCKED = true`) | 100 × 14 steps | 0.7 MB | `tests/Authn/Setup/SetupClaimTracesTest.php`, on every database | `SetupCodes` |
| offline limit (`elapsedLimit`) | 80 × 16 steps | 0.9 MB | `tests/Core/Authorization/OfflineLimitTracesTest.php`, a `MockClock` and `MockElapsed` | `WithinOfflineLimit` |

Each file stays under 1 MB: more executions or steps are added only where they reach what fewer don't.

**The verifier's keys** are replayed step by step against `KeyRegistry`, the part of `Verifier` the model describes:
- **Signatures:** a change is signed by a key the model has; checking the signature is `IntentChecks`', tested on its own.
- **After each step:** the keys, the changes waiting with when they take effect, and whether the bootstrap secret works, are the model's.
- **At the end of each execution:** what the model can't do, `KeyRegistry` refuses: adding a key it has or is getting, removing one it hasn't, one already leaving, or the last.
- **The replayed module:** `replayed` is the same model as `counted`, with a clock that runs to 8 instead of 3, only so that the executions apply more changes. Apalache's check of `counted` (every execution up to 10 steps) is the claim; `replayed` is checked by simulation.
- **One refinement:** the model adds the first key within `bootstrap`. `KeyRegistry::bootstrap()` asks for its change, due at once, and commits it; `Verifier::requested()` then applies it, in a second transaction. A crash between the two leaves "no secret, no keys, one change due now" stored.
  - **It's never observable,** because the store is read and written only through `Verifier::transaction()`, which applies the changes that are due first (`packages/intent-verifier/src/Verifier.php`, its one call to `$this->store->transaction()`). So every later call, `keys()` included, sees the key added, and a crash recovers by itself.
  - **A PHPStan rule** (`disallowedMethodCalls` in `phpstan.dist.neon`) keeps it that way: nothing else calls `VerifierStore::transaction()`.
  - **The replay** applies the first key's change at once, as `Verifier` does. `Verifier` applies what's due at every call, so of the model's executions it shows only those where a due change doesn't wait.

**The setup claim** is replayed by the order its transactions commit in, not step by step. The argument:
- `SetupCodes::claimWith()` takes the realm's log lock before it checks the code and the realm, and holds it until it commits. That's the model's `LOCKED = true`, where `check` needs the lock and takes it, and an invitation's principal appends to the log under the same lock.
- So the claims and the invitation serialize: the order they commit or are refused in decides the outcome.
- Each execution is replayed as that order: one owner, the losers with no principal, a claim refused once the realm is claimed or someone is active in it.
- What happens inside one transaction is the model's, and `tests/Conformance/SetupClaimConcurrencyTest.php`'s, with two connections.
- **The test checks the abstraction too:** two executions in the same order must end the same in the model, or the order wouldn't decide the outcome.
- Only `LOCKED = true` executions are replayed: the others are the broken variants. The lock call in `claimWith()` is killed by mutation testing, so the mapping doesn't rest on an untested line.

### Two configurations (XP-16)

The decision model is checked, and its fixtures generated, in two configurations:
- **default** (enterprise): every extension point at its default;
- **extended:** every M2 extension point engaged: offline decisions (XP-03), an `OfflineDecider` for an operation that accepts Permit-offline; and the pessimistic mode of ordered conditions (XP-15, `PessimisticOrdering`).

In `precedence.als`, a configuration is a set of extension points (`decide[..., engaged]`):
- **MP-1:** with none engaged, the decision is the table, whatever's unreachable (`DefaultIsTheTable`).
- **P1, in the default:** a Permit needs everything (`DefaultPermitNeedsEverything`).
- **P1′, in the extended:** it only adds Permit-offline, where the default is Indeterminate (`ExtendedOnlyAddsPermitOffline`).
- **R2, in the extended:** with an ordered fact unknown, the decision is never more permissive than at any value it may have (`UnknownIsNoMorePermissive`). It fails in the default (`DefaultUnknownIsNoMorePermissive`, expected), so the check isn't vacuous.
- **MP-1 for ordered conditions:** by default, a rule on an unknown fact makes the decision Indeterminate unless a forbid that holds decides it (`DefaultUnknownOrderedIsIndeterminate`). With the fact known, both configurations decide alike (`KnownDecidesAlike`).

The fixtures:
- `fixtures.json` (default, 2772) is unchanged by the second configuration, so "enterprise behaviour unchanged" is checked, not assumed. `check.sh` pins its hash too, so a change to it shows in review.
- `fixtures-extended.json` (3960, up to 4 elements, 0.2 MB) adds which unknowns an unreachable store caused and what the decider says.
- `fixtures-ordered.json` (7452, up to 2 elements on a scale of 0..3, 0.4 MB): plain elements and rules on the ordered fact, each authority, the fact known, or unknown with or without a last known value, and what each configuration decides.
- `fixtures-ordered-sample.json` (10000, 3 and 4 elements, 0.5 MB): a sample drawn with a fixed seed (`ordered-sample.mjs`), each with at least one rule on the fact. Every request of 4 elements would be about 1.4M, so the model decides each sampled one as its own command, the request pinned in its `OrderedRequest`.
- `tests/Core/Authorization/PrecedenceFixturesTest.php` decides the first two with the PHP evaluator: built without a decider, and with one for an operation that accepts it. `tests/Policy/OrderedFixturesTest.php` decides the ordered ones with rules on `OrderedConditions`, in both modes.

The token, key and setup models have no extension point that changes their behaviour: for them, both configurations are one.

### Every new model

A model is done with three things:
- the model;
- a deliberately broken variant, which `check.sh` requires the checker to catch;
- a replay of its executions (or fixtures) against the PHP code.

A new extension point joins the extended configuration in the same change.

