# Changelog

All of YorvoID's packages are released together, with the same version ([compatibility](docs/compatibility.md)). Each release lists what changed, and what to do where a change breaks covered code.

## Unreleased

Every operation runs through one decision point: `Operations::run()` asks the evaluator, and gives its handler a permit.

### Breaking

- Services take a `Permit` where they took an actor or a session (`Principals`, `Sessions`, `Credentials`, `Invitations`, `SetupCodes`, `RecoveryCodes`, `RecoveryLinks`, `Passkeys`, `NotMe`): run operations with `$operations->run($command, $actor, $origin)`, where the actor is the signed-in `Session` or, on the host, `Operator`. A handler called directly, without a permit from `Operations`, doesn't run.
- `#[Operation]` takes what authorizes the operation (`AuthorizedBy`), and its command implements `Command`; one people run on their own account implements `SelfOperation`. `OperationDescription` says what authorizes the operation and what it causes.
- Signing in, claiming a setup code, accepting an invitation, using a recovery code or link, confirming an email address and "This wasn't me" are commands run through `Operations` (`SignInWithPasskey`, `ClaimSetup`, `AcceptInvitation`, `RecoverWithCode`, `RedeemRecoveryCode`, `UseRecoveryLink`, `VerifyEmail`, `ReportNotMe`), as is registering a passkey (`RegisterPasskey`). Claiming and accepting sign the person in and return their session.
- `StartSession`, `AddCredential` and `VerifyIdentifier` run only as a consequence of another operation, e.g. a sign-in.
- `OwnIdentifiers` is gone: someone changes their own email address or username with `SetIdentifier` through `Operations`, and `StepUpSelfService` asks for the recent proof it needed.
- Principals' events (`PrincipalCreating`, `PrincipalCreated`, `PrincipalStatusChanging`, `PrincipalStatusChanged`, `IdentifierChanging`, `IdentifierChanged`) carry the operation's permit; `EmailVerification::send()` takes the permit of the operation that set the address, and `EmailVerification::principalCreated()` sends the link for a principal created active.
- `SecurityNotifications` takes `Operations` instead of `OneTimeTokens`; `PasskeyFlows`, its steps (`PasskeySignInStep`, `PasskeyRegistrationStep`, `SetupCodeStep`, `InvitationStep`, `RecoveryCodeStep`, `RecoveryCodesStep`, `RecoveryLinkStep`, `EmailVerificationStep`), `CurrentSession`, the authenticators (`PasskeyAuthenticator`, `RecoveryCodeAuthenticator`, `RecoveryLinkAuthenticator`) and `Scenario` take `Operations`.
- `Checkpoints` takes `KeyRings`, each realm's signing keys, instead of one `KeyRing`: pass `new SharedKeyRings($ring)` for what it did before.

### Added

- `Operations`, `Permit`, `NotPermitted`, and the actors `Operator`, `Anonymous` and `ActingPrincipal`. In Symfony, abilities are decided by the application's security (`isGranted()`), and the application's own `OperationHandler`s are registered.
- The evaluator (`Evaluator`): Permit, Deny or Indeterminate by the precedence of forbids, the actor's authority and permits ([authorization](docs/concepts/authorization.md)). YorvoID's ways to authorize are its built-in permits, with `AbilityPolicy` and `SelfServicePolicy`; your own rules are `PermitElement`s and `ForbidElement`s, registered by themselves in Symfony. `PrincipalAuthority` refuses a principal that isn't active.
- A decision carries its reasons, what couldn't be established (`IndeterminateReason`), and on a Deny for want of evidence its remedies (`StepUpRemedy`, `WaitRemedy`): a step-up asked by `StepUpSelfService` comes as a remedy on `NotPermitted`, not as `StepUpRequired`.
- An Indeterminate decision is logged as a warning.
- A refused operation is recorded in the security log.
- An operation declares what it causes (`#[Operation(causes: [...])]`), and its permit gives consequences for those only.
- The host's operator acts from a console or job origin only when PHP runs from the command line.
- Signing keys for each realm (`KeyRings`, `SharedKeyRings`, `PerRealmKeyRings`): a realm's checkpoints are signed and verified with its own ring. In Symfony, `keys.per_realm` and `keys.directory`, `yorvoid:keys:rotate --realm`, and the doctor reports a realm without keys.
- The stores' contracts in `yorvoid/testing` (`YorvoID\Testing\Contract`): the tests YorvoID's own stores pass, one trait for each store and one for `Transactions`, for a store of your own to pass too. `yorvoid/testing` requires PHPUnit 12 or 13, and `yorvoid/flows` for `FlowStoreContract`, the tests of a `FlowStore`.

## 0.3.0 (2026-11-06)

A setup code claims a realm under the security log's lock, and CI holds the packages to the compatibility promise: no `@internal` type in the API, no break the changelog doesn't list.

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
