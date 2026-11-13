# Compatibility promise

YorvoID follows [semantic versioning](https://semver.org).

## What's covered

Everything marked `@api` in the code: classes, interfaces, methods, constants, attributes, events, configuration options, view models of templates, operation names, and the extension points.

- **Patch releases** (1.2.x) fix bugs and security issues. Nothing covered changes.
- **Minor releases** (1.x) add features. Nothing covered breaks. Something that will be removed is deprecated first: it keeps working, triggers a deprecation notice, and the release notes say what to use instead.
- **Major releases** (x.0) remove what was deprecated, and come with an upgrade guide.

CI compares every package with its last release, and fails on a break of covered code that the changelog doesn't list under "Breaking". Before 1.0, minor releases (0.x) may break covered code; each break is listed there, with what to do.

## What isn't covered

- Code marked `@internal`, and anything not marked `@api`. That includes `@internal` members of an `@api` class, such as a constructor only the container calls. A check in CI makes sure nothing covered names an `@internal` class.
- Code marked `@experimental`, also when it's `@api`: it may change in a minor release, after 1.0 too, until applications have used it and the mark is removed. Its changes are listed in the changelog. Today: `RelationPermit`, `RelationChanges`, `Preloads`, and explanations (`Ground`, `GroundKind`, `RoleGrant`, `Precedence`, `Explanation`, `WhyNot`, and the grounds on `Truth`, `RuleAnswer` and `Decision::$precedence`); view-as (`ViewingAs`, `BeginViewAs`, `ViewAsStart`); elapsed time (`Elapsed`, `Instant`, `BootTime`, `WithinOfflineLimit`); ordered conditions (`OrderedFact`, `OrderedCondition`, `OrderedConditions`); and `yorvoid/policy-testing`, mutation testing included; relations themselves, groups and roles within resources are covered.
- The exact wording of messages, which can improve in any release. Translations change with it.
- New cases of an enum that lists reasons, e.g. `IndeterminateReason`: a minor release can add one. A `match` of yours on them needs a default arm. YorvoID's own code matches them, and a decision's `Verdict`, exhaustively.
- Security fixes. When the only way to close a vulnerability is a breaking change, it ships in a patch release, and the advisory explains what to do.
- Database tables. Use YorvoID's services, not its tables: migrations change them as needed, always without downtime.

## Versions

All of YorvoID's packages, Composer and npm, are released together with the same version: `yorvoid/core` 0.4.0 goes with `yorvoid/passkeys` 0.4.0 and `@yorvoid/browser` 0.4.0. Require the same version of each: each package requires the others at its own minor, e.g. `^0.4` for 0.4.0, so Composer installs them as one set. Every release is listed in the [changelog](https://github.com/yorvoid/yorvoid/blob/main/CHANGELOG.md).

## Supported platforms

- **PHP** 8.4 or later. CI runs every test on 8.5, and on 8.4 with Symfony 7.4.
- **Symfony** 7.4 (LTS) and 8.1 or later, for the components YorvoID uses and for the Symfony bridge. CI runs every test on both.
- **Laravel** 12 and **Drupal** 11 install YorvoID's framework-free packages next to their own Symfony 7.4 components; CI checks that they resolve. There is no bridge for them yet: [integrate without one](how-to/without-a-bridge.md).
- **PostgreSQL** 16, 17 and 18; **SQLite** 3.35 or later for development and tests ([storage](concepts/storage.md)): YorvoID uses `RETURNING`. RHEL 9 and its rebuilds ship 3.34.

Dropping a version of any of these is a breaking change.

## Before 1.0

Until 1.0, minor releases (0.x) may break covered code. Each such change is listed in the changelog with what to do. Security fixes come as patch releases of the latest 0.x ([security policy](https://github.com/yorvoid/yorvoid/blob/main/SECURITY.md)).

## Long-term support

After 1.0, YorvoID has a long-term support line aligned with Symfony's LTS releases, which receives security fixes for longer.
