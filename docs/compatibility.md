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

## Before 1.0

Until 1.0, minor releases (0.x) may break covered code. Each such change is listed in the release notes with what to do.

## Long-term support

After 1.0, YorvoID has a long-term support line aligned with Symfony's LTS releases, which receives security fixes for longer.
