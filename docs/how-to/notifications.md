# Turn on notifications

Security notifications email people when something changes on their account: a new sign-in from a device it doesn't know, a passkey added or removed, a recovery. Each has "This wasn't me", which signs everyone out of the account and disables a sign-in method that was just added; your page then leads the person to recovery.

1. Install Symfony Mailer, and give it a transport that delivers:

   ```bash
   composer require symfony/mailer
   ```

   ```dotenv
   MAILER_DSN="smtp://user:pass@smtp.example.com:587"
   ```

2. Turn notifications on:

   ```yaml
   # config/packages/yorvoid.yaml
   yorvoid:
       notifications:
           application_name: Acme
           from: security@example.com
           not_me_url: 'https://example.com/security/not-me?token={token}&credential={credential}'
   ```

3. Write the "This wasn't me" page at `not_me_url`: it hands the token to `NotMe` ([notifications](../concepts/notifications.md#this-wasnt-me)).

4. Check: `php bin/console yorvoid:doctor` says whether the sender is an address and, outside development, whether the transport delivers.

Emails go after the commit, in each person's language when you give a `PrincipalLocales` (`notifications.locales`); English otherwise.
