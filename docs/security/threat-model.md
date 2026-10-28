# Threat model

What YorvoID protects, against whom, how, and what it leaves to others. It's kept current: every change that affects security updates it (the pull request template asks).

**Status** says what exists today: **in place** (implemented and tested), **partly**, or **planned** with the milestone that brings it. Until 1.0, most of YorvoID is planned; this page says so rather than describe a design as if it were protection.

## What's protected

| Asset | Why it matters |
|---|---|
| Credentials: passkeys, password hashes, TOTP seeds, recovery codes, API keys, one-time tokens | Taking over accounts |
| Sessions and remember-me tokens | Taking over accounts without credentials |
| Authority: grants, roles, relationships | Doing what one may not |
| Evidence: facts, approvals, verified signatures | Satisfying a requirement falsely |
| Keys: signing keys, the verifier's keys | Forging tokens, checkpoints, or a verifier's trust |
| The security log | Hiding what happened |
| Personal data | Privacy |
| Availability of sign-in and decisions | Locking people out, stopping work |

## Who attacks

1. **Anyone on the internet:** automated attempts, leaked password lists.
2. **Phishers**, relaying in real time.
3. **A signed-in user** trying to exceed their rights.
4. **A malicious or manipulated AI agent** with legitimate, delegated authority.
5. **Compromised code inside the application**, e.g. a vulnerable plugin.
6. **Someone who can read the database** (a leaked backup).
7. **Someone who can write the database.**
8. **A malicious administrator.**
9. **The network** between users, the server and identity providers.
10. **The supply chain:** a compromised dependency or release.

## Assumptions

- TLS everywhere; the application sets secure cookies and HSTS.
- Clocks are roughly right.
- **Every protected operation goes through YorvoID.** YorvoID can't protect what it isn't asked about.
- PHP, the operating system and the database are patched.
- Whoever has root on the server is the final authority.

## Threats

### Storage, keys and the log

| Threat | Mitigation | Status |
|---|---|---|
| Database read leak exposes secrets | Secrets stored hashed or encrypted; secrets in memory are `Secret`s that don't appear in dumps, logs or traces | Partly: `Secret` and hashed session tokens in place; storage of credentials arrives with them (M1) |
| The log can't forget personal data that must be erased | The log holds IDs and kinds, never email addresses, IP addresses or devices; those stay in tables that are erased or purged | In place |
| Database writer changes the log | Hash chain per realm; verification finds changed, removed, inserted, reordered or re-hashed entries and a cut-off end | In place |
| Database writer rewrites the whole chain | Signed checkpoints kept outside the database | Planned (M2) |
| Attacker-controlled text breaks the log (and so the operation, or the record of an attack) | Invalid UTF-8 replaced before hashing and storing; the log is structured, never formatted text | In place |
| Two processes write the log at once and fork it | Appends serialized per realm; the primary key refuses a second entry with the same number | In place, tested with concurrent processes |
| Signing key leaks | Key ring with rotation; keys outside the database; key files readable only by their owner | In place; hardware or cloud key stores planned (M7) |
| Predictable secrets | Only the operating system's CSPRNG, at least 128 bits; predictable generators refused by static analysis | In place |
| Timing of comparisons reveals secrets | Secrets compared only with `hash_equals()`, enforced by static analysis | In place |
| Weak or downgraded hash algorithms | SHA-256 and Ed25519; every hash and key names its algorithm; unknown algorithms refused | In place |

### Authentication and sessions (M1, M4)

