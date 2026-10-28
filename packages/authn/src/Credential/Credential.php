<?php

declare(strict_types=1);

namespace YorvoID\Authn\Credential;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * A way a principal proves who they are, in the registry every kind shares (IDN-013): a passkey, a
 * password, an authenticator app, a set of recovery codes. What's secret about it is kept by its own
 * package; the registry holds what people see in "your sign-in methods".
 *
 * @api
 */
final readonly class Credential
{
    /**
     * @param string $label What its owner calls it, e.g. "MacBook" or "YubiKey 5"
     */
    public function __construct(
        public CredentialId $id,
        public Realm $realm,
        public PrincipalId $principalId,
        public CredentialKind $kind,
        public string $label,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $lastUsedAt = null,
        public CredentialStatus $status = CredentialStatus::Active,
    ) {
    }

    public function isActive(): bool
    {
        return CredentialStatus::Active === $this->status;
    }
}
