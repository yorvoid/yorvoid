<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Authorization;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\Verdict;

/**
 * An Authorization that decides every operation as it's told, Permit by default, with a decision of its
 * own each time: for tests of what Operations does around the decision, not of the decision.
 */
final readonly class PermitsEverything implements Authorization
{
    use DecidesAlone {
        decidesOffline as private alone;
    }

    public function __construct(private Verdict $verdict = Verdict::Permit, private bool $offline = false)
    {
    }

    public function decide(OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
    {
        return Decisions::bound(Decisions::identified(Decisions::of($this->verdict, Verdict::Permit === $this->verdict ? AuthorizedBy::Operator : null, 'As the test says.'), (string) new UuidV7(), new \DateTimeImmutable()), $command, $actor);
    }

    public function decidesOffline(): bool
    {
        return $this->offline;
    }
}
