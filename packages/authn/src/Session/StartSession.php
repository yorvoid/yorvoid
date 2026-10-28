<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;

/**
 * Starts a session for a principal who just proved who they are. Run by the code that checked the proof
 * (a passkey ceremony, a setup code), never on a request's say-so.
 *
 * @api
 */
#[Operation('yorvoid.session.start', 'Start a session', inverse: 'yorvoid.session.end')]
final readonly class StartSession
{
    /**
     * @param string|null $device       What the person would recognise, e.g. "Firefox on Linux"; at most 255 characters are kept
     * @param string|null $address      The IP address of the request
     * @param Secret|null $deviceCookie The device cookie the browser sent, if any (SES-012)
     */
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        public AuthenticationContext $authentication,
        public ?string $device = null,
        public ?string $address = null,
        #[\SensitiveParameter]
        public ?Secret $deviceCookie = null,
    ) {
    }
}
