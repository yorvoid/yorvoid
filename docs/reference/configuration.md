# Configuration reference

Everything the Symfony bundle takes under `yorvoid:`, with its defaults, as `bin/console config:dump-reference yorvoid` prints it. What each setting means is explained in [Symfony](../concepts/symfony.md#configuration); settings marked "preset" come from the [preset](../concepts/symfony.md#presets) when left out.

```yaml
yorvoid:

    # consumer_site, internal_tool, regulated or agent_platform: the settings you leave out (marked "preset")
    preset:               consumer_site # One of "consumer_site"; "internal_tool"; "regulated"; "agent_platform"

    # The DoctrineBundle connection to keep YorvoID's tables in
    connection:           default

    # The realm people sign in to
    realm:                default

    # The service ID of your PrincipalUsers, to sign in your own users; PrincipalUser if not given
    users:                null
    keys:

        # The signing keys' file, only its owner may read, e.g. "/run/secrets/yorvoid-keys.json"; yorvoid:keys:rotate creates it
        file:                 null
    cookies:

        # The cookie that recognises devices
        device:               __Host-yorvoid_device
    storage:

        # Prefix of YorvoID's tables
        prefix:               yorvoid_

        # A PostgreSQL schema of their own
        schema:               null
    sessions:

        # aal2: 24h, 1h idle; aal3: 12h, 15m idle; console: 8h, 1h idle; preset
        preset:               ~ # One of "aal2"; "aal3"; "console"

        # Overrides the preset, e.g. "8h"
        absolute:             null

        # Overrides the preset, e.g. "30m"
        idle:                 null

        # Recognise devices by a cookie, and announce new ones; preset
        recognise_devices:    ~
    passkeys:             # Required

        # E.g. "example.com"
        relying_party_id:     ~ # Required

        # E.g. ["https://example.com"]
        origins:              [] # Required

        # Preset
        user_verification:    ~ # One of "required"; "preferred"
        clone_policy:         refuse_sign_in # One of "refuse_sign_in"; "disable_passkey"; "allow"
        timeout:              5m
    step_up:

        # How recent a proof changing sign-in methods needs; preset
        window:               ~

        # How long sensitive operations wait after an account recovery, e.g. "24h", or ~ for none; preset
        cool_down:            ~
    recovery_codes:
        count:                10
    limits:

        # Attempts at a secret, one account from one address; preset
        per_account_and_address: ~

        # Attempts at a secret, one account from anywhere; preset
        per_account:          ~

        # Attempts at a secret, one address; preset
        per_address:          ~

        # Invitations sent again to one person
        invitation_emails:    1/2m

        # Signatures of intents asked of one person
        signature_requests:   20/1h
    notifications:
        enabled:              true

        # What people call the application, e.g. "Acme"
        application_name:     null

        # The "This wasn't me" page, with {token} and {credential}
        not_me_url:           null

        # The address emails come from, e.g. "security@example.com"
        from:                 null

        # The service ID of a PrincipalLocales, for each principal's language; English if not given
        locales:              null
    flows:

        # Where people go when a flow completes, e.g. after signing in
        completed_path:       /

        # Where to go after particular flows, e.g. {yorvoid.sign_intent: /console/confirmed}
        completed_paths:

            # Prototype
            flow:                 ~

        # Where the pages load the browser client's form script from
        script:               /bundles/yorvoid/browser/forms.js
    intents:

        # The translation domain of your operations' summaries
        summaries_domain:     messages
```
