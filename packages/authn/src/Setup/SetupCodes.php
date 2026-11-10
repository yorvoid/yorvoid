<?php

declare(strict_types=1);

namespace YorvoID\Authn\Setup;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\HumanCode;
use YorvoID\Authn\Session\AuthenticationContext;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\AuthenticationMethod;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Authn\Session\StartedSession;
use YorvoID\Authn\Session\StartSession;
use YorvoID\Core\Crypto\Digest;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Operation\ActingPrincipal;
use YorvoID\Core\Operation\OperationHandler;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Core\Time;
use YorvoID\Throttle\Throttled;

/**
 * Setup codes (ATN-034): whoever installs the application gets a code, and whoever enters it first
 * claims the fresh realm, becoming its first principal. A realm is claimed once; after that, codes are
 * refused and access is recovered another way. A realm that has an active principal already, however it
 * came, gets no code either.
 *
 * A code is 130 random bits, written as 26 characters of Crockford's base 32 in groups of five
 * ("7KQ2M-…"), so it can be read aloud and typed: case, hyphens and spaces don't matter, and O, I and L
 * count as 0, 1 and 1. Only its SHA-256 hash is kept.
 *
 * @api
 */
final readonly class SetupCodes implements OperationHandler
{
    private const int LENGTH = 26;

    public function __construct(
        private SetupStore $store,
        private Principals $principals,
        private SecurityLog $log,
        private Transactions $transactions,
        private Attempts $attempts,
        private Sessions $sessions,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    public function operations(): array
    {
        return [
            CreateSetupCode::class => $this->create(...),
            ClaimSetup::class => $this->claim(...),
        ];
    }

    /**
     * @return Secret The code, e.g. "7KQ2M-W9XHE-…", shown only now
     *
     * @throws RealmAlreadyClaimed
     */
    public function create(CreateSetupCode $command, Permit $permit, Origin $origin): Secret
    {
        $permit->use($command);

        return $this->transactions->run(function () use ($command, $origin): Secret {
            // Only while no owner exists (ATN-034): people who came another way, e.g. invited, count.
            if ($this->principals->hasActive($command->realm)) {
                throw new RealmAlreadyClaimed($command->realm);
            }

            $code = HumanCode::random(self::LENGTH, 5);
            $now = Time::stored($this->clock->now());

            if (!$this->store->replaceCode($command->realm, self::hash(HumanCode::normalize($code)), $now, $command->lifetime->after($now))) {
                throw new RealmAlreadyClaimed($command->realm);
            }

            $this->log->record($command->realm, 'yorvoid.setup.create_code', Outcome::Success, $origin, details: ['expires_at' => $command->lifetime->after($now)->format(\DATE_ATOM)]);

            return Secret::fromString($code);
        });
    }

    /**
     * Claims the realm with the code, creating its first principal, active, and signing it in, in the same
     * transaction. Give it its authority (e.g. the owner's role) in the same transaction too, by running
     * this inside your own: if that fails, the realm stays unclaimed.
     *
     * @return StartedSession The owner's session; its principal is the owner
     *
     * @throws InvalidSetupCode Whatever the reason
     * @throws Throttled        When there were too many attempts from the address; nothing was checked
     */
    public function claim(ClaimSetup $command, Permit $permit, Origin $origin): StartedSession
    {
        $permit->use($command);
        [$realm, $owner, $address] = [$command->realm, $command->owner, $command->address];

        if (!$owner->realm->equals($realm) || PrincipalStatus::Active !== $owner->status) {
            throw new IntegratorError('The setup code\'s principal must be created active, in the realm being claimed.', 'Pass new CreatePrincipal($realm, PrincipalKind::Human) with the realm you claim.', 'concepts/setup-codes.md#claiming-a-realm');
        }

        $this->attempts->count('setup_code', $realm, null, $address);

        try {
            return $this->claimWith($command, $permit, $origin);
        } catch (InvalidSetupCode $invalid) {
            // After the rollback: a failed attempt is recorded, without what was typed.
            $this->log->record($realm, 'yorvoid.setup.claim', Outcome::Failed, $origin);

            throw $invalid;
        }
    }

    public function isClaimed(Realm $realm): bool
    {
        return true === $this->store->get($realm)?->isClaimed();
    }

    /**
     * @throws InvalidSetupCode
     */
    private function claimWith(ClaimSetup $command, Permit $permit, Origin $origin): StartedSession
    {
        return $this->transactions->run(function () use ($command, $permit, $origin): StartedSession {
            $realm = $command->realm;
            // Before asking whether the realm has an active principal: a principal created at the same
            // moment, e.g. by an invitation accepted, records it in the log, so it waits for this claim
            // or this claim waits for it, and sees it.
            $this->log->lock($realm);
            $state = $this->store->get($realm);
            $hash = self::hash(HumanCode::normalize($command->code->reveal()));

            if (null === $state || $state->isClaimed() || $this->clock->now() >= $state->expiresAt || !hash_equals($state->codeHash, $hash) || $this->principals->hasActive($realm)) {
                throw new InvalidSetupCode();
            }

            $principal = $permit->cause($this->principals->create(...), $command->owner, $origin);

            if (!$this->store->claim($realm, $hash, $principal->id, Time::stored($this->clock->now()))) {
                throw new InvalidSetupCode();
            }

            $this->log->record($realm, 'yorvoid.setup.claim', Outcome::Success, $origin, $principal->id, 'principal', (string) $principal->id);

            return $permit->cause($this->sessions->start(...), new StartSession($realm, $principal->id, new AuthenticationContext(new AuthenticationFactor(AuthenticationMethod::SetupCode, $this->clock->now())), $command->device, $command->address, $command->deviceCookie), $origin, new ActingPrincipal($realm, $principal->id));
        });
    }

    private static function hash(string $code): string
    {
        return Digest::sha256($code);
    }
}
