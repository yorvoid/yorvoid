# Symfony

`yorvoid/symfony` puts YorvoID into a Symfony application: its services from your configuration, its events through Symfony's dispatcher, its emails through Symfony Mailer, its messages through Symfony's translator, and its console commands. It never defines a firewall: its authenticators go into yours.

```bash
composer require yorvoid/symfony
```

```php
// config/bundles.php
return [
    // …
    YorvoID\Symfony\YorvoIDBundle::class => ['all' => true],
];
```

It needs DoctrineBundle's DBAL connection, and Symfony Mailer for notifications.

## Configuration

```yaml
# config/packages/yorvoid.yaml
yorvoid:
    passkeys:
        relying_party_id: example.com
        origins: ['https://example.com']
    notifications:
        application_name: Acme
        not_me_url: 'https://example.com/security/not-me?token={token}&credential={credential}'
        from: security@example.com
```

That's all that's required. The rest, with its defaults; `# preset` marks what the [preset](#presets) gives when you leave it out, shown for `consumer_site`:

```yaml
yorvoid:
    preset: consumer_site               # or internal_tool, regulated, agent_platform
    connection: default                 # the DoctrineBundle connection for YorvoID's tables
    keys:
        file: ~                         # the signing keys, e.g. /run/secrets/yorvoid-keys.json; yorvoid:keys:rotate creates it
    realm: default                      # the realm people sign in to
    users: ~                            # the service ID of your PrincipalUsers; PrincipalUser otherwise
    cookies:
        device: __Host-yorvoid_device  # recognises devices; Secure, HttpOnly, SameSite=Lax
    storage:
        prefix: yorvoid_               # YorvoID's tables are yorvoid_principal, yorvoid_passkey, …
        schema: ~                       # or a PostgreSQL schema of their own
    sessions:
        preset: aal2                    # preset; aal2: 24h, 1h idle; aal3: 12h, 15m idle; console: 8h, 1h idle
        absolute: ~                     # overrides the preset, e.g. 8h
        idle: ~
        recognise_devices: true         # preset; announce sign-ins from new devices
    passkeys:
        user_verification: required     # preset; or preferred
        clone_policy: refuse_sign_in    # or disable_passkey, allow
        timeout: 5m
    step_up:
        window: 5m                      # preset; how recent a proof changing sign-in methods needs
        cool_down: ~                    # preset; e.g. 24h after an account recovery, ~ for none
    recovery_codes:
        count: 10
    limits:                             # attempts per period
        per_account_and_address: 10/15m # preset
        per_account: 100/1h             # preset
        per_address: 300/15m            # preset
        invitation_emails: 1/2m
        signature_requests: 20/1h
    notifications:
        enabled: true
        locales: ~                      # the service ID of your PrincipalLocales
    intents:
        summaries_domain: messages      # where your operations' summaries are translated
    flows:
        completed_path: /               # where people go when a flow completes
        completed_paths: {}             # by flow, e.g. {yorvoid.sign_intent: /console/confirmed}
        script: /bundles/yorvoid/browser/forms.js   # the pages' passkey script
```

- Durations are written `90s`, `15m`, `8h`, `30d`; limits as attempts per duration, `10/15m`.
- Each principal's language comes from your profiles: implement `PrincipalLocales` and give its service ID as `notifications.locales`; English otherwise.

### Presets

A preset is a documented starting point for a kind of application (PLT-090). It gives the settings you leave out; whatever you write wins, `~` included. `yorvoid:doctor` warns where yours are weaker than your preset's ([below](#checking-the-setup)); stronger ones are fine.

| Setting | `consumer_site` (default) | `internal_tool` | `regulated` | `agent_platform` |
|---|---|---|---|---|
| `sessions.preset` | `aal2` | `console` | `aal3` | `aal2` |
| `sessions.recognise_devices` | `true` | `true` | `true` | `true` |
| `passkeys.user_verification` | `required` | `required` | `required` | `required` |
| `step_up.window` | `5m` | `5m` | `2m` | `5m` |
| `step_up.cool_down` | none | none | `24h` | `24h` |
| `limits.per_account_and_address` | `10/15m` | `10/15m` | `5/15m` | `10/15m` |
| `limits.per_account` | `100/1h` | `100/1h` | `50/1h` | `100/1h` |
| `limits.per_address` | `300/15m` | `1000/15m` (an office behind one address) | `300/15m` | `300/15m` |

- **consumer_site:** a public site; AAL2 sessions.
- **internal_tool:** staff signing in for a working day, many of them from one address.
- **regulated:** AAL3 session limits, fresher proofs for changing sign-in methods, a day's cool-down after an account recovery, tighter limits. Quorum intents and strict audit join it when YorvoID has them.
- **agent_platform:** people delegating to agents: a taken-over account hands over its agents too, so a recovery waits a day before sensitive operations. Delegation limits join it when YorvoID has agents.

## What the bundle wires

- **Storage:** YorvoID's DBAL stores on your connection, and its transaction middleware added to that connection (and no other) through DoctrineBundle, so YorvoID's "-ed" events wait for your commits.
- **Services:** `Principals`, `Sessions`, `StepUp`, `Credentials`, `Passkeys`, `RecoveryCodes`, `RecoveryLinks`, `SetupCodes`, `Invitations`, `NotMe`, `IntentSigning` and the rest, ready to inject.
- **Events:** YorvoID's events go through Symfony's dispatcher, so your listeners hear them. Suspending a principal ends their sessions in the same transaction; removing a passkey from the registry removes its key.
- **Mail:** [security notifications](notifications.md) through Symfony Mailer, as plain text and HTML, from the address you configure, after every commit.
- **Translations:** YorvoID's catalogues added to Symfony's translator; without one, YorvoID uses its own.

## Signing in

The bundle gives you a user provider, a user checker and three authenticators. Put them in your own firewall, and import YorvoID's routes for the browser client:

```yaml
# config/packages/security.yaml
security:
    providers:
        yorvoid:
            id: YorvoID\Symfony\Security\PrincipalUserProvider
    firewalls:
        main:
            lazy: true
            provider: yorvoid
            user_checker: YorvoID\Symfony\Security\PrincipalUserChecker
            custom_authenticators:
                - YorvoID\Symfony\Security\Authenticator\PasskeyAuthenticator
                - YorvoID\Symfony\Security\Authenticator\RecoveryCodeAuthenticator
                - YorvoID\Symfony\Security\Authenticator\RecoveryLinkAuthenticator
                - YorvoID\Symfony\Security\Authenticator\SessionAuthenticator
            logout:
                path: /sign-out

# config/routes/yorvoid.yaml
yorvoid:
    resource: '@YorvoIDBundle/config/routes.php'
    prefix: /yorvoid
```

| Route | Body | |
|---|---|---|
| `POST /sign-in/options` | `{}` | Starts a passkey sign-in: `{"ceremony", "options"}`. |
| `POST /sign-in` | `{"ceremony", "response"}` | Signs in with the passkey (`PasskeyAuthenticator`). |
| `POST /recover/code` | `{"email", "code"}` | Recovers with a recovery code; every other session ends. The answer is the same whether the account exists or not. |
| `POST /recover/link` | `{"token"}` | Recovers with a recovery link's token. A POST from your recovery page, never the link itself: email scanners open links. |
| `POST /passkeys/options` | `{}` | Starts adding a passkey to the signed-in person's account. |
| `POST /passkeys` | `{"ceremony", "response", "label"}` | Adds it: `{"credential", "label"}`, 201. |
| `POST /passkeys/signals` | `{}` | The account's passkeys and name, for the WebAuthn signal API; 204 without a passkey. |
| `POST /step-up/options` | `{"operation", "multi_factor"}` | Starts a [step-up](step-up.md) for an operation. |
| `POST /step-up` | `{"ceremony", "response"}` | Finishes it; the session gets a new token. |

- Every endpoint takes JSON only, and answers 415 otherwise: another site's form can't send JSON without the browser asking yours first.
- The [browser client](browser-client.md) calls these for you.
- Refusals answer `{"error"}` with a message to show: 401 for a sign-in that didn't work (with `"unknown_credential": true` when the passkey isn't registered here, so the browser can tell its provider), 429 with `Retry-After` when there were too many, 400 with a `reason` for a passkey refused, and 403 with `step_up` when a change needs a more recent proof.
- The PHP session holds only the YorvoID session's token, under a new PHP session ID at every sign-in and step-up. Each request checks the YorvoID session once; when it has ended, expired, or its principal was suspended, the PHP session is emptied before the firewall reads it, and the person is signed out.
- Signing out through the firewall's `logout` ends the YorvoID session as well as the PHP session, so its token is no use to anyone who kept it.
- `CurrentSession::get()` gives the request's YorvoID session, e.g. for `Sessions::stepUp()` checks; `CurrentSession::forget()` drops it when you sign someone out yourself.
- Without `symfony/translation`, YorvoID translates its pages and emails with its own translator.

