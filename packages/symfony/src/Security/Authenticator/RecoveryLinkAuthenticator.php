<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security\Authenticator;

use YorvoID\Authn\Recovery\UseRecoveryLink;
use YorvoID\Authn\Session\StartedSession;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\Anonymous;
use YorvoID\Core\Operation\Operations;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Symfony\Security\CurrentSession;
use YorvoID\Symfony\Security\PrincipalUserProvider;

/**
 * Recovers an account with a recovery link: POST the link's token, as JSON, to the route
 * "yorvoid_recover_with_link" ({"token": "…"}). A POST, not the link itself: email scanners open
 * links, and opening one mustn't use it up. Every other session ends.
 *
 * @api
 */
final class RecoveryLinkAuthenticator extends YorvoIDAuthenticator
{
    public function __construct(
        CurrentSession $current,
        PrincipalUserProvider $users,
        Realm $realm,
        string $deviceCookie,
        private readonly Operations $operations,
    ) {
        parent::__construct($current, $users, $realm, $deviceCookie);
    }

    protected function route(): string
    {
        return 'yorvoid_recover_with_link';
    }

    protected function signIn(array $body, Origin $origin, ?string $device, ?string $address, ?Secret $deviceCookie): StartedSession
    {
        $token = $body['token'] ?? null;
        $started = \is_string($token) && '' !== $token ? $this->operations->run(new UseRecoveryLink($this->realm, Secret::fromString($token), $device, $address, $deviceCookie), new Anonymous(), $origin) : null;

        return $started ?? throw new SignInRefused('This link doesn\'t work any more. Ask for a new one.');
    }
}
