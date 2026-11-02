<?php

declare(strict_types=1);

namespace YorvoID\Tests\IntentVerifier;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use YorvoID\IntentVerifier\CollectingNotifier;
use YorvoID\IntentVerifier\ConfigurationError;
use YorvoID\IntentVerifier\IntentRefused;
use YorvoID\IntentVerifier\Key;
use YorvoID\IntentVerifier\KeyChange;
use YorvoID\IntentVerifier\Operation;
use YorvoID\IntentVerifier\OperationPolicy;
use YorvoID\IntentVerifier\ParameterType;
use YorvoID\IntentVerifier\PreparedIntent;
use YorvoID\IntentVerifier\Refusal;
use YorvoID\IntentVerifier\Storage\FileVerifierStore;
use YorvoID\IntentVerifier\Storage\InMemoryVerifierLog;
use YorvoID\IntentVerifier\Storage\InMemoryVerifierStore;
use YorvoID\IntentVerifier\Verifier;
use YorvoID\IntentVerifier\VerifierConfig;
use YorvoID\IntentVerifier\VerifierNotice;
use YorvoID\IntentVerifier\VerifierNotifier;
use YorvoID\IntentVerifier\VerifierStore;
use YorvoID\VirtualAuthenticator\VirtualAuthenticator;

/**
 * Signed intents, checked by the verifier with its own keys (INT-001…006, INT-008…010, INT-015, INT-016).
 */
final class VerifierTest extends TestCase
{
    private MockClock $clock;

    private InMemoryVerifierLog $log;

    private CollectingNotifier $notifier;

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

        [$prepared] = $this->registered($verifier, $this->owner, 'Ana\'s YubiKey', true);
        $options = self::json($prepared->options);
        self::assertSame([['type' => 'public-key', 'id' => VirtualAuthenticator::encode($this->owner->credentials[0]['id'])]], $options['allowCredentials'], 'Only the key being added is asked for.');
        self::assertSame(['sign.acme.example', 'required', 300000], [$options['rpId'], $options['userVerification'], $options['timeout']]);
        self::assertSame(VirtualAuthenticator::encode($prepared->intent->challenge()), $options['challenge']);

        $change = $verifier->bootstrap($secret, $prepared->intent->nonce, $this->owner->signIn($prepared->options));
        $key = $change->key;

        self::assertSame([-7, 'Ana\'s YubiKey', 1], [$key->algorithm, $key->label, $key->signCount]);
        self::assertEquals([$key], $verifier->keys(), 'The first key waits for nothing: there\'s no owner to tell.');
        self::assertEquals([KeyChange::ADD, $change->requestedAt, $key->id()], [$change->kind, $change->effectiveAt, $change->signer]);
        self::assertSame(['bootstrap_secret_created', 'registration_prepared', 'registered', 'prepared', 'key_change_requested', 'key_added'], array_column($this->log->entries, 'event'));
        self::assertSame(['keys' => 0], $this->log->entries[0]['details']);
        self::assertSame(['action' => OperationPolicy::ADD_KEY, 'nonce' => $prepared->intent->nonce, 'key' => $key->id(), 'algorithm' => -7, 'change' => $change->id, 'kind' => 'add', 'subject' => $key->id(), 'effective_at' => $change->effectiveAt->getTimestamp()], $this->log->entries[4]['details']);
        self::assertSame(['change' => $change->id, 'key' => $key->id(), 'signer' => $key->id()], $this->log->entries[5]['details']);

