<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

use Symfony\Component\Uid\Uuid;
use YorvoID\Authn\Session\SessionId;
use YorvoID\Core\Realm;

/**
 * A ceremony in progress: the options YorvoID gave the browser, including the challenge, kept until the
 * browser answers, and used once. Kept in the database, so any server can finish what another began.
 *
 * @api
 */
final readonly class Ceremony
{
    /**
     * @param string         $options   The options as JSON, exactly as given to the browser
     * @param SessionId|null $sessionId For a registration or a step-up, the session that began it
     * @param string|null    $operation For a step-up, the operation it's for
     */
    public function __construct(
        public Uuid $id,
        public Realm $realm,
        public CeremonyKind $kind,
        public string $options,
        public \DateTimeImmutable $expiresAt,
        public ?SessionId $sessionId = null,
        public ?string $operation = null,
    ) {
    }
}
