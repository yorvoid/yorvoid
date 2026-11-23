<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Principal\PrincipalKind;

/**
 * To whom an action may be delegated (06 §5.4): anyone, anyone but an agent, or nobody. Issuing a
 * delegation of an action it doesn't allow is refused, and so is its use once it no longer allows it.
 *
 * @api
 *
 * @experimental
 */
enum Delegable: string
{
    case Everyone = 'everyone';

    case NotToAgents = 'not-to-agents';

    case Nobody = 'nobody';

    public function to(PrincipalKind $delegate): bool
    {
        return match ($this) {
            self::Everyone => true,
            self::NotToAgents => PrincipalKind::Agent !== $delegate,
            self::Nobody => false,
        };
    }
}
