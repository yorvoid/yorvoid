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
php bin/console yorvoid:keys:rotate --public > keys.public.json
# deploy keys.public.json everywhere signatures are checked; never the key file
php bin/console yorvoid:keys:rotate     # the new key signs; the old one only verifies
# once nothing the old key signed still needs checking, remove it by its ID, from the table each run shows
php bin/console yorvoid:keys:rotate --remove <old key ID>
```

- Sessions, one-time tokens and intents in flight carry on through every step: none of them depend on these keys.
- The file is written for its owner only (`chmod 600`), and replaced at once. Keep it out of the repository and the database.
- `yorvoid:doctor` checks the file exists, only its owner can read it, and it holds a valid ring.

How rotation works is in [keys](../concepts/keys.md#rotating-keys).
