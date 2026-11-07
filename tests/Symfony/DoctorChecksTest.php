<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Filesystem\Filesystem;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\Checkpoint\Checkpoint;
use YorvoID\Core\Log\Checkpoint\FileCheckpoints;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\Migration\Migrator;
use YorvoID\Symfony\Doctor\Check;
use YorvoID\Symfony\Doctor\ClockCheck;
use YorvoID\Symfony\Doctor\ConfigurationCheck;
use YorvoID\Symfony\Doctor\CookiesCheck;
use YorvoID\Symfony\Doctor\EnvironmentCheck;
use YorvoID\Symfony\Doctor\Finding;
use YorvoID\Symfony\Doctor\LogCheckpointsCheck;
use YorvoID\Symfony\Doctor\LogGrantsCheck;
use YorvoID\Symfony\Doctor\MailerCheck;
use YorvoID\Symfony\Doctor\MigrationsCheck;
use YorvoID\Symfony\Doctor\PresetCheck;
use YorvoID\Symfony\Doctor\ProxiesCheck;
use YorvoID\Symfony\Doctor\TestHelpersCheck;
use YorvoID\Symfony\Key\KeyFiles;
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

    public function testWarnsWhileTheApplicationCanRewriteTheSecurityLog(): void
    {
        $sqlite = $this->database('sqlite');
        new Migrator($sqlite, Migrations::all())->migrate();
        self::assertSame([], $this->whats(new LogGrantsCheck(static fn (): Database => $sqlite)), 'SQLite has no privileges to check');
        self::assertSame([], $this->whats(new LogGrantsCheck(static fn (): Database => throw new Unreachable('no route to host'))));

        $database = $this->database('postgresql');
        new Migrator($database, Migrations::all())->migrate();
        $log = $database->table('security_log');
        $head = $database->table('security_log_head');

        // The user that ran the migrations owns the tables, and can do anything to them.
        $findings = iterator_to_array(new LogGrantsCheck(static fn (): Database => $database)->findings(), false);
        self::assertCount(1, $findings);
        self::assertFalse($findings[0]->problem);
        self::assertSame(\sprintf('The application\'s database user can rewrite the security log, and verification wouldn\'t notice: UPDATE on %1$s, DELETE on %1$s, TRUNCATE on %1$s, DELETE on %2$s, TRUNCATE on %2$s.', $log, $head), $findings[0]->what);

        // An application user with only what recording needs.
        $connection = $database->connection;
        $role = 'yorvoid_app_'.bin2hex(random_bytes(4));
        $connection->executeStatement(\sprintf('CREATE ROLE %s', $role));

        try {
            $connection->executeStatement(\sprintf('GRANT USAGE ON SCHEMA %s TO %s', $connection->quoteSingleIdentifier((string) $database->location->schema), $role));
            $connection->executeStatement(\sprintf('GRANT SELECT, INSERT ON %s TO %s', $log, $role));
            $connection->executeStatement(\sprintf('GRANT SELECT, INSERT, UPDATE ON %s TO %s', $head, $role));
            $connection->executeStatement(\sprintf('SET ROLE %s', $role));

            self::assertSame([], $this->whats(new LogGrantsCheck(static fn (): Database => $database)));

            $connection->executeStatement('RESET ROLE');
            $connection->executeStatement(\sprintf('GRANT DELETE ON %s TO %s', $log, $role));
            $connection->executeStatement(\sprintf('SET ROLE %s', $role));

            self::assertSame([\sprintf('The application\'s database user can rewrite the security log, and verification wouldn\'t notice: DELETE on %s.', $log)], $this->whats(new LogGrantsCheck(static fn (): Database => $database)));
        } finally {
            $connection->executeStatement('RESET ROLE');
            $connection->executeStatement(\sprintf('DROP OWNED BY %s', $role));
            $connection->executeStatement(\sprintf('DROP ROLE %s', $role));
        }
    }

    public function testWarnsWithoutRecentCheckpointsOutsideDevelopment(): void
    {
        $file = \sprintf('%s/yorvoid-checkpoints-%s.jsonl', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $clock = new MockClock('2026-11-04 18:00:00 UTC');
        $check = static fn (?string $keys, ?string $at, bool $logger = false, bool $debug = false): array => array_map(static fn (Finding $finding): string => $finding->what, iterator_to_array(new LogCheckpointsCheck(new KeyFiles($keys), $at, $logger, Realm::default(), $clock, $debug)->findings(), false));

        try {
            self::assertSame([], $check(null, null, debug: true), 'Development has no checkpoints, and needs none.');
            self::assertStringStartsWith('The security log has no checkpoints', $check(null, $file)[0] ?? '');
            self::assertStringStartsWith('The security log has no checkpoints', $check('/keys.json', null)[0] ?? '');
            self::assertSame([], $check('/keys.json', null, logger: true), 'Checkpoints that go to the logger can\'t be read back here.');
            self::assertSame(['The newest checkpoint of the realm "default" is missing.'], $check('/keys.json', $file));

            $write = static function (string $at) use ($file): void {
                new FileCheckpoints($file)->write(new Checkpoint(Realm::default(), 1, 'hash', new \DateTimeImmutable($at), 'key', 'signature'));
            };
            $write('2026-11-03 17:59:59 UTC');
            self::assertSame(['The newest checkpoint of the realm "default" is from 2026-11-03T17:59:59+00:00.'], $check('/keys.json', $file));
            $write('2026-11-03 18:00:00 UTC');
            self::assertSame([], $check('/keys.json', $file), 'A day old is recent enough.');
        } finally {
            new Filesystem()->remove($file);
        }
    }

    public function testTheSessionNeedsSafeCookies(): void
    {
        $none = iterator_to_array(new CookiesCheck(new ParameterBag(), '__Host-yorvoid_device', true, true)->findings(), false);
        self::assertSame(['The application has no PHP session: YorvoID\'s flows and sign-in keep their state in it.'], array_map(static fn (Finding $finding): string => $finding->what, $none));
        self::assertTrue($none[0]->problem);

        $unsafe = new ParameterBag(['session.storage.options' => ['cookie_secure' => false, 'cookie_httponly' => false, 'cookie_samesite' => 'none']]);
        $findings = iterator_to_array(new CookiesCheck($unsafe, '__Host-yorvoid_device', true, true)->findings(), false);

        self::assertSame([
            'The session cookie is sent over plain HTTP too, where anyone on the network can take it and act as whoever is signed in.',
            'Scripts can read the session cookie: a cross-site scripting flaw would leak sessions.',
            'The session cookie\'s SameSite is "none": other sites\' requests carry it.',
        ], array_map(static fn (Finding $finding): string => $finding->what, $findings));
        self::assertSame('The session cookie\'s SameSite is not set: other sites\' requests carry it.', iterator_to_array(new CookiesCheck(new ParameterBag(['session.storage.options' => []]), '__Host-d', true, true)->findings(), false)[0]->what);
    }

    public function testOutsideDevelopmentTheSessionCookieIsAlwaysSecure(): void
    {
        $what = static fn (mixed $secure, bool $debug): array => array_map(static fn (Finding $finding): string => $finding->what, iterator_to_array(new CookiesCheck(new ParameterBag(['session.storage.options' => [...(null === $secure ? [] : ['cookie_secure' => $secure]), 'cookie_samesite' => 'lax']]), '__Host-d', true, $debug)->findings(), false));
        $auto = 'The session cookie is "Secure" only when the request looks like HTTPS: behind a proxy that terminates HTTPS, it isn\'t, and the cookie goes over plain HTTP too.';

        self::assertSame([$auto], $what('auto', false));
        self::assertSame([$auto], $what(null, false), '"auto" is Symfony\'s default.');
        self::assertSame([], $what(true, false));
        self::assertSame([], $what('auto', true), 'While developing, over plain HTTP, "auto" is right.');
        self::assertSame(['The session cookie is sent over plain HTTP too, where anyone on the network can take it and act as whoever is signed in.'], $what(false, true));
    }

    public function testNobodyChoosesTheirOwnAddress(): void
    {
        $everyone = 'Every sender is trusted as a proxy: anyone can name any address in X-Forwarded-For, and so slip past the limits per address, or exhaust someone else\'s.';
        $check = static fn (mixed $trusted): array => array_map(static fn (Finding $finding): array => [$finding->what, $finding->problem], iterator_to_array(new ProxiesCheck(new ParameterBag(null === $trusted ? [] : ['kernel.trusted_proxies' => $trusted]))->findings(), false));

        foreach (['0.0.0.0/0', ' ::/0 ', '10.0.0.0/8, 0.0.0.0/0', ['192.0.2.1', '::/0']] as $trusted) {
            self::assertSame([[$everyone, true]], $check($trusted), (string) json_encode($trusted));
        }

        foreach ([null, '', '10.0.0.0/8,192.0.2.0/24', 'REMOTE_ADDR', ['10.0.0.0/8', 'private_ranges'], '0.0.0.0/1', [5, null]] as $trusted) {
            self::assertSame([], $check($trusted), (string) json_encode($trusted));
        }
    }

    public function testProductionRunsWithoutDebugAndWithASecret(): void
    {
        self::assertSame([], iterator_to_array(new EnvironmentCheck('dev', true, '')->findings(), false), 'Development is development.');
        self::assertSame([], iterator_to_array(new EnvironmentCheck('prod', false, 'a-long-random-secret')->findings(), false));
        $findings = iterator_to_array(new EnvironmentCheck('prod', true, ' ')->findings(), false);

        self::assertSame(['Production runs with debug on: error pages show source code and stack traces to anyone.', 'Production has no APP_SECRET: what Symfony signs with it, e.g. links and CSRF tokens, can be made by anyone.'], array_map(static fn (Finding $finding): string => $finding->what, $findings));
        self::assertTrue($findings[0]->problem && $findings[1]->problem);
    }

    public function testTestHelpersAreAProblemOutsideDevelopment(): void
    {
        self::assertSame([], iterator_to_array(new TestHelpersCheck(true)->findings(), false));
        $findings = iterator_to_array(new TestHelpersCheck(false)->findings(), false);

        // This repository has both installed.
        self::assertSame(['yorvoid/testing is installed outside development', 'yorvoid/virtual-authenticator is installed outside development'], array_map(static fn (Finding $finding): string => (string) strstr($finding->what, ':', true), $findings));
        self::assertTrue($findings[0]->problem && $findings[1]->problem);
        self::assertSame('Install it with composer require --dev yorvoid/testing, and deploy with composer install --no-dev.', $findings[0]->fix);
    }

    public function testNotificationsNeedAnAddressAndATransportThatDelivers(): void
    {
        // Off: fine while developing, a warning in production.
        self::assertSame([], iterator_to_array(new MailerCheck(false, null, null, true)->findings(), false));
        $off = iterator_to_array(new MailerCheck(false, null, null, false)->findings(), false);
        self::assertSame(['Notifications are off: nobody hears about recoveries, new passkeys or new devices, so a taken-over account goes unnoticed.'], array_map(static fn (Finding $finding): string => $finding->what, $off));
        self::assertFalse($off[0]->problem);

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

        // The application's own mailer, maybe without Symfony Mailer: its transports aren't YorvoID's to check.
        self::assertSame([], iterator_to_array(new MailerCheck(true, 'security@example.com', null, false, own: true)->findings(), false));
        self::assertCount(1, iterator_to_array(new MailerCheck(true, 'security', null, false, own: true)->findings(), false), 'The address is checked all the same.');
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
        self::assertSame(['step_up.cool_down is none, weaker than the "consumer_site" preset\'s 1d.'], $this->whats(new PresetCheck('consumer_site', ['step_up.cool_down' => null] + Presets::ALL['consumer_site'])), 'The default preset doesn\'t mind a recovery without a wait.');
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
