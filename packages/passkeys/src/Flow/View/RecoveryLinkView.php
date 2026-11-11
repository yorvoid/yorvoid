<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow\View;

use YorvoID\Core\Duration;
use YorvoID\Flows\StepView;

/**
 * Recovering with a recovery link: the person confirms, so an email scanner opening the link doesn't
 * use it up.
 *
 * @api
 */
final readonly class RecoveryLinkView implements StepView
{
    /**
     * @param string|null   $account The account the link is for, as the person knows it, e.g. their email
     *                               address; null if the link doesn't work any more
     * @param Duration|null $holds   How long sensitive changes wait once someone gets back in this way; null if they don't
     */
    public function __construct(
        public ?string $account = null,
        public ?Duration $holds = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['account' => $this->account, 'holds' => $this->holds?->seconds];
    }
}
