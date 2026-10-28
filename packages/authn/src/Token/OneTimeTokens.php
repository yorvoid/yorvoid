<?php

declare(strict_types=1);

namespace YorvoID\Authn\Token;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\NativeClock;
use YorvoID\Authn\Session\InactivePrincipal;
use YorvoID\Authn\Token\Event\OneTimeTokenConsumed;
use YorvoID\Authn\Token\Event\OneTimeTokenIssued;
use YorvoID\Core\Event\NoEventListeners;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Log\Outcome;
use YorvoID\Core\Log\SecurityLog;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\InvalidIdentifier;
use YorvoID\Core\Principal\Principal;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Principal\UnknownPrincipal;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Core\Storage\Transactions;
use YorvoID\Core\Time;

/**
 * One-time tokens (ATN-030), for links sent by email or handed over by an operator: invitations,
 * recovery links, password resets.
 *
 * A token is "selector.verifier": a 128-bit selector that finds it, and a 256-bit verifier kept only as
 * a SHA-256 hash, compared in constant time. It's bound to a principal and a purpose, optionally to the
 * email address it was sent to, and expires. Using it is checked and done in one step, so it works at
 * most once, also when two requests present it at the same moment: the step specified and model-checked
 * in spec/tokens/one_time_token.qnt, whose executions the tests replay. The format is Quire's, so
 * Quire's stored tokens carry over as they are.
 *
 * A token for a suspended or deleted principal never works.
 *
 * @api
 */
