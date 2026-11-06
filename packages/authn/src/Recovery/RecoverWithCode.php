<?php

declare(strict_types=1);

namespace YorvoID\Authn\Recovery;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\IdentifierKind;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;

/**
 * Recovers an account with one of its recovery codes, finding it from what the person typed, e.g. their
 * email address: anyone may try, and the handler checks the code and counts the attempts.
 *
 * @implements Command<\YorvoID\Authn\Session\StartedSession|null>
 *
 * @api
 */
#[Operation('yorvoid.recovery_code.recover', 'Recover access with a recovery code', AuthorizedBy::Authentication, causes: ['yorvoid.session.end_all', 'yorvoid.session.start'])]
final readonly class RecoverWithCode implements Command
{
    /**
     * @param string      $identifier   As typed; one that isn't valid for its kind matches no account
     * @param string      $code         As typed; case, hyphens and spaces don't matter
     * @param string|null $device       As for StartSession
     * @param string|null $address      As for StartSession; pass it, so attempts from one address are limited
     * @param Secret|null $deviceCookie The device cookie the browser sent, if any; the session's result says which to set
     */
    public function __construct(
        public Realm $realm,
        public IdentifierKind $kind,
        public string $identifier,
        #[\SensitiveParameter]
        public string $code,
        public ?string $device = null,
        public ?string $address = null,
        #[\SensitiveParameter]
        public ?Secret $deviceCookie = null,
    ) {
    }
}
