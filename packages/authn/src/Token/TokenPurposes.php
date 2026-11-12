<?php

declare(strict_types=1);

namespace YorvoID\Authn\Token;

use YorvoID\Core\Operation\Permit;

/**
 * YorvoID's own token purposes, "yorvoid" and "yorvoid.*", and the operations they belong to: a token
 * for one is issued or revoked only as a consequence of its owner, so nobody mints a recovery link
 * without the ability to issue one, nor for longer than it allows (M2-13, H-3).
 *
 * @internal
 */
final class TokenPurposes
{
    /** @var array<string, array{issue: list<string>, revoke: list<string>}> By purpose, or by prefix ending in "." */
    private const array OWNERS = [
        'yorvoid.recovery_link' => ['issue' => ['yorvoid.recovery_link.issue'], 'revoke' => []],
        'yorvoid.invitation' => ['issue' => ['yorvoid.invitation.send', 'yorvoid.invitation.resend'], 'revoke' => ['yorvoid.invitation.withdraw']],
        'yorvoid.email_verification' => ['issue' => ['yorvoid.identifier.set', 'yorvoid.principal.create'], 'revoke' => []],
        'yorvoid.not_me' => ['issue' => ['yorvoid.not_me.issue'], 'revoke' => []],
        'yorvoid.not_me.' => ['issue' => ['yorvoid.not_me.issue'], 'revoke' => []],
    ];

    public static function isReserved(string $purpose): bool
    {
        return 'yorvoid' === $purpose || str_starts_with($purpose, 'yorvoid.');
    }

    /**
     * Why the permit may not issue or revoke tokens for the purpose; null if it may.
     *
     * @param 'issue'|'revoke' $what
     */
    public static function refusal(string $purpose, string $what, Permit $permit): ?string
    {
        if (!self::isReserved($purpose)) {
            return null;
        }

        $owners = self::owners($purpose)[$what];
        // Only a consequence has a parent.
        $parent = $permit->parent?->operation;

        if (null !== $parent && \in_array($parent, $owners, true)) {
            return null;
        }

        return [] === $owners
            ? \sprintf('The purpose "%s" is YorvoID\'s own: nothing %ss tokens for it.', $purpose, $what)
            : \sprintf('The purpose "%s" is YorvoID\'s own: only "%s" %ss tokens for it.', $purpose, implode('", "', $owners), $what);
    }

    /**
     * @return array{issue: list<string>, revoke: list<string>}
     */
    private static function owners(string $purpose): array
    {
        if (isset(self::OWNERS[$purpose])) {
            return self::OWNERS[$purpose];
        }

        foreach (self::OWNERS as $prefix => $owners) {
            if (str_ends_with($prefix, '.') && str_starts_with($purpose, $prefix)) {
                return $owners;
            }
        }

        return ['issue' => [], 'revoke' => []];
    }
}
