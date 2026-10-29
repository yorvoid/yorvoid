<?php

declare(strict_types=1);

namespace YorvoID\Tests\IntentVerifier;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use YorvoID\IntentVerifier\ConfigurationError;
use YorvoID\IntentVerifier\IntentRefused;
use YorvoID\IntentVerifier\Key;
use YorvoID\IntentVerifier\Operation;
use YorvoID\IntentVerifier\OperationPolicy;
use YorvoID\IntentVerifier\ParameterType;
use YorvoID\IntentVerifier\Refusal;
use YorvoID\IntentVerifier\Storage\FileVerifierStore;
use YorvoID\IntentVerifier\Storage\InMemoryVerifierLog;
use YorvoID\IntentVerifier\Storage\InMemoryVerifierStore;
use YorvoID\IntentVerifier\Verifier;
use YorvoID\IntentVerifier\VerifierConfig;
use YorvoID\IntentVerifier\VerifierStore;
use YorvoID\Tests\Support\VirtualAuthenticator;

/**
 * Signed intents, checked by the verifier with its own keys (INT-001…006, INT-008…010, INT-015, INT-016).
 */
final class VerifierTest extends TestCase
{
    private MockClock $clock;

    private InMemoryVerifierLog $log;

    private VerifierStore $store;

    private VirtualAuthenticator $owner;

    /** @var list<string> */
    private array $files = [];

    /**
     * @return iterable<string, array{string}>
     */
    public static function stores(): iterable
    {
        yield 'in memory' => ['memory'];
        yield 'in a file' => ['file'];
    }

    #[DataProvider('stores')]
    public function testTheFirstKeyComesWithTheBootstrapSecretSignedByItself(string $store): void
    {
        $verifier = $this->verifier($store);
        $secret = $verifier->createBootstrapSecret();
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $secret, '256 random bits.');

        $prepared = $verifier->prepareBootstrap(self::keyOf($this->owner, 0, 'Ana\'s YubiKey'));
        $options = self::json($prepared->options);
        self::assertSame([['type' => 'public-key', 'id' => VirtualAuthenticator::encode($this->owner->credentials[0]['id'])]], $options['allowCredentials'], 'Only the key being added is asked for.');
        self::assertSame(['acme.example', 'required', 300000], [$options['rpId'], $options['userVerification'], $options['timeout']]);
        self::assertSame(VirtualAuthenticator::encode($prepared->intent->challenge()), $options['challenge']);

        $key = $verifier->bootstrap($secret, $prepared->intent->nonce, $this->owner->signIn($prepared->options));

        self::assertSame([-7, 'Ana\'s YubiKey', 1], [$key->algorithm, $key->label, $key->signCount]);
        self::assertEquals([$key], $verifier->keys());
        self::assertSame(['bootstrap_secret_created', 'prepared', 'bootstrapped'], array_column($this->log->entries, 'event'));
        self::assertSame(['keys' => 0], $this->log->entries[0]['details']);
        self::assertSame(['action' => OperationPolicy::ADD_KEY, 'nonce' => $prepared->intent->nonce, 'key' => $key->id(), 'algorithm' => -7, 'added' => $key->id()], $this->log->entries[2]['details']);

