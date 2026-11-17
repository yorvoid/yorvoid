<?php

declare(strict_types=1);

namespace App;

use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\StepUp\CoolDownStore;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Policy\PolicyStore;
use YorvoID\Policy\Storage\DbalPolicyStore;

final readonly class Controller
{
    public function __construct(
        private PolicyStore $policy,
        private DbalPolicyStore $dbal,
        private CoolDownStore $coolDowns,
        private Sessions $sessions,
    ) {
    }

    public function handle(Realm $realm, PrincipalId $principal): void
    {
        $this->policy->version($realm);
        $this->dbal->bump($realm);
        $this->coolDowns->extend($realm, $principal, new \DateTimeImmutable());
        $this->sessions->current($realm, $principal);
    }

    public function maybe(?CoolDownStore $coolDowns, Realm $realm): void
    {
        $coolDowns?->purge(new \DateTimeImmutable());
    }
}

/**
 * Wraps the store it decorates: calling it is what a decorator does.
 */
final readonly class LoggingCoolDowns implements CoolDownStore
{
    public function __construct(
        private CoolDownStore $inner,
    ) {
    }

    public function extend(Realm $realm, PrincipalId $principalId, \DateTimeImmutable $until): void
    {
        $this->inner->extend($realm, $principalId, $until);
    }

    public function until(Realm $realm, PrincipalId $principalId): ?\DateTimeImmutable
    {
        return $this->inner->until($realm, $principalId);
    }

    public function purge(\DateTimeImmutable $before): int
    {
        return $this->inner->purge($before);
    }

    public function another(PolicyStore $policy, Realm $realm): void
    {
        $policy->version($realm);
    }
}

/**
 * An application's own interface, though it says @spi: only YorvoID's stores count.
 *
 * @spi
 */
interface OwnStore
{
    public function put(): void;
}

function outside(CoolDownStore $coolDowns, OwnStore $own, \YorvoID\Fake\FakeImplementation $fake, \YorvoID\Fake\FakeHelper $helper): void
{
    $coolDowns->purge(new \DateTimeImmutable());
    $own->put();
    $fake->extra();
    $fake->write();
    $helper->run();
}

function either(\YorvoID\Fake\FakeHelper|\YorvoID\Fake\FakeImplementation $either): void
{
    $either->write();
}
