# YorvoID documentation

Documentation for two readers: **integrators**, who add YorvoID to an application, and **researchers and reviewers**, who need to know what YorvoID guarantees and what it doesn't.

> YorvoID is in early development. These pages grow with each milestone.

## Concepts

- [Realms](concepts/realms.md): the isolation boundary every record belongs to.
- [Principals](concepts/principals.md): people, services, workloads, agents and capability holders; their lifecycle, identifiers and guard rules.
- [Passkeys](concepts/passkeys.md): registering them, signing in, user verification, suspected copies.
- [Credentials](concepts/credentials.md): every way someone signs in, and changing them safely.
- [Symfony](concepts/symfony.md): the bundle, its configuration, signing in through your firewall, and its console commands.
- [Flows](concepts/flows.md): sign-in, setup, invitations, recovery, adding passkeys, step-up and signed operations, as server-side steps you can change.
- [Browser client](concepts/browser-client.md): passkeys in the browser, autofill, step-up, signing intents, and errors to translate.
- [Signed intents](concepts/signed-intents.md): operations that run only after a passkey signed exactly them, checked by a verifier with its own keys.
- [Sessions](concepts/sessions.md): authentication sessions, how long they last, and ending them.
- [Throttling](concepts/throttling.md): at most so many attempts per period, slowing down rather than locking out.
- [Step-up](concepts/step-up.md): a recent or strong proof before sensitive operations, and the cool-down after a recovery.
- [One-time tokens](concepts/one-time-tokens.md): the links YorvoID sends or hands over, and how they work once.
- [Setup codes](concepts/setup-codes.md): claiming a fresh installation without a default account.
- [Invitations](concepts/invitations.md): inviting people by email.
- [Notifications](concepts/notifications.md): security emails people can act on, with "This wasn't me".
- [Mail](concepts/mail.md): emails from structured content, as plain text and accessible HTML.
- [Translations](concepts/translations.md): ICU messages in English and German, and changing YorvoID's wording.
- [Recovery codes](concepts/recovery-codes.md): codes people save to get back in once each.
- [Recovery links](concepts/recovery-links.md): an operator restoring someone's access.
- [Security log](concepts/security-log.md): the hash-chained record of everything security-relevant.
- [Configuration](concepts/configuration.md): typed configuration, and durations.
- [Secrets](concepts/secrets.md): how secrets are held, compared and kept out of logs.
- [Keys](concepts/keys.md): signing keys, where they're kept, and rotating them.
- [Operations and events](concepts/operations-and-events.md): commands, the catalogue, events, origins.
- [Storage](concepts/storage.md): databases, where the tables live, migrations, upgrading without downtime.
- [Testing](concepts/testing.md): testing your application with YorvoID: fixtures, the clock, signing in, a virtual authenticator.

## Policies

- [Compatibility promise](compatibility.md): what may change in which releases.

## Security and assurance

- [Threat model](security/threat-model.md): what YorvoID protects, against whom, and what's in place today.
- [Assurance levels](security/assurance-levels.md): NIST SP 800-63B-4 levels and the configurations that reach them.
- [Dependencies](security/dependencies.md): how dependencies are chosen and checked.
- [OWASP ASVS 5.0 traceability](../assurance/asvs-5.0.yaml): every requirement of the standard, and what YorvoID does about it.
- [Formal models](../spec/README.md): the semantics specified and checked by machine.

