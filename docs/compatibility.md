# Compatibility promise

YorvoID follows [semantic versioning](https://semver.org).

## What's covered

Everything marked `@api` in the code: classes, interfaces, methods, constants, attributes, events, configuration options, view models of templates, operation names, and the extension points.

- **Patch releases** (1.2.x) fix bugs and security issues. Nothing covered changes.
- **Minor releases** (1.x) add features. Nothing covered breaks. Something that will be removed is deprecated first: it keeps working, triggers a deprecation notice, and the release notes say what to use instead.
- **Major releases** (x.0) remove what was deprecated, and come with an upgrade guide.

## What isn't covered

- Code marked `@internal`, and anything not marked `@api`.
- The exact wording of messages, which can improve in any release. Translations change with it.
- Security fixes. When the only way to close a vulnerability is a breaking change, it ships in a patch release, and the advisory explains what to do.
- Database tables. Use YorvoID's services, not its tables: migrations change them as needed, always without downtime.

## Versions

All of YorvoID's packages, Composer and npm, are released together with the same version: `yorvoid/core` 0.2.0 goes with `yorvoid/passkeys` 0.2.0 and `@yorvoid/browser` 0.2.0. Require the same version of each. Every release is listed in the [changelog](https://github.com/yorvoid/yorvoid/blob/main/CHANGELOG.md).

## Supported platforms

- **PHP** 8.5 or later.
- **Symfony** 7.4 (LTS) and 8.1 or later, for the components YorvoID uses and for the Symfony bridge. CI runs every test on both.
- **Laravel** 12 and **Drupal** 11 install YorvoID's framework-free packages next to their own Symfony 7.4 components; CI checks that they resolve. There is no bridge for them yet: [integrate without one](how-to/without-a-bridge.md).
- **PostgreSQL** 16, 17 and 18; **SQLite** for development and tests ([storage](concepts/storage.md)).

Dropping a version of any of these is a breaking change.

## Before 1.0

Until 1.0, minor releases (0.x) may break covered code. Each such change is listed in the changelog with what to do. Security fixes come as patch releases of the latest 0.x ([security policy](https://github.com/yorvoid/yorvoid/blob/main/SECURITY.md)).

## Long-term support

After 1.0, YorvoID has a long-term support line aligned with Symfony's LTS releases, which receives security fixes for longer.
