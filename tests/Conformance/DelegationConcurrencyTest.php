<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Authorization\Actions;
use YorvoID\Core\Authorization\Delegable;
use YorvoID\Core\Authorization\ListsActions;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Principal\CreatePrincipal;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Core\Storage\RetryableConflict;
use YorvoID\Delegation\Chains;
use YorvoID\Delegation\Change\IssueDelegation;
use YorvoID\Delegation\Change\RevokeDelegation;
use YorvoID\Delegation\Delegation;
use YorvoID\Delegation\DelegationRefused;
use YorvoID\Delegation\Delegations;
use YorvoID\Tests\Support\DatabaseTestCase;
use YorvoID\Tests\Support\Permits;
use YorvoID\Tests\Support\RootsHold;
use YorvoID\Tests\Support\Stack;

/**
 * A parent revoked while a child is passed on from it, in two processes, each order, both isolations
 * (D-52): both take the realm's lock first, so whichever comes second waits and then sees the other.
 * A child is never left usable under a revoked parent: issued after the revocation, it's refused; issued
 * before it, its chain no longer holds.
 */
final class DelegationConcurrencyTest extends DatabaseTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function isolations(): iterable
    {
        yield 'read committed' => ['READ COMMITTED'];
        yield 'repeatable read' => ['REPEATABLE READ'];
    }

    /**
     * The revocation holds the lock; passing the delegation on waits, then refuses it.
     */
    #[DataProvider('isolations')]
    public function testAChildPassedOnWhileItsParentIsRevokedIsRefused(string $isolation): void
    {
        $stack = $this->stack('postgresql');
        [$delegations, $ana, $bea, $bot, $parent] = $this->chain($stack);
        $refused = null;
        $conflicts = 0;

        $this->race($stack, $isolation, ['revoke', (string) $parent->id, (string) $ana->id], static function () use ($delegations, $stack, $bea, $bot, $parent, &$refused, &$conflicts): void {
            for ($attempt = 1; $attempt <= 2 && null === $refused; ++$attempt) {
                try {
                    $stack->transactions->run(static fn () => Permits::call($delegations->issue(...), self::child($bot, $parent), new Origin(OriginKind::Web), $bea->id));
                    self::fail('Passed on from a revoked parent.');
                } catch (DelegationRefused $e) {
                    $refused = $e;
                } catch (RetryableConflict) {
                    ++$conflicts;
                }
            }
        });

        self::assertSame('parent_chain', $refused?->reason);
        self::assertSame('REPEATABLE READ' === $isolation ? 1 : 0, $conflicts, 'A waiter went on with what it saw before the revocation, or conflicted under READ COMMITTED.');
        self::assertSame([], array_values(array_filter($stack->delegations->involving(new Realm('acme'), $bot->id), static fn (Delegation $d): bool => $d->isActive())), 'No child was written.');
    }

    /**
     * Passing the delegation on holds the lock; the revocation waits, then commits: the child's chain
     * no longer holds.
     */
    #[DataProvider('isolations')]
    public function testARevocationWhileAChildIsPassedOnReachesTheChild(string $isolation): void
    {
        $stack = $this->stack('postgresql');
        [$delegations, $ana, $bea, $bot, $parent] = $this->chain($stack);
        $revoked = false;
        $conflicts = 0;

        $this->race($stack, $isolation, ['issue', (string) $parent->id, (string) $bea->id, (string) $bot->id], static function () use ($delegations, $stack, $ana, $parent, &$revoked, &$conflicts): void {
            for ($attempt = 1; $attempt <= 2 && !$revoked; ++$attempt) {
                try {
                    $revoked = $stack->transactions->run(static fn (): bool => Permits::call($delegations->revoke(...), new RevokeDelegation(new Realm('acme'), $parent->id, 'race'), new Origin(OriginKind::Web), $ana->id));
                } catch (RetryableConflict) {
                    ++$conflicts;
                }
            }
        });

        self::assertTrue($revoked);
        $children = $stack->delegations->involving(new Realm('acme'), $bot->id);
        self::assertCount(1, $children, 'The child committed first.');
        $chain = new Chains($stack->delegations, $stack->principals)->check($children[0], new \DateTimeImmutable());
        self::assertFalse($chain->valid, 'A child under a revoked parent: '.$chain->reason);
    }

    /**
     * Ana, who delegates publishing to Bea, who may pass it on once, to her agent.
     *
     * @return array{Delegations, Principal, Principal, Principal, Delegation}
     */
    private function chain(Stack $stack): array
    {
        $realm = new Realm('acme');
        $log = new SecurityLog($stack->log, $stack->transactions);
        $principals = new Principals($stack->principals, $stack->identifiers, $log, $stack->transactions);
        $create = static fn (CreatePrincipal $command): Principal => Permits::call($principals->create(...), $command, new Origin(OriginKind::Console));
        $ana = $create(new CreatePrincipal($realm, PrincipalKind::Human));
        $bea = $create(new CreatePrincipal($realm, PrincipalKind::Human));
        $bot = $create(new CreatePrincipal($realm, PrincipalKind::Agent, owner: $bea->id));
        $publish = new class implements ListsActions {
            public function actions(): array
            {
                return ['acme.pages.publish' => Delegable::Everyone];
            }
        };
        $delegations = new Delegations($stack->delegations, $stack->principals, new Actions(new OperationCatalogue([]), [$publish]), $log, $stack->transactions, roots: new RootsHold());
        $parent = Permits::call($delegations->issue(...), new IssueDelegation($realm, $bea->id, ['acme.pages.publish'], new Selector('page'), new \DateTimeImmutable('+2 days'), redelegable: ['acme.pages.publish'], depth: 1), new Origin(OriginKind::Web), $ana->id);

        return [$delegations, $ana, $bea, $bot, $parent];
    }

    private static function child(Principal $bot, Delegation $parent): IssueDelegation
    {
        return new IssueDelegation(new Realm('acme'), $bot->id, ['acme.pages.publish'], new Selector('page'), new \DateTimeImmutable('+1 day'), parent: $parent->id);
    }

    /**
     * The other process makes its change and holds it; this one runs its own meanwhile.
     *
     * @param list<string> $arguments
     */
    private function race(Stack $stack, string $isolation, array $arguments, \Closure $meanwhile): void
    {
        $database = $stack->database;
        \assert($database instanceof Database && null !== $database->location->schema);
        $start = \sprintf('%s/yorvoid-start-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        $process = proc_open([\PHP_BINARY, __DIR__.'/../Support/delegation-race.php', (string) getenv('YORVOID_TEST_POSTGRES_URL'), $database->location->schema, ...$arguments, $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $database->connection->executeStatement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL '.$isolation);

        try {
            touch($start);
            self::waitFor($start.'.ready');
            $meanwhile();
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            $exitCode = proc_close($process);
        } finally {
            unlink($start);

            if (file_exists($start.'.ready')) {
                unlink($start.'.ready');
            }

            $database->connection->executeStatement('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        self::assertSame(0, $exitCode, 'The other process failed: '.$output);
    }

    private static function waitFor(string $file): void
    {
        $deadline = microtime(true) + 10;

        while (!file_exists($file)) {
            if (microtime(true) > $deadline) {
                self::fail('The other process never got there.');
            }

            usleep(1_000);
        }
    }
}
