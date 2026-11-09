<?php

declare(strict_types=1);

namespace YorvoID\Policy\ViewAs;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Operation\ViewingAs;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\PrincipalStore;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Policy\Roles;

/**
 * Begins views as someone else (AUZ-023): checks the target, says what's beyond the viewer, and records
 * it. Nothing is stored; the host holds the actor for as long as the view lasts.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's view-as uses it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class Views implements OperationHandler
{
    public function __construct(
        private Roles $roles,
        private PrincipalStore $principals,
        private SecurityLog $log,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    public function operations(): array
    {
        return [BeginViewAs::class => $this->begin(...)];
    }

    /**
     * @throws UnknownPrincipal When the target isn't one of the realm's
     * @throws CannotViewAs     When the target isn't active, or is the viewer
     */
    private function begin(BeginViewAs $command, Permit $permit, Origin $origin): ViewAsStart
    {
        $permit->use($command);
        $principal = $this->principals->get($command->realm, $command->target) ?? throw new UnknownPrincipal($command->realm, $command->target);

        // Its every decision would fail the authority check anyway.
        if (PrincipalStatus::Active !== $principal->status) {
            throw new CannotViewAs(\sprintf('The principal %s is %s.', $command->target, $principal->status->value));
        }

        $viewer = $permit->actor;

        if (null !== $viewer->principalId && $viewer->principalId->equals($command->target)) {
            throw new CannotViewAs('A view as oneself is one\'s own.');
        }

        $actor = new ViewingAs($viewer, new ActingPrincipal($command->realm, $command->target));
        $beyond = $this->roles->beyond($command->realm, $viewer, $actor->target, $origin, $this->clock->now());
        $this->log->record($command->realm, 'yorvoid.view_as.begin', Outcome::Success, $origin, $viewer->principalId, 'principal', $command->target->uuid->toRfc4122(), [
            'beyond' => array_map(static fn (Beyond $one): string => null === $one->on ? $one->ability : \sprintf('%s on %s', $one->ability, $one->on), $beyond),
        ]);

        return new ViewAsStart($actor, $beyond);
    }
}