final readonly class OneTimeTokens
{
    private const string PURPOSE = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/D';

    private const string FORMAT = '/^([0-9a-f]{32})\.([A-Za-z0-9_-]{43})$/D';

    public function __construct(
        private OneTimeTokenStore $store,
        private Principals $principals,
        private SecurityLog $log,
        private Transactions $transactions,
        private EventDispatcherInterface $events = new NoEventListeners(),
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    /**
     * @throws UnknownPrincipal  When there's no such principal
     * @throws InactivePrincipal When it's suspended or deleted
     */
    public function issue(IssueToken $command, Origin $origin, ?PrincipalId $actor = null): IssuedToken
    {
        if (1 !== preg_match(self::PURPOSE, $command->purpose) || \strlen($command->purpose) > 64) {
            throw new IntegratorError(\sprintf('The token purpose "%s" is invalid.', $command->purpose), 'Use up to 64 lowercase letters, digits, dots and underscores, starting with a letter, e.g. "acme.password_reset".', 'concepts/one-time-tokens.md#issuing-a-token');
        }

        return $this->transactions->run(function () use ($command, $origin, $actor): IssuedToken {
            $principal = $this->principals->get($command->realm, $command->principalId) ?? throw new UnknownPrincipal($command->realm, $command->principalId);

            if (!self::mayUseTokens($principal)) {
                throw new InactivePrincipal($principal, 'be sent a token');
            }

            $email = null === $command->sentTo ? null : $this->email($principal, $command->sentTo);

            if ($command->newestOnly) {
                $this->store->deleteFor($principal->realm, $principal->id, $command->purpose);
            }

            $now = Time::stored($this->clock->now());
            $selector = bin2hex(random_bytes(16));
            $verifier = Secret::fromString(self::base64url(random_bytes(32)));
            $token = new OneTimeToken($selector, $principal->realm, $principal->id, $command->purpose, $email, $now, $command->lifetime->after($now));
            $this->store->add($token, hash('sha256', $verifier->reveal()));
            $issued = new IssuedToken($token, Secret::fromString($selector.'.'.$verifier->reveal()));

            $this->log->record($principal->realm, 'yorvoid.token.issue', Outcome::Success, $origin, $actor, 'principal', (string) $principal->id, [
                'expires_at' => $token->expiresAt->format(\DATE_ATOM),
                'purpose' => $token->purpose,
                'sent_to_email' => null !== $email,
            ]);

            $this->transactions->afterCommit(function () use ($issued, $origin, $actor): void {
                $this->events->dispatch(new OneTimeTokenIssued($issued, $origin, $actor));
            });

            return $issued;
        });
    }

    /**
     * The token, if it would work now; it keeps working. For showing a form before the token is used,
     * e.g. "choose your passkey" on an invitation's page.
     */
    public function peek(Realm $realm, string $purpose, #[\SensitiveParameter] Secret $token): ?OneTimeToken
    {
        return $this->working($realm, $purpose, $token)?->token;
    }

    /**
     * Uses the token, if it works now: it, and the principal's other tokens for the purpose, stop
     * working. Of several requests presenting it at once, exactly one gets it.
     *
     * @return OneTimeToken|null The token that was used; null if it doesn't work, for whatever reason
     */
    public function consume(Realm $realm, string $purpose, #[\SensitiveParameter] Secret $token, Origin $origin): ?OneTimeToken
    {
        return $this->transactions->run(function () use ($realm, $purpose, $token, $origin): ?OneTimeToken {
            $working = $this->working($realm, $purpose, $token);

            if (null === $working || !$this->store->delete($realm, $working->token->selector)) {
                return null;
            }

            $used = $working->token;
            $this->store->deleteFor($realm, $used->principalId, $purpose);
            $this->log->record($realm, 'yorvoid.token.consume', Outcome::Success, $origin, $used->principalId, 'principal', (string) $used->principalId, ['purpose' => $purpose]);

            $this->transactions->afterCommit(function () use ($used, $origin): void {
                $this->events->dispatch(new OneTimeTokenConsumed($used, $origin, $used->principalId));
            });

            return $used;
        });
    }

    /**
     * @return int How many tokens stopped working
     */
    public function revoke(RevokeTokens $command, Origin $origin, ?PrincipalId $actor = null): int
    {
        return $this->transactions->run(function () use ($command, $origin, $actor): int {
            $revoked = $this->store->deleteFor($command->realm, $command->principalId, $command->purpose);

            if ($revoked > 0) {
                $this->log->record($command->realm, 'yorvoid.token.revoke', Outcome::Success, $origin, $actor, 'principal', (string) $command->principalId, ['purpose' => $command->purpose, 'tokens' => $revoked]);
            }

            return $revoked;
        });
    }

    /**
     * Deletes expired tokens; run it daily, e.g. from a scheduled job.
     *
     * @return int How many
     */
    public function purge(): int
    {
        return $this->store->purge($this->clock->now());
    }

    private function working(Realm $realm, string $purpose, #[\SensitiveParameter] Secret $token): ?WorkingToken
    {
        if (1 !== preg_match(self::FORMAT, $token->reveal(), $parts)) {
            return null;
        }

        $found = $this->store->find($realm, $parts[1], $purpose);

        if (null === $found || $this->clock->now() >= $found[0]->expiresAt || !hash_equals($found[1], hash('sha256', $parts[2]))) {
            return null;
        }

        [$stored] = $found;
        $principal = $this->principals->get($realm, $stored->principalId);

        if (null === $principal || !self::mayUseTokens($principal)) {
            return null;
        }

        if (null !== $stored->email && $stored->email !== ($this->principals->identifiers($realm, $principal->id)[IdentifierKind::Email->value] ?? null)?->normalized) {
            return null;
        }

        return new WorkingToken($stored, $principal);
    }

    private function email(Principal $principal, string $sentTo): string
    {
        try {
            $normalized = IdentifierKind::Email->normalize($sentTo);
        } catch (InvalidIdentifier) {
            $normalized = null;
        }

        if (null === $normalized || $normalized !== ($this->principals->identifiers($principal->realm, $principal->id)[IdentifierKind::Email->value] ?? null)?->normalized) {
            throw new IntegratorError('A token can be sent only to its principal\'s email address.', 'Pass the principal\'s current email address as sentTo, or null for a token that isn\'t sent by email.', 'concepts/one-time-tokens.md#tokens-sent-by-email');
        }

        return $normalized;
    }

    private static function mayUseTokens(Principal $principal): bool
    {
        return PrincipalStatus::Active === $principal->status || PrincipalStatus::Invited === $principal->status;
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
