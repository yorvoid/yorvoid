# Signed intents

Some operations are too dangerous to run because a web page asked: removing a site, adding an owner, changing keys. With **signed intents**, such an operation runs only after an owner's signing passkey signed exactly that operation, on a page the web console doesn't serve, and the signature is checked where the operation runs, by a **verifier** that trusts nothing the console says. A stolen session, a forged request, someone writing to the database, or a compromised console can ask for an operation, but can't get it signed.

The verifier is the `yorvoid/intent-verifier` package. It stands alone: no framework, no database layer, none of YorvoID's other packages, so it can run in a small privileged process (in Quire Server, the process that runs as root). It keeps its own keys and its own state, and serves its own **signing pages** at a **signing origin** of its own, e.g. `https://sign.example.com`.

```
browser                    console (web)                   privileged side: verifier + signing pages
   │ "remove site jane" ──────►│── operation ─────────────────►│ prepare(): check the policy, issue a nonce,
   │                           │◄──────────────────── nonce ───│ keep the intent
   │◄── redirect to https://sign.example.com/intents/{nonce} ──│
   │── GET the signing page ──────────────────────────────────►│ the operation, from the verifier's own copy
   │── navigator.credentials.get() (sign.example.com's passkey)│
   │── POST the signature ────────────────────────────────────►│ verify(): its own keys → run the operation
   │◄── redirect back to the console ──────────────────────────│ → notify the owners
```

How the console asks the verifier for a nonce, and learns what became of it (a queue, a socket), is your application's choice: the verifier only needs the operation. [Sign operations with a Symfony console](../how-to/signed-operations.md) puts both sides together.

## Why a signing origin of its own

Browsers and authenticators show the person only the site's name, never what a challenge means. If the passkeys that sign operations were the console's sign-in passkeys, a compromised console could pass an operation's challenge off as an ordinary sign-in, and the owner's next sign-in would sign it. So signing has:

- **its own relying party and origin**, e.g. `sign.example.com`, served by the privileged side, which the console is neither at nor under: its pages can't ask for signatures with those passkeys at all;
- **its own passkeys**, registered there, not the console's;
- **its own pages**, which show the operation from the verifier's copy, in full: the console can't show one thing and have another signed.

`VerifierConfig` takes the console's origins and refuses a signing relying party the console is at or under.

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
        audience: 'acme-verifier:host-1',              // this verifier's name: a signature for another doesn't work here
        relyingPartyId: 'sign.example.com',           // the signing passkeys' relying party
        origins: ['https://sign.example.com'],        // where the signing pages are
        consoleOrigins: ['https://console.example.com'],
    ),
    new OperationPolicy([
        new Operation('acme.site.remove', ['site' => ParameterType::String]),
        new Operation('acme.site.list', signed: false), // runs unsigned, and says so
    ]),
    new FileVerifierStore('/var/lib/acme-verifier/state.json'),
    new FileVerifierLog('/var/lib/acme-verifier/log.jsonl'),
    $notifier,                                         // see "Telling the owners"
);
```

- **The policy lists every operation** the verifier runs, with its parameters and their types (strings, integers, booleans), and whether it needs a signature. An operation that isn't listed isn't run, and one with other parameters isn't prepared (INT-008). A string holds only what a person can see on the signing page: no control or format characters (zero-width, bidirectional), no private-use or unassigned ones, no line breaks. Key labels are held to the same rule.
- **The state file** holds the keys, the changes to them waiting, the prepared intents and registrations, and the bootstrap secret's hash. Each change holds an exclusive lock, so processes take turns, and it survives restarts: a nonce works once, also across them (INT-009). It must be a regular file, not a link, owned by the verifier's user and readable by no one else (`chmod 600`); otherwise the verifier refuses it. So must the log. At most 1,000 intents and registrations wait at once (`Verifier::MAX_WAITING`): expired ones make room, and another is refused (`Refusal::TooManyWaiting`).
- **Restoring the state file from a backup** brings back intents that were signed since. Before it takes an intent, the verifier asks the log whether its nonce was used (`VerifierLog::usedNonce()`), so none is signed twice. Restore the state, never the log: the log must go on from where it was. A log of your own implements `usedNonce()` too.
- **The log** records what was prepared, registered, verified and refused, and every change to the keys, as JSON lines in a hash chain: `isIntact()` tells whether an entry was changed or reordered (INT-010). Appending reads only its last line, and `isIntact()` a line at a time. `rotate($archive, $now)` moves the entries to an archive and starts the file again, linked to the archive's last entry: rotate it, e.g. monthly, and keep the archives. Someone who can write the file can still rewrite it whole; keep a copy elsewhere if that matters. The console keeps its side in YorvoID's security log.

## The signing pages

`SigningPages` serves the signing origin, with no framework: route every request for it to `handle()`, and send back what it answers.

```php
use YorvoID\IntentVerifier\SigningPages;
use YorvoID\IntentVerifier\VerifiedIntent;