        // The secret works once.
        $other = $this->owner->create();
        $again = $verifier->prepareBootstrap(self::keyOf($this->owner, $other));
        $this->refused(Refusal::InvalidBootstrapSecret, fn () => $verifier->bootstrap($secret, $again->intent->nonce, $this->owner->signIn($again->options, $other)));
    }

    #[DataProvider('stores')]
    public function testABootstrapKeyMustSignItself(string $store): void
    {
        $verifier = $this->verifier($store);
        $secret = $verifier->createBootstrapSecret();
        $stranger = new VirtualAuthenticator('https://acme.example');
        $stranger->create();
        $prepared = $verifier->prepareBootstrap(self::keyOf($this->owner, 0));

        $this->refused(Refusal::UnknownKey, static fn () => $verifier->bootstrap($secret, $prepared->intent->nonce, $stranger->signIn($prepared->options)));
        $this->refused(Refusal::UnknownIntent, fn () => $verifier->bootstrap($secret, $prepared->intent->nonce, $this->owner->signIn($prepared->options)), 'A refused attempt didn\'t use its nonce up.');

        $prepared = $verifier->prepareBootstrap(self::keyOf($this->owner, 0));
        $this->refused(Refusal::InvalidBootstrapSecret, fn () => $verifier->bootstrap('wrong', $prepared->intent->nonce, $this->owner->signIn($prepared->options)));
        self::assertSame([], $verifier->keys());

        $prepared = $verifier->prepareBootstrap([...self::keyOf($this->owner, 0), 'public_key' => 'not-a-key']);
        $this->refused(Refusal::InvalidKey, fn () => $verifier->bootstrap($secret, $prepared->intent->nonce, $this->owner->signIn($prepared->options)));
    }

    #[DataProvider('stores')]
    public function testRunsAnOperationOnlyWithItsOwnersSignature(string $store): void
    {
        $verifier = $this->bootstrapped($store);
        $prepared = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $options = self::json($prepared->options);
        self::assertSame([['type' => 'public-key', 'id' => VirtualAuthenticator::encode($this->owner->credentials[0]['id'])]], $options['allowCredentials'], 'A list, as browsers take it.');
        self::assertSame(['acme.site.remove', ['site' => 'jane'], 'acme-verifier:host-1'], [$prepared->intent->action, $prepared->intent->parameters, $prepared->intent->audience]);
        self::assertEquals(new \DateTimeImmutable('2026-10-28 18:05:00 UTC'), $prepared->intent->expiresAt);
        $this->clock->sleep(60);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}$/D', $prepared->intent->nonce, '144 random bits.');
        self::assertStringNotContainsString('null', $prepared->options, 'The options hold a null, which browsers refuse.');
        $entry = $this->log->entries[\count($this->log->entries) - 1];
        self::assertSame(['prepared', ['action' => 'acme.site.remove', 'nonce' => $prepared->intent->nonce, 'bootstrap' => false]], [$entry['event'], $entry['details']]);

        $verified = $verifier->verify($prepared->intent->nonce, $this->owner->signIn($prepared->options));

        self::assertSame(['acme.site.remove', ['site' => 'jane']], [$verified->intent->action, $verified->intent->parameters]);
        self::assertSame(-7, $verified->key->algorithm, 'The signature\'s algorithm is recorded (INT-015).');
        self::assertEquals(new \DateTimeImmutable('2026-10-28 18:01:00 UTC'), $verified->verifiedAt);
        self::assertSame(2, $verifier->keys()[0]->signCount);
        $entry = $this->log->entries[\count($this->log->entries) - 1];
        self::assertSame(['verified', ['action' => 'acme.site.remove', 'nonce' => $prepared->intent->nonce, 'key' => $verified->key->id(), 'algorithm' => -7]], [$entry['event'], $entry['details']]);

        $this->refused(Refusal::UnknownIntent, fn () => $verifier->verify($prepared->intent->nonce, $this->owner->signIn($prepared->options)), 'A signature worked twice.');
        $this->refused(Refusal::UnknownIntent, fn () => $verifier->verify('never-prepared', $this->owner->signIn($prepared->options)));
        $entry = $this->log->entries[\count($this->log->entries) - 1];
        self::assertSame(['refused', ['nonce' => 'never-prepared', 'reason' => 'unknown_intent']], [$entry['event'], $entry['details']]);
    }

    public function testAcceptsEdDsaKeys(): void
    {
        $verifier = $this->verifier('memory');
        $edwards = new VirtualAuthenticator('https://acme.example', edwards: true);
        $edwards->create();
        $secret = $verifier->createBootstrapSecret();
        $prepared = $verifier->prepareBootstrap(self::keyOf($edwards, 0));
        self::assertSame(-8, $verifier->bootstrap($secret, $prepared->intent->nonce, $edwards->signIn($prepared->options))->algorithm);

        $prepared = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        self::assertSame(-8, $verifier->verify($prepared->intent->nonce, $edwards->signIn($prepared->options))->key->algorithm);
    }

    public function testARefusalSaysWhy(): void
    {
        self::assertSame('The verifier refused the intent: unknown intent.', new IntentRefused(Refusal::UnknownIntent)->getMessage());
        self::assertSame('The verifier refused the intent: invalid bootstrap secret.', new IntentRefused(Refusal::InvalidBootstrapSecret)->getMessage());
    }

    /**
     * @return iterable<string, array{Refusal, array{userVerified?: bool, counter?: int, origin?: string, challenge?: string, rpId?: string}}>
     */
    public static function badSignatures(): iterable
    {
        yield 'another site' => [Refusal::InvalidSignature, ['origin' => 'https://evil.example']];
        yield 'an origin of the site the verifier doesn\'t list' => [Refusal::InvalidSignature, ['origin' => 'https://other.acme.example']];
        yield 'another relying party' => [Refusal::InvalidSignature, ['rpId' => 'evil.example']];
        yield 'another intent' => [Refusal::InvalidSignature, ['challenge' => VirtualAuthenticator::encode(random_bytes(32))]];
        yield 'without user verification' => [Refusal::UserNotVerified, ['userVerified' => false]];
        yield 'a counter going back' => [Refusal::InvalidSignature, ['counter' => 1]];
    }

    /**
     * @param array{userVerified?: bool, counter?: int, origin?: string, challenge?: string, rpId?: string} $tampering
     */
    #[DataProvider('badSignatures')]
    public function testRefusesSignaturesThatArentTheIntents(Refusal $refusal, array $tampering): void
    {
        $verifier = $this->bootstrapped('memory');
        $prepared = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $answer = $this->owner->signIn($prepared->options, 0, $tampering['userVerified'] ?? true, $tampering['counter'] ?? null, $tampering['origin'] ?? null, $tampering['challenge'] ?? null, $tampering['rpId'] ?? null);

        $this->refused($refusal, static fn () => $verifier->verify($prepared->intent->nonce, $answer));

        $entry = $this->log->entries[\count($this->log->entries) - 1];
        self::assertSame(['refused', ['action' => 'acme.site.remove', 'nonce' => $prepared->intent->nonce, 'reason' => $refusal->value]], [$entry['event'], $entry['details']]);
    }

    public function testRefusesWhatIsntASignatureByItsKeys(): void
    {
        $verifier = $this->bootstrapped('memory');
        $stranger = new VirtualAuthenticator('https://acme.example');
        $stranger->create();

        foreach (['', 'null', '{}', $this->owner->register(self::registration())] as $garbage) {
            $prepared = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
            $this->refused(Refusal::InvalidSignature, static fn () => $verifier->verify($prepared->intent->nonce, $garbage));
        }

        $prepared = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $this->refused(Refusal::UnknownKey, static fn () => $verifier->verify($prepared->intent->nonce, $stranger->signIn($prepared->options)));
    }

    public function testAnIntentExpires(): void
    {
        $verifier = $this->bootstrapped('memory');
        $prepared = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $answer = $this->owner->signIn($prepared->options);
        $this->clock->sleep(300);

        $this->refused(Refusal::UnknownIntent, static fn () => $verifier->verify($prepared->intent->nonce, $answer));

        $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $verifier->prepare('acme.site.remove', ['site' => 'ben']);
        $this->clock->sleep(299);
        $verifier->prepare('acme.site.remove', ['site' => 'cara']);
        self::assertSame(0, $verifier->purge());
        $this->clock->sleep(1);
        self::assertSame(2, $verifier->purge());
    }

    public function testRunsOnlyListedOperationsWithTheirParameters(): void
    {
        $verifier = $this->bootstrapped('memory');

        foreach ([
            ['acme.site.create', ['site' => 'jane'], 'The verifier doesn\'t know the operation "acme.site.create"'],
            ['acme.site.remove', ['site' => 'jane', 'force' => true], 'The parameters aren\'t those of acme.site.remove: give exactly site, of their types.'],
            ['acme.site.remove', [], 'The parameters aren\'t those of acme.site.remove'],
            ['acme.site.remove', ['site' => 5], 'The parameters aren\'t those of acme.site.remove'],
            ['acme.site.list', [], 'The operation acme.site.list runs unsigned: there\'s nothing to prepare.'],
        ] as [$action, $parameters, $message]) {
            try {
                $verifier->prepare($action, $parameters);
                self::fail(\sprintf('%s was prepared.', $action));
            } catch (ConfigurationError $exception) {
                self::assertStringStartsWith($message, $exception->getMessage());
            }
        }
    }

    public function testEachKindOfIntentGoesWhereItBelongs(): void
    {
        $verifier = $this->bootstrapped('memory');
        $second = $this->owner->create();

        $addition = $verifier->prepare(OperationPolicy::ADD_KEY, self::keyOf($this->owner, $second));
        $this->refused(Refusal::WrongOperation, fn () => $verifier->verify($addition->intent->nonce, $this->owner->signIn($addition->options)), 'A key\'s addition ran as an operation.');

        $operation = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $this->refused(Refusal::WrongOperation, fn () => $verifier->addKey($operation->intent->nonce, $this->owner->signIn($operation->options)));

        $bootstrap = $verifier->prepareBootstrap(self::keyOf($this->owner, $second));
        $this->refused(Refusal::WrongOperation, fn () => $verifier->addKey($bootstrap->intent->nonce, $this->owner->signIn($bootstrap->options, $second)), 'A self-signed addition went past the bootstrap secret.');

        $operation = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $this->refused(Refusal::WrongOperation, fn () => $verifier->bootstrap('x', $operation->intent->nonce, $this->owner->signIn($operation->options)));
        $operation = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $this->refused(Refusal::WrongOperation, fn () => $verifier->removeKey($operation->intent->nonce, $this->owner->signIn($operation->options)));
    }

    #[DataProvider('stores')]
    public function testKeysAreAddedAndRemovedOnlyBySignedIntents(string $store): void
    {
        $verifier = $this->bootstrapped($store);
        $laptop = $this->owner->create();

        $addition = $verifier->prepare(OperationPolicy::ADD_KEY, self::keyOf($this->owner, $laptop, 'Laptop'));
        $added = $verifier->addKey($addition->intent->nonce, $this->owner->signIn($addition->options));
        self::assertSame(['Laptop', 0], [$added->label, $added->signCount]);
        $entry = $this->log->entries[\count($this->log->entries) - 1];
        self::assertSame(['key_added', ['action' => OperationPolicy::ADD_KEY, 'nonce' => $addition->intent->nonce, 'key' => $verifier->keys()[0]->id(), 'algorithm' => -7, 'added' => $added->id()]], [$entry['event'], $entry['details']]);
        self::assertCount(2, $verifier->keys());

        // The new key signs too.
        $prepared = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        self::assertEquals($added->id(), $verifier->verify($prepared->intent->nonce, $this->owner->signIn($prepared->options, $laptop))->key->id());

        $again = $verifier->prepare(OperationPolicy::ADD_KEY, self::keyOf($this->owner, $laptop));
        $this->refused(Refusal::InvalidKey, fn () => $verifier->addKey($again->intent->nonce, $this->owner->signIn($again->options)), 'A key was added twice.');

        $garbage = $verifier->prepare(OperationPolicy::ADD_KEY, [...self::keyOf($this->owner, $laptop), 'credential_id' => 'new', 'public_key' => VirtualAuthenticator::encode($this->owner->credentials[$laptop]['publicKey']."\x00")]);
        $this->refused(Refusal::InvalidKey, fn () => $verifier->addKey($garbage->intent->nonce, $this->owner->signIn($garbage->options)), 'A key with bytes after it was added.');

        $removal = $verifier->prepare(OperationPolicy::REMOVE_KEY, ['credential_id' => $verifier->keys()[0]->id()]);
        $removed = $verifier->removeKey($removal->intent->nonce, $this->owner->signIn($removal->options, $laptop));
        self::assertSame([$added->id()], array_map(static fn (Key $key): string => $key->id(), $verifier->keys()));
        self::assertNotSame($added->id(), $removed->id());
        $entry = $this->log->entries[\count($this->log->entries) - 1];
        self::assertSame(['key_removed', $removed->id()], [$entry['event'], $entry['details']['removed']]);

        $last = $verifier->prepare(OperationPolicy::REMOVE_KEY, ['credential_id' => $added->id()]);
        $this->refused(Refusal::LastKey, fn () => $verifier->removeKey($last->intent->nonce, $this->owner->signIn($last->options, $laptop)));
        $unknown = $verifier->prepare(OperationPolicy::REMOVE_KEY, ['credential_id' => 'unknown']);
        $this->refused(Refusal::UnknownKey, fn () => $verifier->removeKey($unknown->intent->nonce, $this->owner->signIn($unknown->options, $laptop)));
        self::assertCount(1, $verifier->keys());
    }

    /**
     * Key rotation with intents in flight (OPS-045): an intent isn't bound to a key until it's signed, so
     * one prepared before a rotation is signed by whichever key the verifier has when it's signed; a
     * removed key's signature is refused, even on an intent prepared while that key was there.
     */
    #[DataProvider('stores')]
    public function testIntentsInFlightOutliveARotationOfTheVerifiersKeys(string $store): void
    {
        $verifier = $this->bootstrapped($store);
        $phone = $verifier->keys()[0]->id();
        $first = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $second = $verifier->prepare('acme.site.remove', ['site' => 'joe']);

        $laptop = $this->owner->create();
        $addition = $verifier->prepare(OperationPolicy::ADD_KEY, self::keyOf($this->owner, $laptop, 'Laptop'));
        $added = $verifier->addKey($addition->intent->nonce, $this->owner->signIn($addition->options));
        $removal = $verifier->prepare(OperationPolicy::REMOVE_KEY, ['credential_id' => $phone]);
        $verifier->removeKey($removal->intent->nonce, $this->owner->signIn($removal->options, $laptop));

        self::assertEquals($added->id(), $verifier->verify($first->intent->nonce, $this->owner->signIn($first->options, $laptop))->key->id());
        $this->refused(Refusal::UnknownKey, fn () => $verifier->verify($second->intent->nonce, $this->owner->signIn($second->options)), 'The removed key\'s signature was accepted.');
    }

    #[DataProvider('stores')]
    public function testANewBootstrapSecretIsTheWayBackInWhenEveryKeyIsLost(string $store): void
    {
        $verifier = $this->bootstrapped($store);
        $lostKeys = \count($verifier->keys());
        $found = new VirtualAuthenticator('https://acme.example');
        $found->create();

        $secret = $verifier->createBootstrapSecret();
        $prepared = $verifier->prepareBootstrap(self::keyOf($found, 0, 'New phone'));
        $verifier->bootstrap($secret, $prepared->intent->nonce, $found->signIn($prepared->options));

        self::assertCount($lostKeys + 1, $verifier->keys());
        $created = array_values(array_filter($this->log->entries, static fn (array $entry): bool => 'bootstrap_secret_created' === $entry['event']));
        self::assertSame(['keys' => 1], $created[1]['details'], 'A re-bootstrap with keys there isn\'t recorded as such.');
    }

    public function testStateSurvivesARestart(): void
    {
        $verifier = $this->bootstrapped('file');
        $prepared = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $answer = $this->owner->signIn($prepared->options);

        $restarted = new Verifier(self::config(), self::policy(), new FileVerifierStore($this->files[0]), $this->log, $this->clock);
        self::assertEquals($verifier->keys(), $restarted->keys());
        $restarted->verify($prepared->intent->nonce, $answer);
        $this->refused(Refusal::UnknownIntent, static fn () => $verifier->verify($prepared->intent->nonce, $answer), 'A nonce worked again in another process.');
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    private function verifier(string $store): Verifier
    {
        $this->clock = new MockClock('2026-10-28 18:00:00 UTC');
        $this->log = new InMemoryVerifierLog();
        $this->owner = new VirtualAuthenticator('https://acme.example');
        $this->owner->create();

        if ('file' === $store) {
            $this->files[] = $path = \sprintf('%s/yorvoid-verifier-%s.json', sys_get_temp_dir(), bin2hex(random_bytes(6)));
            $this->store = new FileVerifierStore($path);
        } else {
            $this->store = new InMemoryVerifierStore();
        }

        return new Verifier(self::config(), self::policy(), $this->store, $this->log, $this->clock);
    }

    private function bootstrapped(string $store): Verifier
    {
        $verifier = $this->verifier($store);
        $secret = $verifier->createBootstrapSecret();
        $prepared = $verifier->prepareBootstrap(self::keyOf($this->owner, 0));
        $verifier->bootstrap($secret, $prepared->intent->nonce, $this->owner->signIn($prepared->options));

        return $verifier;
    }

    private static function config(): VerifierConfig
    {
        return new VerifierConfig('acme-verifier:host-1', 'acme.example', ['https://acme.example']);
    }

    private static function policy(): OperationPolicy
    {
        return new OperationPolicy([
            new Operation('acme.site.remove', ['site' => ParameterType::String]),
            new Operation('acme.site.list', signed: false),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function keyOf(VirtualAuthenticator $authenticator, int $credential, string $label = 'Passkey'): array
    {
        $passkey = $authenticator->credentials[$credential];

        return ['credential_id' => VirtualAuthenticator::encode($passkey['id']), 'public_key' => VirtualAuthenticator::encode($passkey['publicKey']), 'user_handle' => VirtualAuthenticator::encode($passkey['userHandle']), 'label' => $label];
    }

    private static function registration(): string
    {
        return (string) json_encode(['rp' => ['id' => 'acme.example'], 'user' => ['id' => VirtualAuthenticator::encode(random_bytes(32))], 'challenge' => VirtualAuthenticator::encode(random_bytes(32))]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $json): array
    {
        $decoded = json_decode($json, true);
        \assert(\is_array($decoded));
        $fields = [];

        foreach ($decoded as $name => $value) {
            $fields[(string) $name] = $value;
        }

        return $fields;
    }

    /**
     * @param \Closure(): mixed $attempt
     */
    private function refused(Refusal $refusal, \Closure $attempt, string $message = ''): void
    {
        try {
            $attempt();
            self::fail('' === $message ? \sprintf('Expected a refusal: %s.', $refusal->value) : $message);
        } catch (IntentRefused $exception) {
            self::assertSame($refusal, $exception->refusal, $message);
        }
    }
}
