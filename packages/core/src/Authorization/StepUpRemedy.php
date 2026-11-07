<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Duration;

/**
 * Prove who you are again, recently enough: e.g. with a passkey, within 5 minutes.
 *
 * @api
 */
final readonly class StepUpRemedy implements Remedy
{
    /**
     * @param Duration    $within            How recent the proof must be
     * @param bool        $multiFactor       Whether it must be two factors (AAL2)
     * @param bool        $phishingResistant Whether it must be phishing-resistant, e.g. a passkey
     * @param string|null $operation         The operation it's for, when a step-up for that operation counts
     */
    public function __construct(
        public Duration $within,
        public bool $multiFactor = false,
        public bool $phishingResistant = false,
        public ?string $operation = null,
    ) {
    }

    public function name(): string
    {
        return 'step_up';
    }

    public function parameters(): array
    {
        return ['max_age' => $this->within->seconds, 'multi_factor' => $this->multiFactor, 'phishing_resistant' => $this->phishingResistant, 'operation' => $this->operation];
    }
}