$pages = new SigningPages(
    $verifier,
    run: static function (VerifiedIntent $verified) use ($sites): void {
        $sites->remove($verified->intent->parameters['site']);   // exactly the operation signed
    },
    returnTo: 'https://console.example.com/signed',
    summary: static fn (Intent $intent): ?string => $summaries->summarize($intent, 'en')->text,
);

$response = $pages->handle($method, $path, $_POST);   // status, headers, body
```

- `/intents/{nonce}` shows the operation: the summary you give, the operation's name, **every parameter in full**, who it's for and until when. Signing it runs it through `run`, then the browser goes back to `returnTo` with the nonce and `outcome` (`ran` or `refused`) in the query, for a page to show. What really happened, the console learns from the verifier.
- `/keys` lists the signing keys and the changes waiting, to remove a key or cancel a change; `/keys/add` and `/keys/bootstrap` register a new signing passkey there.
- `/browser/*.js` is YorvoID's browser client, which the package ships: its `forms.js` runs the passkey ceremonies, as on the console's flow pages.
- Every answer forbids caching and framing, runs scripts only from the signing origin, and lets forms post only to the signing origin and back to the console.

### Your own look and language

The pages are Twig templates in the package's `templates/` directory, every value escaped, in the `yorvoid_signing` namespace. To brand or translate them, pass a Twig environment whose loader looks in your directory first; a page you don't replace stays the package's:

```php
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

$loader = new FilesystemLoader();
$loader->addPath('/etc/acme-verifier/templates', 'yorvoid_signing');   // e.g. your layout.html.twig
$loader->addPath(SigningPages::TEMPLATES, 'yorvoid_signing');
$loader->addPath(SigningPages::TEMPLATES, 'yorvoid_signing_package'); // to extend a page you replace

$pages = new SigningPages($verifier, $run, $returnTo, $summary, twig: new Environment($loader, ['autoescape' => 'html']));
```

- `layout.html.twig` is every page's frame, with the `lang`, `title` and `body` blocks; extend `@yorvoid_signing_package/layout.html.twig` to change only some.
- `intent.html.twig`, `keys.html.twig`, `naming.html.twig`, `registration.html.twig` and `message.html.twig` are the pages. `message.html.twig` gets the refusal, when there was one, to put it in other words.
- Keep the ceremony forms' `data-yorvoid-*` attributes, their `response` field and the script: the browser client needs them. Keep auto-escaping on: the pages show what the console asked for.
- `intent.html.twig` must show the summary, and every parameter's name and value, as text a person reads. The verifier checks each page before showing it: if a value became markup (`|raw`) or something hides it (`hidden`, `aria-hidden`, an inline `display: none`), it shows "That didn't work" instead of asking for a signature. It can't see your stylesheets: don't hide them there either.

## Signing an operation

```php
// The console, through your channel to the privileged side:
$prepared = $verifier->prepare('acme.site.remove', ['site' => 'jane']);

// The console records the request, then sends the person to sign it:
$signing->request($session, $prepared->intent, $origin);
return new RedirectResponse(SigningPages::url($config, $prepared));
```

- **The intent** is the operation in canonical form (RFC 8785): action, parameters, audience, nonce, issued-at, expires-at (INT-001). The challenge is its domain-separated SHA-256 hash (INT-002), so one signature means one operation (T-INT-5).
- **The verifier keeps the intent** it prepared, under its nonce, and the signing page shows it from there: nobody can change the operation after it was prepared.
- **A nonce works once**, signed or not: a refused attempt uses it up. Unsigned intents expire after 5 minutes (configurable); `purge()` forgets them. A nonce the verifier didn't make (not 24 base64url characters) is refused and logged by a short hash of it, whatever its size.
- **The answer** must be a `webauthn.get` assertion from a signing origin exactly as configured, byte for byte, and not from a frame: what the WebAuthn library leaves unchecked, the verifier checks itself.
- **User verification is required**, checked before anything else; the signature, origin, relying party and signature counter are checked by web-auth/webauthn-lib, against the verifier's own keys (INT-004).
- **The algorithm** of the key that signed is recorded (INT-015).
- A refusal throws `IntentRefused`, with `$refusal` saying why; the signing page says it in words.

Without the signing pages, `prepared($nonce)` gives an intent waiting to be signed, with its WebAuthn options, and `verify($nonce, $assertion)` checks the answer: build your own page at the signing origin the same way.

## The verifier's keys

The verifier accepts signatures only from passkeys of its own relying party, registered at its signing origin (INT-005, INT-006):

- **Adding a key** starts with its registration: `prepareRegistration($label)` gives the creation options, and `finishRegistration($id, $attestation)` checks the authenticator's answer and prepares the "add key" intent from it. One of the verifier's keys signs it (`addKey()`). Nobody can hand the verifier a public key of their own: `prepare()` refuses an "add key".
- **Removing a key** is `yorvoid.verifier.remove_key`, signed (`removeKey()`). The last key can't be removed.
- **Every change waits** (INT-013, INT-017): it takes effect after `keyChangeDelay`, a day by default, and the owners are told when it's asked for. Until then any key can cancel it (`yorvoid.verifier.cancel_key_change`, `cancelKeyChange()`), a key being removed still signs, and one being added doesn't yet. `applyKeyChanges()` applies due changes; every call does it first, and you can run it on a schedule. `keyChanges()` lists those waiting.
- **The first key** comes with a **bootstrap secret**: the host's root creates it (`createBootstrapSecret()`, e.g. from a console command), valid for an hour by default, and the owner types it on the signing page, never into the console. The new passkey signs its own addition (`prepareRegistration($label, bootstrap: true)`, then `bootstrap($secret, $nonce, $assertion)`). The first key is added at once; the secret works once.
- **If every key is lost**, the host's root creates a new bootstrap secret: the way back in (INT-016). With keys still there, the new key waits like any change, so their owners can cancel it.

## Telling the owners

The verifier's `VerifierNotifier` hears of every operation verified and every change to the keys: asked for, cancelled, taken effect, and every bootstrap secret created. Implement it on the privileged side, e.g. an email the privileged process sends itself: a channel the console doesn't control (INT-012). If it throws, the call fails, and an operation whose notice couldn't be sent doesn't run. `CollectingNotifier` keeps notices in memory, for tests or to send them afterwards.

## The console's side

The console is where the person is signed in. `IntentSigning`, in the `yorvoid/passkeys` package, records each request and what became of it in YorvoID's security log (INT-010):

```php
use YorvoID\Passkeys\Intent\IntentSigning;

$signing = new IntentSigning($securityLog, $transactions, $throttle);

$signing->request($session, $prepared->intent, $origin);   // before sending the person to sign

// When the privileged side reports back:
$signing->ran($session, $verified->intent, $verified->key->id(), $origin);   // or refused($session, $intent, $refusal, $origin)
```

- Ask for a signature only in response to the person's own action. Requests are limited per principal: 20 an hour by default (T-INT-3).
- `ran()` announces the operation with `SignedOperationRan`; `SignedOperationNotification` emails the person about it, in their language, with "This wasn't me" ([notifications](notifications.md)). The verifier's own notifier tells them too, independently of the console.
- A delay or an undo window for destructive operations (INT-013) belongs to the operation itself: e.g. a removed site kept for 30 days before it's deleted.

### Summaries

`IntentSummaries` gives an operation's sentence from your translations: the message whose ID is the operation's name, with its parameters, e.g. `'acme.site.remove' => 'Remove the site {site}'`. Pass it to `SigningPages` as `summary`, if the privileged side has a translator; the page shows every parameter in full either way. An operation with a parameter longer than 200 characters isn't summarised (`IntentSummaries::DISPLAY_LIMIT`): pass an ID instead of a document.

Summaries are ICU MessageFormat, whichever catalogue they're in: with Symfony's translator, `messages.en.yaml` works as well as `messages+intl-icu.en.yaml`, though Symfony itself reads only the second as ICU. A summary with a placeholder left unfilled, e.g. a parameter the operation didn't get, isn't shown: `IntegratorError`.

## What signing doesn't stop

- **Someone who controls the privileged side** controls the verifier, its pages and its keys: it's the root of trust.
- **A person who signs what they didn't mean to**: the signing page shows the operation in full, but people click. Delays and undo windows for destructive operations, and the notices, are there for that.
- **A signing origin served by the console** would undo all of this: serve it from the privileged side, under a name the console's server can't answer for.

See the [threat model](../security/threat-model.md).
