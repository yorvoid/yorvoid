<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Realm;

/**
 * Asks whether an actor holds an ability, for what isn't one of YorvoID's operations: an application's
 * own "edit this page" or "see this report". The evaluator decides as it does for operations, with the
 * roles, the rules and every other element.
 *
 * ```php
 * if (!$policy->allows($session, 'acme.pages.edit', $realm, new ResourceRef('page', $id), $origin)) { ... }
 * ```
 *
 * @api
 */
final readonly class Policy
{
    public function __construct(
        private Evaluator $evaluator,
    ) {
    }

    public function decide(Actor $actor, string $ability, Realm $realm, ?ResourceRef $resource, Origin $origin): Decision
    {
        return $this->evaluator->decideAbility($actor, $ability, $realm, $resource, $origin);
    }

    /**
     * Whether the decision is a Permit, and only that.
     */
    public function allows(Actor $actor, string $ability, Realm $realm, ?ResourceRef $resource, Origin $origin): bool
    {
        return $this->decide($actor, $ability, $realm, $resource, $origin)->permits();
    }
}
