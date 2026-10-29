<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Symfony\Doctor\Check;
use YorvoID\Symfony\Doctor\ClockCheck;
use YorvoID\Symfony\Doctor\ConfigurationCheck;
use YorvoID\Symfony\Doctor\CookiesCheck;
use YorvoID\Symfony\Doctor\Finding;
use YorvoID\Symfony\Doctor\MailerCheck;
use YorvoID\Symfony\Doctor\MigrationsCheck;
use YorvoID\Symfony\Doctor\PresetCheck;
use YorvoID\Symfony\Migrations;
use YorvoID\Symfony\Presets;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * yorvoid:doctor's checks on their own, for what an application's test kernel can't set up: other
 * databases, unreachable ones, other clocks, cookies and mailers.
 */
final class DoctorChecksTest extends DatabaseTestCase
{
    #[DataProvider('platforms')]
    public function testTheClockAgreesWithTheDatabasesWithinAFewSeconds(string $platform): void
    {
        $database = $this->database($platform);

        self::assertSame([], $this->whats(new ClockCheck(new MockClock('+3 seconds'), static fn (): Database => $database, true)));
        self::assertSame(['The application\'s clock is 3600 seconds ahead of the database\'s.'], $this->whats(new ClockCheck(new MockClock('+1 hour +500 milliseconds'), static fn (): Database => $database, true)));
        self::assertSame(['The application\'s clock is 3599 seconds behind the database\'s.'], $this->whats(new ClockCheck(new MockClock('-1 hour +500 milliseconds'), static fn (): Database => $database, true)));
    }

    public function testTheClockLeavesAnUnreachableDatabaseToTheMigrationsCheck(): void
    {
        self::assertSame([], $this->whats(new ClockCheck(new MockClock(), static fn (): Database => throw new Unreachable('no route to host'), true)));
        self::assertSame(['The rest is still checked.'], $this->whats(new ConfigurationCheck([
            static fn (): Database => throw new Unreachable('no route to host'),
            static fn (): object => throw new IntegratorError('The rest is still checked.', 'Nothing to fix.', 'concepts/symfony.md'),
        ], false)));

        $findings = iterator_to_array(new MigrationsCheck(static fn (): Database => throw new Unreachable('no route to host'))->findings(), false);
        self::assertCount(1, $findings);
        self::assertTrue($findings[0]->problem);
        self::assertSame('YorvoID can\'t reach its database: no route to host', $findings[0]->what);
    }

    public function testMigrationsFromALaterVersionAreAWarning(): void
    {
        $database = $this->database('sqlite');
        new Migrator($database, Migrations::all())->migrate();

        // This version of YorvoID knows only the first migration: the others came from a later one.
        $findings = iterator_to_array(new MigrationsCheck(static fn (): Database => $database, \array_slice(Migrations::all(), 0, 1))->findings(), false);

        self::assertCount(1, $findings);
        self::assertFalse($findings[0]->problem);
        self::assertStringStartsWith('The database has migrations this version of YorvoID doesn\'t know: ', $findings[0]->what);
    }

    public function testTheSessionNeedsSafeCookies(): void
    {
        $none = iterator_to_array(new CookiesCheck(new ParameterBag(), '__Host-yorvoid_device', true)->findings(), false);
        self::assertSame(['The application has no PHP session: YorvoID\'s flows and sign-in keep their state in it.'], array_map(static fn (Finding $finding): string => $finding->what, $none));
        self::assertTrue($none[0]->problem);

        $unsafe = new ParameterBag(['session.storage.options' => ['cookie_secure' => false, 'cookie_httponly' => false, 'cookie_samesite' => 'none']]);
        $findings = iterator_to_array(new CookiesCheck($unsafe, '__Host-yorvoid_device', true)->findings(), false);

        self::assertSame([
            'The session cookie is sent over plain HTTP too, where anyone on the network can take it and act as whoever is signed in.',
            'Scripts can read the session cookie: a cross-site scripting flaw would leak sessions.',
            'The session cookie\'s SameSite is "none": other sites\' requests carry it.',
        ], array_map(static fn (Finding $finding): string => $finding->what, $findings));
        self::assertSame('The session cookie\'s SameSite is not set: other sites\' requests carry it.', iterator_to_array(new CookiesCheck(new ParameterBag(['session.storage.options' => []]), '__Host-d', true)->findings(), false)[0]->what);
    }

