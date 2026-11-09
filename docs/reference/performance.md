# Performance

How fast YorvoID's hot paths are, what they're held to, and how to measure them yourself (OPS-042, M2-8).

## Targets

| What | Target | Measured by |
|---|---|---|
| A decision, cached | p99 < 1 ms | `DecisionBench::benchCachedDecision` |
| A decision, uncached, depth 3 | p99 < 5 ms on PostgreSQL | `DecisionBench::benchUncachedDecision`, on a role three parents up (page, site, server). OPS-042 means a delegation chain of depth 3, which comes in M3: this target is open until then. |
| The passkey sign-in endpoints | < 50 ms | `SignInBench::benchPasskeySignIn`, both requests through the Symfony bridge's firewall and controllers, without a network |

## Measured

Each release's run of the `Benchmarks` job, on a GitHub-hosted runner with PostgreSQL in a container on the same machine. These are shared machines, so the numbers say "about", not "at most". The median is the centre, and p99 is over the iterations.

*The first release with this table is 0.4.0.*

## Measuring it yourself

```bash
docker compose up -d --wait
docker compose exec php composer bench
```

The benchmarks are in `tools/bench`:
- **Where they run:** on PostgreSQL (`YORVOID_TEST_POSTGRES_URL`), with OPcache on.
- **How many:** 200 iterations of each, after 5 warm-up runs.
- **What they cover:**
  - decisions, cached, uncached and 100 at once;
  - a session's check;
  - an entry appended to the security log;
  - a signed intent verified;
  - a passkey sign-in.
- **The cached decision** runs 20 decisions an iteration, and the verifier 5 verifications: single ones are too short, or too noisy, to compare. Their p99 is that of the iterations, not of single runs.

**In CI**, a pull request's head is measured against its base on one runner, subject by subject in turns: base, head, head, base. The runner's drift then falls on both alike.
- A subject whose median is more than 20 % above the base's fails the build.
- A subject whose benchmark code changed in the pull request, or that's new, is reported but not gated.
- The query budgets in the tests stay the exact guard: a decision's statements don't grow with the number of resources.
