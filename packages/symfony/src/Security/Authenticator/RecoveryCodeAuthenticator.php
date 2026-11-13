<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security\Authenticator;

use YorvoID\Authn\Recovery\RecoverWithCode;
use YorvoID\Authn\Session\StartedSession;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Symfony\Security\CurrentSession;
use YorvoID\Symfony\Security\PrincipalUserProvider;
use YorvoID\Symfony\Security\RequestOrigins;

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
        PrincipalUserProvider $users,
        Realm $realm,
        string $deviceCookie,
        private readonly Operations $operations,
        RequestOrigins $origins,
    ) {
        parent::__construct($current, $users, $realm, $deviceCookie, $origins);
    }

    protected function route(): string
    {
        return 'yorvoid_recover_with_code';
    }

    protected function signIn(array $body, Origin $origin, ?string $device, ?string $address, ?Secret $deviceCookie): StartedSession
    {
        $email = $body['email'] ?? null;
        $code = $body['code'] ?? null;
        $started = $this->operations->run(new RecoverWithCode($this->realm, IdentifierKind::Email, \is_string($email) ? $email : '', \is_string($code) ? $code : '', $device, $address, $deviceCookie), new Anonymous(), $origin);

        return $started ?? throw new SignInRefused('That code didn\'t work. Check it, or use another one.');
    }
}
