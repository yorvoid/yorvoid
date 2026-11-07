<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

/**
 * What lets an actor run an operation. An operation lists one or more; the first that permits wins.
 *
 * @api
 */
enum AuthorizedBy: string
{
    /** An actor holding the operation's ability on its subject, as the application's policy says */
    case Ability = 'ability';

    /** The actor is the subject, e.g. someone renaming their own passkey (see {@see SelfOperation}) */
    case Self = 'self';

    /** The operation is authentication: its handler checks a secret, a token or a ceremony itself */
    case Authentication = 'authentication';

    /** The host's {@see Operator}, from a console command, a job or YorvoID itself */
    case Operator = 'operator';

    /** Only as a consequence of another operation, e.g. starting a session after a sign-in: never by itself */
    case Consequence = 'consequence';
}
