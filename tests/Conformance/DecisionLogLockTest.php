<?php

declare(strict_types=1);

namespace YorvoID\Tests\Conformance;

use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\DecisionLog\DecisionLog;
use YorvoID\Core\Authorization\DecisionLog\Storage\DbalDecisionLogStore;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Event\OriginKind;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Log\Storage\DbalSecurityLogStore;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\Operator;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\Database;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\IssueBadge;
use YorvoID\Tests\Core\Operation\Fixtures\Badges\Member;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * Decisions don't take the realm's log lock (ROADMAP M2-4, ADR-09): while another connection holds
 * it, as a change being recorded does, a thousand decisions are recorded without waiting, and only
 * the security log waits.
 */
final class DecisionLogLockTest extends DatabaseTestCase
{
    public function testDecisionsAreRecordedWhileTheRealmsLogIsLocked(): void
    {
        $stack = $this->stack('postgresql');
        $database = $stack->database;
        \assert($database instanceof Database);
        $realm = new Realm('acme');
        $log = new SecurityLog($stack->log, $stack->transactions);
        $log->record($realm, 'acme.badge.issue', Outcome::Success, new Origin(OriginKind::Console));

        $other = $this->database('postgresql');
        $other->connection->beginTransaction();
        new DbalSecurityLogStore($other)->lock($realm);

        try {
            // Whatever would wait for that lock fails within a second instead of hanging the test.
            $database->connection->executeStatement("SET lock_timeout = '1s'");
            $store = new DbalDecisionLogStore($database);
            $decisions = new DecisionLog($store);
            $operation = new OperationDescription('acme.badge.issue', 'Issue a badge', [AuthorizedBy::Operator], null, null, IssueBadge::class, []);
            $ids = [];

            for ($i = 0; $i < 1000; ++$i) {
                $request = 0 === $i % 2
                    ? Request::forOperation($operation, new IssueBadge($realm, new PrincipalId(new UuidV7())), new Operator(), new Origin(OriginKind::Console), new \DateTimeImmutable())
                    : Request::forAbility('acme.pages.edit', $realm, new ResourceRef('page', (string) $i), new Member($realm, new PrincipalId(new UuidV7())), new Origin(OriginKind::Web), new \DateTimeImmutable());
                $ids[] = (string) $decisions->record($request, Decision::deny('No.')->identified((string) new UuidV7(), new \DateTimeImmutable(), ['policy' => 1]))->id;
            }

            $decisions->flush();

            self::assertCount(1000, $database->connection->fetchFirstColumn(\sprintf('SELECT id FROM %s', $database->table(DbalDecisionLogStore::TABLE))));
            self::assertNotNull($store->find($realm, $ids[999]));

            try {
                $log->record($realm, 'acme.badge.issue', Outcome::Success, new Origin(OriginKind::Console));
                self::fail('The security log didn\'t wait: the lock wasn\'t held.');
            } catch (DriverException $e) {
                self::assertSame('55P03', $e->getSQLState(), 'Only the security log waits for the lock.');
            }
        } finally {
            $other->connection->rollBack();
        }
    }
}