### Your own users

YorvoID keeps identity: the principal, its email, status, passkeys and sessions. Your application keeps everything else, such as names, preferences and its own data, in its own tables, keyed by the principal's ID. Don't copy the email or status: read them from YorvoID, so they can't drift apart.

The firewall's user is a `PrincipalUser`, with `ROLE_USER`, unless you give your own. Implement `PrincipalUsers` and give its service ID as `users`:

```php
final readonly class AppUsers implements PrincipalUsers
{
    public function __construct(private UserRepository $users) {}

    public function userFor(Principal $principal): UserInterface
    {
        // Created on the first sign-in, if it isn't there yet.
        return $this->users->findOrCreateFor($principal->id);
    }
}
```

- Your user's `getUserIdentifier()` returns the principal's ID: the firewall loads your user again by it on every request. YorvoID refuses a user identified otherwise.
- Controllers, voters and `#[IsGranted]` then see only your user, with your roles.
- A principal that isn't active any more isn't loaded, so a suspended person is signed out whatever your user says.

## Flow pages

YorvoID's [flows](flows.md) are at `/flows/{flow}` under YorvoID's routes: e.g. `/yorvoid/flows/yorvoid.sign_in` to sign in, `/yorvoid/flows/yorvoid.setup` to claim a fresh installation, `/yorvoid/flows/yorvoid.invitation?token=…` from an invitation's email. Each step has its own page, and its form posts to that step; after an answer, the browser goes on to the next step's page, so reloading never posts again. When a flow completes, the person goes to `completed_path`, or to the flow's own in `completed_paths`.

