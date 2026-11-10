<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use YorvoID\Core\Crypto\CryptoPolicy;
use YorvoID\Core\Crypto\CryptoProvider;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Key\KeyAlgorithm;
use YorvoID\Core\Key\KeyRing;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\InMemorySecurityLogStore;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\InMemory\InMemoryTransactions;
use YorvoID\Symfony\Command\LogAnchorCommand;
use YorvoID\Symfony\Doctor\Finding;
use YorvoID\Symfony\Doctor\KeyAlgorithmsCheck;
use YorvoID\Symfony\Key\ConfiguredCheckpoints;
use YorvoID\Symfony\Key\KeyFiles;
use YorvoID\Tests\Support\RecordingDispatcher;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * Moving the signing keys from Ed25519 to ES256 (CRY-01, AUD-003): the rotation anchors its realm once
 * everything verifies, or leaves the ring as it was; yorvoid:log:anchor anchors the realms that share
 * the keys; the doctor names a realm that would fail verification in the FIPS mode, before the switch.
 */
final class Es256RotationTest extends TestCase
{
    private string $directory;

    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid-es256-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        new Filesystem()->remove($this->directory);
    }

    #[Group('ed25519-keys')]
    public function testMovingToEs256AnchorsTheRealmsAndTheDoctorSaysWhichAreLeft(): void
    {
        $container = $this->boot();
        $this->command('yorvoid:keys:rotate');
        self::assertSame(KeyAlgorithm::EdDSA, KeyRing::fromFile($this->keys())->signer()->algorithm, 'Ed25519 by default (MP-1).');

        foreach (['default', 'globex'] as $realm) {
            $this->record($container, $realm, 2);
            $this->command('yorvoid:log:checkpoint', ['--realm' => $realm]);
        }

        [$status, $display] = $this->command('yorvoid:keys:rotate', ['--algorithm' => 'ES256']);
        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertStringContainsString('ES256 verify-only', $display);

        [$status, $display] = $this->command('yorvoid:keys:rotate');
        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertStringContainsString('Anchored entry 2 of the realm "default" with it. Anchor the other realms that share these keys before moving to the FIPS mode: yorvoid:log:anchor --all.', $display);
        self::assertSame(KeyAlgorithm::ES256, KeyRing::fromFile($this->keys())->signer()->algorithm);

        // globex's Ed25519 checkpoint has no anchor yet: the doctor says so, before any switch.
        $check = $container->get(KeyAlgorithmsCheck::class);
        \assert($check instanceof KeyAlgorithmsCheck);
        self::assertSame(['Switching to the FIPS mode will fail verification for the realm "globex": its Ed25519 checkpoints, up to entry 2, have no anchor after them.'], array_map(static fn (Finding $finding): string => $finding->what, iterator_to_array($check->findings(), false)));

        [$status, $display] = $this->command('yorvoid:log:anchor', ['--all' => true]);
        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertStringContainsString('Anchored entry 2 of the realm "default"', $display);
        self::assertStringContainsString('Anchored entry 2 of the realm "globex"', $display);
        self::assertSame([], iterator_to_array($check->findings(), false));

        self::assertSame(Command::SUCCESS, $this->command('yorvoid:log:verify', ['--realm' => 'globex'])[0]);
        self::assertStringContainsString('"anchor":true', (string) file_get_contents($this->checkpoints()));
    }

    #[Group('ed25519-keys')]
    public function testARotationThatCantAnchorLeavesTheRingAsItWas(): void
    {
        $container = $this->boot();
        $this->command('yorvoid:keys:rotate');
        $this->record($container, 'default', 1);
        $this->command('yorvoid:log:checkpoint');
        $this->command('yorvoid:keys:rotate', ['--algorithm' => 'ES256']);
        $before = (string) file_get_contents($this->keys());

        // A checkpoint that no key signed: the log doesn't verify, so nothing is anchored.
        file_put_contents($this->checkpoints(), json_encode(['realm' => 'default', 'sequence' => 1, 'hash' => str_repeat('0', 64), 'taken_at' => 0, 'key' => 'nobody', 'signature' => base64_encode(str_repeat("\0", 64))])."\n", \FILE_APPEND);

        [$status, $display] = $this->command('yorvoid:keys:rotate');
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('The realm "default" can\'t be anchored: A checkpoint of entry 1 isn\'t signed by a key in the ring', $display);
        self::assertStringContainsString('The key ring is as it was.', $display);
        self::assertSame($before, file_get_contents($this->keys()));
    }

    public function testEs256KeysCheckpointAnchorAndVerify(): void
    {
        $container = $this->boot();
        [$status, $display] = $this->command('yorvoid:keys:rotate', ['--algorithm' => 'ES256']);
        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertSame(KeyAlgorithm::ES256, KeyRing::fromFile($this->keys())->signer()->algorithm);

        $this->record($container, 'default', 3);
        self::assertSame(Command::SUCCESS, $this->command('yorvoid:log:checkpoint')[0]);
        self::assertStringContainsString('Anchored entry 3 of the realm "default"', $this->command('yorvoid:log:anchor')[1]);
        [$status, $display] = $this->command('yorvoid:log:verify');
        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertStringContainsString('3 entries of the realm "default" are intact, and match their checkpoints.', $display);

        [$status, $display] = $this->command('yorvoid:keys:rotate', ['--algorithm' => 'RS256']);
        self::assertSame([Command::FAILURE, '[ERROR] --algorithm is ES256 or EdDSA.'], [$status, trim($display)]);
    }

    public function testAnchoringNeedsTheCheckpointFile(): void
    {
        $this->kernel = new TestKernel($this->directory, ['keys' => ['file' => $this->keys()]]);
        $this->kernel->boot();

        [$status, $display] = $this->command('yorvoid:log:anchor');
        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Anchoring checks the log against its checkpoints, read back from yorvoid.log.checkpoint_file: set it.', $display);
        self::assertStringNotContainsString('How to fix it', $display, 'It stops there.');
    }

    public function testAnchoringIsForTheStandardMode(): void
    {
        $store = new InMemorySecurityLogStore();
        $log = new SecurityLog($store, new InMemoryTransactions());
        $fips = CryptoPolicy::fips(new CryptoProvider('OpenSSL 3.5.7', true, true, false, null));
        $tester = new CommandTester(new LogAnchorCommand(new ConfiguredCheckpoints($store, $log, new KeyFiles($this->keys()), $this->checkpoints(), false, new MockClock(), $fips, new RecordingDispatcher())));

        self::assertSame(Command::FAILURE, $tester->execute([], ['decorated' => false]));
        self::assertStringContainsString('Anchoring checks every checkpoint, which the FIPS mode may not be able to: anchor in the standard crypto mode, before moving to it.', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertStringNotContainsString('How to fix it', $tester->getDisplay(), 'It stops there.');
    }

    private function boot(): ContainerInterface
    {
        new Filesystem()->mkdir($this->directory, 0o700);
        $this->kernel = new TestKernel($this->directory, ['keys' => ['file' => $this->keys()], 'log' => ['checkpoint_file' => $this->checkpoints()]]);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        \assert($container instanceof ContainerInterface);
        $this->command('yorvoid:migrate');

        return $container;
    }

    private function record(ContainerInterface $container, string $realm, int $entries): void
    {
        $log = $container->get(SecurityLog::class);
        \assert($log instanceof SecurityLog);

        for ($i = 0; $i < $entries; ++$i) {
            $log->record(new Realm($realm), 'yorvoid.principal.create', Outcome::Success, new Origin(OriginKind::Console));
        }
    }

    private function keys(): string
    {
        return $this->directory.'/keys.json';
    }

    private function checkpoints(): string
    {
        return $this->directory.'/checkpoints.jsonl';
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

        return [$status, (string) preg_replace('/\s+/', ' ', $tester->getDisplay())];
    }
}
