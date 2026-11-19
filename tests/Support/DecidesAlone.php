<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

use YorvoID\Core\Authorization\RequiresForbids;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\OperationDescription;

/**
 * The rest of an Authorization that isn't the Evaluator, for tests that give Operations one of their
 * own: it can't check forbids, checks no catalogue, doesn't decide offline, and lets a consequence's
 * parent decision hold as it is.
 */
trait DecidesAlone
{
    public function decideConsequence(Decision $parent, OperationDescription $operation, Command $command, Actor $actor, Origin $origin): Decision
    {
        return $parent;
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
