<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\Notification;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use YorvoID\Authn\Credential\AddCredential;
use YorvoID\Authn\Credential\CredentialId;
use YorvoID\Authn\Credential\CredentialKind;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Credential\CredentialStatus;
use YorvoID\Authn\Credential\RemoveCredential;
use YorvoID\Authn\Notification\Event\NotMeReported;
use YorvoID\Authn\Notification\NotificationSettings;
use YorvoID\Authn\Notification\NotMe;
use YorvoID\Authn\Notification\SecurityNotifications;
use YorvoID\Authn\Recovery\Event\RecoveryCodesGenerated;
use YorvoID\Authn\Recovery\Event\RecoveryCodeUsed;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\SessionEndReason;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartedSession;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Authn\Translations;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Id\UuidV7Generator;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Mail\Block\Action;
use YorvoID\Mail\Block\Facts;
use YorvoID\Mail\Block\Footnote;
use YorvoID\Mail\Block\Paragraph;
use YorvoID\Mail\Email;
use YorvoID\Mail\InMemoryMailer;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\RecordingDispatcher;
use YorvoID\Tests\Support\Stack;
use YorvoID\Translation\Translator;

/**
 * Security notifications people can act on, and "this wasn't me" (UX-016, ATN-080), on every
 * implementation.
 */
final class SecurityNotificationsTest extends DatabaseTestCase
{
    private Stack $stack;

    private MockClock $clock;

    private RecordingDispatcher $events;

    private Principals $principals;

    private Sessions $sessions;

    private Credentials $credentials;

    private InMemoryMailer $mailer;

    private NotMe $notMe;

    /** @var array<string, string> Principal ID => locale */
    private array $locales = [];

    #[DataProvider('implementations')]
    public function testASignInFromANewDeviceIsAnnounced(string $implementation): void
    {
        $this->notifications($implementation);
        $ana = $this->principal('ana@example.com');
        $this->signIn($ana);
        self::assertSame([], $this->mailer->sent, 'A first sign-in was announced.');

        $this->signIn($ana, 'Firefox on Linux', '192.0.2.10');

        $email = $this->only();
        self::assertSame(['ana@example.com', 'New sign-in to your Acme account', 'en'], [$email->to, $email->subject, $email->locale]);
        self::assertSame([Paragraph::class, Facts::class, Paragraph::class, Action::class, Footnote::class], array_map(static fn (object $block): string => $block::class, $email->body));
        self::assertSame('Someone signed in to your Acme account from a device you haven\'t used before. If it was you, there\'s nothing to do.', self::paragraph($email, 0));
        $facts = $email->body[1];
        \assert($facts instanceof Facts);
        self::assertSame(['When', 'Device', 'IP address'], array_keys($facts->facts));
        self::assertStringContainsString('2026', $facts->facts['When']);
        self::assertStringEndsWith(' UTC', $facts->facts['When']);
        self::assertSame(['Firefox on Linux', '192.0.2.10'], [$facts->facts['Device'], $facts->facts['IP address']]);
        self::assertStringStartsWith('If it wasn\'t you, use the link below. It signs everyone out', self::paragraph($email, 2));
        $action = $email->body[3];
        \assert($action instanceof Action);
        self::assertSame('This wasn\'t me', $action->label);
        self::assertMatchesRegularExpression('#^https://acme\.example/not-me\?token=[0-9a-f]{32}\.[A-Za-z0-9_-]{43}&credential=$#D', $action->url);
        $footnote = $email->body[4];
        \assert($footnote instanceof Footnote);
        self::assertStringContainsString('security of your Acme account', $footnote->text);
    }

