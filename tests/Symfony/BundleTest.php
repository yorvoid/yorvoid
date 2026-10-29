<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mailer\EventListener\MessageLoggerListener;
use Symfony\Component\Mime\Email;
use YorvoID\Authn\Attempt\AttemptLimits;
use YorvoID\Authn\Notification\SecurityNotifications;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\SessionPolicy;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;
use YorvoID\Core\Storage\Transactions;
use YorvoID\IntentVerifier\Intent;
use YorvoID\IntentVerifier\OperationPolicy;
use YorvoID\Passkeys\ClonePolicy;
use YorvoID\Passkeys\Intent\Event\SignedOperationRan;
use YorvoID\Passkeys\PasskeyPolicy;
use YorvoID\Passkeys\UserVerification;
use YorvoID\Symfony\Command\MigrateCommand;
use YorvoID\Tests\Symfony\App\TestKernel;
use YorvoID\Throttle\Limit;

/**
 * YorvoID's bundle in a Symfony application (PLT-010…014): configuration, services, commands, and the
 * mailer, translator and event glue.
 */
final class BundleTest extends TestCase
{
    private string $directory;

    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid/symfony-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        new Filesystem()->remove($this->directory);
    }

    public function testConfiguresYorvoIDFromItsDefaults(): void
    {
        $container = $this->boot();

        self::assertEquals(SessionPolicy::aal2(), $container->get(SessionPolicy::class));
        self::assertEquals(new PasskeyPolicy('example.com', ['https://example.com']), $container->get(PasskeyPolicy::class));
        self::assertEquals(new AttemptLimits(), $container->get(AttemptLimits::class));
        self::assertSame('yorvoid_', $this->database($container)->location->prefix);
    }

    public function testConfiguresWhatTheApplicationSays(): void
    {
        $container = $this->boot([
            'sessions' => ['preset' => 'console', 'idle' => '30m'],
            'passkeys' => ['user_verification' => 'preferred', 'clone_policy' => 'disable_passkey', 'timeout' => '2m'],
            'limits' => ['per_account' => '50/1h'],
            'storage' => ['prefix' => 'iam_'],
        ]);

        self::assertEquals(new SessionPolicy(Duration::hours(8), Duration::minutes(30)), $container->get(SessionPolicy::class));
        $policy = $container->get(PasskeyPolicy::class);
        \assert($policy instanceof PasskeyPolicy);
        self::assertSame([UserVerification::Preferred, ClonePolicy::DisablePasskey, 120], [$policy->userVerification, $policy->clonePolicy, $policy->timeout->seconds]);
        $limits = $container->get(AttemptLimits::class);
        \assert($limits instanceof AttemptLimits);
        self::assertEquals(new Limit(50, Duration::hours(1)), $limits->perAccount);
        self::assertSame('iam_', $this->database($container)->location->prefix);
        self::assertEquals(SessionPolicy::aal3(), $this->boot(['sessions' => ['preset' => 'aal3']], fresh: true)->get(SessionPolicy::class));

        $explicit = $this->boot(['sessions' => ['absolute' => '2h'], 'passkeys' => ['user_verification' => 'required', 'clone_policy' => 'refuse_sign_in'], 'recovery_codes' => ['count' => 1]], fresh: true);
        self::assertEquals(new SessionPolicy(Duration::hours(2), Duration::hours(1)), $explicit->get(SessionPolicy::class));
        self::assertEquals(new PasskeyPolicy('example.com', ['https://example.com']), $explicit->get(PasskeyPolicy::class));
        self::assertSame(1, $explicit->getParameter('yorvoid.recovery_codes.count'));
        self::assertSame(100, $this->boot(['recovery_codes' => ['count' => 100]], fresh: true)->getParameter('yorvoid.recovery_codes.count'));
        self::assertSame(10, $this->boot(fresh: true)->getParameter('yorvoid.recovery_codes.count'));
    }

    public function testAPresetGivesTheSettingsTheConfigurationLeavesOut(): void
    {
        $regulated = $this->boot(['preset' => 'regulated']);

        self::assertEquals(SessionPolicy::aal3(), $regulated->get(SessionPolicy::class));
        self::assertEquals(new AttemptLimits(new Limit(5, Duration::minutes(15)), new Limit(50, Duration::hours(1))), $regulated->get(AttemptLimits::class));
        self::assertSame(['24h', '2m'], [$regulated->getParameter('yorvoid.step_up.cool_down'), $regulated->getParameter('yorvoid.step_up.window')]);

        // What the configuration says wins, ~ included.
        $own = $this->boot(['preset' => 'regulated', 'sessions' => ['preset' => 'console'], 'step_up' => ['cool_down' => null], 'limits' => ['per_address' => '50/15m']], fresh: true);
        self::assertEquals(new SessionPolicy(Duration::hours(8), Duration::hours(1)), $own->get(SessionPolicy::class));
        self::assertNull($own->getParameter('yorvoid.step_up.cool_down'));
        self::assertSame('50/15m', $own->getParameter('yorvoid.limits.per_address'));

        self::assertSame('1000/15m', $this->boot(['preset' => 'internal_tool'], fresh: true)->getParameter('yorvoid.limits.per_address'));
        $default = $this->boot(fresh: true);
        self::assertSame(['consumer_site', '5m', null], [$default->getParameter('yorvoid.preset'), $default->getParameter('yorvoid.step_up.window'), $default->getParameter('yorvoid.step_up.cool_down')]);
    }

    public function testNotificationsOffNeedNoSettings(): void
    {
        $container = $this->boot(['notifications' => ['enabled' => false, 'application_name' => null, 'not_me_url' => null, 'from' => null]]);

        self::assertFalse($container->has(SecurityNotifications::class));
    }

    public function testAddsTheMiddlewareToYorvoIDsConnectionOnly(): void
    {
        $container = $this->boot();

        self::assertTrue($container->has('yorvoid.transaction_middleware.default'));
        self::assertFalse($container->has('yorvoid.transaction_middleware.other'), 'YorvoID\'s middleware went on another connection of the application.');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function mistakes(): iterable
    {
        yield 'no relying party' => [['passkeys' => ['relying_party_id' => '']], 'yorvoid.passkeys.relying_party_id'];
        yield 'no origins' => [['passkeys' => ['origins' => []]], 'yorvoid.passkeys.origins'];
        yield 'a limit that isn\'t one' => [['limits' => ['per_address' => '300 per hour']], 'is not a limit: write attempts and a period, e.g. "10/15m".'];
        yield 'notifications without a sender' => [['notifications' => ['from' => null]], 'Notifications need application_name, not_me_url and from'];
        yield 'notifications without a name' => [['notifications' => ['application_name' => null]], 'Notifications need application_name, not_me_url and from'];
        yield 'notifications without a page' => [['notifications' => ['not_me_url' => null]], 'Notifications need application_name, not_me_url and from'];
        yield 'no recovery codes' => [['recovery_codes' => ['count' => 0]], 'yorvoid.recovery_codes.count'];
        yield 'too many recovery codes' => [['recovery_codes' => ['count' => 101]], 'yorvoid.recovery_codes.count'];
        yield 'an unknown preset' => [['sessions' => ['preset' => 'forever']], 'yorvoid.sessions.preset'];
        yield 'an unknown configuration preset' => [['preset' => 'bank'], 'yorvoid.preset'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('mistakes')]
    public function testRefusesConfigurationMistakes(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->boot($config);
    }

    public function testMigratesAndRunsTheCommands(): void
    {
        $container = $this->boot();
        $application = new Application($this->kernel ?? throw new \LogicException());

        $migrate = new CommandTester($application->find('yorvoid:migrate'));
        $migrate->execute(['--dry-run' => true]);
        self::assertMatchesRegularExpression('/^CREATE TABLE yorvoid_principal \(.*\);$/m', $migrate->getDisplay());
        self::assertMatchesRegularExpression('/^CREATE TABLE yorvoid_throttle_key \(.*\);$/m', $migrate->getDisplay());
        self::assertSame(0, $migrate->execute([]));
        self::assertStringContainsString('Ran ', $migrate->getDisplay());
        $migrate->execute([]);
        self::assertStringContainsString('YorvoID\'s tables are up to date.', $migrate->getDisplay());

        $setup = new CommandTester($application->find('yorvoid:setup-code'));
        self::assertSame(0, $setup->execute(['--lifetime' => '1h']));
        self::assertMatchesRegularExpression('/^[0-9A-Z]{5}(-[0-9A-Z]{5}){4}-[0-9A-Z]$/m', $setup->getDisplay());

        $principals = $container->get(Principals::class);
        \assert($principals instanceof Principals);
        $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com', emailVerified: true), new Origin(OriginKind::Console));
        $link = new CommandTester($application->find('yorvoid:recovery-link'));
        self::assertSame(0, $link->execute(['email' => 'ANA@example.com', '--url' => 'https://example.com/recover/{token}']));
        self::assertMatchesRegularExpression('#^https://example\.com/recover/[0-9a-f]{32}\.[A-Za-z0-9_-]{43}$#m', trim($link->getDisplay()));
        self::assertSame(1, $link->execute(['email' => 'nobody@example.com']));
        self::assertStringContainsString('No one in the realm has that email address.', $link->getDisplay());

        $purge = new CommandTester($application->find('yorvoid:purge'));
        self::assertSame(0, $purge->execute([]));
        self::assertStringContainsString('0 sessions, 0 devices, 0 one-time tokens, 0 passkey ceremonies, 0 throttling keys, 0 cool-downs.', (string) preg_replace('/\s+/', ' ', $purge->getDisplay()));
    }

    public function testSendsNotificationsThroughSymfonyMailerAfterTheCommit(): void
    {
        $container = $this->boot();
        $this->migrate();
        $principals = $container->get(Principals::class);
        $sessions = $container->get(Sessions::class);
        \assert($principals instanceof Principals && $sessions instanceof Sessions);
        $ana = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com', emailVerified: true), new Origin(OriginKind::Console));
        $signIn = static fn (): mixed => $sessions->start(new StartSession(Realm::default(), $ana->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable(), true, true)), 'Firefox on Linux'), new Origin(OriginKind::Web));

        // Inside the application's own transaction, as a Symfony controller's work often is.
        $transactions = $container->get(Transactions::class);
        \assert($transactions instanceof Transactions);
        $transactions->run($signIn);
        $transactions->run($signIn);

        $logger = $container->get('mailer.message_logger_listener');
        \assert($logger instanceof MessageLoggerListener);
        $messages = $logger->getEvents()->getMessages();
        self::assertCount(1, $messages);
        $email = $messages[0];
        \assert($email instanceof Email);
        self::assertSame('New sign-in to your Acme account', $email->getSubject());
        self::assertSame('security@example.com', $email->getFrom()[0]->getAddress());
        self::assertSame('ana@example.com', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('Firefox on Linux', (string) $email->getTextBody());
        self::assertStringContainsString('<html lang="en" dir="ltr">', (string) $email->getHtmlBody());
    }

    public function testRunsBlockingMigrationsOnlyWhenAllowed(): void
    {
        $container = $this->boot();
        $blocking = new class implements Migration {
            public function version(): string
            {
                return '20990101000000_blocking';
            }

            public function description(): string
            {
                return 'Blocks';
            }

            public function lockProfile(): LockProfile
            {
                return LockProfile::Blocking;
            }

            public function up(MigrationContext $context): void
            {
            }
        };
        $command = new CommandTester(new MigrateCommand($this->database($container), [$blocking]));

        try {
            $command->execute([]);
            self::fail('A blocking migration ran without being allowed.');
        } catch (IntegratorError $exception) {
            self::assertStringStartsWith('The migration 20990101000000_blocking blocks tables while it runs.', $exception->getMessage());
        }

        self::assertSame(0, $command->execute(['--allow-blocking' => true]));
        self::assertStringContainsString('20990101000000_blocking', $command->getDisplay());
    }

    public function testEmailsAfterASignedOperation(): void
    {
        $container = $this->boot();
        $this->migrate();
        $principals = $container->get(Principals::class);
        $dispatcher = $container->get('event_dispatcher');
        \assert($principals instanceof Principals && $dispatcher instanceof EventDispatcherInterface);
        $ana = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com', emailVerified: true), new Origin(OriginKind::Console));
        $intent = new Intent(OperationPolicy::REMOVE_KEY, ['credential_id' => 'x'], 'acme', 'n', new \DateTimeImmutable('@0'), new \DateTimeImmutable('@1'));

        $dispatcher->dispatch(new SignedOperationRan(Realm::default(), $ana->id, $intent, 'k', new \DateTimeImmutable(), new Origin(OriginKind::System)));

        $logger = $container->get('mailer.message_logger_listener');
        \assert($logger instanceof MessageLoggerListener);
        $email = $logger->getEvents()->getMessages()[0] ?? null;
        \assert($email instanceof Email);
        self::assertSame('An operation you signed ran on Acme', $email->getSubject());
        self::assertStringContainsString('Stop a passkey from signing operations', (string) $email->getTextBody());
    }

    public function testWorksWithoutSymfonysTranslator(): void
    {
        $container = $this->boot(translations: false);
        $this->migrate();
        $principals = $container->get(Principals::class);
        $sessions = $container->get(Sessions::class);
        \assert($principals instanceof Principals && $sessions instanceof Sessions);
        $ana = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com', emailVerified: true), new Origin(OriginKind::Console));

        for ($i = 0; $i < 2; ++$i) {
            $sessions->start(new StartSession(Realm::default(), $ana->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable()))), new Origin(OriginKind::Web));
        }

        $logger = $container->get('mailer.message_logger_listener');
        \assert($logger instanceof MessageLoggerListener);
        $email = $logger->getEvents()->getMessages()[0] ?? null;
        \assert($email instanceof Email);
        self::assertSame('New sign-in to your Acme account', $email->getSubject());
    }

    public function testNotificationsAndDeviceRecognitionCanBeOff(): void
    {
        $container = $this->boot(['notifications' => ['enabled' => false], 'sessions' => ['recognise_devices' => false]]);
        $this->migrate();
        $principals = $container->get(Principals::class);
        $sessions = $container->get(Sessions::class);
        \assert($principals instanceof Principals && $sessions instanceof Sessions);
        $ana = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com', emailVerified: true), new Origin(OriginKind::Console));

        $started = $sessions->start(new StartSession(Realm::default(), $ana->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable()))), new Origin(OriginKind::Web));

        self::assertNull($started->deviceCookie);
        self::assertFalse($container->has(SecurityNotifications::class));
    }

    public function testTheCoolDownAndWindowAreConfigurable(): void
    {
        $container = $this->boot(['step_up' => ['cool_down' => '24h', 'window' => '10m']]);
        $this->migrate();
        $principals = $container->get(Principals::class);
        $stepUp = $container->get(StepUp::class);
        \assert($principals instanceof Principals && $stepUp instanceof StepUp);
        $ana = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human), new Origin(OriginKind::Console));

        $stepUp->startCoolDown(Realm::default(), $ana->id, new Origin(OriginKind::Console));

        self::assertNotNull($stepUp->coolDownEndsAt(Realm::default(), $ana->id));
    }

    /**
     * @param array<string, mixed> $yorvoid
     */
    private function boot(array $yorvoid = [], bool $translations = true, bool $fresh = false): ContainerInterface
    {
        if ($fresh) {
            $this->kernel?->shutdown();
            new Filesystem()->remove($this->directory.'/cache');
        }

        $this->kernel = new TestKernel($this->directory, $yorvoid, $translations);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        return $container;
    }

    private function migrate(): void
    {
        new CommandTester(new Application($this->kernel ?? throw new \LogicException())->find('yorvoid:migrate'))->execute([]);
    }

    private function database(ContainerInterface $container): Database
    {
        $database = $container->get(Database::class);
        \assert($database instanceof Database);

        return $database;
    }
}
