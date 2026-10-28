# Dependencies

Every dependency is code that runs with YorvoID's privileges, so YorvoID has few, and each one is a decision.

- **Few, and reviewed.** A new dependency needs a reason, a look at who maintains it and how actively, what it pulls in, and its security history.
- **Proven cryptography only.** libsodium and OpenSSL through PHP, and established libraries for protocols (for example `web-auth/webauthn-lib` for passkeys, from 1.0's first milestone). YorvoID implements no cryptographic primitive itself.
- **Known vulnerabilities fail the build.** CI runs `composer audit`; known-vulnerable versions are excluded by minimum versions (for example `web-auth/webauthn-lib` 5.3.1 or later, which fixed a user-verification downgrade).
- **Pinned tools.** Development and verification tools are locked (`composer.lock`), and the formal-model checkers are downloaded at fixed versions verified by SHA-256.

## The browser client

`@yorvoid/browser` has no runtime dependencies. Its tools (TypeScript, Node's types, Stryker) are locked in `package-lock.json`, and CI runs `npm audit` and checks the packages' signatures.

## Current runtime dependencies of `yorvoid/core`

| Package | Why |
|---|---|
| `doctrine/dbal` | The application's database connection, on every supported database |
| `psr/clock`, `psr/event-dispatcher` | Standard interfaces for time and events |
| `symfony/clock` | The default clock |
| `symfony/uid` | UUIDv7 identifiers |
| `ext-mbstring` | Comparing email addresses in lowercase, in any script |

## Current runtime dependencies of `yorvoid/authn`

| Package | Why |
|---|---|
| `yorvoid/core` | Principals, the security log, storage |
| `doctrine/dbal`, `psr/clock`, `psr/event-dispatcher`, `symfony/clock` | As for core |
| `ext-mbstring` | Keeping device names within their column, in any script |

## Current runtime dependencies of `yorvoid/flows`

| Package | Why |
|---|---|
| `yorvoid/authn` | Steps run in sessions, and sign people in |
| `yorvoid/core`, `yorvoid/throttle` | Realms and errors; too many attempts |
| `psr/clock`, `psr/event-dispatcher`, `symfony/clock` | As for core |

## Current runtime dependencies of `yorvoid/passkeys`

| Package | Why |
|---|---|
| `yorvoid/authn` | Credentials and sessions |
| `yorvoid/flows` | The passkey-first journeys' steps |
| `web-auth/webauthn-lib` 5.3.9 or later | WebAuthn's checks: the established PHP implementation, MIT-licensed, maintained since 2018. Below 5.3.1, user verification could be downgraded (GHSA-h4fw-6r7f-w494) |
| Its own: `web-auth/cose-lib`, `spomky-labs/cbor-php`, `spomky-labs/pki-framework`, `symfony/serializer` and its components, `paragonie/constant_time_encoding`, `brick/math` | Keys, CBOR, certificates and JSON for WebAuthn; installed only with this package |
