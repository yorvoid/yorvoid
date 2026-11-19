<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use Symfony\Component\Uid\UuidV7;
use YorvoID\Core\Authorization\RequiresForbids;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\OperationDescription;

/**
 * The rest of an Authorization that isn't the Evaluator, for tests that give Operations one of their
 * own: it can't check forbids, checks no catalogue, doesn't decide offline, and permits a consequence
 * as its parent was, with a decision of its own.
 */
trait DecidesAlone
{
    public function decideConsequence(Decision $parent, OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
    {
        // As the parent's, for the consequence: of its own, for its command and actor.
        return Decisions::bound(Decisions::identified(Decisions::of($parent->verdict, AuthorizedBy::Consequence, \sprintf('A consequence of %s.', $parent->id), [], id: (string) new UuidV7()), (string) new UuidV7(), new \DateTimeImmutable()), $command, $actor);
    }

    public function requireForbids(string $what, RequiresForbids $needing): void
    {
        throw new IntegratorError(\sprintf('%s needs forbids this can\'t check.', $what), 'Decide with the Evaluator.', 'concepts/authorization.md#policy-elements');
    }

    public function checkCatalogue(OperationCatalogue $catalogue): void
    {
    }

    public function decidesOffline(): bool
    {
        return false;
    }
}
