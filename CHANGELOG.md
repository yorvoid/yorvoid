# Changelog

All of YorvoID's packages are released together, with the same version ([compatibility](docs/compatibility.md)). Each release lists what changed, and what to do where a change breaks covered code.

## Unreleased

### Breaking

- `SessionStore::stepUp()` takes the factor to add and returns the stored context with it, or null; a store of your own adds the factor itself, with a compare-and-swap on the token.
- `PrincipalStore::hasActive()` is new: a store of your own implements it.
- `AuthenticationFactor::NO_OPERATION` records a step-up for no operation: it counts only for requirements that name none, not for every operation.
- `yorvoid/symfony` requires DoctrineBundle and FrameworkBundle, which it always needed.
- The bundle refuses at boot a configuration it would refuse later: a wrong realm, table prefix, duration, limit, origin, cookie name, script or notification address.
- `NotificationSettings` and email actions accept `https://` addresses only (`http://` for `localhost`), without a user before the host.
- A setup code works only while the realm has no active principal.

### Security

- Passkeys check what the WebAuthn library leaves to them: the ceremony type, cross-origin answers, the public key, the counter with a compare-and-swap, and backup eligibility; ceremonies and flows started per address are limited.
- The intent verifier checks answers, nonces and parameters itself, and caps waiting intents.
- Signing in again ends the session the browser held; two step-ups at once both keep their proof.
- Failed setup codes and tokens, and limits reached, are recorded in the security log.
- The key file is replaced only by a complete, synced copy; Symfony's dumper shows secrets redacted.
- `yorvoid:doctor` warns on PostgreSQL while the application's database user can update, delete or truncate the security log.

### Added

- `SignInEntryPoint` for the firewall; `yorvoid:keys:rotate --public`; `StepResult::reopen()` and `StepFailed` for flow steps.
- The recovery-link page names the account; the step-up page says what it confirms, and takes `within`; the signing page names the passkeys that can sign.

### Fixed

- Flows end in a problem, not an error, when a proof lapses, an operation waits out a recovery's cool-down, or someone has no passkey to confirm with.
- A failing mailer is logged, and fails neither a sign-in nor the change it's about.

## 0.1.0 (2026-11-03)

The first release: passkeys, recovery codes and links, sessions, step-up, flows with their pages, signed intents with their verifier, security notifications, the security log, and the Symfony bridge.
