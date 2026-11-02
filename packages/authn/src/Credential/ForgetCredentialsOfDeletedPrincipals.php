<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Authn\Recovery\RecoveryCodeStore;
use YorvoID\Core\Principal\Event\PrincipalStatusChanging;
use YorvoID\Core\Principal\PrincipalStatus;

/**
 * A listener for PrincipalStatusChanging: when a principal is deleted, its credentials go in the same
 * transaction, with what other packages keep for them (a passkey's public key and user handle, through
 * CredentialRemoving), and its recovery codes' hashes. Nothing of how a deleted principal signed in
 * stays behind.
 *
 * @api
 */
final readonly class ForgetCredentialsOfDeletedPrincipals
{
    public function __construct(
        private Credentials $credentials,
        private RecoveryCodeStore $recoveryCodes,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    public function __invoke(PrincipalStatusChanging $event): void
    {
        if (PrincipalStatus::Deleted !== $event->after->status) {
            return;
        }

        $this->credentials->forgetAllOf($event->after->realm, $event->after->id, $event->origin, $event->actor);
        $this->recoveryCodes->replace($event->after->realm, $event->after->id, [], $this->clock->now());
    }
}
