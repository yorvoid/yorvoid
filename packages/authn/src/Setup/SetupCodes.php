<?php

declare(strict_types=1);

namespace YorvoID\Authn\Setup;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\HumanCode;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\CreatePrincipal;
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
 * refused and access is recovered another way.
 *
 * A code is 130 random bits, written as 26 characters of Crockford's base 32 in groups of five
 * ("7KQ2M-…"), so it can be read aloud and typed: case, hyphens and spaces don't matter, and O, I and L
 * count as 0, 1 and 1. Only its SHA-256 hash is kept.
 *
 * @api
 */
final readonly class SetupCodes
{
    private const int LENGTH = 26;

    public function __construct(
        private SetupStore $store,
        private Principals $principals,
        private SecurityLog $log,
        private Transactions $transactions,
        private Attempts $attempts,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * @return Secret The code, e.g. "7KQ2M-W9XHE-…", shown only now
     *
     * @throws RealmAlreadyClaimed
     */
    public function create(CreateSetupCode $command, Origin $origin): Secret
    {
        return $this->transactions->run(function () use ($command, $origin): Secret {
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
     * Claims the realm with the code, creating its first principal, active, in the same transaction.
     * Give it its authority (e.g. the owner's role) in the same transaction too, by running this inside
     * your own: if that fails, the realm stays unclaimed.
     *
     * @param string|null $address The client's IP address; pass it, so attempts from one address are limited
     *
     * @throws InvalidSetupCode Whatever the reason
     * @throws Throttled        When there were too many attempts from the address; nothing was checked
     */
    public function claim(Realm $realm, #[\SensitiveParameter] Secret $code, CreatePrincipal $owner, Origin $origin, ?string $address = null): Principal
    {
        if (!$owner->realm->equals($realm) || PrincipalStatus::Active !== $owner->status) {
            throw new IntegratorError('The setup code\'s principal must be created active, in the realm being claimed.', 'Pass new CreatePrincipal($realm, PrincipalKind::Human) with the realm you claim.', 'concepts/setup-codes.md#claiming-a-realm');
        }

        $this->attempts->count('setup_code', $realm, null, $address);

        return $this->transactions->run(function () use ($realm, $code, $owner, $origin): Principal {
            $state = $this->store->get($realm);
            $hash = self::hash(HumanCode::normalize($code->reveal()));

            if (null === $state || $state->isClaimed() || $this->clock->now() >= $state->expiresAt || !hash_equals($state->codeHash, $hash)) {
                throw new InvalidSetupCode();
            }

            $principal = $this->principals->create($owner, $origin);

            if (!$this->store->claim($realm, $hash, $principal->id, Time::stored($this->clock->now()))) {
                throw new InvalidSetupCode();
            }

            $this->log->record($realm, 'yorvoid.setup.claim', Outcome::Success, $origin, $principal->id, 'principal', (string) $principal->id);

            return $principal;
        });
    }

    public function isClaimed(Realm $realm): bool
    {
        return true === $this->store->get($realm)?->isClaimed();
    }

    private static function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}
