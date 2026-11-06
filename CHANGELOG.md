# Changelog

All of YorvoID's packages are released together, with the same version ([compatibility](docs/compatibility.md)). Each release lists what changed, and what to do where a change breaks covered code.

## Unreleased

### Breaking

- `TemplateLint`'s constructor is `@internal`: take the lint from the container, as the docs show.
- `SecurityLogStore::lock()` is new: a store of your own locks the realm's log as an append does, without appending.

### Added

- `SecurityLog::lock()` locks a realm's log until the transaction ends, so that changes depending on what the realm holds take turns.

### Fixed

- A setup code claims a realm only if no principal became active before it, also one created at the same moment another way: the claim locks the realm's log before it checks.

## 0.2.0 (2026-11-05)

Stricter passkey and intent checks, signed checkpoints of the security log, Symfony 7.4 LTS next to 8.1, and two new packages: `yorvoid/notifications` and `yorvoid/intent-signing`.

### Breaking

- `SessionStore::stepUp()` takes the factor to add and returns the stored context with it, or null; a store of your own adds the factor itself, with a compare-and-swap on the token.
- `PrincipalStore::hasActive()`, `KnownDeviceStore::isKnown()` and the intent verifier's `VerifierLog::usedNonce()` are new: a store or log of your own implements them.
- `PasskeyStore::update()` returns whether it recorded the sign-in: a store of your own records it only over a lower counter, or a counter of 0, in one step.
- `SessionEndReason::Replaced` and `Refusal::TooManyWaiting` are new cases: a `match` over either handles them.
- `AuthenticationFactor::NO_OPERATION` records a step-up for no operation: it counts only for requirements that name none, not for every operation.
- `yorvoid/symfony` requires DoctrineBundle and FrameworkBundle, which it always needed.
- The bundle refuses at boot a configuration it would refuse later: a wrong realm, table prefix, duration, limit, origin, cookie name, script or notification address.
- `NotificationSettings` and email actions accept `https://` addresses only (`http://` for `localhost`), without a user before the host.
- A setup code works only while the realm has no active principal.
- Security notifications (`SecurityNotifications`, with its `Translations`), "This wasn't me" (`NotMe`, `NotMeReported`) and `EmailVerification` moved from `yorvoid/authn` to the new `yorvoid/notifications` (namespace `YorvoID\Notifications`); signed intents' `IntentSigning`, `IntentSummaries`, `IntentSummary`, `SignedOperationNotification` and `SignedOperationRan` from `yorvoid/passkeys` to the new `yorvoid/intent-signing` (`YorvoID\IntentSigning`). `yorvoid/authn` no longer requires `yorvoid/mail`, nor `yorvoid/passkeys` the intent verifier. Require the new packages if you use what moved; `yorvoid/symfony` requires both.
- `CeremonyStore::take()` takes the session asking: a store of your own returns a ceremony only to the session that began it, and one begun outside a session only without one.
- `ThrottleStore::advance()` replaces `replace()`: a store of your own counts an attempt, or refuses it, in one atomic step, so attempts at the same moment don't refuse each other.
- Email addresses are compared in one Unicode form (NFC), with only A–Z in lowercase and the domain in its ASCII form (IDNA); an address whose domain has no valid ASCII form is refused; `yorvoid/core` requires `ext-intl`. Addresses beyond ASCII stored by 0.1 are found again after `yorvoid:identifiers:normalize` (or `IdentifierRenormalization::run()`): run it once after upgrading, with `--dry-run` first.

### Security

- Passkeys check what the WebAuthn library leaves to them: the ceremony type, cross-origin answers, the public key, the counter with a compare-and-swap, and backup eligibility; ceremonies and flows started per address are limited.
- The intent verifier checks answers, nonces and parameters itself, and caps waiting intents.
- The intent verifier reads the browser's answers by hand into one fixed shape: Symfony Serializer's reflection-driven denormalizers no longer see what the console relays.
- Signing in again ends the session the browser held; two step-ups at once both keep their proof.
- Failed setup codes and tokens, limits reached, and passkey sign-ins and step-ups refused before the passkey is known (an expired ceremony, an unknown passkey), are recorded in the security log.
- The key file is replaced only by a complete, synced copy; Symfony's dumper shows secrets redacted.
- `yorvoid:doctor` warns on PostgreSQL while the application's database user can update, delete or truncate the security log.
- Signed checkpoints of the security log, kept outside the database (`Checkpoints`, `FileCheckpoints`, `LoggerCheckpoints`; in Symfony `yorvoid.log.checkpoint_file`, `yorvoid:log:checkpoint` and `yorvoid:log:verify`): verification finds entries cut off the end, and a history rewritten with its chain recomputed, up to the last checkpoint.
- Another session, or a sign-in, can't use up a session's passkey ceremony; links from other sites can't push the flow in progress out of the browser's session.

### Added

- `SignInEntryPoint` for the firewall; `yorvoid:keys:rotate --public`; `StepResult::reopen()` and `StepFailed` for flow steps.
- The recovery-link page names the account; the step-up page says what it confirms, and takes `within`; the signing page names the passkeys that can sign.
- Each release is a GitHub release with every package's source, its CycloneDX SBOM, and build provenance; only a signed tag is released.
- Symfony 7.4 LTS is supported next to 8.1, and PHP 8.4 next to 8.5, so YorvoID installs in Laravel 12 and Drupal 11 applications.
- `Sessions::check()` gives a valid session with its principal; the Symfony bridge loads the principal once a request instead of twice.
- `FlowSource` in the Symfony bridge adds an application's own flows, and the ones a request may start.

### Fixed

- Flows end in a problem, not an error, when a proof lapses, an operation waits out a recovery's cool-down, or someone has no passkey to confirm with.
- A failing mailer is logged, and fails neither a sign-in nor the change it's about.

## 0.1.0 (2026-11-03)

The first release: passkeys, recovery codes and links, sessions, step-up, flows with their pages, signed intents with their verifier, security notifications, the security log, and the Symfony bridge.
