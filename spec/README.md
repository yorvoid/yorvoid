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
| [`decisions/precedence.als`](decisions/precedence.als) | The outcome of a decision (Permit, Deny, Indeterminate) from the matching policy elements and the authority (requirements 06 §3) | Alloy: exhaustively, for up to 6 matching elements |
| [`tokens/one_time_token.qnt`](tokens/one_time_token.qnt) | One-time tokens: at most once, never after expiry or revocation, newest only (ATN-030), with 3 consumers racing for 3 tokens | Apalache: every execution up to 10 steps, beyond the longest (9); simulation: 20 000 random runs |

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

Each model also contains a **deliberately broken variant**, the naive "read, then delete" consumption, and the script requires the checker to find its double use. A checker that stopped finding problems would therefore fail the build too.

## Bounds

Model checking is exhaustive within its bounds, not beyond them. The bounds are chosen so that every interesting interleaving fits: 3 consumers racing for 3 tokens, with a clock that lets tokens expire. A Quint model's step bound must be at least its longest execution (its diameter), measured when the model is written; the token model's is 9, and it's checked up to 10. A property that needs larger bounds to fail is possible in principle; the PHP tests (concurrency, mutation testing) are the second line.

### The implementation, against the model

`tokens/traces.json` holds 100 executions of the atomic token model (`node tokens/traces.mjs > tokens/traces.json`, with a fixed seed). The PHP tests replay them against YorvoID's one-time tokens, on every database: each action the model takes, YorvoID takes, and after every step exactly the tokens the model says work, work (`tests/Authn/Token/OneTimeTokenTracesTest.php`). `check.sh` fails if the file no longer matches the model, so changing the model means regenerating it.

