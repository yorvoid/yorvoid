<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\PrincipalStore;

/**
 * An actor's own authority (06 §4): a principal that exists, is active and acts in the command's realm.
 * The host's operator and someone not signed in claim none, so there's nothing to invalidate: what they
 * may run is up to the permits.
 *
 * @api
 */
final readonly class PrincipalAuthority implements AuthorityCheck
{
    public function __construct(
        private PrincipalStore $principals,
    ) {
    }

    public function check(Request $request): Truth
    {
        $actor = $request->actor;

        if (null === $actor->principalId) {
            return Truth::holds('No principal acts, so no authority is claimed.');
        }

        if (null === $actor->realm || !$actor->realm->equals($request->command->realm)) {
            return Truth::fails('The actor acts in another realm than the command\'s.');
        }

        $principal = $this->principals->get($actor->realm, $actor->principalId);

        if (null === $principal) {
            return Truth::unknown(IndeterminateReason::MissingData, \sprintf('The acting principal %s isn\'t on record.', $actor->principalId));
        }

        return PrincipalStatus::Active === $principal->status
            ? Truth::holds('The acting principal is active in the realm.')
            : Truth::fails(\sprintf('The acting principal is %s.', $principal->status->value));
    }
}
