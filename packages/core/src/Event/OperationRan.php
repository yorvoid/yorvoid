<?php

declare(strict_types=1);

namespace YorvoID\Core\Event;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * An operation ran: dispatched for every operation, YorvoID's and the application's own, once its
 * transaction committed (after the operation's own "-ed" events, if it has any), and for each of its
 * consequences, which name the operation's decision as their parent. Without Transactions, it's
 * dispatched as soon as the operation's handler returns, its consequences' with it. Never for an
 * operation that was refused, failed or rolled back, nor for the consequences of one, and once for one
 * that ran again after a conflict.
 *
 * It carries IDs, never the records: look them up, or follow the decision ID to the security log.
 *
 * @api
 */
final readonly class OperationRan
{
    /**
     * @param string              $operation  The operation's name, e.g. "yorvoid.role.assign"
     * @param Command<mixed>|null $command    The command it ran, or null when the command holds a Secret: a
     *                                        listener never gets one
     * @param string              $decisionId The decision that permitted it
     * @param string|null         $parent     For a consequence, the decision of the operation that caused it
     * @param PrincipalId|null    $about      The principal the operation is about, if it's about one
     * @param ResourceRef|null    $created    What it created, for an operation that creates something that
     *                                        isn't a principal, e.g. a role
     */
    public function __construct(
        public string $operation,
        public Realm $realm,
        public ?Command $command,
        public ?PrincipalId $actor,
        public Origin $origin,
        public string $decisionId,
        public ?string $parent = null,
        public ?PrincipalId $about = null,
        public ?ResourceRef $created = null,
    ) {
    }
}
