<?php

declare(strict_types=1);

namespace YorvoID\Authn\StepUp;

use YorvoID\Authn\Session\Session;
use YorvoID\Core\Duration;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\OperationDescription;
use YorvoID\Core\Operation\SelfOperation;
use YorvoID\Core\Operation\SelfServicePolicy;

/**
 * What someone changing their own account must show: a session, and for some operations a recent proof
 * of who they are (a step-up), as their requirement says. Changing one's email address or username needs
 * a recent proof and waits out a recovery's cool-down: it's where notifications and recovery go.
 *
 * Operations that check more than this themselves (a credential's removal needs a passkey's proof, a new
 * set of recovery codes replacing the old one does too) have no requirement here; operations it doesn't
 * know, e.g. an application's own, need a proof within the re-authentication window.
 *
 * @api
 */
final readonly class StepUpSelfService implements SelfServicePolicy
{
    private Duration $window;

    /** @var array<string, AuthenticationRequirement|null> */
    private array $requirements;

    /**
     * @param array<string, AuthenticationRequirement|null> $requirements By operation, over YorvoID's own; null for none
     * @param Duration|null                                 $window       How recent a proof must be: 5 minutes if not given
     */
    public function __construct(
        private StepUp $stepUp,
        array $requirements = [],
        ?Duration $window = null,
    ) {
        $this->window = $window ?? Duration::minutes(5);
        $this->requirements = [
            'yorvoid.identifier.set' => new AuthenticationRequirement($this->window, operation: 'yorvoid.identifier.set', heldAfterRecovery: true),
            'yorvoid.identifier.remove' => new AuthenticationRequirement($this->window, operation: 'yorvoid.identifier.remove', heldAfterRecovery: true),
            // Their handlers require the step-up themselves, as each needs.
            'yorvoid.credential.remove' => null,
            'yorvoid.credential.rename' => null,
            'yorvoid.recovery_codes.generate' => null,
            'yorvoid.passkey.register' => null,
            // Signing out, here or everywhere, never waits for a proof.
            'yorvoid.session.end' => null,
            'yorvoid.session.end_all' => null,
            ...$requirements,
        ];
    }

    /**
     * @throws StepUpRequired    When the session hasn't proved who it is recently enough
     * @throws HeldAfterRecovery When the account was recovered recently
     */
    public function decide(OperationDescription $operation, SelfOperation $command, Actor $actor): Decision
    {
        if (!$actor instanceof Session) {
            return Decision::deny(\sprintf('"%s" runs on one\'s own account from a session only.', $operation->name));
        }

        $requirement = \array_key_exists($operation->name, $this->requirements)
            ? $this->requirements[$operation->name]
            : new AuthenticationRequirement($this->window, operation: $operation->name);

        if (null !== $requirement) {
            $this->stepUp->require($actor, $requirement);
        }

        return Decision::permit(AuthorizedBy::Self, 'The actor\'s own account, from its session.');
    }
}
