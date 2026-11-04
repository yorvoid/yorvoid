# Sign operations with a Symfony console

This page puts [signed intents](../concepts/signed-intents.md) together end to end: a Symfony application, the **console**, where people are signed in and ask for an operation, and a **privileged side**, which runs the operation only once an owner's signing passkey signed it. Read the concept page first for why it's built this way; here is the code.

The example removes a site. Names in `Acme\` are yours.

## The two sides, and what goes between them

| | Console (`https://console.example.com`) | Privileged side |
|---|---|---|
| Runs | Your Symfony application with YorvoID's bundle | A small PHP process, no framework, as the user that may run the operation |
| Serves | The pages people use | The signing pages at `https://sign.example.com`, and an internal endpoint only the console reaches |
| Knows | Who is signed in; YorvoID's security log | The verifier's keys, state and log |

The console never runs the operation, and the privileged side never trusts the console: it prepares what the console asks for, if its policy lists it, and runs it only with a valid signature. Between them, the console needs two things: to have an operation **prepared**, and to learn **what became of it**. Here that's a small JSON endpoint on the privileged side, bound to an address only the console can reach (e.g. `127.0.0.1:8081`, or a private network). A queue or a Unix socket does as well.

## The privileged side

One front controller serves both the signing origin and the internal endpoint. Run it with PHP-FPM or `php -S`, as its own user, behind a web server that answers for `sign.example.com` over HTTPS, and on the private address for the console.

```php
<?php
// /srv/acme-verifier/public/index.php

declare(strict_types=1);

use Acme\Sites;
use YorvoID\IntentVerifier\IntentRefused;
use YorvoID\IntentVerifier\Operation;
use YorvoID\IntentVerifier\OperationPolicy;
use YorvoID\IntentVerifier\ParameterType;
use YorvoID\IntentVerifier\SigningPages;
use YorvoID\IntentVerifier\Storage\FileVerifierLog;
use YorvoID\IntentVerifier\Storage\FileVerifierStore;
use YorvoID\IntentVerifier\Verifier;
use YorvoID\IntentVerifier\VerifierConfig;
use YorvoID\IntentVerifier\VerifiedIntent;

require dirname(__DIR__).'/vendor/autoload.php';

$config = new VerifierConfig(
    audience: 'acme-verifier:host-1',
    relyingPartyId: 'sign.example.com',
    origins: ['https://sign.example.com'],
    consoleOrigins: ['https://console.example.com'],
);
$verifier = new Verifier(
    $config,
    new OperationPolicy([new Operation('acme.site.remove', ['site' => ParameterType::String])]),
    new FileVerifierStore('/var/lib/acme-verifier/state.json'),
    new FileVerifierLog('/var/lib/acme-verifier/log.jsonl'),
    new Acme\EmailNotifier(),   // your VerifierNotifier: tells the owners, through a channel the console doesn't control
);

// What became of each intent, for the console to ask: written here, never taken from the browser.
$outcomes = '/var/lib/acme-verifier/outcomes';
$record = static function (string $nonce, array $outcome) use ($outcomes): void {
    file_put_contents(sprintf('%s/%s.json', $outcomes, $nonce), json_encode($outcome, JSON_THROW_ON_ERROR));
};

$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// The internal endpoint: only on the private address, never on sign.example.com.
if ('127.0.0.1:8081' === $_SERVER['HTTP_HOST']) {
    header('Content-Type: application/json');

    if ('POST' === $method && '/prepare' === $path) {
        $asked = json_decode((string) file_get_contents('php://input'), true, 4, JSON_THROW_ON_ERROR);

        try {
            $prepared = $verifier->prepare($asked['action'], $asked['parameters']);
        } catch (IntentRefused $refused) {
            http_response_code(422);
            echo json_encode(['refusal' => $refused->refusal->value]);

            return;
        }

        $intent = $prepared->intent;
        echo json_encode([
            'intent' => ['action' => $intent->action, 'parameters' => $intent->parameters, 'audience' => $intent->audience, 'nonce' => $intent->nonce, 'issued_at' => $intent->issuedAt->format(DATE_ATOM), 'expires_at' => $intent->expiresAt->format(DATE_ATOM)],
            'url' => SigningPages::url($config, $prepared),
        ], JSON_THROW_ON_ERROR);

        return;
    }

    if ('GET' === $method && 1 === preg_match('#^/outcomes/([A-Za-z0-9_-]{24})$#D', $path, $nonce)) {
        $file = sprintf('%s/%s.json', $outcomes, $nonce[1]);
        echo is_file($file) ? file_get_contents($file) : '{"outcome": null}';

        return;
    }

    http_response_code(404);

    return;
}

// The signing origin: YorvoID's signing pages.
$pages = new SigningPages(
    $verifier,
    run: static function (VerifiedIntent $verified) use ($record): void {
        new Sites()->remove($verified->intent->parameters['site']);   // exactly what was signed
        $record($verified->intent->nonce, ['outcome' => 'ran', 'key' => $verified->key->id()]);
    },
    returnTo: 'https://console.example.com/signed',
);
$response = $pages->handle($method, $path, $_POST);

http_response_code($response->status);

foreach ($response->headers as $name => $value) {
    header($name.': '.$value);
}

echo $response->body;
```

