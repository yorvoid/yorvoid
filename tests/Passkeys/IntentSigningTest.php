<?php

declare(strict_types=1);

namespace YorvoID\Tests\Passkeys;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Notification\NotificationSettings;
use YorvoID\Authn\Notification\SecurityNotifications;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Authn\Translations as AuthnTranslations;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\IntentVerifier\CollectingNotifier;
use YorvoID\IntentVerifier\Intent;
use YorvoID\IntentVerifier\Key;
use YorvoID\IntentVerifier\Operation;
use YorvoID\IntentVerifier\OperationPolicy;
use YorvoID\IntentVerifier\ParameterType;
use YorvoID\IntentVerifier\Refusal;
use YorvoID\IntentVerifier\Storage\InMemoryVerifierLog;
use YorvoID\IntentVerifier\Storage\InMemoryVerifierStore;
use YorvoID\IntentVerifier\Verifier;
use YorvoID\IntentVerifier\VerifierConfig;
use YorvoID\Mail\Block\Facts;
use YorvoID\Mail\InMemoryMailer;
use YorvoID\Passkeys\Intent\Event\SignedOperationRan;
use YorvoID\Passkeys\Intent\IntentSigning;
use YorvoID\Passkeys\Intent\IntentSummaries;
use YorvoID\Passkeys\Intent\SignedOperationNotification;
use YorvoID\Passkeys\Translations;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\RecordingDispatcher;
use YorvoID\Tests\Support\Stack;
use YorvoID\Throttle\Limit;
use YorvoID\Throttle\Throttle;
use YorvoID\Throttle\Throttled;
use YorvoID\Translation\Translator;
use YorvoID\VirtualAuthenticator\VirtualAuthenticator;

/**
 * The console's side of signed intents, with a verifier (INT-010…012), on every implementation.
 */
final class IntentSigningTest extends DatabaseTestCase
{
    private Stack $stack;

    private MockClock $clock;

    private RecordingDispatcher $events;

    private Sessions $sessions;

    private Principals $principals;

    private InMemoryMailer $mailer;

    private IntentSummaries $summaries;

    #[DataProvider('implementations')]
    public function testAnOwnerSignsAnOperationTheVerifierRuns(string $implementation): void
    {
        $signing = $this->signing($implementation);
        $session = $this->session();
        // A passkey for signing, registered at the signing origin: not one of the console's.
        $authenticator = new VirtualAuthenticator('https://sign.example.com');
        $verifier = new Verifier(new VerifierConfig('acme-verifier', 'sign.example.com', ['https://sign.example.com'], ['https://example.com']), new OperationPolicy([new Operation('acme.site.remove', ['site' => ParameterType::String])]), new InMemoryVerifierStore(), new InMemoryVerifierLog(), new CollectingNotifier(), $this->clock);

        // The passkey becomes the verifier's first key, with the bootstrap secret.
        $registration = $verifier->prepareRegistration('Ana\'s YubiKey', true);
        $bootstrap = $verifier->finishRegistration($registration->id, $authenticator->register($registration->options));
        $added = $verifier->bootstrap($verifier->createBootstrapSecret(), $bootstrap->intent->nonce, $authenticator->signIn($bootstrap->options))->key;
        self::assertSame([-7, 'Ana\'s YubiKey'], [$added->algorithm, $added->label]);

        // An operation: the console records the request and sends the person to the signing page, where
        // they sign it, and the verifier runs it.
        $prepared = $verifier->prepare('acme.site.remove', ['site' => 'jane']);
        $signing->request($session, $prepared->intent, new Origin(OriginKind::Web));
        $verified = $verifier->verify($prepared->intent->nonce, $authenticator->signIn($prepared->options));
        $this->events->dispatched = [];

        $signing->ran($session, $verified->intent, $verified->key->id(), new Origin(OriginKind::System, 'verifier'));

        $entries = array_values(array_filter(iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false), static fn (LogEntry $entry): bool => str_starts_with($entry->action, 'yorvoid.intent.')));
        self::assertSame(['yorvoid.intent.request', 'yorvoid.intent.run'], array_map(static fn (LogEntry $entry): string => $entry->action, $entries));
        self::assertSame(['intent', $prepared->intent->nonce, Outcome::Success], [$entries[1]->subjectType, $entries[1]->subjectId, $entries[1]->outcome]);
        self::assertEquals($session->principalId, $entries[1]->actor);
        self::assertSame(['key' => $added->id(), 'operation' => 'acme.site.remove', 'principal' => (string) $session->principalId], $entries[1]->details);
        self::assertSame(['intent', $prepared->intent->nonce, ['operation' => 'acme.site.remove', 'principal' => (string) $session->principalId]], [$entries[0]->subjectType, $entries[0]->subjectId, $entries[0]->details]);

