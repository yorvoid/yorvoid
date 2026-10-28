<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use YorvoID\Core\Exception\IntegratorError;

/**
 * How a session's principal proved who they are: the factors, in the order they were added (signing in,
 * then any step-up). It becomes the identity context of decisions (06 §2).
 *
 * @api
 */
final readonly class AuthenticationContext
{
    /** @var non-empty-list<AuthenticationFactor> */
    public array $factors;

    public function __construct(AuthenticationFactor ...$factors)
    {
        if ([] === $factors) {
            throw new IntegratorError('A session needs at least one authentication factor.', 'Pass the factor the person signed in with, e.g. new AuthenticationFactor(AuthenticationMethod::Passkey, $now).', 'concepts/sessions.md#starting-a-session');
        }

        $this->factors = array_values($factors);
    }

    /**
     * This context with one more proof, e.g. after a step-up. Earlier proofs that it makes redundant are
     * dropped, so a long session's context doesn't grow with every step-up.
     */
    public function with(AuthenticationFactor $factor): self
    {
        return new self(...array_filter($this->factors, static fn (AuthenticationFactor $earlier): bool => !$factor->supersedes($earlier)), ...[$factor]);
    }

    /**
     * @return list<AuthenticationMethod> Each once, in the order first used
     */
    public function methods(): array
    {
        $methods = [];

        foreach ($this->factors as $factor) {
            if (!\in_array($factor->method, $methods, true)) {
                $methods[] = $factor->method;
            }
        }

        return $methods;
    }

    /**
     * When the method was last used in this session, or null if it wasn't.
     */
    public function lastUsed(AuthenticationMethod $method): ?\DateTimeImmutable
    {
        $times = array_map(static fn (AuthenticationFactor $factor): \DateTimeImmutable => $factor->at, array_filter($this->factors, static fn (AuthenticationFactor $factor): bool => $method === $factor->method));

        return [] === $times ? null : max($times);
    }

    /**
     * When the principal last proved who they are, by any method.
     */
    public function lastAuthenticatedAt(): \DateTimeImmutable
    {
        return max(array_map(static fn (AuthenticationFactor $factor): \DateTimeImmutable => $factor->at, $this->factors));
    }

    /**
     * @return list<array{method: string, at: string, multi_factor: bool, phishing_resistant: bool, operation: string|null}> For storage
     */
    public function toArray(): array
    {
        return array_map(static fn (AuthenticationFactor $factor): array => [
            'method' => $factor->method->value,
            'at' => $factor->at->format(\DATE_ATOM),
            'multi_factor' => $factor->multiFactor,
            'phishing_resistant' => $factor->phishingResistant,
            'operation' => $factor->operation,
        ], $this->factors);
    }

    /**
     * @param list<array{method: string, at: string, multi_factor: bool, phishing_resistant: bool, operation: string|null}> $factors As toArray() returns them
     */
    public static function fromArray(array $factors): self
    {
        return new self(...array_map(static fn (array $factor): AuthenticationFactor => new AuthenticationFactor(AuthenticationMethod::from($factor['method']), new \DateTimeImmutable($factor['at']), $factor['multi_factor'], $factor['phishing_resistant'], $factor['operation']), $factors));
    }
}
