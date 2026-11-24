<?php

declare(strict_types=1);

namespace YorvoID\Tests\Delegation;

use PHPUnit\Framework\Attributes\DataProvider;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Duration;
use YorvoID\Core\Operation\DelegationId;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Delegation\Delegation;
use YorvoID\Delegation\Storage\DbalDelegationStore;
use YorvoID\Testing\Ids;
use YorvoID\Tests\Support\DatabaseTestCase;

/**
 * A stored delegation that isn't one, e.g. edited by hand, is refused when read, never half read.
 */
final class DbalDelegationStoreTest extends DatabaseTestCase
{
    /**
     * @return iterable<string, array{string, string, string|null, class-string<\Throwable>, string}>
     */
    public static function malformed(): iterable
    {
        foreach (self::platforms() as $name => [$platform]) {
            yield $name.': not a list' => [$platform, 'actions', '"acme.pages.publish"', \UnexpectedValueException::class, 'A stored delegation is malformed.'];
            yield $name.': not only text' => [$platform, 'actions', '["acme.pages.publish", 1]', \UnexpectedValueException::class, 'A stored delegation\'s list is malformed.'];
            yield $name.': named, not listed' => [$platform, 'actions', '{"a": "acme.pages.publish"}', \UnexpectedValueException::class, 'A stored delegation\'s list is malformed.'];
            yield $name.': as deep as it may be' => [$platform, 'actions', '[[[[[[["a"]]]]]]]', \UnexpectedValueException::class, 'A stored delegation\'s list is malformed.'];
            yield $name.': deeper' => [$platform, 'actions', '[[[[[[[["a"]]]]]]]]', \JsonException::class, 'Maximum stack depth exceeded'];
            yield $name.': a step-up without its time' => [$platform, 'step_up', '{"within": "5m"}', \UnexpectedValueException::class, 'A stored delegation\'s step-up is malformed.'];
        }
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('malformed')]
    public function testRefusesAStoredDelegationThatIsntOne(string $platform, string $column, string $value, string $exception, string $message): void
    {
        $stack = $this->stack($platform);
        $store = $stack->delegations;
        $database = $stack->database ?? throw new \LogicException();
        $realm = new Realm('acme');
        $principal = new PrincipalId(Ids::next());
        $delegation = new Delegation(new DelegationId(Ids::next()), $realm, $principal, new PrincipalId(Ids::next()), null, $principal, 0, ['acme.pages.publish'], ['acme.pages.publish'], new Selector('page'), null, new \DateTimeImmutable('2026-11-23 18:00:00 UTC'), new \DateTimeImmutable('2026-11-30 18:00:00 UTC'), [], 0, null, new StepUpRemedy(Duration::minutes(5)), 'decision', 1, new \DateTimeImmutable('2026-11-23 18:00:00 UTC'));
        $transactions = $stack->transactions;
        $transactions->run(static function () use ($transactions, $store, $realm, $delegation): void {
            $transactions->lockRealm($realm);
            $store->add($delegation);
        });
        $database->connection->update($database->table(DbalDelegationStore::TABLE), [$column => $value], ['id' => $delegation->id->id->toString()]);

        $this->expectException($exception);
        $this->expectExceptionMessage($message);
        $store->get($realm, $delegation->id);
    }
}
