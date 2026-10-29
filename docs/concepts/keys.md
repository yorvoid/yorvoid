# Keys

YorvoID signs with Ed25519 keys (RFC 8032, through libsodium): the security log's checkpoints, and later tokens and other evidence. Each signature names the ID of its key, so keys can be rotated without breaking what was signed before.

A **key ring** holds the keys: exactly one is **active** and signs; every key in the ring verifies.

```php
use YorvoID\Core\Key\KeyRing;

$keys = KeyRing::fromFile('/run/secrets/yorvoid-keys.json');
$signature = $keys->signer()->sign($message);
$keys->verify($keyId, $message, $signature);   // true or false
```

## Where keys are kept

Keys are kept as a JSON Web Key Set (RFC 7517; Ed25519 keys as in RFC 8037), with a `status` for each key:

```json
{
    "keys": [
        {"kid": "2026-10", "kty": "OKP", "crv": "Ed25519", "alg": "EdDSA", "use": "sig", "status": "active", "x": "…", "d": "…"}
    ]
}
```

- `x` is the public key and `d` the private seed, both base64url without padding.
- Keep the set in an environment variable or a secrets file, never in the database next to what it signs.
- A file must be readable only by its owner (`chmod 600`). YorvoID refuses a key file others can read, as SSH does.
- Private parts are `Secret`s: they don't appear in dumps, logs or stack traces.
- A verifier that only checks signatures gets the set without private parts (`toJwks()` leaves them out unless asked).

## Rotating keys

1. Add the new key as `verify-only`, and deploy it everywhere signatures are checked.
2. Make the new key `active` and the old one `verify-only`.
3. Remove the old key once nothing it signed still needs checking.

A ring with no active key, or more than one, is refused when it's loaded.

In code, each step is a new ring: `$ring->with(SigningKey::generate('2026-11', KeyStatus::VerifyOnly))`, `$ring->activating('2026-11')`, `$ring->without('2026-10')`; `$ring->toFile($path)` writes it for its owner only, replacing the old file at once. With the Symfony bundle, `yorvoid:keys:rotate` takes the next step each time it runs and says what comes next; its first run creates the ring (see [Symfony](symfony.md#console-commands)).

What's in flight doesn't notice a rotation: what the old key signed verifies until the old key is removed, and sessions and one-time tokens are random secrets kept as hashes, not signatures. Intents are signed by people's passkeys, and the verifier's keys rotate by intents ([signed intents](signed-intents.md)); an intent prepared before a rotation is signed by whichever key the verifier has then. Tests keep all of this so (OPS-045).

## Algorithms

Ed25519 is the only algorithm today. Keys and signatures record theirs (`EdDSA`), so post-quantum signatures (ML-DSA) can be added beside it once PHP offers them, without changing what's already stored.
