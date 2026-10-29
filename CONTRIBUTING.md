# Contributing to YorvoID

Thanks for helping to improve YorvoID! This guide explains how to set up the project, what we expect from changes, and how to get them merged.

Everyone taking part follows our [code of conduct](CODE_OF_CONDUCT.md).

## Before you start

- **Security issues:** don't open a public issue. Follow the [security policy](SECURITY.md).
- **Bugs:** search the [existing issues](https://github.com/yorvoid/yorvoid/issues) first. If yours is new, open an issue with steps to reproduce, what you expected, and what happened instead.
- **New features and larger changes:** open an issue to discuss them first. YorvoID is security software: every feature needs a place in the threat model, tests at the right levels, and documentation of what it guarantees. Agreeing on the approach first saves you from writing code that can't be merged.
- **Small fixes** (typos, docs, obvious bugs) can go straight to a pull request.

## Setting up

You need Docker with Docker Compose. PHP, Composer and PostgreSQL all run in the containers.

```bash
git clone https://github.com/yorvoid/yorvoid.git
cd yorvoid
docker compose up -d --wait
docker compose exec php composer install
```

On Linux, start the containers with your own user and group IDs, so the files they create belong to you (`UID` is already set by the shell; it only needs exporting):

```bash
export UID GID=$(id -g)
docker compose up -d --wait
```

## Repository layout

This is a monorepo. Develop everything here, even code that's published as separate packages: each directory of `packages/` is copied to a read-only repository of its own, which Composer installs it from.

| Path | Contents |
|------|----------|
| `packages/core/` | `yorvoid/core` |
| `packages/authn/` | `yorvoid/authn` |
| `packages/passkeys/` | `yorvoid/passkeys` |
| `packages/flows/` | `yorvoid/flows` |
| `packages/intent-verifier/` | `yorvoid/intent-verifier` |
| `packages/throttle/` | `yorvoid/throttle` |
| `packages/mail/` | `yorvoid/mail` |
| `packages/translation/` | `yorvoid/translation` |
| `packages/symfony/` | `yorvoid/symfony` |
| `packages/testing/` | `yorvoid/testing` |
| `packages/virtual-authenticator/` | `yorvoid/virtual-authenticator` |
| `packages/browser/` | `@yorvoid/browser` (npm), with its tests |
| `packages/…` | The other packages, as they arrive |
| `tests/` | The tests of every package, by package |
| `e2e/` | The browser tests, and the app they run against |
| `docs/` | The documentation for integrators and researchers |

## Making changes

1. Create a branch from `main`, named after what it does, e.g. `fix-token-expiry-boundary`.
2. Make your change, with tests.
3. Run all checks:

   ```bash
   docker compose exec php composer qa
   ```

   For the browser client, `docker compose run --rm node npm run qa` (types, tests with full coverage, the build) and `npm run mutation`. `npm run assets` copies the build into the Symfony bridge, which ships it: commit it with your change.

   The browser tests run YorvoID's flows in Chromium, with a virtual authenticator, against the app in `e2e/app`:

   ```bash
   docker compose --profile e2e up -d --wait e2e-app
   docker compose run --rm e2e npm ci
   docker compose run --rm e2e npx playwright test
   ```

   The quickstart test follows [the Symfony quickstart](docs/tutorials/symfony-quickstart.md) as it's written, in a fresh application, then signs in to it in Chromium. Change the tutorial and its steps change with it:

   ```bash
   docker compose exec php php tools/quickstart/follow.php
   docker compose --profile quickstart up -d --wait quickstart-app
   docker compose run --rm quickstart npx playwright test --config playwright.quickstart.config.ts
   ```

   The documentation site arranges `docs/` with VitePress (`site/`). It refuses dead links; preview it at <http://localhost:5173/yorvoid/>:

   ```bash
   docker compose run --rm -w /app/site node npm ci
   docker compose run --rm -w /app/site node npm run build
   docker compose run --rm -p 5173:5173 -w /app/site node npm run dev
   ```

   `site/package.json` pins Vite to a release with the dev server's security fixes, which VitePress 1 doesn't depend on yet: drop the override when VitePress does. The reference pages are written from the code: `composer docs-reference` after changing the configuration or a command.

   CI runs the same checks, and a pull request can only be merged when they pass. `composer cs-fix` fixes the coding style for you. Changes to security-critical code also need `composer infection` (mutation testing) to stay above its thresholds.
4. Open a pull request against `main`.

### What a change needs

- **Tests** at the right levels: unit tests, and integration tests against a real database where storage is involved.
- **PHPStan at the maximum level**, with no new baseline entries.
- **Documentation** for anything an integrator can see, in `docs/`.
- **Security-relevant changes** also update the threat model and the standards traceability.
- **Public API** (`@api`) changes follow the compatibility promise: no breaking changes without a deprecation first.

### Code guidelines

- Classes are `final` unless they're designed to be extended; value objects are `readonly`.
- Interfaces only at real seams (storage, clock, delivery, sources), never "just in case".
- Secrets come from `random_bytes()` / `random_int()` and are compared with `hash_equals()`. PHPStan refuses predictable generators.
- Errors aimed at developers say what went wrong, how to fix it, and link to the docs (`IntegratorError`).
- No cryptography of our own: libsodium, OpenSSL and proven libraries only.

## Before a release

**Measure the quickstart with someone new to YorvoID** (PLT-080): a developer who knows Symfony and hasn't used YorvoID follows [the quickstart](docs/tutorials/symfony-quickstart.md) on their own machine, from the published packages, while you watch without helping.

1. Time from the first command to signing in again after signing out. The target is 15 minutes at most.
2. Note every place they hesitated, misread, or needed something the page didn't say, and fix the tutorial for each.
3. Record the result in the release's pull request: who (a role, not a name), the date, their platform, the time, and what changed in the tutorial because of it.

A release whose measured time is above 15 minutes doesn't go out until the quickstart is shorter or clearer. Before YorvoID is on Packagist, run `QUICKSTART_PACKAGIST=1 php tools/quickstart/follow.php` once it is, to check the published packages install as the page says.

## License

By contributing, you agree that your contributions are licensed under the [Apache License 2.0](LICENSE), like the rest of the repository (its section 5).
