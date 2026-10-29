# Rotate the signing keys

Rotate YorvoID's signing keys on a schedule, or at once when one may have leaked. With the Symfony bundle, set the key file:

```yaml
# config/packages/yorvoid.yaml
yorvoid:
    keys:
        file: /run/secrets/yorvoid-keys.json
```

Then run `yorvoid:keys:rotate`; each run takes one step and says what comes next:

```bash
php bin/console yorvoid:keys:rotate     # the first time: creates the ring, with one active key
php bin/console yorvoid:keys:rotate     # adds a new key, verify-only
# deploy the key file everywhere signatures are checked
php bin/console yorvoid:keys:rotate     # the new key signs; the old one only verifies
# once nothing the old key signed still needs checking
php bin/console yorvoid:keys:rotate --remove 2026-10-29
```

- Sessions, one-time tokens and intents in flight carry on through every step: none of them depend on these keys.
- The file is written for its owner only (`chmod 600`), and replaced at once. Keep it out of the repository and the database.
- `yorvoid:doctor` checks the file exists, only its owner can read it, and it holds a valid ring.

How rotation works is in [keys](../concepts/keys.md#rotating-keys).