    #[DataProvider('implementations')]
    public function testNotificationsAreInThePrincipalsLanguage(string $implementation): void
    {
        $this->notifications($implementation);
        $ben = $this->principal('ben@example.de', locale: 'de');
        $this->signIn($ben);
        $this->signIn($ben);

        $email = $this->only();
        self::assertSame(['Neue Anmeldung bei Ihrem Acme-Konto', 'de'], [$email->subject, $email->locale]);
        $facts = $email->body[1];
        \assert($facts instanceof Facts);
        self::assertSame(['Wann', 'Gerät', 'IP-Adresse'], array_keys($facts->facts));
        self::assertSame(['Unbekannt', 'Unbekannt'], [$facts->facts['Gerät'], $facts->facts['IP-Adresse']]);
        $action = $email->body[3];
        \assert($action instanceof Action);
        self::assertSame('Das war ich nicht', $action->label);
    }

    #[DataProvider('implementations')]
    public function testOnlyAVerifiedAddressIsWrittenTo(string $implementation): void
    {
        $this->notifications($implementation);

        foreach ([$this->principal('cara@example.com', verified: false), $this->principal(null)] as $principal) {
            $this->signIn($principal);
            $this->signIn($principal);
        }

        self::assertSame([], $this->mailer->sent);
    }

    #[DataProvider('implementations')]
    public function testEveryChangeToHowSomeoneSignsInIsAnnounced(string $implementation): void
    {
        $this->notifications($implementation);
        $ana = $this->principal('ana@example.com');
        $session = $this->signIn($ana)->session;

        $phone = $this->credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Phone'), $session, new Origin(OriginKind::Web));
        $added = $this->only();
        self::assertSame('A sign-in method was added to your Acme account', $added->subject);
        $facts = $added->body[1];
        \assert($facts instanceof Facts);
        self::assertSame(['Sign-in method', 'Name', 'When'], array_keys($facts->facts));
        self::assertSame(['Passkey', 'Phone'], [$facts->facts['Sign-in method'], $facts->facts['Name']]);
        self::assertStringEndsWith(' UTC', $facts->facts['When']);
        self::assertStringStartsWith('If it wasn\'t you, use the link below. It turns this sign-in method off', self::paragraph($added, 2));
        self::assertStringEndsWith('&credential='.$phone->id, self::url($added));

        $this->credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Laptop'), $session, new Origin(OriginKind::Web));
        $this->mailer->sent = [];
        $this->credentials->remove(new RemoveCredential(new Realm('acme'), $phone->id), $session, new Origin(OriginKind::Web));
        $removed = $this->only();
        self::assertSame('A sign-in method was removed from your Acme account', $removed->subject);
        self::assertStringEndsWith('&credential=', self::url($removed));

        $this->events->dispatch(new RecoveryCodeUsed(new Realm('acme'), $ana->id, 0, new Origin(OriginKind::Web)));
        $used = $this->only();
        self::assertStringEndsWith('You have no recovery codes left: create new ones.', self::paragraph($used, 0));
        self::assertSame(['When'], array_keys(self::facts($used)));
        $this->events->dispatch(new RecoveryCodeUsed(new Realm('acme'), $ana->id, 1, new Origin(OriginKind::Web)));
        self::assertStringEndsWith('You have 1 recovery code left.', self::paragraph($this->only(), 0));
        $this->events->dispatch(new RecoveryCodesGenerated(new Realm('acme'), $ana->id, 10, new Origin(OriginKind::Web)));
        $generated = $this->only();
        self::assertSame('New recovery codes for your Acme account', $generated->subject);
        self::assertStringStartsWith('10 new recovery codes were created', self::paragraph($generated, 0));
        self::assertSame(['When'], array_keys(self::facts($generated)));
    }

    #[DataProvider('implementations')]
    public function testNotMeSignsEveryoneOutOnce(string $implementation): void
    {
        $this->notifications($implementation);
        $ana = $this->principal('ana@example.com');
        $first = $this->signIn($ana)->session;
        $attackers = $this->signIn($ana)->session;
        [$token, $credential] = self::link($this->only());
        // A later notification's link doesn't make an earlier one stop working.
        $this->signIn($ana);
        self::link($this->only());
        $this->events->dispatched = [];

        self::assertSame('', $credential);
        self::assertEquals($ana->id, $this->notMe->confirm(new Realm('acme'), $token, $credential, new Origin(OriginKind::Web)));

        foreach ([$first, $attackers] as $session) {
            self::assertSame(SessionEndReason::Reported, $this->sessions->get(new Realm('acme'), $session->id)?->endReason);
        }

        $event = $this->events->last();
        \assert($event instanceof NotMeReported);
        self::assertEquals([$ana->id, null], [$event->principalId, $event->disabled]);
        $entries = iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false);
        $reported = array_values(array_filter($entries, static fn ($entry): bool => 'yorvoid.notification.not_me' === $entry->action));
        self::assertCount(1, $reported);
        self::assertEquals($ana->id, $reported[0]->actor);
        self::assertSame([], $reported[0]->details);

