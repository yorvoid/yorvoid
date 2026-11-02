<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * The signing pages, served by the privileged side at the signing origin (e.g. https://sign.example.com),
 * never by the console: the only place an owner's signing passkeys are used, and the only view of what
 * they sign. Each page is built from the verifier's own copy of the intent, so the console can neither
 * show one thing and have another signed, nor ask for a signature itself (T-INT-3).
 *
 * - /intents/{nonce}: the operation, every parameter in full, and the host's summary; signing it runs it
 *   (the $run closure), then the browser goes back to the console. Key changes, their cancellations and
 *   bootstraps are signed here too.
 * - /keys: the verifier's keys and the changes waiting, with removing and cancelling; /keys/add and
 *   /keys/bootstrap register a passkey at the signing origin, then have its addition signed. The
 *   bootstrap secret is typed here, and only here.
 *
 * It needs no framework: give handle() the request's method, path (after the pages' prefix) and form
 * fields, and send what it answers. Each answer forbids caching and framing, allows forms to post only
 * here, and runs only its own script.
 *
 * @api
 */
final readonly class SigningPages
{
    /**
     * @param \Closure(VerifiedIntent): void       $run      Runs an operation the verifier verified: the host's own
     * @param string                               $returnTo Where to send the browser after an operation, on the console, e.g.
     *                                                       "https://console.example.com/signed": it gets "nonce" and "outcome"
     *                                                       ("ran" or "refused") in the query, to show a page; what really
     *                                                       happened, the console learns from the verifier
     * @param \Closure(Intent): (string|null)|null $summary  The host's sentence for an operation, e.g. "Remove the site jane"
     */
    public function __construct(
        private Verifier $verifier,
        private \Closure $run,
        private string $returnTo,
        private ?\Closure $summary = null,
    ) {
    }

    /**
     * Where to send the person to sign an intent: the signing origin, and the intent's page.
     */
    public static function url(VerifierConfig $config, PreparedIntent $prepared): string
    {
        return rtrim($config->origins[0], '/').'/intents/'.rawurlencode($prepared->intent->nonce);
    }

    /**
     * @param string               $method E.g. "GET"
     * @param string               $path   E.g. "/intents/abc"
     * @param array<string, mixed> $form   The POST's form fields
     */
    public function handle(string $method, string $path, array $form = []): SigningResponse
    {
        $field = static fn (string $name): string => \is_string($form[$name] ?? null) ? $form[$name] : '';
        $required = static fn (string $name): string => '' !== $field($name) ? $field($name) : throw new ConfigurationError(\sprintf('The form has no %s.', $name));

        try {
            return match (true) {
                'GET' === $method && 1 === preg_match('#^/intents/([A-Za-z0-9_-]{1,64})$#D', $path, $nonce) => $this->intent($nonce[1]),
                'POST' === $method && 1 === preg_match('#^/intents/([A-Za-z0-9_-]{1,64})$#D', $path, $nonce) => $this->sign($nonce[1], $field('credential'), $field('secret')),
                'GET' === $method && '/keys' === $path => $this->keys(),
                'POST' === $method && '/keys/remove' === $path => $this->redirect('/intents/'.$this->verifier->prepare(OperationPolicy::REMOVE_KEY, ['credential_id' => $required('key')])->intent->nonce),
                'POST' === $method && '/keys/cancel' === $path => $this->redirect('/intents/'.$this->verifier->prepare(OperationPolicy::CANCEL_KEY_CHANGE, ['change' => $required('change')])->intent->nonce),
                'GET' === $method && \in_array($path, ['/keys/add', '/keys/bootstrap'], true) => $this->naming('/keys/bootstrap' === $path),
                'POST' === $method && \in_array($path, ['/keys/add', '/keys/bootstrap'], true) => $this->registration($this->verifier->prepareRegistration($field('label'), '/keys/bootstrap' === $path)),
                'POST' === $method && 1 === preg_match('#^/keys/registrations/([A-Za-z0-9_-]{1,64})$#D', $path, $id) => $this->redirect('/intents/'.$this->verifier->finishRegistration($id[1], $field('credential'))->intent->nonce),
                default => $this->page(404, 'Not found', '<p>There\'s no such page.</p>'),
            };
        } catch (IntentRefused $refusal) {
            return $this->page(422, 'That didn\'t work', \sprintf('<p>%s</p><p><a href="/keys">Back to the keys</a></p>', self::e(self::reason($refusal->refusal))));
        } catch (ConfigurationError $error) {
            return $this->page(400, 'That didn\'t work', \sprintf('<p>%s</p>', self::e($error->getMessage())));
        }
    }

    private function intent(string $nonce): SigningResponse
    {
        $prepared = $this->verifier->prepared($nonce);

        if (null === $prepared) {
            return $this->page(404, 'This request has expired', '<p>Nothing waits to be signed here: it was signed, refused, or it expired. Ask again from where you started.</p>');
        }

        $intent = $prepared->intent;
        $rows = '';

        foreach ($intent->parameters as $name => $value) {
            $rows .= \sprintf('<tr><th scope="row">%s</th><td><code>%s</code></td></tr>', self::e($name), self::e(\is_string($value) ? $value : json_encode($value, \JSON_THROW_ON_ERROR)));
        }

        $summary = $this->summarize($intent);
        $secret = $prepared->bootstrap ? '<p><label for="secret">Bootstrap secret</label><br><input id="secret" name="secret" type="password" autocomplete="off" required></p>' : '';

        return $this->page(200, 'Confirm with your passkey', \sprintf(
            '%s<p>Operation: <code>%s</code></p><table>%s</table><p>For <code>%s</code>, until %s UTC.</p><form method="post" data-ceremony="get" data-options="%s">%s<input type="hidden" name="credential"><button>Sign with your passkey</button></form><p data-problem role="alert" hidden></p>',
            null === $summary ? '' : \sprintf('<p><strong>%s</strong></p>', self::e($summary)),
            self::e($intent->action),
            $rows,
            self::e($intent->audience),
            $intent->expiresAt->format('Y-m-d H:i'),
            self::e($prepared->options),
            $secret,
        ));
    }

    private function sign(string $nonce, string $credential, string $secret): SigningResponse
    {
        $prepared = $this->verifier->prepared($nonce);
        $action = $prepared->intent->action ?? '';

        if (null !== $prepared && $prepared->bootstrap) {
            $this->verifier->bootstrap($secret, $nonce, $credential);

            return $this->redirect('/keys');
        }

        if (str_starts_with($action, 'yorvoid.verifier.')) {
            match ($action) {
                OperationPolicy::ADD_KEY => $this->verifier->addKey($nonce, $credential),
                OperationPolicy::REMOVE_KEY => $this->verifier->removeKey($nonce, $credential),
                default => $this->verifier->cancelKeyChange($nonce, $credential),
            };

            return $this->redirect('/keys');
        }

        try {
            $verified = $this->verifier->verify($nonce, $credential);
        } catch (IntentRefused $refusal) {
            return $this->back($nonce, 'refused');
        }

        ($this->run)($verified);

        return $this->back($nonce, 'ran');
    }

    private function keys(): SigningResponse
    {
        $keys = '';

        foreach ($this->verifier->keys() as $key) {
            $keys .= \sprintf('<li>%s, added %s UTC <form method="post" action="/keys/remove"><input type="hidden" name="key" value="%s"><button>Remove</button></form></li>', self::e($key->label), $key->addedAt->format('Y-m-d H:i'), self::e($key->id()));
        }

        $changes = '';

        foreach ($this->verifier->keyChanges() as $change) {
            $changes .= \sprintf('<li>%s %s, from %s UTC <form method="post" action="/keys/cancel"><input type="hidden" name="change" value="%s"><button>Cancel</button></form></li>', KeyChange::ADD === $change->kind ? 'Adding' : 'Removing', self::e($change->key->label), $change->effectiveAt->format('Y-m-d H:i'), self::e($change->id));
        }

        return $this->page(200, 'Signing keys', \sprintf(
            '<h2>Keys</h2><ul>%s</ul><h2>Waiting to take effect</h2>%s<p><a href="/keys/add">Add a passkey</a> · <a href="/keys/bootstrap">Add one with the bootstrap secret</a></p>',
            '' === $keys ? '<li>None yet: add one with the bootstrap secret.</li>' : $keys,
            '' === $changes ? '<p>Nothing.</p>' : '<ul>'.$changes.'</ul>',
        ));
    }

    private function naming(bool $bootstrap): SigningResponse
    {
        return $this->page(200, $bootstrap ? 'Add a passkey with the bootstrap secret' : 'Add a passkey', \sprintf(
            '<form method="post"><p><label for="label">Name it</label><br><input id="label" name="label" maxlength="64" required></p><button>Continue</button></form>%s',
            $bootstrap ? '<p>You\'ll sign its addition with the passkey itself, and type the bootstrap secret the host\'s administrator gave you.</p>' : '<p>One of the passkeys here signs its addition. It takes effect after a waiting time, and every owner is told.</p>',
        ));
    }

    private function registration(PendingRegistration $registration): SigningResponse
    {
        return $this->page(200, 'Create the passkey', \sprintf(
            '<p>Create a passkey named <strong>%s</strong> for signing.</p><form method="post" action="/keys/registrations/%s" data-ceremony="create" data-options="%s"><input type="hidden" name="credential"><button>Create the passkey</button></form><p data-problem role="alert" hidden></p>',
            self::e($registration->label),
            self::e($registration->id),
            self::e($registration->options),
        ));
    }

    private function summarize(Intent $intent): ?string
    {
        $text = static fn (string $name): string => \is_string($intent->parameters[$name] ?? null) ? $intent->parameters[$name] : '';

        return match ($intent->action) {
            OperationPolicy::ADD_KEY => \sprintf('Allow the passkey "%s" to sign operations', $text('label')),
            OperationPolicy::REMOVE_KEY => \sprintf('Stop the passkey %s from signing operations', $text('credential_id')),
            OperationPolicy::CANCEL_KEY_CHANGE => \sprintf('Cancel the change to the keys %s', $text('change')),
            default => null === $this->summary ? null : ($this->summary)($intent),
        };
    }

    private function back(string $nonce, string $outcome): SigningResponse
    {
        $separator = str_contains($this->returnTo, '?') ? '&' : '?';

        return new SigningResponse(303, ['Location' => $this->returnTo.$separator.http_build_query(['nonce' => $nonce, 'outcome' => $outcome])] + $this->headers(''), '');
    }

    private function redirect(string $path): SigningResponse
    {
        return new SigningResponse(303, ['Location' => $path] + $this->headers(''), '');
    }

    private function page(int $status, string $title, string $body): SigningResponse
    {
        $nonce = Base64Url::encode(random_bytes(18));

        return new SigningResponse($status, $this->headers($nonce) + ['Content-Type' => 'text/html; charset=utf-8'], \sprintf(
            '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>%1$s</title></head><body><main><h1>%1$s</h1>%2$s</main><script nonce="%3$s">%4$s</script></body></html>',
            self::e($title),
            $body,
            $nonce,
            self::SCRIPT,
        ));
    }

    /**
     * The headers of every answer. Forms post only here, and the redirect after an operation goes to the
     * console's origin, which browsers check against form-action too.
     *
     * @return array<string, string>
     */
    private function headers(string $nonce): array
    {
        $scheme = parse_url($this->returnTo, \PHP_URL_SCHEME);
        $host = parse_url($this->returnTo, \PHP_URL_HOST);
        $port = parse_url($this->returnTo, \PHP_URL_PORT);
        $console = \is_string($scheme) && \is_string($host) ? \sprintf(' %s://%s%s', $scheme, $host, \is_int($port) ? ':'.$port : '') : '';

        return [
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => \sprintf("default-src 'none'; script-src 'nonce-%s'; form-action 'self'%s; frame-ancestors 'none'; base-uri 'none'", $nonce, $console),
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    /**
     * The words the pages use for a refusal: for a host that builds its own pages.
     */
    public static function reason(Refusal $refusal): string
    {
        return match ($refusal) {
            Refusal::UnknownIntent, Refusal::InvalidRegistration => 'This request has expired, or was used already. Start again.',
            Refusal::UnknownKey => 'That passkey can\'t sign here. Use one of the signing passkeys.',
            Refusal::UserNotVerified => 'Your authenticator didn\'t confirm it\'s you. Try again, with its PIN or biometrics.',
            Refusal::InvalidBootstrapSecret => 'That bootstrap secret doesn\'t work: it\'s wrong, used, or expired.',
            Refusal::LastKey => 'That\'s the last key: add another before removing it.',
            Refusal::InvalidKey => 'That passkey is here already.',
            default => 'The signature didn\'t work. Start again.',
        };
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }

    /** Runs a page's ceremony: the form's WebAuthn options in, the credential as JSON out, then submits */
    private const string SCRIPT = <<<'JS'
        const bytes = (text) => Uint8Array.from(atob(text.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
        const text = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        const json = (credential) => ({
          id: credential.id, rawId: text(credential.rawId), type: credential.type,
          response: Object.fromEntries(['clientDataJSON', 'authenticatorData', 'signature', 'userHandle', 'attestationObject']
            .filter((name) => credential.response[name]).map((name) => [name, text(credential.response[name])])),
        });
        for (const form of document.querySelectorAll('form[data-ceremony]')) {
          form.addEventListener('submit', async (event) => {
            if (form.credential.value) return;
            event.preventDefault();
            const options = JSON.parse(form.dataset.options);
            options.challenge = bytes(options.challenge);
            for (const list of [options.allowCredentials, options.excludeCredentials]) for (const item of list ?? []) item.id = bytes(item.id);
            if (options.user) options.user.id = bytes(options.user.id);
            try {
              const credential = 'create' === form.dataset.ceremony ? await navigator.credentials.create({ publicKey: options }) : await navigator.credentials.get({ publicKey: options });
              form.credential.value = JSON.stringify(json(credential));
              form.submit();
            } catch (error) {
              const problem = document.querySelector('[data-problem]');
              problem.textContent = 'Your passkey didn\'t answer. Try again.';
              problem.hidden = false;
            }
          });
        }
        JS;
}
