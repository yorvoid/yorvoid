<?php

declare(strict_types=1);

namespace App;

use YorvoID\Authn\Credential\Credential;
use YorvoID\Authn\Credential\Credentials;
use YorvoID\Authn\Recovery\CredentialDisabledMeanwhile;
use YorvoID\Authn\Session\AuthenticationFactor;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Authn\Session\Sessions;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Operation\Permit;
use YorvoID\Core\Realm;
use YorvoID\Policy\RoleAssignment;

final readonly class Admin
{
    public function __construct(
        private Sessions $sessions,
        private Credentials $credentials,
    ) {
    }

    public function handle(Realm $realm, SessionId $id, AuthenticationFactor $factor, Origin $origin, Credential $credential, Permit $permit): void
    {
        $this->credentials->markUsed($realm, $credential->id);
        $this->credentials->markUsed($realm, $credential->id);
        $this->credentials->get($realm, $credential->id);
        Credentials::session($permit);
        RoleAssignment::applies(null, null, new \DateTimeImmutable(), []);
        throw new CredentialDisabledMeanwhile($credential);
    }
}

/**
 * @param class-string $class
 */
function more(CredentialDisabledMeanwhile $disabled, \YorvoID\Fake\FakeApi $api, Credentials $credentials, Permit $permit, string $class, ?\YorvoID\Fake\FakeApi $maybe): void
{
    $disabled->credential;
    $api->secret;
    $api->open;
    new \YorvoID\Fake\FakeApi();
    new \YorvoID\Fake\FakeChild();
    new $class();
    new \DateTimeImmutable();
    $credentials::session($permit);
    $maybe?->secret;
    new \YorvoID\Fake\FakeHelper();
}
