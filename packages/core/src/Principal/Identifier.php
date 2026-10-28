<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

use YorvoID\Core\Realm;

/**
 * An email address or username of a principal (IDN-003), as entered, and whether its owner has shown
 * that it's theirs.
 *
 * @api
 */
final readonly class Identifier
{
    /**
     * The form it's compared in; see IdentifierKind::normalize().
     */
    public string $normalized;

    /**
     * @param string                  $value      As entered, e.g. "Ana.Kovac@Example.com"
     * @param \DateTimeImmutable|null $verifiedAt When its owner showed it's theirs (e.g. by a link sent to
     *                                            the address), or null if they haven't
     *
     * @throws InvalidIdentifier
     */
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public IdentifierKind $kind,
        public string $value,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $verifiedAt = null,
    ) {
        $this->normalized = $kind->normalize($value);
    }

    public function isVerified(): bool
    {
        return null !== $this->verifiedAt;
    }
}
