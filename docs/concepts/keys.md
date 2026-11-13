# Keys

YorvoID's key ring holds its signing keys, Ed25519 or ES256 ([algorithms](#algorithms)), signing through OpenSSL ([cryptography](cryptography.md)). They sign the security log's checkpoints and anchors ([security log](security-log.md)). Each signature names the ID of its key, so keys can be rotated without breaking what was signed before.

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
- A verifier that only checks signatures gets the set without private parts: `toJwks()` leaves them out unless asked, and marks every key verify-only, so the set loads where nothing signs. `yorvoid:keys:rotate --public` prints it. Never deploy the key file there: it holds every private part.

## Rotating keys

1. Add the new key as `verify-only`, and deploy the public set (`yorvoid:keys:rotate --public`) everywhere signatures are checked.
2. Make the new key `active` and the old one `verify-only`.
3. Remove the old key once nothing it signed still needs checking.

A ring with no active key, or more than one, is refused when it's loaded.

In code, each step is a new ring: `$ring->with(SigningKey::generate('2026-11', KeyStatus::VerifyOnly))`, `$ring->activating('2026-11')`, `$ring->without('2026-10')`; `$ring->toFile($path)` writes it for its owner only, replacing the old file at once. With the Symfony bundle, `yorvoid:keys:rotate` takes the next step each time it runs and says what comes next; its first run creates the ring (see [Symfony](symfony.md#console-commands)).

What's in flight doesn't notice a rotation: what the old key signed verifies until the old key is removed, and sessions and one-time tokens are random secrets kept as hashes, not signatures. Intents are signed by people's passkeys, and the verifier's keys rotate by intents ([signed intents](signed-intents.md)); an intent prepared before a rotation is signed by whichever key the verifier has then. Tests keep all of this so (OPS-045).

## A ring for each realm

By default every realm signs with the one ring. Where realms are tenants that mustn't share keys (one's rotation or compromise mustn't touch another's, and a tenant that leaves takes its keys), give each realm a ring of its own (D-40):

```php
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Key\PerRealmKeyRings;
use YorvoID\Core\Key\SharedKeyRings;

$rings = new SharedKeyRings(KeyRing::fromFile('/run/secrets/yorvoid-keys.json'));  // one for every realm
$rings = new PerRealmKeyRings('/run/secrets/yorvoid-keys');  // acme.json for the realm "acme", and so on

$ring = $rings->forRealm($realm);
```

- What YorvoID signs names its realm next to the key's ID, and is verified with that realm's ring only: a realm's key IDs are its own, and what one realm signed doesn't verify with another's keys.
- A realm without its file can't sign: `forRealm()` says how to create it. Each file is a key file like any other, only its owner may read it, and it rotates by itself.
- With the Symfony bundle, set `keys.per_realm: true` and `keys.directory`; `yorvoid:keys:rotate --realm acme` creates and rotates the realm's ring, and `yorvoid:doctor` reports a realm without keys.

## Algorithms

A key is Ed25519 (`EdDSA`, the default) or ECDSA on P-256 (`ES256`), for a FIPS provider without EdDSA ([cryptography](cryptography.md)). Both give 64-byte signatures. A signature names its key's ID, and the key decides the algorithm, so one algorithm's signature is never taken for the other's.

In the key set, an ES256 key is a JSON Web Key of type `EC` (RFC 7518 §6.2): `{"kty": "EC", "crv": "P-256", "alg": "ES256", "x", "y", "d"}`. In plain PHP: `SigningKey::generate($id, algorithm: KeyAlgorithm::ES256)`.

`yorvoid:keys:rotate` adds the crypto policy's algorithm, Ed25519 in the standard mode and ES256 in the FIPS mode, unless `--algorithm` says otherwise. Post-quantum signatures (ML-DSA) can be added the same way once PHP offers them.

## Moving to ES256

A FIPS provider without EdDSA, such as OpenSSL 3.1.2's, can't sign with Ed25519 keys or check what they signed. Move to ES256 keys in the standard crypto mode, before switching to the FIPS mode:

1. `yorvoid:keys:rotate --algorithm=ES256` adds the ES256 key, verify-only. Deploy the public key set as for any rotation.
2. `yorvoid:keys:rotate` makes it active. Because its algorithm differs from the old key's, the rotation then **anchors** the realm: it verifies the log against every checkpoint and signs an anchor with the new key. If anything fails to verify, the key ring stays as it was.
3. With a key ring shared by several realms, anchor the others too: `yorvoid:log:anchor --all`, or `--realm` for each. `yorvoid:doctor` names every realm that still needs it.
4. Keep the old key, verify-only, as long as you keep its checkpoints. Then switch to the FIPS mode (`yorvoid.crypto.mode: fips`).

In the FIPS mode, YorvoID refuses to start while an Ed25519 key is active in the shared key file. With a ring for each realm, `yorvoid:doctor` reports an active Ed25519 key in any realm as a problem.

**What an anchor certifies.** A checkpoint commits to the hash of the entry it names, and the chain links every earlier entry into that hash. An anchor at entry *N* therefore covers entries 1 to *N* as the Ed25519 checkpoints before it did, and certifies the tail after the last Ed25519 checkpoint just as any new checkpoint does. Under a provider without EdDSA, the Ed25519 checkpoints are reported as not checkable here, never as valid or failed, and verification passes only if an anchor at or after them covers them. What's lost there is only *when* the log was in each earlier state; that stays checkable in the standard mode.

An anchor is signed as an anchor (`yorvoid-security-log-anchor/1`), so no ordinary checkpoint can be taken for one: with a shared ring, cron's first ES256 checkpoint of a realm covers nothing before it. Checkpoints already kept are unchanged.