| Threat | Mitigation | Status |
|---|---|---|
| Credential stuffing, password spraying | Throttling per account and address, per account and per address, atomic across servers; passkeys first; breached-password checks | Partly: throttling in place for the forms that check a secret; passwords and breached-password checks arrive in M4 |
| Locking a user out by failing on purpose | Limits slow attempts down (one per share of the period) rather than lock; the per-account limit is generous, so attempts from the owner's own address keep their own limit | In place |
| Phishing and real-time relay | Passkeys are bound to the site; policies can require phishing-resistant methods | Partly: passkeys in place, origins and relying party checked; a step-up can require a phishing-resistant proof; MFA policies per realm or role arrive in M4 |
| User verification downgraded by the browser | Required server-side, regardless of what the client asks; YorvoID checks the flag itself, besides the library | In place, with a regression test for GHSA-h4fw-6r7f-w494 |
| One-time token used twice | Checked and used in one atomic step | In place: model-checked, the model's executions replayed against the implementation, and tested with concurrent processes |
| A link sent to an old email address is used by whoever reads that inbox now | Tokens sent by email work only while the address is still the principal's | In place |
| A default account with a known password | None: a setup code claims a fresh realm, once | In place |
| A hijacked session adds the attacker's passkey | Adding a credential needs recent authentication and notifies the owner, whose "This wasn't me" disables it and signs everyone out | In place |
| A stolen session cookie or credential used from another device goes unnoticed | New devices recognised by a random cookie (not fingerprinting); a sign-in from one is emailed with "This wasn't me" | In place |
| A copied hardware key signs in | Signature counters recorded; a counter that doesn't go up is refused, or disables the passkey | In place |
| A passkey sign-in or registration replayed | Each ceremony's 256-bit challenge works once, kept server-side, and expires | In place |
| Recovery as the weakest way in | Recovery links only from the server's operator, short-lived; recovery codes of 120 random bits, stored hashed, each used once; every recovery ends every other session, is logged and emailed, and can start a cool-down that holds removing credentials | In place |
| Session fixation, theft, revoked sessions still accepted | New token on sign-in, on refresh and on step-up; 256-bit tokens stored only hashed; short, server-side sessions checked on every request | In place |
| A session left open, or taken over, runs a sensitive operation | Step-up: a recent proof of the required strength, which counts for that operation only | In place |
| Someone who recovered an account that isn't theirs locks the owner out | An optional cool-down after every recovery holds removing credentials and replacing recovery codes | In place |
| A suspension is undone, and sessions opened before it come back | A session is valid only while its principal keeps the status version it started with | In place |
| Two administrators each remove one of the last two owners at the same moment | Guard rules run after the change, while changes in the realm wait for each other | In place, tested with concurrent processes |

### Authorization and agents (M2, M3)

| Threat | Mitigation | Status |
|---|---|---|
| An unknown condition or missing data turns into a Permit | Three outcomes; Indeterminate is never Permit | Specified and model-checked; implementation in M2 |
| A child agent gets more authority than its parent | Attenuation checked at issuance and at every use; the delegator's own rights re-checked at every use | Planned (M3) |
| An agent forges evidence ("the review was approved") | Evidence only from registered sources; never from the agent itself | Planned (M3) |
| Revocation races with the effect | The final check runs in the effect's own transaction | Planned (M3) |
| Consent phishing: a user tricked into broad delegation | Consent screens computed from the grant, showing the worst case; limits on what may be delegated | Planned (M3) |

### Signed operations (M1)

| Threat | Mitigation | Status |
|---|---|---|
| A compromised web application runs a powerful operation | A separate verifier checks a passkey signature over the exact operation, with keys it holds itself; it derives the challenge from the intent it prepared and kept, so the console can't change the operation after preparing it | In place |
| A compromised web application adds its own key to the verifier | Keys are added only by an intent signed by an existing key; the first one with a bootstrap secret from the host's root, signed by the key itself | In place |
| A signature replayed, or used at another verifier | Each intent has a nonce the verifier issued, works once (also when refused, and across restarts) and expires; its audience names the verifier | In place |
| One signature meaning two operations | Canonical JSON (RFC 8785) of the whole intent, hashed with a domain separator; values without a single encoding (floats, huge integers, non-UTF-8 text) refused | In place, against RFC 8785's examples and an independent implementation |
| Every verifier key lost, or a re-bootstrap abused | A new bootstrap secret only from the host's root, recorded with how many keys there were | In place; telling owners is the host's procedure, documented |
| The verifier's log changed to hide what ran | Hash-chained log, checked with `isIntact()` | In place |
| **Bait and switch:** the page shows one operation and asks to sign another | Not fully preventable today: browsers show only the site, not the operation. Narrowed by a fixed list of operations with checked parameters, notifications after each, and delays before destructive ones | Partly: the operation list, full summaries (refusing what can't be shown), limits on requests and a notification after each signed operation are in place; delays belong to each operation; limitation stated |

### Supply chain

| Threat | Mitigation | Status |
|---|---|---|
| A known-vulnerable dependency | `composer audit` in CI; minimum versions | In place |
| A compromised release | Signed releases, SBOMs | Planned (first release) |
| A compromised dependency | Few dependencies, reviewed, pinned in the lock file; formal-model tools pinned by checksum | Partly |

## Not covered

- Compromised code inside the application can call YorvoID with any input; YorvoID records what it's asked to do.
- Someone who can write the database can change state; YorvoID detects changes to its log, it doesn't prevent them.
- Prompt injection itself. YorvoID limits what a manipulated agent can do, not what it wants to do.
- Effects outside the application's database can't be made atomic with revocation.
- Vulnerabilities in PHP, the operating system, the database or the kernel.
