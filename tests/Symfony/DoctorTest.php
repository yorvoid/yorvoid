<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use YorvoID\Symfony\Migrations;
use YorvoID\Symfony\Templates\TemplatePublisher;
use YorvoID\Tests\Symfony\App\TestKernel;

/**
 * yorvoid:doctor (PLT-081): what it finds in an application's setup, and how it says to fix it.
 *
 * Each test in a process of its own: the doctor renders the flow pages, and Twig names compiled
 * templates after their path in the project, the same in every test.
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
final class DoctorTest extends TestCase
{
    private string $directory;

    private ?TestKernel $kernel = null;

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid-doctor-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        new Filesystem()->remove($this->directory);
    }

    public function testAFreshInstallationNeedsItsMigrations(): void
    {
        [$status, $display] = $this->doctor();

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('✘ Database and migrations', $display);
        self::assertStringContainsString('Problem: '.\count(Migrations::all()).' of YorvoID\'s migrations haven\'t run: 20261027', $display);
        self::assertStringContainsString('How to fix it: Run yorvoid:migrate; --dry-run shows the SQL first.', $display);
        self::assertStringContainsString('Documentation: https://github.com/yorvoid/yorvoid/blob/main/docs/concepts/storage.md#migrations', $display);
        self::assertStringContainsString('1 problem, 0 warnings.', $display);
    }

    public function testAReadySetupPassesEveryCheck(): void
    {
        [$status, $display] = $this->doctor(migrate: true);

        self::assertSame(Command::SUCCESS, $status, $display);

        foreach (['Configuration', 'Database and migrations', 'Clock', 'Assurance level', 'Preset', 'Cookies', 'Mailer', 'Flow pages'] as $check) {
            self::assertStringContainsString('✔ '.$check, $display);
        }

        self::assertStringContainsString('YorvoID\'s setup is ready.', $display);
        self::assertStringNotContainsString('0 problems', $display);
    }

    public function testWarnsWhereTheConfigurationFallsShortOfItsAssuranceLevel(): void
    {
        $yorvoid = [
            'passkeys' => ['user_verification' => 'preferred'],
            'sessions' => ['absolute' => '48h', 'idle' => '2h'],
            'cookies' => ['device' => 'yorvoid_device'],
        ];
        [$status, $display] = $this->doctor($yorvoid, migrate: true);

        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertStringContainsString('! Assurance level', $display);
        self::assertStringContainsString('Warning: Passkeys don\'t require user verification: one used without it is a single factor, below the AAL2 that sessions.preset "aal2" targets.', $display);
        self::assertStringContainsString('Warning: Sessions last 2d: AAL2 asks to re-authenticate at least every 24h.', $display);
        self::assertStringContainsString('Warning: Sessions end after 2h idle: AAL2 asks for at most 1h.', $display);
        self::assertStringContainsString('! Cookies', $display);
        self::assertStringContainsString('Warning: The device cookie "yorvoid_device" has no "__Host-" prefix', $display);
        self::assertStringContainsString('0 problems, 4 warnings.', $display);

        [$strict] = $this->doctor($yorvoid, ['--strict' => true]);
        self::assertSame(Command::FAILURE, $strict);
    }

    public function testSessionsWithinTheirPresetsLimitsAreFine(): void
    {
        [, $display] = $this->doctor(['sessions' => ['preset' => 'console', 'absolute' => '24h', 'idle' => '1h', 'recognise_devices' => false], 'cookies' => ['device' => 'anything']], migrate: true);

        self::assertStringContainsString('✔ Assurance level', $display);
        self::assertStringContainsString('✔ Cookies', $display);
    }

    public function testComparesTheSettingsWithThePresets(): void
    {
        [$status, $display] = $this->doctor(['preset' => 'agent_platform', 'step_up' => ['cool_down' => null]], migrate: true);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('! Preset', $display);
        self::assertStringContainsString('Warning: step_up.cool_down is none, weaker than the "agent_platform" preset\'s 1d.', $display);
        self::assertStringContainsString('How to fix it: Use the preset\'s, by leaving step_up.cool_down out, unless you chose yours knowingly.', $display);
        self::assertStringContainsString('0 problems, 1 warning.', $display);
    }

    public function testSaysWhatAnAal3PresetDoesntReachYet(): void
    {
        [$status, $display] = $this->doctor(['sessions' => ['preset' => 'aal3']], migrate: true);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Warning: Sessions target AAL3, but YorvoID accepts synced passkeys, which AAL3 doesn\'t allow.', $display);
        self::assertStringContainsString('0 problems, 1 warning.', $display);
    }

    public function testReportsWhatYorvoIDRefusesWhenItBuildsItsServices(): void
    {
        [$status, $display] = $this->doctor([
            'passkeys' => ['relying_party_id' => 'Example.com', 'origins' => ['https://example.com']],
            'sessions' => ['idle' => 'a while'],
        ], migrate: true);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('✘ Configuration', $display);
        self::assertStringContainsString('Problem: "Example.com" isn\'t a domain to use as the relying party ID.', $display);
        self::assertStringContainsString('How to fix it: Use the site\'s domain in lowercase', $display);
        self::assertStringContainsString('Problem: "a while"', $display);
        self::assertStringContainsString('2 problems, 0 warnings.', $display);
    }

    public function testNoticesAClockThatDisagreesWithTheDatabase(): void
    {
        $this->boot();
        \assert(null !== $this->kernel);
        $clock = $this->kernel->getContainer()->get('clock');
        \assert($clock instanceof MockClock);
        $clock->modify('-1 hour');

        [$status, $display] = $this->doctor(migrate: true);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('✘ Clock', $display);
        self::assertStringContainsString('Problem: The application\'s clock is 3600 seconds behind the database\'s.', $display);
    }

    public function testOutsideDevelopmentAFrozenClockAndDroppedEmailsAreProblems(): void
    {
        [$status, $display] = $this->doctor(['passkeys' => ['relying_party_id' => 'localhost', 'origins' => ['http://localhost:8000']]], migrate: true, debugging: false);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Warning: Passkeys are created for "localhost" outside development', $display);
        self::assertStringContainsString('Problem: The application\'s clock is a MockClock outside development', $display);
        self::assertStringContainsString('Problem: Notifications go to a transport that drops them', $display);
        self::assertStringContainsString('2 problems, 1 warning.', $display);
    }

    public function testNoticesTemplatesThatFellBehindYorvoIDs(): void
    {
        [, $display] = $this->doctor(migrate: true);
        self::assertStringContainsString('✔ Flow pages', $display);

        // As if YorvoID changed the templates after they were published: the application changed its copy
        // of one, not of the other, which also lost what its page needs; and YorvoID dropped a third.
        $this->command('yorvoid:templates:publish', ['templates' => ['passkey', 'confirm', 'step_up']]);
        $before = '{# what YorvoID had before #}';
        file_put_contents($this->directory.'/'.TemplatePublisher::UPSTREAM.'/passkey.html.twig', $before);
        file_put_contents($this->directory.'/'.TemplatePublisher::UPSTREAM.'/confirm.html.twig', $before);
        file_put_contents($this->directory.'/'.TemplatePublisher::OVERRIDES.'/confirm.html.twig', $before);
        file_put_contents($this->directory.'/'.TemplatePublisher::UPSTREAM.'/gone.html.twig', $before);

        // A new process, as the next command would be: Twig remembers where it found each template.
        $this->kernel?->shutdown();
        $this->kernel = null;
        [$status, $display] = $this->doctor();

        self::assertSame(Command::FAILURE, $status, $display);
        self::assertStringContainsString('Problem: @YorvoID/flow/confirm.html.twig', $display);
        self::assertStringNotContainsString('Problem: @YorvoID/flow/passkey.html.twig', $display);
        self::assertStringContainsString('Warning: YorvoID changed the "passkey" template since you published it, and so did you.', $display);
        self::assertStringContainsString('How to fix it: Merge its changes into yours: yorvoid:templates:diff passkey shows them; then --accept.', $display);
        self::assertStringContainsString('Warning: YorvoID changed the "confirm" template since you published it, and your copy didn\'t change.', $display);
        self::assertStringContainsString('How to fix it: Take YorvoID\'s with yorvoid:templates:diff --accept confirm.', $display);
        self::assertStringContainsString('Warning: YorvoID no longer has the "gone" template you published: nothing uses your copy.', $display);
        self::assertStringContainsString(', 3 warnings.', $display);
    }

    /**
     * @param array<string, mixed> $yorvoid
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function doctor(array $yorvoid = [], array $input = [], bool $migrate = false, bool $debugging = true): array
    {
        $this->boot($yorvoid, $debugging);

        if ($migrate) {
            $this->command('yorvoid:migrate');
        }

        return $this->command('yorvoid:doctor', $input);
    }

    /**
     * @param array<string, mixed> $yorvoid
     */
    private function boot(array $yorvoid = [], bool $debugging = true): void
    {
        if (null !== $this->kernel) {
            return;
        }

        $this->kernel = new TestKernel($this->directory, $yorvoid, debugging: $debugging);
        $this->kernel->boot();
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{int, string}
     */
    private function command(string $name, array $input = []): array
    {
        $this->boot();
        \assert(null !== $this->kernel);
        $tester = new CommandTester(new Application($this->kernel)->find($name));
        $status = $tester->execute($input, ['decorated' => false]);

        return [$status, $tester->getDisplay()];
    }
}
