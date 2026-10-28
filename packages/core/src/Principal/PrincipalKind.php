<?php

declare(strict_types=1);

namespace YorvoID\Core\Principal;

/**
 * What a principal is (IDN-002). All kinds go through the same authorization.
 *
 * @api
 */
enum PrincipalKind: string
{
    /** A person */
    case Human = 'human';

    /** An application or service acting as itself */
    case Service = 'service';

    /** A workload with an identity from its platform (e.g. SPIFFE) */
    case Workload = 'workload';

    /** An AI agent acting with authority delegated to it */
    case Agent = 'agent';

    /** Whoever holds an account-less capability: a link or a PIN, e.g. a scorekeeper's */
    case Capability = 'capability';
}
