# Dependencies

Every dependency is code that runs with YorvoID's privileges, so YorvoID has few, and each one is a decision.

- **Few, and reviewed.** A new dependency needs a reason, a look at who maintains it and how actively, what it pulls in, and its security history.
- **Proven cryptography only.** OpenSSL through PHP (and libsodium, which the WebAuthn library verifies EdDSA passkeys with), and established libraries for protocols (for example `web-auth/webauthn-lib` for passkeys, from 1.0's first milestone). YorvoID implements no cryptographic primitive itself.
- **Known vulnerabilities fail the build.** CI runs `composer audit`; known-vulnerable versions are excluded by minimum versions (for example `web-auth/webauthn-lib` 5.3.1 or later, which fixed a user-verification downgrade).
- **Pinned tools.** Development and verification tools are locked (`composer.lock`), the images CI runs in are pinned by digest, and the formal-model checkers are downloaded at fixed versions verified by SHA-256.
- **Declared where used.** Each package requires everything it uses, PHP extensions included, and uses everything it requires: `composer dependencies` checks every package as if it were installed alone, in CI too. What a package uses only when it's there is a `suggest`, and the code checks for it first.

## The browser client

`@yorvoid/browser` has no runtime dependencies. Its tools (TypeScript, Node's types, Stryker) are locked in `package-lock.json`, and CI runs `npm audit` and checks the packages' signatures.

## Current runtime dependencies of `yorvoid/core`

| Package | Why |
|---|---|
| `doctrine/dbal` | The application's database connection, on every supported database |
| `psr/clock`, `psr/event-dispatcher` | Standard interfaces for time and events |
| `symfony/clock` | The default clock |
| `symfony/uid` | UUIDv7 identifiers |
| `ext-intl` | Comparing email addresses: one Unicode form (NFC), and domains in their ASCII form (IDNA) |
| `ext-mbstring` | Text in any script |
| `psr/cache`, `psr/log` | The shared pool for what decisions read; warnings for Indeterminate decisions and audit records that couldn't be written |
| `ext-openssl` | Ed25519 and ES256 signing keys and the security hashes, from a FIPS provider when one is configured ([cryptography](../concepts/cryptography.md)) |

## Current runtime dependencies of `yorvoid/authn`

| Package | Why |
|---|---|
| `yorvoid/core`, `yorvoid/throttle` | Principals, the security log, storage; limits on attempts |
| `doctrine/dbal`, `psr/clock`, `psr/event-dispatcher`, `symfony/clock`, `symfony/uid` | As for core |
| `psr/log` | Warning of a refused token for one of YorvoID's purposes |
| `ext-mbstring`, `ext-filter` | Device names within their column in any script; checking addresses |

## Current runtime dependencies of `yorvoid/notifications`

| Package | Why |
|---|---|
| `yorvoid/authn`, `yorvoid/core` | What the notifications are about; "This wasn't me" ends sessions with a one-time token |
| `yorvoid/mail` | Writing and sending the emails |
| `psr/clock`, `psr/event-dispatcher`, `symfony/clock`, `symfony/uid` | As for core |
| `psr/log` | Logging an email that couldn't be sent, rather than failing what it's about |
| `symfony/translation-contracts`, `ext-intl` | The notifications' words and dates in each person's language |

## Current runtime dependencies of `yorvoid/intent-signing`

| Package | Why |
|---|---|
| `yorvoid/authn`, `yorvoid/core`, `yorvoid/throttle` | Sessions, the security log, limits on asking |
| `yorvoid/intent-verifier` | The intents themselves and their refusals: the same classes on both sides |
| `psr/clock`, `psr/event-dispatcher`, `symfony/clock` | As for core |
| `symfony/translation-contracts`, `ext-intl`, `ext-mbstring` | Summaries in each language, within their length |
| `yorvoid/notifications`, `symfony/translation` (suggested) | Telling the person an operation they signed ran; refusing a summary that has no message |

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
| `web-auth/cose-lib`, `spomky-labs/cbor-php` | Which COSE algorithms are accepted, and the public keys' checks: declared, so YorvoID's constraint pins them, not only webauthn-lib's |
| `symfony/serializer`, `symfony/uid` | WebAuthn's options as JSON; ceremony IDs |
| `ext-openssl`, `ext-sodium`, `ext-filter` | Checking public keys; EdDSA passkeys (libsodium, through the WebAuthn library); integers in COSE keys |
| `yorvoid/notifications` (suggested) | The link that confirms the owner's email address in the setup flow, and the `yorvoid.verify_email` flow |
| webauthn-lib's own: `spomky-labs/pki-framework`, `paragonie/constant_time_encoding`, `brick/math` | Certificates and arithmetic for WebAuthn |

## Current runtime dependencies of `yorvoid/intent-verifier`

The verifier runs on its own server, so it doesn't use YorvoID's other packages. What the console relays from the browser, which the verifier doesn't trust, is read by hand into one fixed shape, every binary field strict base64url, before the WebAuthn library's byte-level checks see it: Symfony Serializer, which webauthn-lib requires, handles only the verifier's own options. Its trusted base is webauthn-lib and the packages below.

| Package | Why |
|---|---|
| `web-auth/webauthn-lib`, `web-auth/cose-lib`, `spomky-labs/cbor-php`, `symfony/serializer` | Checking signatures made with passkeys, as passkeys does |
| `ext-openssl`, `ext-sodium` | Checking signatures; EdDSA, through the WebAuthn library |
| `twig/twig` | The signing pages |
| `psr/clock`, `symfony/uid` | Time; IDs |
| `ext-dom`, `ext-libxml`, `ext-mbstring` | Checking that a page shows what is signed; text in any script |
| `ext-posix` (suggested) | Checking that its files belong to its own user |

## Current runtime dependencies of `yorvoid/throttle`, `yorvoid/mail` and `yorvoid/translation`

| Package | Why |
|---|---|
| `yorvoid/core` | Errors, durations, storage |
| `doctrine/dbal`, `psr/clock`, `symfony/clock`, `ext-filter` (throttle) | Its counters, and their time; addresses as keys |
| `twig/twig`, `ext-mbstring`, `ext-filter` (mail) | Rendering emails; checking addresses |
| `symfony/translation-contracts`, `ext-intl` (translation) | The translator interface it implements; ICU messages |

## Current runtime dependencies of `yorvoid/policy` and `yorvoid/relations`

| Package | Why |
|---|---|
| `yorvoid/core` | The evaluator, operations, the security log, storage |
| `yorvoid/relations` (policy) | Roles on resources and groups, as relations |
| `doctrine/dbal` | Their tables |
| `psr/clock`, `symfony/clock`, `ext-mbstring` (policy) | Role assignments' expiry; names within their columns |

## Current runtime dependencies of `yorvoid/symfony`

The bridge needs what a Symfony application with a database and a firewall has: FrameworkBundle, DoctrineBundle, SecurityBundle and their components, and the PHP extensions they use. Twig, Mailer, Translation and VarDumper are suggested: the bridge works without each, and says so where it needs one, e.g. notifications without a mailer stop the application from starting with what to do.
