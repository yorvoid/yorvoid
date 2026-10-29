# Check the setup in deployments

Run `yorvoid:doctor` where your application is deployed, after migrating and before taking traffic:

```bash
php bin/console yorvoid:migrate
php bin/console yorvoid:doctor --strict
```

- **A problem** (sign-in, emails or pages broken) fails the command, so the deployment stops before people notice.
- **`--strict`** fails on warnings too: settings weaker than your [preset](../concepts/symfony.md#presets) or than your sessions' assurance level, cookies without their protections. Leave it out where you've chosen weaker settings knowingly.
- **Run it in the real environment**, with its own configuration and secrets: it checks the database it reaches, the mailer DSN it has, the key file it can read. It sends nothing and changes nothing.

What each check looks at is in [checking the setup](../concepts/symfony.md#checking-the-setup).
