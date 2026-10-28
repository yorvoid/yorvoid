<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

/**
 * @api
 */
enum CeremonyKind: string
{
    case Registration = 'registration';

    case SignIn = 'sign_in';

    case StepUp = 'step_up';
}
