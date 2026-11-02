<?php

declare(strict_types=1);

namespace YorvoID\Tests\IntentVerifier;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use YorvoID\IntentVerifier\CollectingNotifier;
use YorvoID\IntentVerifier\Intent;
use YorvoID\IntentVerifier\Operation;
use YorvoID\IntentVerifier\OperationPolicy;
use YorvoID\IntentVerifier\ParameterType;
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
        self::assertStringContainsString('None yet: add one with the bootstrap secret.', $page->body);
        self::assertSame(200, $this->pages->handle('GET', '/keys/bootstrap')->status);
        $created = $this->pages->handle('POST', '/keys/bootstrap', ['label' => 'Ana\'s <YubiKey>']);
        self::assertStringContainsString('Create a passkey named <strong>Ana&apos;s &lt;YubiKey&gt;</strong>', $created->body, 'The label isn\'t escaped.');
        [$action, $options] = self::form($created, 'create');

        $signing = $this->pages->handle('POST', $action, ['credential' => $this->owner->register($options)]);
        self::assertSame(303, $signing->status);
        $page = $this->pages->handle('GET', $signing->headers['Location']);
        self::assertStringContainsString('Allow the passkey &quot;Ana&apos;s &lt;YubiKey&gt;&quot; to sign operations', $page->body);
        self::assertStringContainsString('name="secret" type="password"', $page->body, 'The bootstrap secret is asked for on the signing page.');
        [, $options] = self::form($page, 'get');

        $wrong = $this->pages->handle('POST', $signing->headers['Location'], ['credential' => $this->owner->signIn($options), 'secret' => 'wrong']);
        self::assertSame(422, $wrong->status);
        self::assertStringContainsString('That bootstrap secret doesn&apos;t work', $wrong->body);

        // The nonce is used up: once more from the registration.
        $created = $this->pages->handle('POST', '/keys/bootstrap', ['label' => 'Laptop']);
        [$action, $options] = self::form($created, 'create');
        $location = $this->pages->handle('POST', $action, ['credential' => $this->owner->register($options)])->headers['Location'];
        [, $options] = self::form($this->pages->handle('GET', $location), 'get');
        $done = $this->pages->handle('POST', $location, ['credential' => $this->owner->signIn($options, 1), 'secret' => $secret]);

        self::assertSame([303, '/keys'], [$done->status, $done->headers['Location']]);
        self::assertSame(['Laptop'], array_map(static fn ($key): string => $key->label, $this->verifier->keys()));
        self::assertStringContainsString('Laptop, added 2026-10-28 18:00 UTC', $this->pages->handle('GET', '/keys')->body);
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
        self::assertStringContainsString('<strong>Remove the site &lt;b&gt;jane&lt;/b&gt;</strong>', $page->body);
        self::assertStringContainsString('<th scope="row">site</th><td><code>&lt;b&gt;jane&lt;/b&gt;</code></td>', $page->body, 'Every parameter, in full, escaped.');
        self::assertStringContainsString('Operation: <code>acme.site.remove</code>', $page->body);
        self::assertStringNotContainsString('name="secret"', $page->body);
        self::assertSame('no-store', $page->headers['Cache-Control']);
        self::assertMatchesRegularExpression("/^default-src 'none'; script-src 'nonce-[A-Za-z0-9_-]{24}'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'$/D", $page->headers['Content-Security-Policy']);
        self::assertStringContainsString('<script nonce="'.self::nonce($page).'">', $page->body, 'The script\'s nonce isn\'t the policy\'s.');
        self::assertNotSame(self::nonce($page), self::nonce($this->pages->handle('GET', $path)), 'Two pages had one nonce.');
        self::assertSame(['no-referrer', 'nosniff'], [$page->headers['Referrer-Policy'], $page->headers['X-Content-Type-Options']]);
        [, $options] = self::form($page, 'get');

        $done = $this->pages->handle('POST', $path, ['credential' => $this->owner->signIn($options)]);

        self::assertSame(303, $done->status);
        self::assertSame('https://acme.example/signed?nonce='.$prepared->intent->nonce.'&outcome=ran', $done->headers['Location']);
        self::assertCount(1, $this->ran);
        $ran = $this->ran[0];
        \assert($ran instanceof VerifiedIntent);
        self::assertSame(['site' => '<b>jane</b>'], $ran->intent->parameters);

        $again = $this->pages->handle('GET', $path);
        self::assertSame(404, $again->status);
        self::assertStringContainsString('This request has expired', $again->body);
        self::assertStringEndsWith('&outcome=refused', $this->pages->handle('POST', $path, ['credential' => $this->owner->signIn($options)])->headers['Location']);
        self::assertCount(1, $this->ran, 'An operation ran twice.');
    }

    public function testASignatureByAnotherPasskeyRunsNothing(): void
    {
        $this->bootstrap();
        $stranger = new VirtualAuthenticator('https://sign.acme.example');
        $stranger->create();
        $prepared = $this->verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $path = '/intents/'.$prepared->intent->nonce;

        $done = $this->pages->handle('POST', $path, ['credential' => $stranger->signIn($prepared->options)]);

        self::assertStringEndsWith('&outcome=refused', $done->headers['Location']);
        self::assertCount(0, $this->ran);
    }

    public function testKeysAreAddedRemovedAndChangesCancelledThroughThePages(): void
    {
        $this->bootstrap();
        $first = $this->verifier->keys()[0];

        self::assertStringContainsString('One of the passkeys here signs its addition.', $this->pages->handle('GET', '/keys/add')->body);
        [$action, $options] = self::form($this->pages->handle('POST', '/keys/add', ['label' => 'Laptop']), 'create');
        $location = $this->pages->handle('POST', $action, ['credential' => $this->owner->register($options)])->headers['Location'];
        [, $options] = self::form($this->pages->handle('GET', $location), 'get');
        self::assertSame('/keys', $this->pages->handle('POST', $location, ['credential' => $this->owner->signIn($options)])->headers['Location']);

        $keys = $this->pages->handle('GET', '/keys')->body;
        self::assertStringContainsString('Adding Laptop, from 2026-10-29 18:00 UTC', $keys);
        $change = $this->verifier->keyChanges()[0];

        $cancel = $this->pages->handle('POST', '/keys/cancel', ['change' => $change->id]);
        $page = $this->pages->handle('GET', $cancel->headers['Location']);
        self::assertStringContainsString('Cancel the change to the keys '.$change->id, $page->body);
        [, $options] = self::form($page, 'get');
        $this->pages->handle('POST', $cancel->headers['Location'], ['credential' => $this->owner->signIn($options)]);
        self::assertSame([], $this->verifier->keyChanges());

        $remove = $this->pages->handle('POST', '/keys/remove', ['key' => $first->id()]);
        $page = $this->pages->handle('GET', $remove->headers['Location']);
        self::assertStringContainsString('Stop the passkey '.$first->id().' from signing operations', $page->body);
        [, $options] = self::form($page, 'get');
        $last = $this->pages->handle('POST', $remove->headers['Location'], ['credential' => $this->owner->signIn($options)]);
        self::assertSame(422, $last->status);
        self::assertStringContainsString('That&apos;s the last key', $last->body);
    }

    public function testMistakesGetAPageThatSaysSo(): void
    {
        self::assertSame(404, $this->pages->handle('GET', '/elsewhere')->status);
        self::assertSame(404, $this->pages->handle('DELETE', '/keys')->status);
        self::assertSame(404, $this->pages->handle('GET', '/intents/not/a/nonce')->status);
        $empty = $this->pages->handle('POST', '/keys/add', ['label' => ' ']);
        self::assertSame(400, $empty->status);
        self::assertStringContainsString('A key&apos;s label has 1 to 64 characters.', $empty->body);
        $unknown = $this->pages->handle('POST', '/keys/registrations/unknown', ['credential' => '{}']);
        self::assertSame(422, $unknown->status);
        self::assertStringContainsString('This request has expired, or was used already.', $unknown->body);
        self::assertSame(400, $this->pages->handle('POST', '/keys/remove', ['key' => ['x']])->status, 'A key that isn\'t text was taken.');
        self::assertStringContainsString('The form has no change.', $this->pages->handle('POST', '/keys/cancel')->body);
    }

    private static function nonce(SigningResponse $page): string
    {
        preg_match("/'nonce-([A-Za-z0-9_-]+)'/", $page->headers['Content-Security-Policy'], $nonce);

        return $nonce[1] ?? '';
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
        self::assertSame(1, preg_match(\sprintf('#<form method="post"(?: action="([^"]*)")? data-ceremony="%s" data-options="([^"]*)">#', $ceremony), $page->body, $form), $page->body);

        return [html_entity_decode($form[1], \ENT_QUOTES | \ENT_HTML5), html_entity_decode($form[2], \ENT_QUOTES | \ENT_HTML5)];
    }
}
