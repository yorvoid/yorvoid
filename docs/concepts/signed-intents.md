# Signed intents

Some operations are too dangerous to run because a web page asked: removing a site, adding an owner, changing keys. With **signed intents**, such an operation runs only after the owner's passkey signed exactly that operation, and the signature is checked where the operation runs, by a **verifier** that trusts nothing the web console says. A stolen session, a forged request, someone writing to the database, or a compromised console acting on its own can't run it.

The verifier is the `yorvoid/intent-verifier` package. It stands alone: no framework, no database layer, none of YorvoID's other packages, so it can run in a small privileged process (in Quire Server, the process that runs as root). It keeps its own keys and its own state.

```
browser            console (web)                               verifier (privileged process)
   │ "remove site jane" │                                              │
   │───────────────────►│── operation ───────────────────────────────►│ prepare(): check the policy, issue a nonce,
   │                    │◄──────────────────────── WebAuthn options ───│ keep the intent
   │◄── page shows what's being signed; navigator.credentials.get() ───│
   │── assertion ──────►│── nonce + assertion ───────────────────────►│ verify(): the challenge from the intent it kept,
   │                    │◄──────────────────────────────── verified ───│ its own keys → run the operation
```

How the console and the verifier talk (a queue, a database row, a socket) is your application's choice: the verifier only needs the operation, then the nonce and the browser's answer.

## Setting up the verifier

```php
use YorvoID\IntentVerifier\Operation;
use YorvoID\IntentVerifier\OperationPolicy;
use YorvoID\IntentVerifier\ParameterType;
use YorvoID\IntentVerifier\Storage\FileVerifierLog;
use YorvoID\IntentVerifier\Storage\FileVerifierStore;
use YorvoID\IntentVerifier\Verifier;
use YorvoID\IntentVerifier\VerifierConfig;

$verifier = new Verifier(
    new VerifierConfig(
        audience: 'acme-verifier:host-1',            // this verifier's name: a signature for another doesn't work here
        relyingPartyId: 'console.example.com',      // the passkeys' relying party
        origins: ['https://console.example.com'],   // where the signing page is
    ),
    new OperationPolicy([
        new Operation('acme.site.remove', ['site' => ParameterType::String]),
        new Operation('acme.site.list', signed: false), // runs unsigned, and says so
    ]),
    new FileVerifierStore('/var/lib/acme-verifier/state.json'),
    new FileVerifierLog('/var/lib/acme-verifier/log.jsonl'),
);
```

- **The policy lists every operation** the verifier runs, with its parameters and their types (strings, integers, booleans), and whether it needs a signature. An operation that isn't listed isn't run, and one with other parameters isn't prepared (INT-008).
- **The state file** holds the keys, the prepared intents and the bootstrap secret's hash, readable only by its owner. Each change holds an exclusive lock, so processes take turns, and it survives restarts: a nonce works once, also across them (INT-009).
- **The log** records what was prepared, verified and refused, and every change to the keys, as JSON lines in a hash chain: `isIntact()` tells whether any entry was changed, removed or reordered (INT-010). The console keeps its side in YorvoID's security log.

## Signing an operation

```php
// In the verifier, for the console's request:
$prepared = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
// → the console: $prepared->intent->nonce, and $prepared->options for navigator.credentials.get()

// In the verifier, with what the console sends back:
$verified = $verifier->verify($nonce, $assertionFromTheBrowser);
$sites->remove($verified->intent->parameters['site']);   // exactly the operation signed
```

- **The intent** is the operation in canonical form (RFC 8785): action, parameters, audience, nonce, issued-at, expires-at (INT-001). The challenge is its domain-separated SHA-256 hash (INT-002), so one signature means one operation (T-INT-5).
- **The verifier keeps the intent** it prepared, under its nonce. `verify()` takes only the nonce and the answer, and derives the challenge from what it kept: the console can't change the operation after it was prepared.
- **A nonce works once**, signed or not: a refused attempt uses it up. Unsigned intents expire after 5 minutes (configurable); `purge()` forgets them.
- **User verification is required**, checked before anything else; the signature, origin, relying party and signature counter are checked by web-auth/webauthn-lib, against the verifier's own keys (INT-004).
- **The algorithm** of the key that signed is recorded (INT-015).
- A refusal throws `IntentRefused`, with `$refusal` saying why.

