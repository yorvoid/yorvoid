<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Authn\Token\IssueToken;
use YorvoID\Authn\Token\OneTimeTokens;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Key\KeyStatus;
use YorvoID\Core\Key\SigningKey;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * Rotating the signing keys with yorvoid:keys:rotate (OPS-003), with sessions and tokens in flight
 * (OPS-045): what the old key signed keeps verifying until it's removed, and nothing people hold stops
 * working. Sessions and one-time tokens are random secrets kept as hashes, not signed, so no rotation
 * reaches them; this test keeps it so. Intents in flight while the verifier's keys rotate are in
 * VerifierTest.
 */
final class KeyRotationTest extends TestCase
{
    private string $directory;

    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid-keys-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        new Filesystem()->remove($this->directory);
    }

    public function testRotatesStepByStepWithSessionsAndTokensInFlight(): void
    {
        $container = $this->boot();
        $this->command('yorvoid:migrate');
        $clock = $container->get('clock');
        \assert($clock instanceof MockClock);
        $clock->modify('2026-10-29 18:00:00');

        [$status, $display] = $this->command('yorvoid:keys:rotate');
        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Created the key ring in '.$this->file().', with 2026-10-29 active.', $display);
        self::assertSame(0o600, fileperms($this->file()) & 0o777);

        // In flight: a signature by the first key, a session, a one-time token.
        $signature = KeyRing::fromFile($this->file())->signer()->sign('checkpoint 1');
        [$session, $token] = $this->inFlight($container);

        // Sessions end after an hour idle: the rotation happens within it.
        $clock->modify('+10 minutes');
        [, $display] = $this->command('yorvoid:keys:rotate');
        self::assertStringContainsString('Added 2026-10-29.2, verify-only. Deploy the key file everywhere signatures are checked, then run yorvoid:keys:rotate again to make it active.', $display);
        $this->assertStillWorking($container, $signature, $session);

        [, $display] = $this->command('yorvoid:keys:rotate');
        self::assertStringContainsString('2026-10-29.2 signs now, and 2026-10-29 only verifies. Once nothing 2026-10-29 signed still needs checking, run: yorvoid:keys:rotate --remove 2026-10-29', $display);
        self::assertSame(['2026-10-29' => KeyStatus::VerifyOnly, '2026-10-29.2' => KeyStatus::Active], $this->statuses());
        self::assertStringContainsString('Key Status -------------- ------------- 2026-10-29 verify-only 2026-10-29.2 active', $display);
        $this->assertStillWorking($container, $signature, $session);

        [, $display] = $this->command('yorvoid:keys:rotate', ['--remove' => '2026-10-29']);
        self::assertStringContainsString('Removed 2026-10-29: what it signed no longer verifies.', $display);
        self::assertSame(['2026-10-29.2' => KeyStatus::Active], $this->statuses());
        self::assertFalse(KeyRing::fromFile($this->file())->verify('2026-10-29', 'checkpoint 1', $signature));
        self::assertNotNull($this->sessions($container)->validate(Realm::default(), $session), 'Removing a key ended a session.');

        $tokens = $container->get(OneTimeTokens::class);
        \assert($tokens instanceof OneTimeTokens);
        self::assertNotNull($tokens->consume(Realm::default(), 'acme.invitation', $token, new Origin(OriginKind::Web)), 'A token issued before the rotation stopped working.');
    }

    public function testOnlyAKeyAddedAfterTheActiveOneThatCanSignBecomesActive(): void
    {
        $this->boot();
        $today = new \DateTimeImmutable()->format('Y-m-d');

        // An old key before the active one, and a newer one without its private part: neither can take over.
        new KeyRing([
            SigningKey::generate('older', KeyStatus::VerifyOnly),
            SigningKey::generate($today),
            SigningKey::generate('elsewhere', KeyStatus::VerifyOnly)->withoutPrivatePart(),
        ])->toFile($this->file());

        [, $display] = $this->command('yorvoid:keys:rotate');

        // A second key of the day is numbered.
        self::assertStringContainsString(\sprintf('Added %s.2, verify-only.', $today), $display);
        self::assertSame(['older' => KeyStatus::VerifyOnly, $today => KeyStatus::Active, 'elsewhere' => KeyStatus::VerifyOnly, $today.'.2' => KeyStatus::VerifyOnly], $this->statuses());
    }

    public function testSaysWhatsWrongInsteadOfRotating(): void
    {
        $this->boot(keys: false);
        [$status, $display] = $this->command('yorvoid:keys:rotate');
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('YorvoID has no key file to rotate: set yorvoid.keys.file', $display);

        $this->kernel?->shutdown();
        $this->kernel = null;
        new Filesystem()->remove($this->directory.'/cache');
        $this->boot();
        $this->command('yorvoid:keys:rotate');
        $active = KeyRing::fromFile($this->file())->signer()->id;
        [$status, $display] = $this->command('yorvoid:keys:rotate', ['--remove' => $active]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(\sprintf('The key "%s" is active: it can\'t be removed.', $active), $display);
        self::assertStringContainsString('How to fix it: Make another key active first', $display);
    }

    public function testTheDoctorChecksTheKeyFile(): void
    {
        $this->boot();
        $this->command('yorvoid:migrate');

        [$status, $display] = $this->command('yorvoid:doctor');
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(\sprintf('Problem: The key file "%s" doesn\'t exist.', $this->file()), $display);
        self::assertStringContainsString('How to fix it: Create it with yorvoid:keys:rotate.', $display);

        $this->command('yorvoid:keys:rotate');
        self::assertStringContainsString('✔ Keys', $this->command('yorvoid:doctor')[1]);

        chmod($this->file(), 0o644);
        [, $display] = $this->command('yorvoid:doctor');
        self::assertStringContainsString('✘ Keys', $display);
        self::assertStringContainsString('can be read by others than its owner (mode 644)', $display);
        self::assertStringContainsString('How to fix it: Run: chmod 600 '.$this->file(), $display);
    }

    /**
     * @return array{\YorvoID\Core\Secret, \YorvoID\Core\Secret} The session's token and a one-time token
     */
    private function inFlight(ContainerInterface $container): array
    {
        $principals = $container->get(Principals::class);
        $sessions = $container->get(Sessions::class);
        $tokens = $container->get(OneTimeTokens::class);
        \assert($principals instanceof Principals && $sessions instanceof Sessions && $tokens instanceof OneTimeTokens);
        $ana = $principals->create(new CreatePrincipal(Realm::default(), PrincipalKind::Human, email: 'ana@example.com', emailVerified: true), new Origin(OriginKind::Console));
        $now = $container->get('clock');
        \assert($now instanceof MockClock);
        $now = $now->now();
        $session = $sessions->start(new StartSession(Realm::default(), $ana->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::Passkey, $now, true, true))), new Origin(OriginKind::Web));
        $token = $tokens->issue(new IssueToken(Realm::default(), $ana->id, 'acme.invitation', Duration::days(7)), new Origin(OriginKind::Console));

        return [$session->token, $token->secret];
    }

    private function assertStillWorking(ContainerInterface $container, string $signature, \YorvoID\Core\Secret $session): void
    {
        $ring = KeyRing::fromFile($this->file());
        self::assertTrue($ring->verify('2026-10-29', 'checkpoint 1', $signature), 'What the first key signed stopped verifying.');
        self::assertTrue($ring->verify($ring->signer()->id, 'checkpoint 2', $ring->signer()->sign('checkpoint 2')));
        self::assertNotNull($this->sessions($container)->validate(Realm::default(), $session), 'A rotation ended a session.');
    }

    private function sessions(ContainerInterface $container): Sessions
    {
        $sessions = $container->get(Sessions::class);
        \assert($sessions instanceof Sessions);

        return $sessions;
    }

    /**
     * @return array<string, KeyStatus>
     */
    private function statuses(): array
    {
        return array_column(array_map(static fn (SigningKey $key): array => [$key->id, $key->status], KeyRing::fromFile($this->file())->keys()), 1, 0);
    }

    private function file(): string
    {
        return $this->directory.'/keys.json';
    }

    private function boot(bool $keys = true): ContainerInterface
    {
        $this->kernel = new TestKernel($this->directory, $keys ? ['keys' => ['file' => $this->file()]] : []);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);

        return $container;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function command(string $name, array $input = []): array
    {
        \assert(null !== $this->kernel);
        $tester = new CommandTester(new Application($this->kernel)->find($name));
        $status = $tester->execute($input, ['decorated' => false]);

        // The display's lines wrap; the text doesn't.
        return [$status, (string) preg_replace('/\s+/', ' ', $tester->getDisplay())];
    }
}