- **The bootstrap secret** for the first signing passkey comes from the host's root, never from the console: a command next to this front controller, e.g. `bin/bootstrap-secret` that prints `$verifier->createBootstrapSecret()`. The owner opens `https://sign.example.com/keys/bootstrap`, types it there, and registers their signing passkey. It works once, for an hour.
- **A refused signature** isn't recorded above: the signing page tells the person, and the intent can't be signed again. The console sees no outcome, and treats one still missing after the intent expires as refused.
- **Files:** `state.json`, `log.jsonl` and `outcomes/` belong to the verifier's user, readable by no one else. Rotate the log, and keep its archives ([signed intents](../concepts/signed-intents.md#setting-up-the-verifier)).

## The console

YorvoID's bundle provides `IntentSigning`, which records each request and its outcome in YorvoID's security log, and limits how often a person asks (`limits.signature_requests`). Add a client for the privileged side:

```php
<?php
// src/Signing/PrivilegedSide.php

namespace Acme\Signing;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use YorvoID\IntentVerifier\Intent;

final readonly class PrivilegedSide
{
    public function __construct(private HttpClientInterface $http) {}

    /**
     * @param array<string, string|int|bool> $parameters
     *
     * @return array{Intent, string} The intent the verifier prepared, and where to sign it
     */
    public function prepare(string $action, array $parameters): array
    {
        $answer = $this->http->request('POST', 'http://127.0.0.1:8081/prepare', ['json' => ['action' => $action, 'parameters' => $parameters], 'headers' => ['Host' => '127.0.0.1:8081']])->toArray();
        $intent = $answer['intent'];

        return [new Intent($intent['action'], $intent['parameters'], $intent['audience'], $intent['nonce'], new \DateTimeImmutable($intent['issued_at']), new \DateTimeImmutable($intent['expires_at'])), $answer['url']];
    }

    /**
     * @return array{outcome: string|null, key?: string} "ran", or null while nothing ran
     */
    public function outcome(string $nonce): array
    {
        return $this->http->request('GET', 'http://127.0.0.1:8081/outcomes/'.rawurlencode($nonce), ['headers' => ['Host' => '127.0.0.1:8081']])->toArray();
    }
}
```

The controller asks for the operation in answer to the person's own action, with a recent proof that it's them, and sends them to sign it. The page they come back to asks the privileged side what happened, rather than believe the query:

```php
<?php
// src/Controller/SitesController.php

namespace Acme\Controller;

use Acme\Signing\PrivilegedSide;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\IntentVerifier\Intent;
use YorvoID\IntentVerifier\Refusal;
use YorvoID\Passkeys\Intent\IntentSigning;
use YorvoID\Symfony\Security\CurrentSession;

final class SitesController
{
    public function __construct(
        private PrivilegedSide $privileged,
        private IntentSigning $signing,
        private CurrentSession $current,
    ) {}

    #[Route('/sites/{site}/remove', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    #[IsCsrfTokenValid('remove-site')]
    public function remove(Request $request, string $site): Response
    {
        $session = $this->current->get() ?? throw new \LogicException('The firewall lets only signed-in people here.');
        [$intent, $url] = $this->privileged->prepare('acme.site.remove', ['site' => $site]);
        $this->signing->request($session, $intent, new Origin(OriginKind::Web, 'acme_site_remove'));

        // Keep what was asked, to tell its outcome when the person comes back.
        $request->getSession()->set('acme.signing.'.$intent->nonce, [$intent->action, $intent->parameters, $intent->audience, $intent->issuedAt->format(DATE_ATOM), $intent->expiresAt->format(DATE_ATOM)]);

        return new RedirectResponse($url);
    }

    #[Route('/signed', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function signed(Request $request): Response
    {
        $nonce = (string) $request->query->get('nonce');
        $asked = $request->getSession()->remove('acme.signing.'.$nonce);
        $session = $this->current->get();

        if (!is_array($asked) || null === $session) {
            return new Response('Nothing of yours waits for a signature.', 404);
        }

        [$action, $parameters, $audience, $issuedAt, $expiresAt] = $asked;
        $intent = new Intent($action, $parameters, $audience, $nonce, new \DateTimeImmutable($issuedAt), new \DateTimeImmutable($expiresAt));
        $outcome = $this->privileged->outcome($nonce);   // the privileged side's word, not the query's
        $origin = new Origin(OriginKind::Web, 'acme_signed');

        if ('ran' === $outcome['outcome']) {
            $this->signing->ran($session, $intent, $outcome['key'] ?? '', $origin);

            return new Response(sprintf('The site %s was removed.', $parameters['site']));
        }

        $this->signing->refused($session, $intent, Refusal::InvalidSignature, $origin);

        return new Response('Nothing was removed: the operation wasn\'t signed.');
    }
}
```

- **`ran()`** announces the operation (`SignedOperationRan`): with notifications on, the person gets an email about it with "This wasn't me". The verifier's notifier tells the owners too, independently of the console.
- **A request** is limited per person: 20 an hour by default. `request()` throws `Throttled` beyond that; answer 429.
- **The console's origin** must be in the verifier's `consoleOrigins`: the signing pages' forms may post back only there.
- **Summaries:** give the signing pages a sentence for each operation by passing `summary:` to `SigningPages`, e.g. with `IntentSummaries` and a translator on the privileged side; the page shows every parameter in full either way ([summaries](../concepts/signed-intents.md#summaries)).

## Check it

- Signing in to the console and removing a site leads to `https://sign.example.com/intents/…`, which shows the operation in full; signing it removes the site and comes back to `/signed`.
- The security log has `yorvoid.intent.request`, then `yorvoid.intent.run` with its outcome; the verifier's log has the intent prepared and verified.
- Changing the query of `/signed` changes nothing: what the console says comes from the privileged side.
