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
- `RelationStore::version()` is new, and every change to relations increments the realm's relations version: a store of your own keeps one (`RelationStoreContract`).
- `PolicyStore::version()` and `RelationStore::version()` must be read from what the store holds, so every process reads the same: never a counter of the process's own. The contracts check it with a second instance over the same data (`samePolicyStore()`, `sameRelationStore()`; override them for a store processes share).
- `yorvoid/core` requires `ext-openssl` instead of `ext-sodium`: signing keys sign, and the hashes security depends on are computed, through OpenSSL ([cryptography](docs/concepts/cryptography.md)). Keys, signatures and hashes are byte-identical to before. `yorvoid/passkeys` and `yorvoid/intent-verifier` require `ext-sodium`, which the WebAuthn library verifies EdDSA passkeys with.

### Added

- `Operations`, `Permit`, `NotPermitted`, and the actors `Operator`, `Anonymous` and `ActingPrincipal`. In Symfony, the application's own `OperationHandler`s are registered.
- The evaluator (`Evaluator`): Permit, Deny or Indeterminate by the precedence of forbids, the actor's authority and permits ([authorization](docs/concepts/authorization.md)). YorvoID's ways to authorize are its built-in permits, with `SelfServicePolicy` and optionally an `AbilityPolicy`; your own rules are `PermitElement`s and `ForbidElement`s, registered by themselves in Symfony. `PrincipalAuthority` refuses a principal that isn't active.
- A decision carries its reasons, what couldn't be established (`IndeterminateReason`), and on a Deny for want of evidence its remedies (`StepUpRemedy`, `WaitRemedy`): a step-up asked by `StepUpSelfService` comes as a remedy on `NotPermitted`, not as `StepUpRequired`.
- An Indeterminate decision is logged as a warning.
- A store that can't be reached makes a decision Indeterminate with the reason `store_unreachable` (`StoreUnreachable`): a refused or lost connection, a statement's timeout, a failover. A store of your own throws `StoreUnreachable` itself.
- Offline decisions, off by default: an `OfflineDecider` may give Permit-offline while a store is unreachable, a verdict of its own that only operations declaring `#[Operation(acceptsOffline: true)]` run on; their permit says it was offline (`Permit::$offline`), and the log records `yorvoid.decision.offline`.
- A refused operation is recorded in the security log.
- An operation declares what it causes (`#[Operation(causes: [...])]`), and its permit gives consequences for those only.
- The host's operator acts from a console or job origin only when PHP runs from the command line.
- Signing keys for each realm (`KeyRings`, `SharedKeyRings`, `PerRealmKeyRings`): a realm's checkpoints are signed and verified with its own ring. In Symfony, `keys.per_realm` and `keys.directory`, `yorvoid:keys:rotate --realm`, and the doctor reports a realm without keys.
- The stores' contracts in `yorvoid/testing` (`YorvoID\Testing\Contract`): the tests YorvoID's own stores pass, one trait for each store and one for `Transactions`, for a store of your own to pass too. `yorvoid/testing` requires PHPUnit 12 or 13, and `yorvoid/flows` for `FlowStoreContract`, the tests of a `FlowStore`.
- `yorvoid/policy`: declared abilities and roles (`PolicyDeclarations`, `Ability`, `Role`), super roles, strict abilities, the `everyone` role, a realm's changes to the defaults kept as differences (`AbilityChanges`), roles created in a realm, roles assigned in a realm or on one resource with an expiry (`RoleAssignment`), roles from a `RoleSource`, and rules in PHP that permit, forbid or can't tell (`Rule`). The role permit (`RolePermit`) decides operations that need an ability; `Policy` decides an application's own abilities, in Symfony also through `isGranted()` ([authorization](docs/concepts/authorization.md#abilities)).
- A request can be about an ability on a resource (`ResourceRef`) instead of an operation: `Evaluator::decideAbility()`. A command about one resource implements `ConcernsResource`.
- A request about an ability carries its subject, the application's own object (`Request::$subject`), for rules and attribute resolvers to read, e.g. a page's author for "edit your own": the resource is the one it concerns (`ConcernsResource`) unless given, and it's never recorded, logged or cached. `isGranted()` passes any object other than a `ResourceRef` as the subject ([the subject](docs/concepts/authorization.md#the-subject)).
- Explanations (experimental): what each element's truth rests on (`Ground`: the roles that have the ability and how, the relation, the rule and the attributes it read, a proof, the actor's status), the row of the precedence that decided (`Decision::$precedence`), and `Explanation::of()` putting them together for an explainer to build its own text from ([explanations](docs/concepts/authorization.md#explanations)).
- `Policy::whyNot()` (experimental): why an actor may not do something, and the roles, relations and forbids that would change it, never who holds them. About someone else it takes the new ability `yorvoid.policy.explain`, whose permits are always recorded ([why not](docs/concepts/authorization.md#why-not)).
- View-as (experimental): `ViewingAs`, an actor seeing the application as another sees it. Every decision is made for the viewer and the target and intersected, so it never exceeds the viewer, and the decision carries the viewer's own (`Decision::$viewer`); no operation runs for it (`yorvoid.view_as.read_only`). The operation `yorvoid.view_as.begin` (`BeginViewAs`, ability `yorvoid.policy.view_as`) begins one and says what the target's roles give beyond the viewer's (`ViewAsStart::$beyond`) ([view-as](docs/concepts/authorization.md#view-as)).
- `yorvoid/policy-testing` (experimental, for development): policy tests, the decisions an application's policy must give as cases (`PolicyCase`, `PolicyCases`), run on the policy as production builds it with its stores in memory: in Symfony from the application's container in the `yorvoid_policy` environment (`YorvoIDPolicyTestingBundle`, `yorvoid:policy:test`), without it from production's own function (`PolicyUnderTest::of()`, `vendor/bin/yorvoid-policy`), and in PHPUnit (`PolicyCasesTest`) ([test your policy](docs/how-to/test-your-policy.md)).
- Policy mutation testing (experimental, AUZ-045): `yorvoid:policy:mutate` and `vendor/bin/yorvoid-policy mutate` break the policy one change at a time (14 operators on roles, grants, groups, parents, a relation's implications, forbids, permits and the authority check) and report what no case catches, and what no case reaches, with `--min-score` and `--ignore` by stable IDs (`PolicyMutation`, `Mutants`) ([mutation testing](docs/how-to/test-your-policy.md#mutation-testing)).
- `RelationSchema::types()`: every resource type the schema declares.
- A deleted principal's roles are taken.
- The policy changes through operations (`yorvoid.role.*`, `yorvoid.ability.*`, `PolicyChanges`), each in the security log and incrementing the realm's policy version. Nobody gives what they don't hold (`NoEscalation`), and giving a strict ability or a super role takes a recent proof (`StepUpCheck`, `SessionStepUpCheck`).
- A policy element can check the operations when `Operations` is built (`ChecksCatalogue`): the role permit refuses an operation whose ability isn't declared.
- Every behaviour model is replayed against the code: the setup claim (by the order its transactions commit in) and the intent verifier's keys, as the one-time tokens were; the decision model and its fixtures in two configurations, the default and every extension point engaged (XP-16), the default's outcomes unchanged ([formal models](spec/README.md)). Inside `Verifier`, which is unchanged, its keys and the checks of what the browser answers are parts of their own (`KeyRegistry`, `IntentChecks`, internal).
- [Performance](docs/reference/performance.md): the targets (OPS-042), what each release measures, and how to measure it yourself (`composer bench`, on PostgreSQL).
- `yorvoid/relations`: relations between resources (ReBAC, AUZ-011): declared per resource type, implied by others, inherited from parents, to subject sets; checked a level at a time and at most 16 levels deep (`Relations`), with abilities they give on their resource (`RelationPermit`) and changes through `yorvoid.relation.add` and `.remove`. A role on a resource counts for what's within it ([relations](docs/concepts/authorization.md#relations)).
- Groups (IDN-056): nested, with roles given to them (`yorvoid.group.*`, `GroupChanges`, `GroupRelations`).
- Security attributes of principals (IDN-055) and attributes of the request through resolvers (AUZ-013): `SecurityAttribute`, `AttributeDeclarations`, `Attributes`, `AttributeResolver`, `yorvoid.attribute.set`. What isn't known is Indeterminate (`missing_data`), as is any exception implementing `MissingData` ([attributes](docs/concepts/authorization.md#attributes)).
- The decision log (AUZ-040), apart from the security log and without a lock (`DecisionLog`, `DecisionLogStore`, `DecisionRecord`, `PermitRecording`): every operation's decision before it runs, abilities' refusals and Indeterminate, and their permits as configured (`yorvoid.decision_log.permits`, `sample_rate`; `Ability::$recordsPermits`). Security-log entries cite the decision that let their operation run, by ID and hash. `yorvoid:purge --keep-decisions` prunes it ([decision log](docs/concepts/security-log.md#the-decision-log)).
- Batch decisions: `Policy::decideMany()` decides an ability on many resources at one moment, each with its subject if given, with what roles, relations and the principal need read once for all (`Decisions`, `Evaluator::decideAbilities()`, `Relations::checkMany()`, `ancestorsOfMany()`); an element of your own reads for a batch by implementing `Preloads` (experimental) ([many at once](docs/concepts/authorization.md#many-at-once)).
- What decisions read is read once a request, at the realm's versions, and kept in a shared pool if one is given (`VersionedCache`, `CachedPolicyStore`, `CachedRelationStore`): keys start with the installation's ID (`Installation`, the `installation` table) and the realm; a pool that fails is passed over. In Symfony, the pool `yorvoid.cache`, on `cache.app`, or the one `cache.pool` names ([what a request reads](docs/concepts/authorization.md#what-a-request-reads)).
- Each decision has an ID, a time and the versions it read (`Decision::$id`, `$at`, `$versions`, `VersionSource`, `PolicyVersion`, `RelationsVersion`); the permit of an operation carries its decision's ID.
- Elapsed time that can't go backwards (XP-14, experimental): `Elapsed`, `Instant` and `BootTime` (the boot's clock, which counts suspended time; per process without `/proc`), `MockElapsed` in `yorvoid/testing`. `WithinOfflineLimit` decorates an `OfflineDecider`: Permit-offline only while the time since a mark is under a limit, measured so that a changed wall clock or a reboot can't extend it ([offline limits](docs/concepts/authorization.md#offline-limits)).
- Ordered conditions (XP-15, experimental): `OrderedFact`, a value on a bounded scale, known or not, and `OrderedCondition` (`atLeast`, `atMost`), decided by `OrderedConditions`. An unknown fact is missing data (Indeterminate) by default; with `new OrderedConditions(pessimistic: true)` (`ordered_conditions.pessimistic` in Symfony) it's decided at its worst ([ordered conditions](docs/concepts/authorization.md#ordered-conditions)).
- The audit alert (AUD-006): every audit record that can't be written, in the security log, the decision log, the intent verifier's log or a checkpoint, raises a `critical` log entry and an `AuditFailed` event; the verifier tells its notifier (`audit_failed`). A failed write of waiting decisions is reported this way, where it was an error before.
- Refusing without a record (AUD-006): with `AuditFailure::Refuse` (`audit.on_failure: refuse`), every recorded decision is written before it's returned, and a Permit whose record can't be written is Indeterminate, with the new reason `audit_unavailable`. `DbalDecisionLogStore` can write on a connection of its own (`decision_log.connection`), which a rollback of the application's doesn't take back ([when a record can't be written](docs/concepts/security-log.md#when-a-record-cant-be-written)).
- A crypto policy with a FIPS mode (CRY-01): `CryptoPolicy`, `CryptoMode`, `CryptoProvider` (`crypto.mode: fips`). The FIPS mode starts only with OpenSSL's FIPS provider alone active, and passkeys (`PasskeyPolicy::$algorithms`) and the intent verifier (`VerifierConfig::$algorithms`) use only the algorithms it allows ([cryptography](docs/concepts/cryptography.md)).
- ES256 signing keys (`KeyAlgorithm::ES256`, `yorvoid:keys:rotate --algorithm`), for a FIPS provider without EdDSA, and anchors: a checkpoint signed only once the log verifies against every checkpoint before it (`Checkpoints::anchor()`, `yorvoid:log:anchor`), which covers Ed25519 checkpoints such a provider can't check ([moving to ES256](docs/concepts/keys.md#moving-to-es256)).
- `yorvoid:doctor` checks the audit mode, the crypto mode and the keys' algorithms, and says what a check found after its name.
- Refusals for people (M2-10): in Symfony, a `NotPermitted` nothing catches is answered with a page, or JSON, of what would satisfy it: "Confirm it's you" with a button to the step-up, which comes back to the page, or how long to wait. A Deny is a 403 and what couldn't be decided a 503, never with the decision's reason; the decision's ID is shown as a reference (`refused.html.twig`, `yorvoid_remedies()`, `remedies.pages`). The step-up flow takes `return`, a path on this site. The browser client has the error code `not_permitted` and `notPermitted()` ([refusals for people](docs/concepts/symfony.md#refusals-for-people)).

### Fixed

- The packages require each other at the version they're released with (`^0.4` for 0.4), so a release installs as a set. 0.2.0 and 0.3.0 required `^0.1` of each other and don't install together from Packagist: stay on 0.1.0, or take 0.4.0. Every release now checks this before it's published, and installs from Packagist afterwards.

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