## The verifier's keys

The verifier accepts signatures only from passkeys in its own registry, and the registry changes only through signed intents (INT-005, INT-006):

- **Adding a key** is the intent `yorvoid.verifier.add_key`, with the passkey's credential ID, COSE public key and user handle (base64url) and a label, signed by one of the verifier's keys: `prepare(OperationPolicy::ADD_KEY, $key)`, then `addKey($nonce, $assertion)`. A compromised console can't add its own key (T-INT-2).
- **Removing a key** is `yorvoid.verifier.remove_key`, signed: `removeKey($nonce, $assertion)`. The last key can't be removed.
- **The first key** comes with a **bootstrap secret**: the host's root creates it (`createBootstrapSecret()`, e.g. from a console command), and the owner gives it to the console with their passkey. The passkey signs its own "add key" intent (`prepareBootstrap($key)`, then `bootstrap($secret, $nonce, $assertion)`), so the key that's added is one the owner holds. The secret works once.
- **If every key is lost**, the host's root creates a new bootstrap secret: the way back in (INT-016). It's recorded with how many keys there were; tell every owner, through a channel the console doesn't control.

## The console's side

The console is where the person is signed in. `IntentSigning`, in the `yorvoid/passkeys` package, asks them to sign what the verifier prepared, and records what became of it in YorvoID's security log (INT-010):

```php
use YorvoID\Passkeys\Intent\IntentSigning;
use YorvoID\Passkeys\Intent\IntentSummaries;

$signing = new IntentSigning(new IntentSummaries($translator, 'acme'), $securityLog, $transactions, $throttle);

// With the prepared intent the verifier sent back:
$request = $signing->request($session, $prepared, 'en', $origin);
// Show $request->summary on the confirming page; pass $request->options to navigator.credentials.get().

// With what the verifier sent back after verify():
$signing->ran($session, $verified->intent, $verified->key->id(), $origin);   // or refused($session, $intent, $refusal, $origin)
```

- Ask for a signature only in response to the person's own action. Requests are limited per principal: 20 an hour by default (T-INT-3).
- `ran()` records the operation and announces it with `SignedOperationRan`. Register `SignedOperationNotification` as its listener, and the person gets an email after every signed operation, in their language, with "This wasn't me" ([notifications](notifications.md), INT-012): through a channel the console doesn't control.
- A delay or an undo window for destructive operations (INT-013) belongs to the operation itself: e.g. a removed site kept for 30 days before it's deleted.

### The confirming page

The page is the person's only view of what they sign: their browser and authenticator show the site, not the operation. `IntentSummaries` gives the page:

- **the summary**, from your translations: the message whose ID is the operation's name, with its parameters, e.g. `'acme.site.remove' => 'Remove the site {site}'`. The verifier's own operations have theirs in YorvoID's catalogue.
- **every parameter, in full**, as the text the summary uses, so the sentence and the list can't differ.

An operation without a summary isn't summarised, and neither is one with a parameter longer than 200 characters (`IntentSummaries::DISPLAY_LIMIT`): what a page can't show in full can't be signed safely. Pass an ID instead of a document.

### Adding the person's passkey to the verifier

`Passkeys::verifierKey($passkey, 'Ana\'s YubiKey')` gives the parameters of the verifier's "add key" operation for one of the principal's registered passkeys: prepare it at the verifier, have one of its keys sign it, or, for the first key, the passkey itself with the bootstrap secret.

## What signing doesn't stop

Browsers and authenticators show the person only the site's name, not what the challenge means. A compromised console could show "back up site" and ask for a signature of "remove site", **while the person is confirming something** (T-INT-3). It's narrowed by: the verifier running only listed operations with checked parameters; a notification after every signed operation, through a channel the console doesn't control; delays and undo windows for destructive operations; and asking for a signature only in response to the person's own action. See the [threat model](../security/threat-model.md).
