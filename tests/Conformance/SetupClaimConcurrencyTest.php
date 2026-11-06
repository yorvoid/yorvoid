<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Setup\CreateSetupCode;
use YorvoID\Authn\Setup\SetupCodes;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\LogEntry;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Throttle\Throttle;

/**
 * A setup code claims a realm only while it has no active principal (ATN-034), also when another
 * principal is created at the same moment, e.g. by an invitation accepted (architecture audit A-10).
 * The claim asks, then pauses before it creates the owner; another principal is created in that pause.
 * Either the claim made the realm's first principal, or it was refused: never an owner after someone else.
 */
final class SetupClaimConcurrencyTest extends DatabaseTestCase
{
    public function testAClaimNeverCreatesAnOwnerAfterAnotherPrincipal(): void
    {
        $stack = $this->stack('postgresql');
        $database = $stack->database;
        \assert($database instanceof Database && null !== $database->location->schema);
        $realm = new Realm('acme');
        $log = new SecurityLog($stack->log, $stack->transactions);
        $principals = new Principals($stack->principals, $stack->identifiers, $log, $stack->transactions);
        $codes = new SetupCodes($stack->setup, $principals, $log, $stack->transactions, new Attempts(new Throttle($stack->throttle)));
        $code = $codes->create(new CreateSetupCode($realm, Duration::days(1)), new Origin(OriginKind::Console));
        $start = \sprintf('%s/yorvoid-start-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $process = proc_open(
            [\PHP_BINARY, __DIR__.'/../Support/claim-setup.php', (string) getenv('YORVOID_TEST_POSTGRES_URL'), $database->location->schema, $code->reveal(), $start],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        try {
            touch($start);
            // Inside the claim's pause: it has asked, and not yet created the owner.
            usleep(200_000);
            $other = $principals->create(new CreatePrincipal($realm, PrincipalKind::Human), new Origin(OriginKind::Web));
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            $exitCode = proc_close($process);
        } finally {
            unlink($start);
        }

        self::assertContains($exitCode, [0, 3], 'The claim failed: '.$output);
        $entries = iterator_to_array($stack->log->entries($realm, 1), false);
        $created = array_values(array_map(static fn (LogEntry $entry): ?string => $entry->subjectId, array_filter($entries, static fn (LogEntry $entry): bool => 'yorvoid.principal.create' === $entry->action)));
        $claims = array_values(array_filter($entries, static fn (LogEntry $entry): bool => 'yorvoid.setup.claim' === $entry->action && Outcome::Success === $entry->outcome));

        if (0 === $exitCode) {
            self::assertCount(1, $claims);
            self::assertSame([$claims[0]->subjectId, (string) $other->id], $created, 'The owner was created after another principal.');
        } else {
            self::assertSame([], $claims);
            self::assertSame([(string) $other->id], $created);
        }
    }
}