        $ran = $this->events->of(SignedOperationRan::class);
        self::assertCount(1, $ran);
        $event = $ran[0];
        self::assertSame([$verified->intent, $added->id()], [$event->intent, $event->key]);
        self::assertEquals([$session->realm, $session->principalId, $this->clock->now()], [$event->realm, $event->principalId, $event->at]);

        self::assertCount(1, $this->mailer->sent);
        $email = $this->mailer->sent[0];
        self::assertSame('An operation you signed ran on Acme', $email->subject);
        $facts = $email->body[1];
        \assert($facts instanceof Facts);
        self::assertSame(['Operation', 'When'], array_keys($facts->facts));
        self::assertSame('Remove the site jane', $facts->facts['Operation']);
        self::assertStringEndsWith(' UTC', $facts->facts['When']);
    }

    #[DataProvider('implementations')]
    public function testRecordsARefusal(string $implementation): void
    {
        $signing = $this->signing($implementation);
        $session = $this->session();
        $intent = self::intent('acme.site.remove', ['site' => 'jane']);

        $signing->refused($session, $intent, Refusal::UserNotVerified, new Origin(OriginKind::System));

        $entries = iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false);
        $entry = $entries[\count($entries) - 1];
        self::assertSame(['yorvoid.intent.run', Outcome::Failed, 'n'], [$entry->action, $entry->outcome, $entry->subjectId]);
        self::assertSame(['operation' => 'acme.site.remove', 'principal' => (string) $session->principalId, 'reason' => 'user_not_verified'], $entry->details);
        self::assertSame([], $this->mailer->sent);
    }

    public function testAPrincipalIsAskedForSignaturesOnlySoOften(): void
    {
        $signing = $this->signing('memory', new Limit(2, Duration::hours(1)));
        $session = $this->session();
        $other = $this->session();
        $verifier = new Verifier(new VerifierConfig('acme-verifier', 'sign.example.com', ['https://sign.example.com'], ['https://example.com']), new OperationPolicy([new Operation('acme.site.remove', ['site' => ParameterType::String])]), new InMemoryVerifierStore(), new InMemoryVerifierLog(), new CollectingNotifier(), $this->clock);

        $signing->request($session, $verifier->prepare('acme.site.remove', ['site' => 'a'])->intent, new Origin(OriginKind::Web));
        $signing->request($session, $verifier->prepare('acme.site.remove', ['site' => 'b'])->intent, new Origin(OriginKind::Web));
        $signing->request($other, $verifier->prepare('acme.site.remove', ['site' => 'c'])->intent, new Origin(OriginKind::Web));

        $this->expectException(Throttled::class);

        $signing->request($session, $verifier->prepare('acme.site.remove', ['site' => 'd'])->intent, new Origin(OriginKind::Web));
    }

    public function testTheDefaultLimitIsTwentyAnHour(): void
    {
        $signing = $this->signing('memory');
        $session = $this->session();
        $prepared = new Verifier(new VerifierConfig('acme-verifier', 'sign.example.com', ['https://sign.example.com'], ['https://example.com']), new OperationPolicy([new Operation('acme.site.remove', ['site' => ParameterType::String])]), new InMemoryVerifierStore(), new InMemoryVerifierLog(), new CollectingNotifier(), $this->clock)->prepare('acme.site.remove', ['site' => 'a']);

        for ($i = 0; $i < 20; ++$i) {
            $signing->request($session, $prepared->intent, new Origin(OriginKind::Web));
        }

        try {
            $signing->request($session, $prepared->intent, new Origin(OriginKind::Web));
            self::fail('A twenty-first request in an hour was made.');
        } catch (Throttled $exception) {
            self::assertSame(180, $exception->seconds);
        }
    }

    public function testSummariesAreInThePersonsLanguageWithEveryParameter(): void
    {
        $this->signing('memory');

        self::assertSame('Die Website jane entfernen', $this->summaries->summarize(self::intent('acme.site.remove', ['site' => 'jane']), 'de')->text);
        $restore = $this->summaries->summarize(self::intent('acme.site.restore', ['site' => 'jane', 'full' => true, 'days' => 30]), 'en');
        self::assertSame('Restore the site jane (all of it)', $restore->text);
        self::assertSame(['site' => 'jane', 'full' => 'true', 'days' => '30'], $restore->parameters);
        self::assertSame(['full' => 'false'], $this->summaries->summarize(self::intent('acme.site.restore', ['full' => false]), 'en')->parameters);
        self::assertSame('Einem Passkey das Signieren von Vorgängen entziehen', $this->summaries->summarize(self::intent(OperationPolicy::REMOVE_KEY, ['credential_id' => 'x']), 'de')->text);

        $longest = str_repeat('é', IntentSummaries::DISPLAY_LIMIT);
        self::assertSame($longest, $this->summaries->summarize(self::intent('acme.site.remove', ['site' => $longest]), 'en')->parameters['site']);
    }

    /**
     * @return iterable<string, array{Intent, string}>
     */
    public static function unsummarisable(): iterable
    {
        yield 'no summary' => [self::intent('acme.site.create', ['site' => 'jane']), 'The operation acme.site.create has no summary in the "acme" translations.'];
        yield 'a parameter too long to show' => [self::intent('acme.site.remove', ['site' => str_repeat('a', 201)]), 'The parameter "site" of acme.site.remove is longer than a page can show in full (200 characters).'];
        yield 'a parameter that isn\'t a scalar' => [self::intent('acme.site.remove', ['site' => ['jane']]), 'The parameter "site" of acme.site.remove isn\'t a string, an integer or a boolean.'];
        yield 'a parameter that is nothing' => [self::intent('acme.site.remove', ['site' => null]), 'The parameter "site" of acme.site.remove isn\'t a string'];
    }

    #[DataProvider('unsummarisable')]
    public function testRefusesToSummariseWhatCantBeShownInFull(Intent $intent, string $message): void
    {
        $this->signing('memory');

        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage($message);

        $this->summaries->summarize($intent, 'en');
    }

    private function signing(string $implementation, ?Limit $limit = null): IntentSigning
    {
        $this->stack = $this->stack($implementation);
        $this->clock = new MockClock('2026-10-28 18:45:30 UTC');
        $this->events = new RecordingDispatcher();
        $ids = new UuidV7Generator($this->clock);
        $log = new SecurityLog($this->stack->log, $this->stack->transactions, $this->events, $ids, $this->clock);
        $this->principals = new Principals($this->stack->principals, $this->stack->identifiers, $log, $this->stack->transactions, ids: $ids, clock: $this->clock);
        $this->sessions = new Sessions($this->stack->sessions, $this->principals, $log, $this->stack->transactions, events: $this->events, ids: $ids, clock: $this->clock);
        $throttle = new Throttle($this->stack->throttle, $this->clock);
        $translator = new Translator([AuthnTranslations::DIRECTORY, Translations::DIRECTORY, __DIR__.'/catalogues']);
        $this->summaries = new IntentSummaries($translator, 'acme');
        $this->mailer = new InMemoryMailer();
        $notifications = new SecurityNotifications($this->principals, new OneTimeTokens($this->stack->tokens, $this->principals, $log, $this->stack->transactions, clock: $this->clock), $this->mailer, $translator, new NotificationSettings('Acme', 'https://example.com/not-me?token={token}'), $this->clock);
        $this->events->listen(SignedOperationRan::class, new SignedOperationNotification($notifications, $this->summaries)(...));

        return new IntentSigning($log, $this->stack->transactions, $throttle, $limit, $this->events, $this->clock);
    }

    private function session(): Session
    {
        $principal = $this->principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, email: bin2hex(random_bytes(4)).'@example.com', emailVerified: true), new Origin(OriginKind::Console));

        return $this->sessions->start(new StartSession(new Realm('acme'), $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), true, true))), new Origin(OriginKind::Web))->session;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private static function intent(string $action, array $parameters): Intent
    {
        return new Intent($action, $parameters, 'acme-verifier', 'n', new \DateTimeImmutable('@1793210400'), new \DateTimeImmutable('@1793210700'));
    }
}