    public function testNotificationsNeedAnAddressAndATransportThatDelivers(): void
    {
        self::assertSame([], iterator_to_array(new MailerCheck(false, null, null, false)->findings(), false));

        $findings = iterator_to_array(new MailerCheck(true, 'security at example', null, true)->findings(), false);

        self::assertSame([
            'Notifications come from "security at example", which isn\'t an email address.',
            'Notifications are on, but the application has no Symfony Mailer to send them with.',
        ], array_map(static fn (Finding $finding): string => $finding->what, $findings));
        self::assertTrue($findings[0]->problem && $findings[1]->problem);

        // Symfony's DSN when none is configured delivers nowhere; outside development, that's a problem.
        self::assertCount(1, iterator_to_array(new MailerCheck(true, 'security@example.com', ['main' => 'smtp://null'], false)->findings(), false));
        self::assertSame([], iterator_to_array(new MailerCheck(true, 'security@example.com', ['main' => 'smtp://null'], true)->findings(), false));
        self::assertSame([], iterator_to_array(new MailerCheck(true, 'security@example.com', ['main' => 'smtp://mail.example.com', 'other' => 'null://null'], false)->findings(), false));
    }

    public function testWarnsOfSettingsWeakerThanThePresetsOnly(): void
    {
        self::assertSame([], $this->whats(new PresetCheck('regulated', Presets::ALL['regulated'])));

        $stronger = ['sessions.preset' => 'aal3', 'sessions.recognise_devices' => true, 'step_up.window' => '1m', 'step_up.cool_down' => '2d', 'limits.per_account_and_address' => '3/15m', 'limits.per_account' => '50/2h', 'limits.per_address' => '100/15m'];
        self::assertSame([], $this->whats(new PresetCheck('regulated', $stronger)));

        $weaker = ['sessions.preset' => 'console', 'sessions.recognise_devices' => false, 'step_up.window' => '10m', 'step_up.cool_down' => '12h', 'limits.per_account_and_address' => '6/15m', 'limits.per_account' => '50/30m', 'limits.per_address' => '301/15m'];
        self::assertSame([
            'sessions.preset is "console", weaker than the "regulated" preset\'s "aal3".',
            'sessions.recognise_devices is false, weaker than the "regulated" preset\'s true.',
            'step_up.window is 10m, weaker than the "regulated" preset\'s 2m.',
            'step_up.cool_down is 12h, weaker than the "regulated" preset\'s 1d.',
            'limits.per_account_and_address is 6/15m, weaker than the "regulated" preset\'s 5/15m.',
            'limits.per_account is 50/30m, weaker than the "regulated" preset\'s 50/1h.',
            'limits.per_address is 301/15m, weaker than the "regulated" preset\'s 300/15m.',
        ], $this->whats(new PresetCheck('regulated', $weaker)));

        self::assertSame(['step_up.cool_down is none, weaker than the "agent_platform" preset\'s 1d.'], $this->whats(new PresetCheck('agent_platform', ['step_up.cool_down' => null] + Presets::ALL['agent_platform'])));
        // Idle time alone makes a session preset weaker.
        self::assertSame(['sessions.preset is "console", weaker than the "regulated" preset\'s "aal3".'], $this->whats(new PresetCheck('regulated', ['sessions.preset' => 'console'] + Presets::ALL['regulated'])));
        self::assertSame([], $this->whats(new PresetCheck('consumer_site', ['sessions.preset' => 'aal3'] + Presets::ALL['consumer_site'])));
        // Settings YorvoID can't read are the configuration check's.
        self::assertSame([], $this->whats(new PresetCheck('consumer_site', ['step_up.window' => 'soon'] + Presets::ALL['consumer_site'])));
    }

    /**
     * @return list<string>
     */
    private function whats(Check $check): array
    {
        return array_map(static fn (Finding $finding): string => $finding->what, iterator_to_array($check->findings(), false));
    }
}

/**
 * A database that can't be reached.
 */
final class Unreachable extends \RuntimeException implements DbalException
{
}
