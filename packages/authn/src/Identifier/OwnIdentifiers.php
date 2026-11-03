<?php

declare(strict_types=1);

namespace YorvoID\Authn\Identifier;

use YorvoID\Authn\Session\Session;
use YorvoID\Authn\StepUp\AuthenticationRequirement;
use YorvoID\Authn\StepUp\HeldAfterRecovery;
use YorvoID\Authn\StepUp\StepUp;
use YorvoID\Authn\StepUp\StepUpRequired;
use YorvoID\Core\Duration;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\Identifier;
use YorvoID\Core\Principal\IdentifierTaken;
use YorvoID\Core\Principal\InvalidIdentifier;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\RemoveIdentifier;
use YorvoID\Core\Principal\SetIdentifier;
use YorvoID\Core\Storage\Transactions;

/**
 * Someone changing their own email address or username, from their session. The email address is where
 * security notifications and recovery go, so whoever holds a session must prove who they are again
 * first, as for adding a passkey, and not during a recovery's cool-down. The old address hears of the
 * change (SecurityNotifications).
 *
 * Principals::setIdentifier() and removeIdentifier() check none of this: they're for administrators and
 * the console, after the application's own authorization.
 *
 * @api
 */
final readonly class OwnIdentifiers
{
    private Duration $reauthenticationWindow;

    public function __construct(
        private Principals $principals,
        private StepUp $stepUp,
        private Transactions $transactions,
        ?Duration $reauthenticationWindow = null,
    ) {
        $this->reauthenticationWindow = $reauthenticationWindow ?? Duration::minutes(5);
    }

    /**
     * @throws StepUpRequired    When the session hasn't proved who it is within the re-authentication window
     * @throws HeldAfterRecovery When the account was recovered recently
     * @throws InvalidIdentifier When the value isn't valid
     * @throws IdentifierTaken   When another principal in the realm has it
     */
    public function set(SetIdentifier $command, Session $session, Origin $origin): Identifier
    {
        $this->refuseOthers($command->realm->equals($session->realm) && $command->principalId->equals($session->principalId));

        return $this->transactions->run(function () use ($command, $session, $origin): Identifier {
            $this->requireStepUp($session, 'yorvoid.identifier.set');

            return $this->principals->setIdentifier($command, $origin, $session->principalId);
        });
    }

    /**
     * @throws StepUpRequired    When the session hasn't proved who it is within the re-authentication window
     * @throws HeldAfterRecovery When the account was recovered recently
     */
    public function remove(RemoveIdentifier $command, Session $session, Origin $origin): void
    {
        $this->refuseOthers($command->realm->equals($session->realm) && $command->principalId->equals($session->principalId));

        $this->transactions->run(function () use ($command, $session, $origin): void {
            $this->requireStepUp($session, 'yorvoid.identifier.remove');
            $this->principals->removeIdentifier($command, $origin, $session->principalId);
        });
    }

    private function requireStepUp(Session $session, string $operation): void
    {
        $this->stepUp->require($session, new AuthenticationRequirement($this->reauthenticationWindow, operation: $operation, heldAfterRecovery: true));
    }

    private function refuseOthers(bool $own): void
    {
        if (!$own) {
            throw new IntegratorError('The session belongs to another principal than the identifier.', 'Change only the signed-in principal\'s own identifiers here; an administrator changes others\' with Principals::setIdentifier(), after checking they may.', 'concepts/principals.md#identifiers');
        }
    }
}
