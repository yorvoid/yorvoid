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

So each package directory carries what its repository needs on its own: a README, the LICENSE and NOTICE, a `.gitattributes` that keeps `.github/` out of the archives Composer installs, a workflow that closes pull requests opened there, and `support` links in its `composer.json` back to this repository. `tests/Repository` checks them all; a new package needs them too, and an entry in `.github/split.json`.

| Path | Contents |
|------|----------|
| `packages/core/` | `yorvoid/core` |
| `packages/authn/` | `yorvoid/authn` |
| `packages/passkeys/` | `yorvoid/passkeys` |
| `packages/policy/` | `yorvoid/policy` |
| `packages/policy-testing/` | `yorvoid/policy-testing` |
| `packages/relations/` | `yorvoid/relations` |
| `packages/notifications/` | `yorvoid/notifications` |
| `packages/intent-signing/` | `yorvoid/intent-signing` |
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

1. Create a branch from `main`, named `<kind>/<slug>` after what it does, e.g. `fix/token-expiry-boundary` ([names](#branches-pull-requests-and-releases)).
2. Make your change, with tests.
3. Run all checks:

   ```bash
   docker compose exec php composer qa
   ```

   The tests run in parallel, one process per CPU (ParaTest). `composer test -- --filter …` takes PHPUnit's options. `composer test-serial` runs them in one process, which is easier to debug. A test that fails only in parallel or in a random order depends on another test: rerun with `composer test -- --order-by=random --random-order-seed=<seed>`, with the seed CI's nightly run printed.

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

   The store kit runs as another project would run it: `tools/store-kit/project` has stores of its own, on PDO, and tests them with `yorvoid/testing`'s contracts, installed with Composer. A contract that needs more than its package declares fails there:

   ```bash
   docker compose exec php php tools/store-kit/check.php
   ```

   The documentation site arranges `docs/` with VitePress (`site/`). It refuses dead links; preview it at <http://localhost:5173/yorvoid/>:

   ```bash
   docker compose run --rm -w /app/site node npm ci
   docker compose run --rm -w /app/site node npm run build
   docker compose run --rm -p 127.0.0.1:5173:5173 -w /app/site node npm run dev
   ```

   `site/package.json` pins Vite to a release with the dev server's security fixes, which VitePress 1 doesn't depend on yet: drop the override when VitePress does. The reference pages are written from the code: `composer docs-reference` after changing the configuration or a command.

   CI runs the same checks, and a pull request can only be merged when they pass: its **CI** check sums them up. `composer cs-fix` fixes the coding style for you. Mutation testing has to stay above its thresholds too. On a pull request, CI mutates only the lines it changes, as `composer infection -- --git-diff-lines --git-diff-base=origin/main` does locally. The full run (`composer infection`), and fuzzing ten times as long, run on `main` and every night; the browser client's mutation testing runs when the client changes.
4. Open a pull request against `main`, titled and described as [below](#pull-requests).

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

## Branches, pull requests and releases

The same conventions hold across YorvoLabs' repositories.

### Pull requests

Pull requests are squashed: the title becomes the commit's subject on `main`, followed by `(#N)`, and the description its body.

- **The title** is one imperative sentence of at most 72 characters, without a final period, e.g. "Refuse a release whose tag isn't signed". References go at the end, in parentheses, and only to what anyone can open: an issue or pull request, e.g. "Write refusals outside the caller's transaction (#123)". Titles and descriptions say what the code changes and why, for a reader outside the project: no internal tracking codes or decision numbers, and no account of who reviewed or approved it. No `feat:`-style prefixes: only Dependabot's `chore:` stays, so dependency updates can be filtered.
- **The description** follows the template: **Why**, **What changes** (as behavior, not files), **Tested** (what ran, and what didn't, with why), **Refs** (issues, pull requests, advisories, RFCs or upstream docs, or none).
- A release's pull request is titled `Release X.Y.Z`, and the one that moves `main` on to the next minor `Start X.Y`.
- **Commits on a branch** are free-form, since they're squashed away. `Co-authored-by` is the only trailer, for a human co-author.

### Branches

- `<kind>/<slug>`, where the kind is `feat`, `fix`, `docs`, `chore`, `ci` or `security`, and the slug is lowercase with hyphens. It may carry a work item: `feat/m3-1-delegation`, `fix/arc-0061-host-patching`.
- A release is prepared on `chore/release-X.Y.Z`. `release/` is kept for maintenance branches (below).
- **A fix of an embargoed vulnerability** is developed in the repository's private security advisory fork, never on a public branch. Until the advisory is published, its title, description and branch describe the change neutrally: no advisory ID, and no description of the exploit. The changelog's Security entry and the advisory ID are added at the release.

### Tags and versions

- **A release tag** is `vX.Y.Z` or `vX.Y.Z-rc.N`, and nothing else: no other pre-release labels. It's annotated, signed with SSH, with the message `YorvoID X.Y.Z`, on a commit of `main` that passed CI. The release workflows refuse any other tag, and one that isn't signed by a key in [`.github/release-signers`](.github/release-signers). Changes to that file and to the workflows need the maintainer's review ([`CODEOWNERS`](.github/CODEOWNERS)).
- **Other tags** live under a namespace and never start with `v`, e.g. `fips-run/0.4.0`.
- **Before 1.0,** a minor may break covered code, and a patch only fixes. The changelog's Breaking section says what to change.
- **Releases come from `main`.** Only when an older minor needs a fix after `main` has moved on is a `release/X.Y` branch created, from `vX.Y.0`'s line: the fix lands on `main` first, then is cherry-picked (`git cherry-pick -x`), and the patch is tagged there.

### The changelog

[`CHANGELOG.md`](CHANGELOG.md) follows [Keep a Changelog 1.1](https://keepachangelog.com/en/1.1.0/): `## [Unreleased]`, then `## [X.Y.Z] - YYYY-MM-DD` for each release, newest first. The GitHub release is titled `YorvoID X.Y.Z`, with the version's section as its notes, and is marked a pre-release for an `-rc.N`.

## Before a release

**Measure the quickstart with someone new to YorvoID** (PLT-080): a developer who knows Symfony and hasn't used YorvoID follows [the quickstart](docs/tutorials/symfony-quickstart.md) on their own machine, from the published packages, while you watch without helping.

1. Time from the first command to signing in again after signing out. The target is 15 minutes at most.
2. Note every place they hesitated, misread, or needed something the page didn't say, and fix the tutorial for each.
3. Record the result in the release's pull request: who (a role, not a name), the date, their platform, the time, and what changed in the tutorial because of it.

**Read the benchmarks** (M2-8): the `Benchmarks` job of the commit you'll tag has the table, its p99s against OPS-042's targets, and the change since the last release.
- Copy the table into [`docs/reference/performance.md`](docs/reference/performance.md), with the runner's CPU and PostgreSQL version.
- A subject over its target needs a line in the release notes: "accepted, because …" or "fixed in …".
- So does one whose median is more than 20 % slower than at the last release, or whose p99 grew as much. Each pull request was within 20 % on its lower quartile, but several can add up, and the tail isn't what a pull request's gate sees.

A release whose measured time is above 15 minutes doesn't go out until the quickstart is shorter or clearer. Once the release is on Packagist, run `QUICKSTART_PACKAGIST=1 php tools/quickstart/follow.php`, to check the published packages install as the page says.

**Releasing:** main always says which minor it's heading for: each package's branch alias (`0.4.x-dev`) and what the packages require of each other (`^0.4`), which `composer versions` checks. In a pull request titled `Release X.Y.Z`, move the [changelog](CHANGELOG.md)'s `[Unreleased]` under the new version: `## [0.2.0] - 2026-11-05`. Then push a signed version tag (`git tag -s v0.2.0 -m "YorvoID 0.2.0"`) on a commit of main that passed CI: the release workflows refuse a tag that isn't signed by a key in [`.github/release-signers`](.github/release-signers). Tags are signed with SSH keys (`git config gpg.format ssh`, and `git config user.signingkey` with the public key's path); add a key to that file in a pull request of its own. The Release workflow publishes a GitHub release with each package's source, SBOM and build provenance, and the changelog's section for the version; the Split workflow copies the PHP packages to their repositories, which Packagist installs from; the npm workflow publishes the browser client as `@yorvoid/browser` with the tag's version, through npm's trusted publishing (no token), and a pre-release such as `v0.1.0-rc.1` under `next`. Each of them refuses a tag of another minor than the packages require, or one with a package Packagist doesn't have (`php tools/release/version.php check v0.4.0`: run it before you tag, and submit a new package's repository to Packagist first). Once Split has pushed the tag, it installs every package at the tag's version from Packagist into an empty project, trying again for up to 30 minutes while Packagist catches up; to check a release again, run the Split workflow by hand with the tag (it only installs then). Right after tagging a minor, open a pull request that moves main on to the next one, titled `Start 0.3`: `php tools/release/version.php set 0.5`, then `composer update 'yorvoid/*'`.

## License

By contributing, you agree that your contributions are licensed under the [Apache License 2.0](LICENSE), like the rest of the repository (its section 5).
