<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Realm;

/**
 * An actor's own authority (06 §4): a principal that exists, is active and acts in the request's realm.
 * The host's operator and someone not signed in claim none, so there's nothing to invalidate: what they
 * may run is up to the permits. For a batch, each principal is read once.
 *
 * @api
 */
final readonly class PrincipalAuthority implements AuthorityCheck, Preloads
{
    /** @var \WeakMap<Request, array{Principal|null}> By request, the principal read for its batch */
    private \WeakMap $preloaded;

    /**
     * @param (\Closure(Actor): ?Principal)|null $loaded The actor's principal when it's loaded already, e.g. by
     *                                                   the request's session check: read once per request (PERF-07)
     */
    public function __construct(
        private PrincipalStore $principals,
        private ?\Closure $loaded = null,
    ) {
        $this->preloaded = new \WeakMap();
    }

    public function preload(array $requests): void
    {
        /** @var array<string, array<string, array{Principal|null}>> $read By realm, then principal */
        $read = [];

        foreach ($requests as $request) {
            $actor = $request->actor;

            if ($request->actorInRealm()) {
                \assert(null !== $actor->realm);
                $this->preloaded[$request] = $read[$actor->realm->name][(string) $actor->principalId] ??= [$this->principal($actor->realm, $actor)];
            }
        }
    }

    public function check(Request $request): Truth
    {
        $actor = $request->actor;

        if (null === $actor->principalId) {
            return Truth::holds('No principal acts, so no authority is claimed.');
        }

        if (null === $actor->realm || !$actor->realm->equals($request->realm)) {
            return Truth::fails('The actor acts in another realm than the request\'s.');
        }

        $principal = ($this->preloaded[$request] ?? [$this->principal($actor->realm, $actor)])[0];

        if (null === $principal) {
            return Truth::unknown(IndeterminateReason::MissingData, \sprintf('The acting principal %s isn\'t on record.', $actor->principalId));
        }

        return PrincipalStatus::Active === $principal->status
            ? Truth::holds('The acting principal is active in the realm.')
            : Truth::fails(\sprintf('The acting principal is %s.', $principal->status->value));
    }

    private function principal(Realm $realm, Actor $actor): ?Principal
    {
        \assert(null !== $actor->principalId);
        $known = null === $this->loaded ? null : ($this->loaded)($actor);

        return null !== $known && $known->id->equals($actor->principalId) && $known->realm->equals($realm) ? $known : $this->principals->get($realm, $actor->principalId);
    }
}
