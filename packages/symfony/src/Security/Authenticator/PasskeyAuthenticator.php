<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Security\Authenticator;

use YorvoID\Authn\Session\StartedSession;
use YorvoID\Core\Event\Origin;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;
use YorvoID\Passkeys\PasskeyRefused;
use YorvoID\Passkeys\Passkeys;
use YorvoID\Passkeys\Refusal;
use YorvoID\Symfony\Security\CurrentSession;
use YorvoID\Symfony\Security\Json;
use YorvoID\Symfony\Security\PrincipalUserProvider;

/**
 * Signs in with a passkey: POST the ceremony's ID and the browser's answer, as JSON, to the route
 * "yorvoid_sign_in" ({"ceremony": "…", "response": {…}}). The options come from
 * "yorvoid_sign_in_options".
 *
 * @api
 */
final class PasskeyAuthenticator extends YorvoIDAuthenticator
{
    public function __construct(
        CurrentSession $current,
        PrincipalUserProvider $users,
        Realm $realm,
        string $deviceCookie,
        private readonly Passkeys $passkeys,
    ) {
        parent::__construct($current, $users, $realm, $deviceCookie);
    }

    protected function route(): string
    {
        return 'yorvoid_sign_in';
    }

    protected function signIn(array $body, Origin $origin, ?string $device, ?string $address, ?Secret $deviceCookie): StartedSession
    {
        $response = json_encode($body['response'] ?? null, \JSON_THROW_ON_ERROR);

        try {
            return $this->passkeys->finishSignIn($this->realm, Json::string($body, 'ceremony') ?? '', $response, $origin, $device, $address, $deviceCookie);
        } catch (PasskeyRefused $exception) {
            // The browser then tells the passkey provider, which can stop offering it (ATN-008). The ID
            // came from the browser, and is random: saying it isn't registered here tells no one more.
            throw new SignInRefused($exception->getMessage(), 401, null, $exception, Refusal::UnknownPasskey === $exception->refusal ? ['unknown_credential' => true] : []);
        }
    }
}
