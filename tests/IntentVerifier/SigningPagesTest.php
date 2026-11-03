<?php

declare(strict_types=1);

namespace YorvoID\Tests\IntentVerifier;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use YorvoID\IntentVerifier\CollectingNotifier;
use YorvoID\IntentVerifier\Intent;
use YorvoID\IntentVerifier\Operation;
use YorvoID\IntentVerifier\OperationPolicy;
use YorvoID\IntentVerifier\ParameterType;
use YorvoID\IntentVerifier\Refusal;
use YorvoID\IntentVerifier\SigningPages;
use YorvoID\IntentVerifier\SigningResponse;
use YorvoID\IntentVerifier\Storage\InMemoryVerifierLog;
use YorvoID\IntentVerifier\Storage\InMemoryVerifierStore;
use YorvoID\IntentVerifier\VerifiedIntent;
use YorvoID\IntentVerifier\Verifier;
use YorvoID\IntentVerifier\VerifierConfig;
use YorvoID\VirtualAuthenticator\VirtualAuthenticator;

/**
 * The signing pages, as a browser at the signing origin uses them.
 */
final class SigningPagesTest extends TestCase
{
    private MockClock $clock;

    private Verifier $verifier;

    private SigningPages $pages;

    private VirtualAuthenticator $owner;