        // The secret works once.
        [$again, $other] = $this->registered($verifier, $this->owner, 'Passkey', true);
        $this->refused(Refusal::InvalidBootstrapSecret, fn () => $verifier->bootstrap($secret, $again->intent->nonce, $this->owner->signIn($again->options, $other)));
    }

    #[DataProvider('stores')]
    public function testABootstrapKeyMustSignItself(string $store): void
    {
        $verifier = $this->verifier($store);
        $secret = $verifier->createBootstrapSecret();
        $stranger = new VirtualAuthenticator('https://sign.acme.example');
        $stranger->create();
        [$prepared] = $this->registered($verifier, $this->owner, 'Passkey', true);

        $this->refused(Refusal::UnknownKey, static fn () => $verifier->bootstrap($secret, $prepared->intent->nonce, $stranger->signIn($prepared->options)));
        $this->refused(Refusal::UnknownIntent, fn () => $verifier->bootstrap($secret, $prepared->intent->nonce, $this->owner->signIn($prepared->options)), 'A refused attempt didn\'t use its nonce up.');

        [$prepared] = $this->registered($verifier, $this->owner, 'Passkey', true);
        $this->refused(Refusal::InvalidBootstrapSecret, fn () => $verifier->bootstrap('wrong', $prepared->intent->nonce, $this->owner->signIn($prepared->options)));
        self::assertSame([], $verifier->keys());
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
        $edwards = new VirtualAuthenticator('https://sign.acme.example', edwards: true);
        $secret = $verifier->createBootstrapSecret();
        [$prepared] = $this->registered($verifier, $edwards, 'Passkey', true);
        self::assertSame(-8, $verifier->bootstrap($secret, $prepared->intent->nonce, $edwards->signIn($prepared->options))->key->algorithm);

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
        yield 'an origin of the site the verifier doesn\'t list' => [Refusal::InvalidSignature, ['origin' => 'https://other.sign.acme.example']];
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
        $stranger = new VirtualAuthenticator('https://sign.acme.example');
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
        [$addition, $second] = $this->registered($verifier, $this->owner);
        $this->refused(Refusal::WrongOperation, fn () => $verifier->verify($addition->intent->nonce, $this->owner->signIn($addition->options)), 'A key\'s addition ran as an operation.');

        $operation = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $this->refused(Refusal::WrongOperation, fn () => $verifier->addKey($operation->intent->nonce, $this->owner->signIn($operation->options)));

        [$bootstrap, $second] = $this->registered($verifier, $this->owner, 'Passkey', true);
        $this->refused(Refusal::WrongOperation, fn () => $verifier->addKey($bootstrap->intent->nonce, $this->owner->signIn($bootstrap->options, $second)), 'A self-signed addition went past the bootstrap secret.');

        $operation = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $this->refused(Refusal::WrongOperation, fn () => $verifier->bootstrap('x', $operation->intent->nonce, $this->owner->signIn($operation->options)));
        $operation = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $this->refused(Refusal::WrongOperation, fn () => $verifier->removeKey($operation->intent->nonce, $this->owner->signIn($operation->options)));
    }

    /**
     * A key comes from a registration at the signing origin, for its relying party, with the user verified:
     * nobody's word for its public key. The console can't hand the verifier a key of its own.
     */
    public function testAKeyComesFromItsRegistrationAtTheSigningOrigin(): void
    {
        $verifier = $this->bootstrapped('memory');

        try {
            $verifier->prepare(OperationPolicy::ADD_KEY, ['credential_id' => 'a', 'public_key' => 'b', 'user_handle' => 'c', 'label' => 'd']);
            self::fail('A key was prepared from its parameters.');
        } catch (ConfigurationError $exception) {
            self::assertSame('A key is added from its registration at the signing origin: use prepareRegistration().', $exception->getMessage());
        }

        $registration = $verifier->prepareRegistration('  Laptop ');
        $options = self::json($registration->options);
        self::assertSame(['sign.acme.example', 'required', 'Laptop'], [((array) $options['rp'])['id'], ((array) $options['authenticatorSelection'])['userVerification'], ((array) $options['user'])['name']]);
        self::assertSame([VirtualAuthenticator::encode($this->owner->credentials[0]['id'])], array_column((array) $options['excludeCredentials'], 'id'), 'The keys there already are excluded.');

        $this->refused(Refusal::UserNotVerified, fn () => $verifier->finishRegistration($registration->id, $this->owner->register($registration->options, userVerified: false)));
        $this->refused(Refusal::InvalidRegistration, fn () => $verifier->finishRegistration($registration->id, $this->owner->register($registration->options)), 'A registration was answered twice.');
        $registration = $verifier->prepareRegistration('Laptop');
        $this->refused(Refusal::InvalidRegistration, fn () => $verifier->finishRegistration($registration->id, $this->owner->register($registration->options, origin: 'https://acme.example')), 'A registration at the console was accepted.');
        $registration = $verifier->prepareRegistration('Laptop');
        $this->refused(Refusal::InvalidRegistration, static fn () => $verifier->finishRegistration($registration->id, '{}'));
        $this->refused(Refusal::InvalidRegistration, fn () => $verifier->finishRegistration('unknown', $this->owner->register($registration->options)));
        $registration = $verifier->prepareRegistration('Laptop');
        $this->clock->sleep(300);
        $this->refused(Refusal::InvalidRegistration, fn () => $verifier->finishRegistration($registration->id, $this->owner->register($registration->options)), 'An expired registration was accepted.');
        $entry = $this->log->entries[\count($this->log->entries) - 1];
        self::assertSame(['refused', ['registration' => $registration->id, 'reason' => 'invalid_registration']], [$entry['event'], $entry['details']]);

        $registration = $verifier->prepareRegistration('Laptop');
        $prepared = $verifier->finishRegistration($registration->id, $this->owner->register($registration->options));
        self::assertSame([OperationPolicy::ADD_KEY, 'Laptop', VirtualAuthenticator::encode($this->owner->credentials[\count($this->owner->credentials) - 1]['id'])], [$prepared->intent->action, $prepared->intent->parameters['label'], $prepared->intent->parameters['credential_id']]);
        $entry = $this->log->entries[\count($this->log->entries) - 2];
        self::assertSame(['registered', $registration->id], [$entry['event'], $entry['details']['registration']]);

        $registration = $verifier->prepareRegistration('Later');
        $this->clock->sleep(301);
        self::assertSame(2, $verifier->purge(), 'An expired registration and an expired intent stay.');

        foreach (['', str_repeat('x', 65)] as $label) {
            try {
                $verifier->prepareRegistration($label);
                self::fail('A key was given the label "'.$label.'".');
            } catch (ConfigurationError $exception) {
                self::assertSame('A key\'s label has 1 to 64 characters.', $exception->getMessage());
            }
        }
    }

    #[DataProvider('stores')]
    public function testKeysAreAddedAndRemovedOnlyBySignedIntents(string $store): void
    {
        $verifier = $this->bootstrapped($store);
        $phone = $verifier->keys()[0]->id();
        [$addition, $laptop] = $this->registered($verifier, $this->owner, 'Laptop');
        $change = $verifier->addKey($addition->intent->nonce, $this->owner->signIn($addition->options));
        $added = $change->key;
        self::assertSame(['Laptop', 0, KeyChange::ADD, $phone], [$added->label, $added->signCount, $change->kind, $change->signer]);
        self::assertEquals(new \DateTimeImmutable('2026-10-29 18:00:00 UTC'), $change->effectiveAt, 'A day later.');
        $entry = $this->log->entries[\count($this->log->entries) - 1];
        self::assertSame(['key_change_requested', ['action' => OperationPolicy::ADD_KEY, 'nonce' => $addition->intent->nonce, 'key' => $phone, 'algorithm' => -7, 'change' => $change->id, 'kind' => 'add', 'subject' => $added->id(), 'effective_at' => $change->effectiveAt->getTimestamp()]], [$entry['event'], $entry['details']]);
        self::assertSame([$phone], array_map(static fn (Key $key): string => $key->id(), $verifier->keys()), 'A key signs before its addition takes effect.');
        self::assertEquals([$change], $verifier->keyChanges());
        $again = $verifier->prepareRegistration('Laptop');
        $again = $verifier->finishRegistration($again->id, $this->owner->register($again->options, credentialId: $this->owner->credentials[$laptop]['id']));
        $this->refused(Refusal::InvalidKey, fn () => $verifier->addKey($again->intent->nonce, $this->owner->signIn($again->options)), 'A key was asked for twice.');

        $this->clock->sleep(86400);
        self::assertSame([$phone, $added->id()], array_map(static fn (Key $key): string => $key->id(), $verifier->keys()));
        self::assertSame([], $verifier->keyChanges());
        $entry = $this->log->entries[\count($this->log->entries) - 1];
        self::assertSame(['key_added', ['change' => $change->id, 'key' => $added->id(), 'signer' => $phone]], [$entry['event'], $entry['details']]);

        // The new key signs too.
        $prepared = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        self::assertEquals($added->id(), $verifier->verify($prepared->intent->nonce, $this->owner->signIn($prepared->options, $laptop))->key->id());

        $again = $verifier->prepareRegistration('Laptop');
        $again = $verifier->finishRegistration($again->id, $this->owner->register($again->options, credentialId: $this->owner->credentials[$laptop]['id']));
        $this->refused(Refusal::InvalidKey, fn () => $verifier->addKey($again->intent->nonce, $this->owner->signIn($again->options)), 'A key was added twice.');

        $removal = $verifier->prepare(OperationPolicy::REMOVE_KEY, ['credential_id' => $phone]);
        $removed = $verifier->removeKey($removal->intent->nonce, $this->owner->signIn($removal->options, $laptop));
        self::assertSame([KeyChange::REMOVE, $phone], [$removed->kind, $removed->key->id()]);

        // Until the removal takes effect, the key signs; and the other can't go, or none would be left.
        $prepared = $verifier->prepare('acme.site.remove', ['site' => 'joe']);
        self::assertSame($phone, $verifier->verify($prepared->intent->nonce, $this->owner->signIn($prepared->options))->key->id());
        $last = $verifier->prepare(OperationPolicy::REMOVE_KEY, ['credential_id' => $added->id()]);
        $this->refused(Refusal::LastKey, fn () => $verifier->removeKey($last->intent->nonce, $this->owner->signIn($last->options, $laptop)));
        $twice = $verifier->prepare(OperationPolicy::REMOVE_KEY, ['credential_id' => $phone]);
        $this->refused(Refusal::UnknownKey, fn () => $verifier->removeKey($twice->intent->nonce, $this->owner->signIn($twice->options, $laptop)), 'A key was asked to go twice.');

        $this->clock->sleep(86400);
        self::assertSame([$added->id()], array_map(static fn (Key $key): string => $key->id(), $verifier->keys()));
        $entry = $this->log->entries[\count($this->log->entries) - 1];
        self::assertSame(['key_removed', $phone], [$entry['event'], $entry['details']['key']]);

        $last = $verifier->prepare(OperationPolicy::REMOVE_KEY, ['credential_id' => $added->id()]);
        $this->refused(Refusal::LastKey, fn () => $verifier->removeKey($last->intent->nonce, $this->owner->signIn($last->options, $laptop)));
        $unknown = $verifier->prepare(OperationPolicy::REMOVE_KEY, ['credential_id' => 'unknown']);
        $this->refused(Refusal::UnknownKey, fn () => $verifier->removeKey($unknown->intent->nonce, $this->owner->signIn($unknown->options, $laptop)));
        self::assertCount(1, $verifier->keys());
    }

    /**
     * Someone who got a key change signed (a stolen key, a deceived owner) loses it if any owner cancels it
     * in time: the owners were told when it was asked for.
     */
    #[DataProvider('stores')]
    public function testAnyKeyCancelsAKeyChangeBeforeItTakesEffect(string $store): void
    {
        $verifier = $this->bootstrapped($store);
        [$addition, $intruder] = $this->registered($verifier, $this->owner, 'Not mine');
        $change = $verifier->addKey($addition->intent->nonce, $this->owner->signIn($addition->options));
        self::assertSame(VerifierNotice::KEY_CHANGE_REQUESTED, $this->notifier->notices[\count($this->notifier->notices) - 1]->event);
        $this->clock->sleep(86399);

        $cancel = $verifier->prepare(OperationPolicy::CANCEL_KEY_CHANGE, ['change' => $change->id]);
        $this->refused(Refusal::UnknownKey, fn () => $verifier->cancelKeyChange($cancel->intent->nonce, $this->owner->signIn($cancel->options, $intruder)), 'A key not yet added cancelled.');
        $cancel = $verifier->prepare(OperationPolicy::CANCEL_KEY_CHANGE, ['change' => $change->id]);
        $cancelled = $verifier->cancelKeyChange($cancel->intent->nonce, $this->owner->signIn($cancel->options));

        self::assertEquals($change, $cancelled);
        $notice = $this->notifier->notices[\count($this->notifier->notices) - 1];
        self::assertSame([VerifierNotice::KEY_CHANGE_CANCELLED, $change->id], [$notice->event, $notice->change?->id]);
        $entry = $this->log->entries[\count($this->log->entries) - 1];
        self::assertSame(['key_change_cancelled', $change->id], [$entry['event'], $entry['details']['change']]);
        $this->clock->sleep(2);
        self::assertCount(1, $verifier->keys(), 'A cancelled change took effect.');
        self::assertSame([], $verifier->keyChanges());

        $again = $verifier->prepare(OperationPolicy::CANCEL_KEY_CHANGE, ['change' => $change->id]);
        $this->refused(Refusal::UnknownKeyChange, fn () => $verifier->cancelKeyChange($again->intent->nonce, $this->owner->signIn($again->options)));
        $operation = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $this->refused(Refusal::WrongOperation, fn () => $verifier->cancelKeyChange($operation->intent->nonce, $this->owner->signIn($operation->options)));
        $cancel = $verifier->prepare(OperationPolicy::CANCEL_KEY_CHANGE, ['change' => 'x']);
        $this->refused(Refusal::WrongOperation, fn () => $verifier->verify($cancel->intent->nonce, $this->owner->signIn($cancel->options)), 'A cancellation went through verify().');
    }

    /**
     * The owners hear of every operation verified and every change to the keys from the verifier itself,
     * through its notifier: not from the console.
     */
    public function testTellsItsNotifierOfEveryOperationAndKeyChange(): void
    {
        $verifier = $this->verifier('memory', keyChangeDelay: 0);
        $secret = $verifier->createBootstrapSecret();
        [$bootstrap] = $this->registered($verifier, $this->owner, 'Passkey', true);
        $verifier->bootstrap($secret, $bootstrap->intent->nonce, $this->owner->signIn($bootstrap->options));
        [$addition] = $this->registered($verifier, $this->owner, 'Laptop');
        $added = $verifier->addKey($addition->intent->nonce, $this->owner->signIn($addition->options));
        $prepared = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $verified = $verifier->verify($prepared->intent->nonce, $this->owner->signIn($prepared->options));
        $removal = $verifier->prepare(OperationPolicy::REMOVE_KEY, ['credential_id' => $added->key->id()]);
        $verifier->removeKey($removal->intent->nonce, $this->owner->signIn($removal->options));
        $phone = $verifier->keys()[0]->id();
        $laptop = $added->key->id();

        self::assertSame(
            [
                [VerifierNotice::BOOTSTRAP_SECRET_CREATED, null, null],
                [VerifierNotice::KEY_CHANGE_REQUESTED, OperationPolicy::ADD_KEY, $phone],
                [VerifierNotice::KEY_ADDED, null, $phone],
                [VerifierNotice::KEY_CHANGE_REQUESTED, OperationPolicy::ADD_KEY, $laptop],
                [VerifierNotice::KEY_ADDED, null, $laptop],
                [VerifierNotice::VERIFIED, 'acme.site.remove', $phone],
                [VerifierNotice::KEY_CHANGE_REQUESTED, OperationPolicy::REMOVE_KEY, $laptop],
                [VerifierNotice::KEY_REMOVED, null, $laptop],
            ],
            array_map(static fn (VerifierNotice $notice): array => [$notice->event, $notice->intent?->action, $notice->key?->id()], $this->notifier->notices),
        );
        self::assertSame(['site' => 'jane'], $this->notifier->notices[5]->intent?->parameters);
        self::assertEquals($this->clock->now(), $this->notifier->notices[5]->at);
        self::assertSame($added->id, $this->notifier->notices[4]->change?->id);

        // A refusal isn't notified: nothing ran.
        $wrong = $verifier->prepare('acme.site.remove', ['site' => 'joe']);
        $this->refused(Refusal::InvalidSignature, static fn () => $verifier->verify($wrong->intent->nonce, '{}'));
        self::assertCount(8, $this->notifier->notices);
    }

    /**
     * A notice that can't be sent stops the operation: verify() throws, so the operation doesn't run
     * unannounced. The nonce is used up all the same.
     */
    public function testAnOperationWhoseNoticeCantBeSentDoesntRun(): void
    {
        $verifier = $this->bootstrapped('memory');
        $prepared = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $answer = $this->owner->signIn($prepared->options);
        $failing = new class implements VerifierNotifier {
            public function notify(VerifierNotice $notice): void
            {
                throw new \RuntimeException('The mail queue is down.');
            }
        };
        $silent = new Verifier(self::config(), self::policy(), $this->store, $this->log, $failing, $this->clock);

        try {
            $silent->verify($prepared->intent->nonce, $answer);
            self::fail('An operation was verified without its notice.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The mail queue is down.', $exception->getMessage());
        }

        $this->refused(Refusal::UnknownIntent, static fn () => $verifier->verify($prepared->intent->nonce, $answer));
    }

    /**
     * Key rotation with intents in flight (OPS-045): an intent isn't bound to a key until it's signed, so
     * one prepared before a rotation is signed by whichever key the verifier has when it's signed; a
     * removed key's signature is refused, even on an intent prepared while that key was there.
     */
    #[DataProvider('stores')]
    public function testIntentsInFlightOutliveARotationOfTheVerifiersKeys(string $store): void
    {
        $verifier = $this->bootstrapped($store, keyChangeDelay: 0);
        $phone = $verifier->keys()[0]->id();
        $first = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $second = $verifier->prepare('acme.site.remove', ['site' => 'joe']);

        [$addition, $laptop] = $this->registered($verifier, $this->owner, 'Laptop');
        $added = $verifier->addKey($addition->intent->nonce, $this->owner->signIn($addition->options));
        $removal = $verifier->prepare(OperationPolicy::REMOVE_KEY, ['credential_id' => $phone]);
        $verifier->removeKey($removal->intent->nonce, $this->owner->signIn($removal->options, $laptop));

        self::assertEquals($added->key->id(), $verifier->verify($first->intent->nonce, $this->owner->signIn($first->options, $laptop))->key->id());
        $this->refused(Refusal::UnknownKey, fn () => $verifier->verify($second->intent->nonce, $this->owner->signIn($second->options)), 'The removed key\'s signature was accepted.');
    }

    #[DataProvider('stores')]
    public function testANewBootstrapSecretIsTheWayBackInWhenEveryKeyIsLost(string $store): void
    {
        $verifier = $this->bootstrapped($store);
        $lostKeys = \count($verifier->keys());
        $found = new VirtualAuthenticator('https://sign.acme.example');

        $secret = $verifier->createBootstrapSecret();
        [$prepared] = $this->registered($verifier, $found, 'New phone', true);
        $change = $verifier->bootstrap($secret, $prepared->intent->nonce, $found->signIn($prepared->options));

        // With keys there, their owners get a day to cancel it: a host's root could be someone else.
        self::assertCount($lostKeys, $verifier->keys());
        self::assertSame([VerifierNotice::KEY_CHANGE_REQUESTED, $change->id], [$this->notifier->notices[\count($this->notifier->notices) - 1]->event, $this->notifier->notices[\count($this->notifier->notices) - 1]->change?->id]);
        $this->clock->sleep(86400);
        self::assertSame(1, $verifier->applyKeyChanges(), 'The due change wasn\'t applied once.');
        self::assertCount($lostKeys + 1, $verifier->keys());
        $created = array_values(array_filter($this->log->entries, static fn (array $entry): bool => 'bootstrap_secret_created' === $entry['event']));
        self::assertSame(['keys' => 1], $created[1]['details'], 'A re-bootstrap with keys there isn\'t recorded as such.');
    }

    public function testStateSurvivesARestart(): void
    {
        $verifier = $this->bootstrapped('file');
        $prepared = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $answer = $this->owner->signIn($prepared->options);

        $restarted = new Verifier(self::config(), self::policy(), new FileVerifierStore($this->files[0]), $this->log, $this->notifier, $this->clock);
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

    private function verifier(string $store, int $keyChangeDelay = 86400): Verifier
    {
        $this->clock = new MockClock('2026-10-28 18:00:00 UTC');
        $this->log = new InMemoryVerifierLog();
        $this->owner = new VirtualAuthenticator('https://sign.acme.example');

        if ('file' === $store) {
            $this->files[] = $path = \sprintf('%s/yorvoid-verifier-%s.json', sys_get_temp_dir(), bin2hex(random_bytes(6)));
            $this->store = new FileVerifierStore($path);
        } else {
            $this->store = new InMemoryVerifierStore();
        }

        $this->notifier = new CollectingNotifier();

        return new Verifier(self::config($keyChangeDelay), self::policy(), $this->store, $this->log, $this->notifier, $this->clock);
    }

    private function bootstrapped(string $store, int $keyChangeDelay = 86400): Verifier
    {
        $verifier = $this->verifier($store, $keyChangeDelay);
        $secret = $verifier->createBootstrapSecret();
        [$prepared] = $this->registered($verifier, $this->owner, 'Passkey', true);
        $verifier->bootstrap($secret, $prepared->intent->nonce, $this->owner->signIn($prepared->options));

        return $verifier;
    }

    /**
     * Registers a new passkey of the authenticator at the signing origin, and prepares its addition.
     *
     * @return array{PreparedIntent, int} The addition, and the passkey's index in the authenticator
     */
    private function registered(Verifier $verifier, VirtualAuthenticator $authenticator, string $label = 'Passkey', bool $bootstrap = false): array
    {
        $registration = $verifier->prepareRegistration($label, $bootstrap);
        $index = \count($authenticator->credentials);

        return [$verifier->finishRegistration($registration->id, $authenticator->register($registration->options)), $index];
    }

    private static function config(int $keyChangeDelay = 86400): VerifierConfig
    {
        return new VerifierConfig('acme-verifier:host-1', 'sign.acme.example', ['https://sign.acme.example'], ['https://acme.example'], keyChangeDelay: $keyChangeDelay);
    }

    private static function policy(): OperationPolicy
    {
        return new OperationPolicy([
            new Operation('acme.site.remove', ['site' => ParameterType::String]),
            new Operation('acme.site.list', signed: false),
        ]);
    }

    private static function registration(): string
    {
        return (string) json_encode(['rp' => ['id' => 'sign.acme.example'], 'user' => ['id' => VirtualAuthenticator::encode(random_bytes(32))], 'challenge' => VirtualAuthenticator::encode(random_bytes(32))]);
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
