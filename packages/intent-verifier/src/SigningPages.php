<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

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
 * - /browser/{name}.js: YorvoID's browser client, which runs the passkey ceremonies (forms.js).
 *
 * It needs no framework: give handle() the request's method, path (after the pages' prefix) and form
 * fields, and send what it answers. Each answer forbids caching and framing, allows forms to post only
 * here, and runs only the browser client from here.
 *
 * The pages are Twig templates, in the "yorvoid_signing" namespace (TEMPLATES): to brand or translate them,
 * give a Twig environment of your own whose loader has your templates first, then these.
 *
 * @api
 */
final readonly class SigningPages
{
    /** The pages' templates, for a Twig loader's "yorvoid_signing" namespace */
    public const string TEMPLATES = __DIR__.'/../templates';

    /** The browser client's scripts, as its build ships them */
    private const string SCRIPTS = __DIR__.'/../public/browser';

    private Environment $twig;

    /**
     * @param \Closure(VerifiedIntent): void       $run      Runs an operation the verifier verified: the host's own
     * @param string                               $returnTo Where to send the browser after an operation, on the console, e.g.
     *                                                       "https://console.example.com/signed": it gets "nonce" and "outcome"
     *                                                       ("ran" or "refused") in the query, to show a page; what really
     *                                                       happened, the console learns from the verifier
     * @param \Closure(Intent): (string|null)|null $summary  The host's sentence for an operation, e.g. "Remove the site jane"
     * @param Environment|null                     $twig     Renders the pages: by default these templates, escaped, with nothing cached
     */
    public function __construct(
        private Verifier $verifier,
        private \Closure $run,
        private string $returnTo,
        private ?\Closure $summary = null,
        ?Environment $twig = null,
    ) {
        $this->twig = $twig ?? self::twig();
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
                'POST' === $method && 1 === preg_match('#^/intents/([A-Za-z0-9_-]{1,64})$#D', $path, $nonce) => $this->sign($nonce[1], $field('response'), $field('secret')),
                'GET' === $method && '/keys' === $path => $this->keys(),
                'POST' === $method && '/keys/remove' === $path => $this->redirect('/intents/'.$this->verifier->prepare(OperationPolicy::REMOVE_KEY, ['credential_id' => $required('key')])->intent->nonce),
                'POST' === $method && '/keys/cancel' === $path => $this->redirect('/intents/'.$this->verifier->prepare(OperationPolicy::CANCEL_KEY_CHANGE, ['change' => $required('change')])->intent->nonce),
                'GET' === $method && \in_array($path, ['/keys/add', '/keys/bootstrap'], true) => $this->page(200, 'naming', ['bootstrap' => '/keys/bootstrap' === $path]),
                'POST' === $method && \in_array($path, ['/keys/add', '/keys/bootstrap'], true) => $this->page(200, 'registration', ['registration' => $this->verifier->prepareRegistration($field('label'), '/keys/bootstrap' === $path)]),
                'POST' === $method && 1 === preg_match('#^/keys/registrations/([A-Za-z0-9_-]{1,64})$#D', $path, $id) => $this->redirect('/intents/'.$this->verifier->finishRegistration($id[1], $field('response'))->intent->nonce),
                'GET' === $method && 1 === preg_match('#^/browser/([a-z0-9]{1,32}\.js)$#D', $path, $script) && is_file(self::SCRIPTS.'/'.$script[1]) => $this->script($script[1]),
                default => $this->message(404, 'Not found', 'There\'s no such page.'),
            };
        } catch (IntentRefused $refusal) {
            return $this->message(422, 'That didn\'t work', self::reason($refusal->refusal), $refusal->refusal);
        } catch (ConfigurationError $error) {
            return $this->message(400, 'That didn\'t work', $error->getMessage());
        }
    }

    private function intent(string $nonce): SigningResponse
    {
        $prepared = $this->verifier->prepared($nonce);

        if (null === $prepared) {
            return $this->message(404, 'This request has expired', 'Nothing waits to be signed here: it was signed, refused, or it expired. Ask again from where you started.');
        }

        $parameters = array_map(static fn (mixed $value): string => \is_string($value) ? $value : json_encode($value, \JSON_THROW_ON_ERROR), $prepared->intent->parameters);
        $summary = $this->summarize($prepared->intent);
        $page = $this->page(200, 'intent', [
            'intent' => $prepared->intent,
            'parameters' => $parameters,
            'summary' => $summary,
            'options' => $prepared->options,
            'bootstrap' => $prepared->bootstrap,
            // Which passkeys can sign: one elsewhere, e.g. on a phone, isn't offered, and the browser only waits.
            'keys' => array_map(static fn (Key $key): string => $key->label, $this->verifier->keys()),
        ]);

        // The page is the person's only view of what they sign: a template that hides any of it, or lets
        // a value's text become markup, doesn't get to ask for a signature.
        $visible = self::visibleText($page->body);
        $sentence = self::spaced($summary ?? '');
        // Each parameter's name and value on its own too, not only where the summary says it.
        $missing = array_filter(
            [...(null === $summary ? [] : [$summary]), ...array_keys($parameters), ...$parameters],
            static fn (string $text): bool => '' !== $text && substr_count($visible, self::spaced($text)) < ($text === $summary ? 1 : substr_count($sentence, self::spaced($text)) + 1),
        );

        if ([] !== $missing) {
            throw new ConfigurationError(\sprintf('The signing page doesn\'t show "%s" as text a person can read: show the summary, and every parameter\'s name and value, as escaped text ({{ value }}, never |raw) that nothing hides.', implode('", "', $missing)));
        }

        return $page;
    }

    /**
     * The text of the elements a browser displays, read as text: markup a value let in isn't text.
     */
    private static function visibleText(string $html): string
    {
        $document = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR);
        $hidden = 'ancestor-or-self::*[self::script or self::style or self::template or self::noscript or self::head or @hidden or @inert or @aria-hidden="true" or contains(translate(@style, " ", ""), "display:none") or contains(translate(@style, " ", ""), "visibility:hidden")]';
        $texts = [];

        foreach (new \Dom\XPath($document)->query(\sprintf('//text()[not(%s)]', $hidden)) as $node) {
            \assert($node instanceof \Dom\Text);
            $texts[] = $node->data;
        }

        return self::spaced(implode(' ', $texts));
    }

    private static function spaced(string $text): string
    {
        return (string) preg_replace('/\s+/u', ' ', $text);
    }

    private function sign(string $nonce, string $response, string $secret): SigningResponse
    {
        $prepared = $this->verifier->prepared($nonce);
        $action = $prepared->intent->action ?? '';

        if (null !== $prepared && $prepared->bootstrap) {
            $this->verifier->bootstrap($secret, $nonce, $response);

            return $this->redirect('/keys');
        }

        if (str_starts_with($action, 'yorvoid.verifier.')) {
            match ($action) {
                OperationPolicy::ADD_KEY => $this->verifier->addKey($nonce, $response),
                OperationPolicy::REMOVE_KEY => $this->verifier->removeKey($nonce, $response),
                default => $this->verifier->cancelKeyChange($nonce, $response),
            };

            return $this->redirect('/keys');
        }

        try {
            $verified = $this->verifier->verify($nonce, $response);
        } catch (IntentRefused $refusal) {
            return $this->back($nonce, 'refused');
        }

        ($this->run)($verified);

        return $this->back($nonce, 'ran');
    }

    private function keys(): SigningResponse
    {
        return $this->page(200, 'keys', [
            'keys' => $this->verifier->keys(),
            'changes' => array_map(static fn (KeyChange $change): array => [
                'id' => $change->id,
                'adding' => KeyChange::ADD === $change->kind,
                'label' => $change->key->label,
                'effectiveAt' => $change->effectiveAt,
            ], $this->verifier->keyChanges()),
        ]);
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

        return new SigningResponse(303, ['Location' => $this->returnTo.$separator.http_build_query(['nonce' => $nonce, 'outcome' => $outcome])] + $this->headers(), '');
    }

    private function redirect(string $path): SigningResponse
    {
        return new SigningResponse(303, ['Location' => $path] + $this->headers(), '');
    }

    private function message(int $status, string $title, string $message, ?Refusal $refusal = null): SigningResponse
    {
        return $this->page($status, 'message', ['title' => $title, 'message' => $message, 'refusal' => $refusal, 'back' => null !== $refusal]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function page(int $status, string $template, array $context): SigningResponse
    {
        return new SigningResponse($status, $this->headers() + ['Content-Type' => 'text/html; charset=utf-8'], $this->twig->render(\sprintf('@yorvoid_signing/%s.html.twig', $template), ['script' => '/browser/forms.js', ...$context]));
    }

    private function script(string $name): SigningResponse
    {
        $script = file_get_contents(self::SCRIPTS.'/'.$name);
        \assert(\is_string($script));

        return new SigningResponse(200, $this->headers() + ['Content-Type' => 'text/javascript; charset=utf-8'], $script);
    }

    /**
     * The headers of every answer. Scripts come only from here, forms post only here, and the redirect
     * after an operation goes to the console's origin, which browsers check against form-action too.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        $scheme = parse_url($this->returnTo, \PHP_URL_SCHEME);
        $host = parse_url($this->returnTo, \PHP_URL_HOST);
        $port = parse_url($this->returnTo, \PHP_URL_PORT);
        $console = \is_string($scheme) && \is_string($host) ? \sprintf(' %s://%s%s', $scheme, $host, \is_int($port) ? ':'.$port : '') : '';

        return [
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => \sprintf("default-src 'none'; script-src 'self'; form-action 'self'%s; frame-ancestors 'none'; base-uri 'none'", $console),
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    /**
     * The pages' own templates, every value escaped for HTML, nothing cached: the privileged side writes
     * no compiled templates anywhere.
     */
    private static function twig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(self::TEMPLATES, 'yorvoid_signing');

        return new Environment($loader, ['autoescape' => 'html', 'strict_variables' => true, 'cache' => false]);
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
            Refusal::TooManyWaiting => 'Too many requests wait to be signed. Try again in a few minutes.',
            default => 'The signature didn\'t work. Start again.',
        };
    }
}