    /** @var \ArrayObject<int, VerifiedIntent> */
    private \ArrayObject $ran;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-28 18:00:00 UTC');
        $this->ran = new \ArrayObject();
        $this->verifier = new Verifier(self::config(), new OperationPolicy([new Operation('acme.site.remove', ['site' => ParameterType::String])]), new InMemoryVerifierStore(), new InMemoryVerifierLog(), new CollectingNotifier(), $this->clock);
        $this->pages = new SigningPages($this->verifier, function (VerifiedIntent $verified): void {
            $this->ran[] = $verified;
        }, 'https://acme.example/signed', static fn (Intent $intent): string => \sprintf('Remove the site %s', \is_string($intent->parameters['site']) ? $intent->parameters['site'] : ''));
        $this->owner = new VirtualAuthenticator('https://sign.acme.example');
    }

    public function testTheFirstKeyIsAddedWithTheBootstrapSecretTypedOnThePage(): void
    {
        $secret = $this->verifier->createBootstrapSecret();

        $page = $this->pages->handle('GET', '/keys');
        self::assertStringContainsString('None yet: add one with the bootstrap secret.', self::text($page));
        self::assertSame(200, $this->pages->handle('GET', '/keys/bootstrap')->status);
        $created = $this->pages->handle('POST', '/keys/bootstrap', ['label' => 'Ana\'s <YubiKey>']);
        self::assertSame('Ana\'s <YubiKey>', self::document($created)->querySelector('[data-label]')?->textContent);
        self::assertNull(self::document($created)->querySelector('yubikey'), 'The label isn\'t escaped.');
        [$action, $options] = self::form($created, 'create');

        $signing = $this->pages->handle('POST', $action, ['response' => $this->owner->register($options)]);
        self::assertSame(303, $signing->status);
        $page = $this->pages->handle('GET', $signing->headers['Location']);
        self::assertSame('Allow the passkey "Ana\'s <YubiKey>" to sign operations', self::document($page)->querySelector('[data-summary]')?->textContent);
        self::assertSame('password', self::document($page)->querySelector('form[data-yorvoid-ceremony] input[name="secret"]')?->getAttribute('type'), 'The bootstrap secret is asked for on the signing page, in the signed form.');
        [, $options] = self::form($page, 'get');

        $wrong = $this->pages->handle('POST', $signing->headers['Location'], ['response' => $this->owner->signIn($options), 'secret' => 'wrong']);
        self::assertSame(422, $wrong->status);
        self::assertStringContainsString('That bootstrap secret doesn\'t work', self::text($wrong));

        // The nonce is used up: once more from the registration.
        $created = $this->pages->handle('POST', '/keys/bootstrap', ['label' => 'Laptop']);
        [$action, $options] = self::form($created, 'create');
        $location = $this->pages->handle('POST', $action, ['response' => $this->owner->register($options)])->headers['Location'];
        [, $options] = self::form($this->pages->handle('GET', $location), 'get');
        $done = $this->pages->handle('POST', $location, ['response' => $this->owner->signIn($options, 1), 'secret' => $secret]);

        self::assertSame([303, '/keys'], [$done->status, $done->headers['Location']]);
        self::assertSame(['Laptop'], array_map(static fn ($key): string => $key->label, $this->verifier->keys()));
        self::assertStringContainsString('Laptop, added 2026-10-28 18:00 UTC', self::text($this->pages->handle('GET', '/keys')));
    }

    public function testAnOperationIsShownInFullSignedAndRunThenTheBrowserGoesBack(): void
    {
        $this->bootstrap();
        $prepared = $this->verifier->prepare('acme.site.remove', ['site' => '<b>jane</b>']);
        $url = SigningPages::url(self::config(), $prepared);
        self::assertSame('https://sign.acme.example/intents/'.$prepared->intent->nonce, $url);
        $path = (string) parse_url($url, \PHP_URL_PATH);

        $page = $this->pages->handle('GET', $path);

        self::assertSame(200, $page->status);
        $document = self::document($page);
        self::assertSame('Remove the site <b>jane</b>', $document->querySelector('[data-summary]')?->textContent);
        self::assertSame([['site', '<b>jane</b>']], self::rows($page), 'Every parameter, in full.');
        self::assertNull($document->querySelector('main b'), 'A parameter isn\'t escaped.');
        self::assertSame('acme.site.remove', $document->querySelector('[data-action]')?->textContent);
        self::assertNull($document->querySelector('input[name="secret"]'));
        self::assertSame('no-store', $page->headers['Cache-Control']);
        // The console's origin too: browsers check the redirect after a form's post against form-action.
        self::assertSame("default-src 'none'; script-src 'self'; form-action 'self' https://acme.example; frame-ancestors 'none'; base-uri 'none'", $page->headers['Content-Security-Policy']);
        self::assertSame([['module', '/browser/forms.js', '']], array_map(static fn (\Dom\Element $script): array => [$script->getAttribute('type'), $script->getAttribute('src'), $script->textContent], iterator_to_array($document->querySelectorAll('script'))), 'The page runs more than the browser client.');
        self::assertSame(['no-referrer', 'nosniff'], [$page->headers['Referrer-Policy'], $page->headers['X-Content-Type-Options']]);
        [, $options] = self::form($page, 'get');

        $done = $this->pages->handle('POST', $path, ['response' => $this->owner->signIn($options)]);

        self::assertSame(303, $done->status);
        self::assertSame('https://acme.example/signed?nonce='.$prepared->intent->nonce.'&outcome=ran', $done->headers['Location']);
        self::assertCount(1, $this->ran);
        $ran = $this->ran[0];
        \assert($ran instanceof VerifiedIntent);
        self::assertSame(['site' => '<b>jane</b>'], $ran->intent->parameters);

        $again = $this->pages->handle('GET', $path);
        self::assertSame(404, $again->status);
        self::assertSame('This request has expired', self::document($again)->querySelector('h1')?->textContent);
        self::assertStringEndsWith('&outcome=refused', $this->pages->handle('POST', $path, ['response' => $this->owner->signIn($options)])->headers['Location']);
        self::assertCount(1, $this->ran, 'An operation ran twice.');
    }

    public function testASignatureByAnotherPasskeyRunsNothing(): void
    {
        $this->bootstrap();
        $stranger = new VirtualAuthenticator('https://sign.acme.example');
        $stranger->create();
        $prepared = $this->verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $path = '/intents/'.$prepared->intent->nonce;

        $done = $this->pages->handle('POST', $path, ['response' => $stranger->signIn($prepared->options)]);

        self::assertStringEndsWith('&outcome=refused', $done->headers['Location']);
        self::assertCount(0, $this->ran);
    }

    public function testKeysAreAddedRemovedAndChangesCancelledThroughThePages(): void
    {
        $this->bootstrap();
        $first = $this->verifier->keys()[0];

        self::assertStringContainsString('One of the passkeys here signs its addition.', self::text($this->pages->handle('GET', '/keys/add')));
        [$action, $options] = self::form($this->pages->handle('POST', '/keys/add', ['label' => 'Laptop']), 'create');
        $location = $this->pages->handle('POST', $action, ['response' => $this->owner->register($options)])->headers['Location'];
        [, $options] = self::form($this->pages->handle('GET', $location), 'get');
        self::assertSame('/keys', $this->pages->handle('POST', $location, ['response' => $this->owner->signIn($options)])->headers['Location']);

        self::assertSame(['Adding Laptop, from 2026-10-29 18:00 UTC Cancel'], self::items($this->pages->handle('GET', '/keys'), '[data-changes] li'));
        $change = $this->verifier->keyChanges()[0];

        $cancel = $this->pages->handle('POST', '/keys/cancel', ['change' => $change->id]);
        $page = $this->pages->handle('GET', $cancel->headers['Location']);
        self::assertSame('Cancel the change to the keys '.$change->id, self::document($page)->querySelector('[data-summary]')?->textContent);
        [, $options] = self::form($page, 'get');
        $this->pages->handle('POST', $cancel->headers['Location'], ['response' => $this->owner->signIn($options)]);
        self::assertSame([], $this->verifier->keyChanges());

        $remove = $this->pages->handle('POST', '/keys/remove', ['key' => $first->id()]);
        $page = $this->pages->handle('GET', $remove->headers['Location']);
        self::assertSame('Stop the passkey '.$first->id().' from signing operations', self::document($page)->querySelector('[data-summary]')?->textContent);
        [, $options] = self::form($page, 'get');
        $last = $this->pages->handle('POST', $remove->headers['Location'], ['response' => $this->owner->signIn($options)]);
        self::assertSame(422, $last->status);
        self::assertSame('That\'s the last key: add another before removing it.', self::document($last)->querySelector('[data-message]')?->textContent);
        self::assertSame('/keys', self::document($last)->querySelector('main a')?->getAttribute('href'), 'A refusal has no way back to the keys.');
    }

    public function testMistakesGetAPageThatSaysSo(): void
    {
        self::assertSame(404, $this->pages->handle('GET', '/elsewhere')->status);
        self::assertSame(404, $this->pages->handle('DELETE', '/keys')->status);
        self::assertSame(404, $this->pages->handle('GET', '/intents/not/a/nonce')->status);
        $empty = $this->pages->handle('POST', '/keys/add', ['label' => ' ']);
        self::assertSame(400, $empty->status);
        self::assertSame('A key\'s label has 1 to 64 characters.', self::document($empty)->querySelector('[data-message]')?->textContent);
        self::assertNull(self::document($empty)->querySelector('main a'), 'A mistake in the form offered the keys.');
        $unknown = $this->pages->handle('POST', '/keys/registrations/unknown', ['response' => '{}']);
        self::assertSame(422, $unknown->status);
        self::assertStringContainsString('This request has expired, or was used already.', self::text($unknown));
        self::assertSame(400, $this->pages->handle('POST', '/keys/remove', ['key' => ['x']])->status, 'A key that isn\'t text was taken.');
        self::assertStringContainsString('The form has no change.', self::text($this->pages->handle('POST', '/keys/cancel')));
        self::assertSame('There\'s no such page.', self::document($this->pages->handle('GET', '/elsewhere'))->querySelector('[data-message]')?->textContent);
    }

    public function testOnlyItsOwnPathsAnswer(): void
    {
        $this->bootstrap();
        $nonce = $this->verifier->prepare('acme.site.remove', ['site' => 'jane'])->intent->nonce;
        $registration = $this->verifier->prepareRegistration('Laptop')->id;

        foreach (['/x/intents/'.$nonce, '/intents/'.$nonce.'/x', "/intents/{$nonce}\n"] as $path) {
            self::assertSame([404, 404], [$this->pages->handle('GET', $path)->status, $this->pages->handle('POST', $path, ['response' => '{}'])->status], $path);
        }

        foreach (['/x/keys/registrations/'.$registration, '/keys/registrations/'.$registration.'/x', "/keys/registrations/{$registration}\n"] as $path) {
            self::assertSame(404, $this->pages->handle('POST', $path, ['response' => '{}'])->status, $path);
        }

        self::assertSame(200, $this->pages->handle('GET', '/intents/'.$nonce)->status, 'The intent was used up.');
    }

    public function testEveryParameterKeyAndChangeIsListed(): void
    {
        $verifier = new Verifier(self::config(), new OperationPolicy([new Operation('acme.site.move', ['site' => ParameterType::String, 'to' => ParameterType::String, 'keep' => ParameterType::Boolean])]), new InMemoryVerifierStore(), new InMemoryVerifierLog(), new CollectingNotifier(), $this->clock);
        $pages = new SigningPages($verifier, static fn (VerifiedIntent $verified): null => null, 'https://acme.example/signed');
        $secret = $verifier->createBootstrapSecret();
        $registration = $verifier->prepareRegistration('Phone', true);
        $prepared = $verifier->finishRegistration($registration->id, $this->owner->register($registration->options));
        $verifier->bootstrap($secret, $prepared->intent->nonce, $this->owner->signIn($prepared->options));

        $page = $pages->handle('GET', '/intents/'.$verifier->prepare('acme.site.move', ['site' => 'jane', 'to' => 'joe', 'keep' => false])->intent->nonce);
        self::assertSame([['site', 'jane'], ['to', 'joe'], ['keep', 'false']], self::rows($page));
        self::assertNull(self::document($page)->querySelector('[data-summary]'), 'An operation without a summary got one.');

        foreach (['Laptop', 'Tablet'] as $label) {
            $registration = $verifier->prepareRegistration($label);
            $prepared = $verifier->finishRegistration($registration->id, $this->owner->register($registration->options));
            $verifier->addKey($prepared->intent->nonce, $this->owner->signIn($prepared->options));
        }

        $keys = $pages->handle('GET', '/keys');
        self::assertSame([200, 'text/html; charset=utf-8'], [$keys->status, $keys->headers['Content-Type']]);
        self::assertSame(['Adding Laptop, from 2026-10-29 18:00 UTC Cancel', 'Adding Tablet, from 2026-10-29 18:00 UTC Cancel'], self::items($keys, '[data-changes] li'));
        $changes = $verifier->keyChanges();
        self::assertSame(array_map(static fn ($change): string => $change->id, $changes), array_map(static fn (\Dom\Element $field): string => (string) $field->getAttribute('value'), iterator_to_array(self::document($keys)->querySelectorAll('form[action="/keys/cancel"] input[name="change"]'))));
        $this->clock->sleep(86400);
        $keys = $pages->handle('GET', '/keys');
        self::assertSame(['Phone, added 2026-10-28 18:00 UTC Remove', 'Laptop, added 2026-10-28 18:00 UTC Remove', 'Tablet, added 2026-10-28 18:00 UTC Remove'], self::items($keys, '[data-keys] li'));
        self::assertSame(array_map(static fn ($key): string => $key->id(), $verifier->keys()), array_map(static fn (\Dom\Element $field): string => (string) $field->getAttribute('value'), iterator_to_array(self::document($keys)->querySelectorAll('form[action="/keys/remove"] input[name="key"]'))));
        self::assertSame('Nothing.', self::document($keys)->querySelector('[data-changes]')?->textContent);

        $add = $pages->handle('GET', '/keys/add');
        self::assertSame(200, $add->status);
        self::assertSame(['Add a passkey', 'Add a passkey'], [self::document($add)->querySelector('h1')?->textContent, self::document($add)->querySelector('title')?->textContent]);
        self::assertSame('Add a passkey with the bootstrap secret', self::document($pages->handle('GET', '/keys/bootstrap'))->querySelector('h1')?->textContent);
        self::assertSame(200, $pages->handle('POST', '/keys/add', ['label' => 'Watch'])->status);
    }

    public function testTheWayBackKeepsTheConsolesQueryAndPort(): void
    {
        $pages = new SigningPages($this->verifier, static fn (VerifiedIntent $verified): null => null, 'http://localhost:8000/signed?from=sites');
        $this->bootstrap();
        $prepared = $this->verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $path = '/intents/'.$prepared->intent->nonce;

        self::assertStringContainsString("form-action 'self' http://localhost:8000;", $pages->handle('GET', $path)->headers['Content-Security-Policy']);
        self::assertSame('http://localhost:8000/signed?from=sites&nonce='.$prepared->intent->nonce.'&outcome=ran', $pages->handle('POST', $path, ['response' => $this->owner->signIn($prepared->options)])->headers['Location']);

        $relative = new SigningPages($this->verifier, static fn (VerifiedIntent $verified): null => null, '/signed');
        self::assertStringContainsString("form-action 'self';", $relative->handle('GET', '/keys')->headers['Content-Security-Policy'], 'A relative way back allowed another origin.');
        $hostless = new SigningPages($this->verifier, static fn (VerifiedIntent $verified): null => null, 'https:/signed');
        self::assertStringContainsString("form-action 'self';", $hostless->handle('GET', '/keys')->headers['Content-Security-Policy'], 'A way back without a host allowed one.');
        self::assertSame('https://sign.acme.example/intents/'.$prepared->intent->nonce, SigningPages::url(new VerifierConfig('acme', 'sign.acme.example', ['https://sign.acme.example/'], ['https://acme.example']), $prepared), 'A trailing slash doubled.');
    }

    public function testEveryRefusalHasWords(): void
    {
        $words = [];

        foreach (Refusal::cases() as $refusal) {
            $words[$refusal->value] = SigningPages::reason($refusal);
        }

        self::assertSame([
            'unknown_intent' => 'This request has expired, or was used already. Start again.',
            'wrong_operation' => 'The signature didn\'t work. Start again.',
            'invalid_signature' => 'The signature didn\'t work. Start again.',
            'unknown_key' => 'That passkey can\'t sign here. Use one of the signing passkeys.',
            'user_not_verified' => 'Your authenticator didn\'t confirm it\'s you. Try again, with its PIN or biometrics.',
            'invalid_key' => 'That passkey is here already.',
            'last_key' => 'That\'s the last key: add another before removing it.',
            'invalid_registration' => 'This request has expired, or was used already. Start again.',
            'unknown_key_change' => 'The signature didn\'t work. Start again.',
            'invalid_bootstrap_secret' => 'That bootstrap secret doesn\'t work: it\'s wrong, used, or expired.',
        ], $words);
    }

    public function testItServesTheBrowserClientAndNothingElse(): void
    {
        $script = $this->pages->handle('GET', '/browser/forms.js');

        self::assertSame([200, 'text/javascript; charset=utf-8', 'no-store', 'nosniff'], [$script->status, $script->headers['Content-Type'], $script->headers['Cache-Control'], $script->headers['X-Content-Type-Options']]);
        // The copy the package ships; CI's browser job checks it's the client as built.
        self::assertStringEqualsFile(\dirname(__DIR__, 2).'/packages/intent-verifier/public/browser/forms.js', $script->body, 'It isn\'t the browser client the package ships.');
        self::assertSame(200, $this->pages->handle('GET', '/browser/webauthn.js')->status, 'forms.js imports it.');

        foreach (['/browser/missing.js', '/browser/../composer.json', '/browser/forms.js/x', '/browser/Forms.js', "/browser/forms.js\n", '/x/browser/forms.js', '/browser/forms.ts'] as $path) {
            self::assertSame(404, $this->pages->handle('GET', $path)->status, $path);
        }

        self::assertSame(404, $this->pages->handle('POST', '/browser/forms.js')->status);
    }

    public function testAHostRendersThePagesWithItsOwnTemplates(): void
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/templates', 'yorvoid_signing');
        $loader->addPath(SigningPages::TEMPLATES, 'yorvoid_signing');
        // To extend a page it replaces.
        $loader->addPath(SigningPages::TEMPLATES, 'yorvoid_signing_package');
        $pages = new SigningPages($this->verifier, static fn (VerifiedIntent $verified): null => null, 'https://acme.example/signed', twig: new Environment($loader, ['autoescape' => 'html', 'strict_variables' => true]));

        $page = $pages->handle('GET', '/keys/remove/none');

        self::assertSame('de', self::document($page)->documentElement?->getAttribute('lang'));
        self::assertSame('Seite nicht gefunden', self::document($page)->querySelector('[data-message]')?->textContent);
        self::assertSame('Signing keys', self::document($pages->handle('GET', '/keys'))->querySelector('h1')?->textContent, 'A page the host left alone isn\'t the package\'s.');
    }

    public function testAPageThatHidesWhatIsSignedIsntShown(): void
    {
        $prepared = $this->verifier->prepare('acme.site.remove', ['site' => '<img src=x onerror=alert(1)>jane']);
        $path = '/intents/'.$prepared->intent->nonce;

        // YorvoID's page shows the markup as text; an empty value has nothing to show.
        self::assertStringContainsString('Remove the site <img src=x onerror=alert(1)>jane', self::text($this->pages->handle('GET', $path)));
        self::assertSame(200, $this->pages->handle('GET', '/intents/'.$this->verifier->prepare('acme.site.remove', ['site' => ''])->intent->nonce)->status);

        $overrides = [
            'the summary as markup' => '<p>{{ summary|raw }}</p>{% for name, value in parameters %}<p>{{ name }} {{ value }}</p>{% endfor %}',
            'a parameter as markup' => '<p>{{ summary }}</p>{% for name, value in parameters %}<p>{{ name }} {{ value|raw }}</p>{% endfor %}',
            'the parameters hidden' => '<p>{{ summary }}</p><div hidden>{% for name, value in parameters %}{{ name }} {{ value }}{% endfor %}</div>',
            'the parameters out of sight' => '<p>{{ summary }}</p><div style="display: none">{% for name, value in parameters %}{{ name }} {{ value }}{% endfor %}</div>',
            'the parameters for screen readers only' => '<p>{{ summary }}</p><div aria-hidden="true">{% for name, value in parameters %}{{ name }} {{ value }}{% endfor %}</div>',
            'no parameters' => '<p>{{ summary }}</p>',
            'no summary' => '{% for name, value in parameters %}<p>{{ name }} {{ value }}</p>{% endfor %}',
        ];

        foreach ($overrides as $what => $body) {
            $package = new FilesystemLoader();
            $package->addPath(SigningPages::TEMPLATES, 'yorvoid_signing');
            $loader = new ChainLoader([new ArrayLoader(['@yorvoid_signing/intent.html.twig' => '<!DOCTYPE html><html><body>'.$body.'<form data-yorvoid-ceremony="get"></form></body></html>']), $package]);
            $pages = new SigningPages($this->verifier, static fn (VerifiedIntent $verified): null => null, 'https://acme.example/signed', static fn (Intent $intent): string => \sprintf('Remove the site %s', \is_string($intent->parameters['site']) ? $intent->parameters['site'] : ''), new Environment($loader, ['autoescape' => 'html']));

            $page = $pages->handle('GET', $path);
            self::assertSame(400, $page->status, $what);
            self::assertStringContainsString('The signing page doesn\'t show "', self::text($page), $what);
            self::assertNull(self::document($page)->querySelector('[data-yorvoid-ceremony]'), \sprintf('A page with %s asked for a signature.', $what));
        }
    }

    public function testEveryPartOfWhatIsSignedIsShownOnItsOwn(): void
    {
        $verifier = new Verifier(self::config(), new OperationPolicy([new Operation('acme.site.move', ['site' => ParameterType::String, 'to' => ParameterType::String])]), new InMemoryVerifierStore(), new InMemoryVerifierLog(), new CollectingNotifier(), $this->clock);
        $path = '/intents/'.$verifier->prepare('acme.site.move', ['site' => 'jane', 'to' => 'eu-west'])->intent->nonce;
        $row = '<p>{{ name }}: {{ value }}</p>';
        $pages = static function (string $body) use ($verifier): SigningPages {
            $package = new FilesystemLoader();
            $package->addPath(SigningPages::TEMPLATES, 'yorvoid_signing');
            $loader = new ChainLoader([new ArrayLoader(['@yorvoid_signing/intent.html.twig' => '<!DOCTYPE html><html><body>'.$body.'<form data-yorvoid-ceremony="get"></form></body></html>']), $package]);

            // The summary mentions the site, not where it goes.
            return new SigningPages($verifier, static fn (VerifiedIntent $verified): null => null, 'https://acme.example/signed', static fn (Intent $intent): string => \sprintf('Move the site %s', \is_string($intent->parameters['site']) ? $intent->parameters['site'] : ''), new Environment($loader, ['autoescape' => 'html']));
        };

        self::assertSame(200, $pages('<p>{{ summary }}</p>{% for name, value in parameters %}'.$row.'{% endfor %}')->handle('GET', $path)->status);

        foreach ([
            'no summary, the parameters twice' => '{% for name, value in parameters %}'.$row.$row.'{% endfor %}',
            'the values without their names' => '<p>{{ summary }}</p>{% for name, value in parameters %}<p>{{ value }}</p>{% endfor %}',
            'the first parameter only' => '<p>{{ summary }}</p>{% for name, value in parameters %}{% if loop.first %}'.$row.'{% endif %}{% endfor %}',
            'the last value without its name' => '<p>{{ summary }}</p>{% for name, value in parameters %}<p>{% if loop.first %}{{ name }}: {% endif %}{{ value }}</p>{% endfor %}',
            'the last name without its value' => '<p>{{ summary }}</p>{% for name, value in parameters %}<p>{{ name }}{% if loop.first %}: {{ value }}{% endif %}</p>{% endfor %}',
        ] as $what => $body) {
            self::assertSame(400, $pages($body)->handle('GET', $path)->status, $what);
        }
    }

    private static function document(SigningResponse $page): \Dom\HTMLDocument
    {
        return \Dom\HTMLDocument::createFromString($page->body, \LIBXML_NOERROR);
    }

    /**
     * The page's text, its spaces collapsed.
     */
    private static function text(SigningResponse $page): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) self::document($page)->body?->textContent));
    }

    /**
     * @return list<string> The elements' texts, their spaces collapsed
     */
    private static function items(SigningResponse $page, string $selector): array
    {
        return array_values(array_map(static fn (\Dom\Element $item): string => trim((string) preg_replace('/\s+/', ' ', (string) $item->textContent)), iterator_to_array(self::document($page)->querySelectorAll($selector))));
    }

    /**
     * @return list<array{string, string}> The intent's parameters, as the page lists them
     */
    private static function rows(SigningResponse $page): array
    {
        return array_values(array_map(static fn (\Dom\Element $row): array => [(string) $row->querySelector('th')?->textContent, (string) $row->querySelector('td code')?->textContent], iterator_to_array(self::document($page)->querySelectorAll('[data-parameters] tr'))));
    }

    private function bootstrap(): void
    {
        $secret = $this->verifier->createBootstrapSecret();
        $registration = $this->verifier->prepareRegistration('Phone', true);
        $prepared = $this->verifier->finishRegistration($registration->id, $this->owner->register($registration->options));
        $this->verifier->bootstrap($secret, $prepared->intent->nonce, $this->owner->signIn($prepared->options));
    }

    private static function config(): VerifierConfig
    {
        return new VerifierConfig('acme-verifier', 'sign.acme.example', ['https://sign.acme.example'], ['https://acme.example']);
    }

    /**
     * The page's ceremony form: where it posts, and its WebAuthn options.
     *
     * @return array{string, string}
     */
    private static function form(SigningResponse $page, string $ceremony): array
    {
        $form = self::document($page)->querySelector(\sprintf('form[method="post"][data-yorvoid-ceremony="%s"]', $ceremony));
        self::assertNotNull($form, $page->body);
        self::assertNotNull($form->querySelector('input[type="hidden"][name="response"]'), 'The browser client has nowhere to put the answer.');
        self::assertNotNull($form->querySelector('[data-yorvoid-error]'), 'The browser client has nowhere to say what went wrong.');

        return [(string) $form->getAttribute('action'), (string) $form->getAttribute('data-yorvoid-options')];
    }
}
