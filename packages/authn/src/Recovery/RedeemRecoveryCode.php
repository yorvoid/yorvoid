<?php

declare(strict_types=1);

namespace YorvoID\Authn\Recovery;

use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\ConcernsPrincipal;
use YorvoID\Core\Operation\Operation;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;
use YorvoID\Core\Secret;

/**
 * Recovers a known principal's account with one of its recovery codes: anyone may try, and the handler
 * checks the code and counts the attempts. When the person types who they are, use RecoverWithCode.
 *
 * @implements Command<\YorvoID\Authn\Session\StartedSession|null>
 *
 * @api
 */
#[Operation('yorvoid.recovery_code.redeem', 'Recover a known account with a recovery code', AuthorizedBy::Authentication, causes: ['yorvoid.session.end_all', 'yorvoid.session.start'])]
final readonly class RedeemRecoveryCode implements Command, ConcernsPrincipal
{
    /**
     * @param string|null $device       As for StartSession
     * @param string|null $address      As for StartSession; pass it, so attempts from one address are limited
     * @param Secret|null $deviceCookie The device cookie the browser sent, if any; the session's result says which to set
     */
    public function __construct(
        public Realm $realm,
        public PrincipalId $principalId,
        #[\SensitiveParameter]
        public Secret $code,
        public ?string $device = null,
        public ?string $address = null,
        #[\SensitiveParameter]
        public ?Secret $deviceCookie = null,
    ) {
    }

    public function principal(): PrincipalId
    {
        return $this->principalId;
    }
}
