<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
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
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\SessionPolicy;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Core\Crypto\CryptoMode;
use YorvoID\Core\Crypto\CryptoPolicy;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Key\KeyAlgorithm;
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Key\SigningKey;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\Storage\DbalIdentifierStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Migration\LockProfile;
use YorvoID\Core\Storage\Migration\Migration;
use YorvoID\Core\Storage\Migration\MigrationContext;
use YorvoID\Core\Storage\Transactions;
use YorvoID\IntentSigning\Event\SignedOperationRan;
use YorvoID\IntentVerifier\Intent;
use YorvoID\IntentVerifier\OperationPolicy;
use YorvoID\Notifications\EmailVerification;
use YorvoID\Notifications\SecurityNotifications;
use YorvoID\Passkeys\ClonePolicy;
use YorvoID\Passkeys\PasskeyPolicy;
use YorvoID\Passkeys\UserVerification;
use YorvoID\Policy\Ordered\OrderedConditions;
use YorvoID\Symfony\Command\MigrateCommand;
use YorvoID\Tests\Support\Permits;
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
        self::assertSame(['consumer_site', '5m', '24h'], [$default->getParameter('yorvoid.preset'), $default->getParameter('yorvoid.step_up.window'), $default->getParameter('yorvoid.step_up.cool_down')]);
        self::assertSame('24h', $this->boot(['preset' => 'internal_tool'], fresh: true)->getParameter('yorvoid.step_up.cool_down'), 'Every preset waits after a recovery.');
    }

    public function testOrderedConditionsArePessimisticOnlyWhenTheApplicationSays(): void
    {
        self::assertEquals(new OrderedConditions(), $this->boot(fresh: true)->get(OrderedConditions::class), 'Off by default (MP-1).');
        self::assertEquals(new OrderedConditions(pessimistic: true), $this->boot(['ordered_conditions' => ['pessimistic' => true]], fresh: true)->get(OrderedConditions::class));
    }

    public function testTheFipsModeRefusesToStartWithoutAFipsProviderAlone(): void
    {
        $container = $this->boot(fresh: true);
        $standard = $container->get(CryptoPolicy::class);
        \assert($standard instanceof CryptoPolicy);
        self::assertSame(CryptoMode::Standard, $standard->mode, 'Standard by default (MP-1).');
        $passkeys = $container->get(PasskeyPolicy::class);
        \assert($passkeys instanceof PasskeyPolicy);
        self::assertSame([-7, -8, -257], $passkeys->algorithms, 'Passkeys take the policy\'s algorithms.');

        if ('1' === getenv('YORVOID_TEST_FIPS')) {
            $fips = $this->boot(['crypto' => ['mode' => 'fips']], fresh: true)->get(CryptoPolicy::class);
            \assert($fips instanceof CryptoPolicy);
            self::assertSame(CryptoMode::Fips, $fips->mode, 'The FIPS-configured image starts in the FIPS mode.');

            if ($fips->allowsEdDsa()) {
                return;
            }

            // Its provider has no Ed25519: with an Ed25519 key active, the checkpoints would stop, so it refuses (AUD-003).
            new Filesystem()->mkdir($this->directory, 0o700);
            $seed = random_bytes(32);
            $base64url = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
            file_put_contents($keys = $this->directory.'/keys.json', json_encode(['keys' => [['kid' => 'k', 'kty' => 'OKP', 'crv' => 'Ed25519', 'alg' => 'EdDSA', 'status' => 'verify-only', 'x' => $base64url(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed)))]]]));
            chmod($keys, 0o600);
            $active = (string) file_get_contents($keys);
            file_put_contents($keys, str_replace('"verify-only"', '"active"', substr($active, 0, -3)).',"d":"'.$base64url($seed).'"}]}');

            try {
                $this->boot(['crypto' => ['mode' => 'fips'], 'keys' => ['file' => $keys]], fresh: true);
                self::fail('It started with a key it can\'t sign with.');
            } catch (IntegratorError $e) {
                self::assertStringContainsString('The key "k" is active, but this OpenSSL can\'t sign with EdDSA', $e->getMessage());
            }

            // With an ES256 key active, it starts.
            new KeyRing([SigningKey::generate('es', algorithm: KeyAlgorithm::ES256)])->toFile($keys);
            $started = $this->boot(['crypto' => ['mode' => 'fips'], 'keys' => ['file' => $keys]], fresh: true)->get(CryptoPolicy::class);
            \assert($started instanceof CryptoPolicy);
            self::assertSame(CryptoMode::Fips, $started->mode);

            return;
        }

        try {
            $this->boot(['crypto' => ['mode' => 'fips']], fresh: true);
            self::fail('It started: the development image has OpenSSL\'s default provider.');
        } catch (IntegratorError $e) {
            self::assertStringContainsString('it computed MD5', $e->getMessage());
        }
    }

    public function testNotificationsOffNeedNoSettings(): void
    {
        $container = $this->boot(['notifications' => ['enabled' => false, 'application_name' => null, 'not_me_url' => null, 'from' => null]]);

        self::assertFalse($container->has(SecurityNotifications::class));
        self::assertFalse($container->has(EmailVerification::class), 'Without a mailer, nothing sends the link.');
        self::assertTrue($this->boot(fresh: true)->has(EmailVerification::class));
        self::assertTrue($this->kernel?->getContainer()->has(EmailVerification::class), 'The application can send a link again.');
    }

    public function testATokenRefusalWarnsInTheApplicationsLogger(): void
    {
        $container = $this->boot();
        $tokens = $container->get(OneTimeTokens::class);
        \assert($tokens instanceof OneTimeTokens);

        self::assertSame($container->get('logger'), (new \ReflectionProperty(OneTimeTokens::class, 'logger'))->getValue($tokens), 'Not a logger of its own, which nobody reads.');
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
        yield 'a completed path on another site' => [['flows' => ['completed_path' => 'https://example.org/']], 'is not a path on this site'];
        yield 'a completed path for any site' => [['flows' => ['completed_path' => '//example.org/']], 'is not a path on this site'];
        yield 'a completed path with a backslash browsers read as a slash' => [['flows' => ['completed_path' => '/\\example.org/']], 'is not a path on this site'];
        yield 'a completed path with a tab browsers drop' => [['flows' => ['completed_path' => "/\t/example.org/"]], 'is not a path on this site'];
        yield 'a completed path with a line break browsers drop' => [['flows' => ['completed_path' => "/\n/example.org/"]], 'is not a path on this site'];
        yield 'a completed path that ends with a line break' => [['flows' => ['completed_path' => "/home\n"]], 'is not a path on this site'];
        yield 'a completed path with a backslash later on' => [['flows' => ['completed_path' => '/home\\..\\x']], 'is not a path on this site'];
        yield 'a flow\'s completed path with a backslash' => [['flows' => ['completed_paths' => ['yorvoid.sign_in' => '/\\example.org/']]], 'is not a path on this site'];
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
        self::assertSame(0, $setup->execute(['--lifetime' => '1h'], ['capture_stderr_separately' => true]));
        self::assertMatchesRegularExpression('/^[0-9A-Z]{5}(-[0-9A-Z]{5}){4}-[0-9A-Z]$/D', trim($setup->getDisplay()), 'Only the code goes to standard output.');
        self::assertStringContainsString('That\'s the setup code for the realm "default": it\'s a secret.', (string) preg_replace('/\s+/', ' ', $setup->getErrorOutput()));
        self::assertStringContainsString('it works once, for 1h.', (string) preg_replace('/\s+/', ' ', $setup->getErrorOutput()));

        $principals = $container->get(Principals::class);
        \assert($principals instanceof Principals);
        Permits::call($principals->create(...), new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com', emailVerified: true), new Origin(OriginKind::Console));
        $link = new CommandTester($application->find('yorvoid:recovery-link'));
        self::assertSame(0, $link->execute(['email' => 'ANA@example.com', '--url' => 'https://example.com/recover/{token}']));
        self::assertMatchesRegularExpression('#^https://example\.com/recover/[0-9a-f]{32}\.[A-Za-z0-9_-]{43}$#m', trim($link->getDisplay()));
        self::assertSame(1, $link->execute(['email' => 'nobody@example.com']));
        self::assertStringContainsString('No one in the realm has that email address.', $link->getDisplay());

        $purge = new CommandTester($application->find('yorvoid:purge'));
        self::assertSame(0, $purge->execute([]));
        self::assertStringContainsString('0 sessions, 0 devices, 0 one-time tokens, 0 passkey ceremonies, 0 throttling keys, 0 cool-downs, 0 decisions.', (string) preg_replace('/\s+/', ' ', $purge->getDisplay()));

        // An address 0.1 stored in its lowercase form, and one that is now the same as another.
        $database = $this->database($container);
        Permits::call($principals->create(...), new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'Ána@Пример.рф'), new Origin(OriginKind::Console));
        Permits::call($principals->create(...), new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: "Jose\u{301}@example.com"), new Origin(OriginKind::Console));
        Permits::call($principals->create(...), new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'bob@example.com'), new Origin(OriginKind::Console));
        $table = $database->table(DbalIdentifierStore::TABLE);
        $database->connection->update($table, ['normalized' => 'ána@пример.рф'], ['normalized' => 'Ána@xn--e1afmkfd.xn--p1ai']);
        $normalize = new CommandTester($application->find('yorvoid:identifiers:normalize'));

        self::assertSame(0, $normalize->execute(['--dry-run' => true]));
        self::assertStringContainsString('Would change in the realm default: ána@пример.рф → Ána@xn--e1afmkfd.xn--p1ai', $normalize->getDisplay());
        self::assertSame(0, $normalize->execute([]));
        self::assertStringContainsString('1 email address(es) changed.', $normalize->getDisplay());
        self::assertNotNull($principals->findByIdentifier(Realm::default(), IdentifierKind::Email, 'Ána@ПРИМЕР.рф'));

        $database->connection->update($table, ['normalized' => "jose\u{301}@example.com", 'value' => 'José@example.com'], ['normalized' => "jos\u{E9}@example.com"]);
        $database->connection->update($table, ['normalized' => "jos\u{E9}@example.com", 'value' => "jos\u{E9}@example.com"], ['normalized' => 'bob@example.com']);
        self::assertSame(1, $normalize->execute([]));
        self::assertStringContainsString('is now the same as another one', (string) preg_replace('/\s+/', ' ', $normalize->getDisplay()));
    }

    #[Group('ed25519-keys')]
    public function testChecksTheSecurityLogAgainstItsCheckpoints(): void
    {
        $keys = $this->directory.'/keys.json';
        $file = $this->directory.'/checkpoints.jsonl';
        new Filesystem()->mkdir($this->directory, 0o700);
        new KeyRing([SigningKey::generate('2026-11-04')])->toFile($keys);
        $container = $this->boot(['keys' => ['file' => $keys], 'log' => ['checkpoint_file' => $file]]);
        $this->migrate();
        $application = new Application($this->kernel ?? throw new \LogicException());
        $checkpoint = new CommandTester($application->find('yorvoid:log:checkpoint'));
        $verify = new CommandTester($application->find('yorvoid:log:verify'));

        self::assertSame(0, $checkpoint->execute([]));
        self::assertStringContainsString('The realm "default" has no entries yet: nothing to checkpoint.', $checkpoint->getDisplay());

        $principals = $container->get(Principals::class);
        \assert($principals instanceof Principals);
        Permits::call($principals->create(...), new CreatePrincipal(Realm::default(), PrincipalKind::Human), new Origin(OriginKind::Console));
        self::assertSame(0, $checkpoint->execute([]));
        self::assertMatchesRegularExpression('/Checkpointed entry 1 of the realm "default", signed with the key "[^"]+"\./', $checkpoint->getDisplay());
        self::assertSame(0, $verify->execute([]));
        self::assertStringContainsString('1 entries of the realm "default" are intact, and match their checkpoints.', (string) preg_replace('/\s+/', ' ', $verify->getDisplay()));

        // Cut off, with the head moved back: only the checkpoint knows.
        $database = $this->database($container);
        $database->connection->executeStatement(\sprintf('DELETE FROM %s', $database->table(DbalSecurityLogStore::TABLE)));
        $database->connection->executeStatement(\sprintf('DELETE FROM %s', $database->table(DbalSecurityLogStore::HEAD_TABLE)));
        self::assertSame(1, $verify->execute([]));
        self::assertStringContainsString('isn\'t in the log: entries at the end were removed.', (string) preg_replace('/\s+/', ' ', $verify->getDisplay()));
    }

    public function testWithoutCheckpointsVerificationSaysWhatItCantSee(): void
    {
        $this->boot();
        $this->migrate();
        $application = new Application($this->kernel ?? throw new \LogicException());
        $verify = new CommandTester($application->find('yorvoid:log:verify'));

        self::assertSame(0, $verify->execute([]));
        self::assertStringContainsString('Without checkpoints, someone who can write the database could have cut', $verify->getDisplay());
        self::assertStringContainsString('yorvoid.log.checkpoint_file', $verify->getDisplay());

        try {
            new CommandTester($application->find('yorvoid:log:checkpoint'))->execute([]);
            self::fail('A checkpoint was taken without keys or a place to keep it.');
        } catch (IntegratorError $error) {
            self::assertSame('Checkpoints of the security log aren\'t configured.', $error->problem);
        }
    }

    public function testSendsNotificationsThroughSymfonyMailerAfterTheCommit(): void
    {
        $container = $this->boot();
        $this->migrate();
        $principals = $container->get(Principals::class);
        $sessions = $container->get(Sessions::class);
        \assert($principals instanceof Principals && $sessions instanceof Sessions);
        $ana = Permits::call($principals->create(...), new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com', emailVerified: true), new Origin(OriginKind::Console));
        $signIn = static fn (): mixed => Permits::call($sessions->start(...), new StartSession(Realm::default(), $ana->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable(), true, true)), 'Firefox on Linux'), new Origin(OriginKind::Web));

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
        $ana = Permits::call($principals->create(...), new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com', emailVerified: true), new Origin(OriginKind::Console));
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
        $ana = Permits::call($principals->create(...), new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com', emailVerified: true), new Origin(OriginKind::Console));

        for ($i = 0; $i < 2; ++$i) {
            Permits::call($sessions->start(...), new StartSession(Realm::default(), $ana->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable()))), new Origin(OriginKind::Web));
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
        $ana = Permits::call($principals->create(...), new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com', emailVerified: true), new Origin(OriginKind::Console));

        $started = Permits::call($sessions->start(...), new StartSession(Realm::default(), $ana->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, new \DateTimeImmutable()))), new Origin(OriginKind::Web));

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
        $ana = Permits::call($principals->create(...), new CreatePrincipal(Realm::default(), PrincipalKind::Human), new Origin(OriginKind::Console));

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
