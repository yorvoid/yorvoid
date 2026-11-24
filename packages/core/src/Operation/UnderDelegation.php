<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * An actor that authenticated, e.g. an agent's session or key, acting under a delegation it was given.
 * The evaluator proves the actor as itself, then the delegation's chain, whose delegate must be that
 * actor's principal: naming a delegation isn't using it.
 *
 * @api
 *
 * @experimental
 */
final readonly class UnderDelegation implements ActsUnderDelegation
{
    public ?PrincipalId $principalId;

    public ?Realm $realm;

    /**
     * @param string|null $purpose   What the request is for, as the caller says, when the request doesn't say
     * @param string|null $execution The task it's part of, as the caller says, when the request doesn't say
     *
     * @throws IntegratorError When the actor acts under a delegation already, or views as someone else
     */
    public function __construct(
        public Actor $as,
        public DelegationId $delegation,
        public ?string $purpose = null,
        public ?string $execution = null,
    ) {
        if ($as instanceof ActsUnderDelegation || $as instanceof ViewingAs) {
            throw new IntegratorError('An actor under a delegation is one that authenticated, as itself.', 'Wrap the actor that signed in (its session or key): a delegation isn\'t used under another, nor while viewing as someone else.', 'concepts/delegation.md#using-a-delegation');
        }

        $this->principalId = $as->principalId;
        $this->realm = $as->realm;
    }
}
