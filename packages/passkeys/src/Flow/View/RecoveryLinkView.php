<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow\View;

use YorvoID\Flows\StepView;

/**
 * Recovering with a recovery link: the person confirms, so an email scanner opening the link doesn't
 * use it up.
 *
 * @api
 */
final readonly class RecoveryLinkView implements StepView
{
    public function jsonSerialize(): array
    {
        return [];
    }
}
