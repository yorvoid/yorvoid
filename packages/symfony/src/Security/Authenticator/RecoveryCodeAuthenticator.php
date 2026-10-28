<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security\Authenticator;

use YorvoID\Authn\Attempt\Attempts;
use YorvoID\Authn\Recovery\RecoveryCodes;
use YorvoID\Authn\Session\StartedSession;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Principal\Principals;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Symfony\Security\CurrentSession;
use YorvoID\Symfony\Security\PrincipalUserProvider;

/**
 * Recovers an account with one of its recovery codes: POST the email address and the code, as JSON, to
 * the route "yorvoid_recover_with_code" ({"email": "…", "code": "…"}). Every other session ends. The
 * answer is the same whether there's no such account or the code is wrong.
 *
 * @api
 */
final class RecoveryCodeAuthenticator extends YorvoIDAuthenticator
{
    public function __construct(
        CurrentSession $current,
        private readonly Principals $principals,
        PrincipalUserProvider $users,
        Realm $realm,
        string $deviceCookie,
        private readonly RecoveryCodes $codes,
        private readonly Attempts $attempts,
    ) {
        parent::__construct($current, $users, $realm, $deviceCookie);
    }

    protected function route(): string
    {
        return 'yorvoid_recover_with_code';
    }

    protected function signIn(array $body, Origin $origin, ?string $device, ?string $address, ?Secret $deviceCookie): StartedSession
    {
        $email = $body['email'] ?? null;
        $code = $body['code'] ?? null;
        $principal = \is_string($email) ? $this->principals->findByIdentifier($this->realm, IdentifierKind::Email, $email) : null;

        // Attempts on accounts that don't exist count too, against the address's limit.
        if (null === $principal) {
            $this->attempts->count('recovery_code', $this->realm, null, $address);
        }
        $started = null === $principal || !\is_string($code) ? null : $this->codes->redeem($this->realm, $principal->id, Secret::fromString($code), $origin, $device, $address, $deviceCookie);

        return $started ?? throw new SignInRefused('That code didn\'t work. Check it, or use another one.');
    }
}