- **Signing in happens in the flow.** The step that signs someone in keeps the YorvoID session's token in the PHP session; `SessionAuthenticator`, in your firewall, signs them in to it from the next request. Whoever was signed in before isn't any more.
- **The flow's ID is in its address,** long and random, and the flow lives in the PHP session: a page elsewhere can neither guess it nor continue it, so the forms need no CSRF token.
- **A request starts only the public flows,** with only their inputs: `yorvoid.invitation` and `yorvoid.recover_with_link` take `token`, `yorvoid.step_up` takes `operation` and `multi_factor`. Others, like confirming a signed operation, your application starts, then sends the person to the step's page:

```php
$progress = $flows->start(PasskeyFlows::SIGN_INTENT, $realm, $contexts->of($request), IntentConfirmationStep::data($signingRequest));

return $this->redirectToRoute('yorvoid_flow_show', ['flow' => PasskeyFlows::SIGN_INTENT, 'id' => $progress->state->id]);
```

  When it completes, `FlowCompleted` has the nonce and the passkey's answer for your verifier.
- **A browser keeps five flows going** at once; older ones are forgotten.
- **To change a flow,** implement `FlowCustomizer`: every service implementing it is given each flow in turn, to insert, replace or remove steps. Give your own steps a template, `templates/bundles/YorvoIDBundle/flow/{step}.html.twig`.