        self::assertNull($this->notMe->confirm(new Realm('acme'), $token, null, new Origin(OriginKind::Web)), 'A link worked twice.');
    }

    #[DataProvider('implementations')]
    public function testNotMeDisablesTheSignInMethodItWasAbout(string $implementation): void
    {
        $this->notifications($implementation);
        $ana = $this->principal('ana@example.com');
        $session = $this->signIn($ana)->session;
        $attackers = $this->credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Mine now'), $session, new Origin(OriginKind::Web));
        [$token, $credential] = self::link($this->only());
        $other = $this->credentials->add(new AddCredential(new Realm('acme'), CredentialKind::Passkey, 'Phone'), $session, new Origin(OriginKind::Web));

        self::assertNull($this->notMe->confirm(new Realm('acme'), $token, (string) $other->id, new Origin(OriginKind::Web)), 'The link disabled another sign-in method.');
        self::assertNull($this->notMe->confirm(new Realm('acme'), $token, null, new Origin(OriginKind::Web)));
        self::assertNull($this->notMe->confirm(new Realm('acme'), $token, 'not-an-id', new Origin(OriginKind::Web)));
        self::assertNull($this->notMe->confirm(new Realm('globex'), $token, $credential, new Origin(OriginKind::Web)));

        self::assertEquals($ana->id, $this->notMe->confirm(new Realm('acme'), $token, $credential, new Origin(OriginKind::Web)));
        self::assertSame(CredentialStatus::Disabled, $this->credentials->get(new Realm('acme'), $attackers->id)?->status);
        self::assertSame(CredentialStatus::Active, $this->credentials->get(new Realm('acme'), $other->id)?->status);
        $event = $this->events->last();
        \assert($event instanceof NotMeReported);
        self::assertEquals($attackers->id, $event->disabled);
        $entries = iterator_to_array($this->stack->log->entries(new Realm('acme'), 1), false);
        self::assertSame(['credential' => (string) $attackers->id], $entries[\count($entries) - 1]->details);
    }

    public function testALinkAboutASignInMethodNamesIt(): void
    {
        self::assertSame('yorvoid.not_me', SecurityNotifications::notMePurpose(null));
        self::assertSame('yorvoid.not_me.c01970d9c8a2e7b1c9f3a2d4e5f6a7b8c', SecurityNotifications::notMePurpose(CredentialId::fromString('01970d9c-8a2e-7b1c-9f3a-2d4e5f6a7b8c')));
    }

    public function testEveryEventHasItsListener(): void
    {
        foreach (SecurityNotifications::events() as $event => $method) {
            $parameter = new \ReflectionMethod(SecurityNotifications::class, $method)->getParameters()[0]->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $parameter);
            self::assertSame($event, $parameter->getName());
        }
    }

    public function testSettingsNeedALinkWithAToken(): void
    {
        $settings = new NotificationSettings('Acme', 'https://acme.example/not-me/{token}');
        self::assertEquals(Duration::days(7), $settings->notMeLifetime);
        self::assertEquals(Duration::days(1), new NotificationSettings('Acme', 'https://acme.example/{token}', notMeLifetime: Duration::days(1))->notMeLifetime);
        self::assertSame('en', $settings->localeOf(new Realm('acme'), new PrincipalId(new UuidV7Generator()->next())));

        foreach (['https://acme.example/not-me', 'javascript:{token}', '/not-me?token={token}'] as $url) {
            try {
                new NotificationSettings('Acme', $url);
                self::fail(\sprintf('"%s" was accepted.', $url));
            } catch (IntegratorError $exception) {
                self::assertStringStartsWith(\sprintf('"%s" isn\'t a web address with {token} in it.', $url), $exception->getMessage());
            }
        }
    }

    private function notifications(string $implementation): void
    {
        $this->stack = $this->stack($implementation);
        $this->clock = new MockClock('2026-10-28 18:45:30 UTC');
        $this->events = new RecordingDispatcher();
        $ids = new UuidV7Generator($this->clock);
        $log = new SecurityLog($this->stack->log, $this->stack->transactions, $this->events, $ids, $this->clock);
        $this->principals = new Principals($this->stack->principals, $this->stack->identifiers, $log, $this->stack->transactions, ids: $ids, clock: $this->clock);
        $this->sessions = new Sessions($this->stack->sessions, $this->principals, $log, $this->stack->transactions, events: $this->events, ids: $ids, clock: $this->clock, devices: $this->stack->devices);
        $this->credentials = new Credentials($this->stack->credentials, $this->sessions, new StepUp($this->sessions, $this->stack->coolDowns, $log, $this->clock), $log, $this->stack->transactions, $this->events, $ids, $this->clock);
        $tokens = new OneTimeTokens($this->stack->tokens, $this->principals, $log, $this->stack->transactions, clock: $this->clock);
        $this->mailer = new InMemoryMailer();
        $settings = new NotificationSettings('Acme', 'https://acme.example/not-me?token={token}&credential={credential}', fn (Realm $realm, PrincipalId $principal): string => $this->locales[(string) $principal] ?? 'en');
        $notifications = new SecurityNotifications($this->principals, $tokens, $this->mailer, new Translator([Translations::DIRECTORY]), $settings, $this->clock);
        $this->notMe = new NotMe($tokens, $this->sessions, $this->credentials, $log, $this->stack->transactions, $this->events);

        foreach (SecurityNotifications::events() as $event => $method) {
            $listener = [$notifications, $method];
            \assert(\is_callable($listener));
            $this->events->listen($event, static function (object $dispatched) use ($listener): void {
                $listener($dispatched);
            });
        }
    }

    private function principal(?string $email, bool $verified = true, string $locale = 'en'): Principal
    {
        $principal = $this->principals->create(new CreatePrincipal(new Realm('acme'), PrincipalKind::Human, email: $email, emailVerified: $verified), new Origin(OriginKind::Console));
        $this->locales[(string) $principal->id] = $locale;

        return $principal;
    }

    private function signIn(Principal $principal, ?string $device = null, ?string $address = null): StartedSession
    {
        return $this->sessions->start(new StartSession(new Realm('acme'), $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $this->clock->now(), true, true)), $device, $address), new Origin(OriginKind::Web));
    }

    /**
     * The one email sent since the last call.
     */
    private function only(): Email
    {
        self::assertCount(1, $this->mailer->sent);
        $email = $this->mailer->sent[0];
        $this->mailer->sent = [];

        return $email;
    }

    private static function paragraph(Email $email, int $block): string
    {
        $paragraph = $email->body[$block];
        \assert($paragraph instanceof Paragraph);

        return $paragraph->text;
    }

    /**
     * @return array<string, string>
     */
    private static function facts(Email $email): array
    {
        $facts = $email->body[1];
        \assert($facts instanceof Facts);

        return $facts->facts;
    }

    private static function url(Email $email): string
    {
        $action = $email->body[3];
        \assert($action instanceof Action);

        return $action->url;
    }

    /**
     * @return array{Secret, string} The token and the credential, from the email's link
     */
    private static function link(Email $email): array
    {
        parse_str((string) parse_url(self::url($email), \PHP_URL_QUERY), $query);
        \assert(\is_string($query['token'] ?? null) && \is_string($query['credential'] ?? null));

        return [Secret::fromString($query['token']), $query['credential']];
    }
}
