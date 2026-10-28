# YorvoID

**Identity and access management for PHP, built for systems in which people, services and AI agents all act.** By YorvoLabs.

YorvoID is a library your application embeds, not a server you run beside it. It covers who someone is, how they proved it, what they may do, under whose authority, and why. Humans, services, workloads and autonomous agents are all principals, and they all go through the same authorization. Authority can be delegated along chains of agents, and it can only shrink as it's passed on. Decisions can depend on verified facts, such as a human approval, never on what an agent claims. Every decision can explain itself.

> **Status:** early development, nothing to install yet. The first milestone brings passkeys, recovery codes, sessions, step-up and passkey-signed operations.

## What it will do

- **Authentication:** passkeys first, passwords, sign-in links, TOTP, recovery codes, MFA policies, step-up, sessions and devices.
- **Authorization:** abilities with defaults, roles (also per resource), relationships, attributes, rules that can veto, "why not?" with what would fix it, view-as.
- **Agent authority:** agent identities, delegation chains with attenuation, budgets and expiry, evidence from trusted sources, human approval, revocation that reaches the effect.
- **Signed operations:** a passkey signs the exact operation, and a separate process verifies it.
- **Federation and protocols** (after 1.0): OpenID Connect, SAML and SCIM; optionally an OAuth 2.1 / OpenID provider for MCP clients and agents.
- **Assurance:** a tamper-evident security log, formal models of the authorization semantics, conformance tests, an independent audit before 1.0.

It works in any PHP 8.5 application, with or without a framework, and integrates with Symfony first. It plugs into your security setup rather than replacing it.

## Packages

This repository is a monorepo. Each directory of `packages/` is published as its own Composer package, and the browser client as an npm package.

| Package | Contents |
|---|---|
| [`yorvoid/core`](packages/core) | Principals, realms, decisions and evidence, the security log, keys, storage |
| [`yorvoid/authn`](packages/authn) | Authentication: sessions, step-up, credentials, one-time tokens, setup codes, invitations, recovery links and codes |
| [`yorvoid/passkeys`](packages/passkeys) | Passkeys: registration, sign-in, user-verification policy, and the passkey-first journeys |
| [`yorvoid/flows`](packages/flows) | Flows: journeys as server-side steps that applications insert into, replace or remove |
| [`yorvoid/intent-verifier`](packages/intent-verifier) | Signed intents, checked where the operation runs: stands alone, with its own keys |
| [`yorvoid/throttle`](packages/throttle) | Throttling: at most so many attempts per period, per key, shared through the database |
| [`yorvoid/mail`](packages/mail) | Emails from structured content: plain text and accessible HTML, delivered through an interface |
| [`yorvoid/symfony`](packages/symfony) | YorvoID in Symfony: configuration, services, authenticators for any firewall, console commands |
| [`yorvoid/translation`](packages/translation) | ICU message translation from the catalogues packages ship (English and German) |
| [`@yorvoid/browser`](packages/browser) (npm) | The browser client: passkeys with autofill, step-up, signing intents, the signal API, errors to translate |

More packages arrive with each milestone: authorization, federation, and testing tools.

## Development

You need Docker with Docker Compose. PHP, Composer and PostgreSQL all run in the containers.

```bash
docker compose up -d --wait
docker compose exec php composer install
docker compose exec php composer qa
```

The browser client has its own toolchain, also in a container:

```bash
docker compose run --rm node npm ci
docker compose run --rm node npm run qa
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the details, and [SECURITY.md](SECURITY.md) to report a vulnerability.

## License

[Apache License 2.0](LICENSE).