The pages need Twig (`symfony/twig-bundle`); the [browser client](browser-client.md#flows) asks for JSON instead, from the same addresses, and needs nothing more.

### The reference templates

Every step has an accessible, unstyled template, phone-first, in `@YorvoID/flow/` (UX-002, UX-011). Each extends `layout.html.twig` and renders its step's view model:

| Template | View model |
|---|---|
| `passkey.html.twig` | `PasskeySignInView`: passkeys in the email field's autofill, and a button; a link to recovery |
| `setup_code.html.twig` | `SetupCodeView` |
| `passkey_registration.html.twig` | `PasskeyRegistrationView`: "Not now" when the person may add one later |
| `recovery_codes.html.twig` | `RecoveryCodesView` |
| `invitation.html.twig` | `InvitationView` |
| `recovery_code.html.twig` | `RecoveryCodeView` |
| `recovery_link.html.twig` | `RecoveryLinkView` |
| `step_up.html.twig` | `StepUpView` |
| `confirm.html.twig` | `IntentConfirmationView`: the summary and every parameter, in full |
| `failed.html.twig`, `expired.html.twig` | A flow that couldn't go on, or is gone |

- **Give them your look** by overriding `layout.html.twig`, or one block of it ([below](#changing-the-templates)).
- **Change the wording through translations,** never in templates: every text is a message in the `yorvoid` domain, in English and German.
- **Problems** are listed at the top in an alert that gets the focus, each linked to its field; the field says it's invalid, and why (`aria-invalid`, `aria-describedby`).
- **Passkey steps need JavaScript:** the browser client's `forms.js` runs the ceremony when the form is sent. Run `assets:install` to publish it, or point `flows.script` where you serve it.

### Changing the templates

Change as little as you can, so YorvoID's improvements keep reaching you (UX-006):

1. **The wording:** translations, never templates.
2. **One block:** extend YorvoID's template in your own, and override only that block. `@!YorvoID` is always YorvoID's, even where you override it:

   ```twig
   {# templates/bundles/YorvoIDBundle/flow/passkey.html.twig #}
   {% extends '@!YorvoID/flow/passkey.html.twig' %}

   {% block alternatives %}
       <p><a href="{{ path('support') }}">Can't sign in? Contact us</a></p>
   {% endblock %}
   ```

   The layout's blocks are `title`, `head`, `main`, `problems`, `content` and `scripts`; steps have their own, like `alternatives` on the sign-in page, and `summary` and `parameters` on the signing page.
3. **A whole template:** publish it, then change your copy.

   ```bash
   bin/console yorvoid:templates:publish passkey layout       # some templates
   bin/console yorvoid:templates:publish yorvoid.setup       # a flow's steps
   ```

   Copies go to `templates/bundles/YorvoIDBundle/flow/`, where Symfony uses them instead of YorvoID's; the versions they came from to `templates/bundles/YorvoIDBundle/.upstream/flow/`. Commit both. A template you wrote yourself is only replaced with `--force`.

**After an upgrade,** `yorvoid:templates:diff` compares each published template three ways: the version you published, your copy, and YorvoID's now.

- *Up to date:* nothing to do.
- *YorvoID changed it, you didn't:* `--accept` takes the new version.
- *Both changed:* it shows what YorvoID changed; merge that into your copy, then `--accept` marks it merged.
- *YorvoID doesn't have it any more:* remove your copy; `--accept` forgets it.

It fails while anything needs your attention, so it can run in CI.

**Check your templates** with `yorvoid:templates:lint` (UX-014). It renders every page, with your templates, in every state, and checks what each step needs: the fields and their names, `autocomplete` tokens like `username webauthn` and `one-time-code`, a label for every field, problems in an alert and on their fields, forms that post to their step, the passkey ceremony's form, field and script, and copy that mustn't go, like every parameter of an operation being signed. In a test:

```php
self::assertSame([], static::getContainer()->get(TemplateLint::class)->lint());
```

YorvoID's server never relies on it: it's for the people using your pages.

**The gallery** shows every page with your templates, in every state and language, with made-up data. Import its routes for development only:

```yaml
# config/routes/yorvoid.yaml
when@dev:
    yorvoid_template_gallery:
        resource: '@YorvoIDBundle/config/routes/gallery.php'
        prefix: /_yorvoid/templates
```

It answers only in debug mode, wherever it's imported.

## Console commands

| Command | |
|---|---|
| `yorvoid:doctor` | Checks YorvoID's setup and says how to fix each problem ([below](#checking-the-setup)). `--strict` fails on warnings too. |
| `yorvoid:keys:rotate` | Takes the next step in [rotating the signing keys](keys.md#rotating-keys): creates the ring, adds a verify-only key, makes it active; `--remove <key>` removes an old one. Sessions and tokens in flight carry on. |
| `yorvoid:migrate` | Creates or updates YorvoID's tables, separately from your migrations. `--dry-run` prints the SQL; `--allow-blocking` runs migrations that block tables, in a maintenance window. |
| `yorvoid:setup-code [realm]` | Prints a setup code that claims a fresh installation (`--lifetime`, 24 hours by default). |
| `yorvoid:recovery-link <email>` | Prints a recovery link for someone who lost access, for whoever has the server's shell (`--url` with `{token}`, `--lifetime`, 15 minutes by default). |
| `yorvoid:templates:publish <templates or flow>` | Copies flow templates into your application, to change them ([above](#changing-the-templates)). |
| `yorvoid:templates:diff [templates]` | After an upgrade, what YorvoID changed in the templates you published; `--accept` takes it. |
| `yorvoid:templates:lint` | Checks the flow pages, with your templates, for what each step needs. |
| `yorvoid:purge` | Removes ended sessions, expired tokens and ceremonies, throttling state that's clear, ended cool-downs and long-unused devices. Run it daily. |

## Checking the setup

`yorvoid:doctor` checks what the configuration alone can't, and says how to fix each thing it finds, with a link to the page that explains it:

| Check | What it looks at |
|---|---|
| Configuration | Builds what YorvoID builds only when it's first used: the passkey policy (the relying party ID and the origins), the session policy, the limits, the database. Passkeys for `localhost` outside development are a warning. |
| Keys | With `keys.file` set: the file exists, only its owner can read it, and it holds a valid key ring. |
| Database and migrations | YorvoID reaches its database, every migration of this version ran, and the database has none from a later version. |
| Clock | The application's clock agrees with the database's within 5 seconds; a `MockClock` outside development is a problem. |
| Assurance level | The configuration reaches the level its `sessions.preset` targets ([assurance levels](../security/assurance-levels.md)): passkeys require user verification, sessions last no longer than the level allows, and no AAL3 with synced passkeys. |
| Preset | Your settings are no weaker than your [preset's](#presets): session preset, device recognition, step-up window and cool-down, limits. |
| Cookies | The PHP session is on, its cookie is `Secure` (or `auto`), `HttpOnly` and `SameSite=Lax` or `Strict`, and the device cookie has the `__Host-` prefix. |
| Mailer | With notifications on: the sender is an email address, and outside development the default transport delivers (not `null://`, not Symfony's `smtp://null`). It sends nothing. |
| Flow pages | The pages have what each step needs (as `yorvoid:templates:lint`), and the templates you published kept up with YorvoID's (as `yorvoid:templates:diff`). |

A **problem** breaks something: sign-in, emails, the pages. The command then fails, so a deployment can stop on it. A **warning** works, but less safely than it could, or than the configuration says; `--strict` fails on warnings too.
