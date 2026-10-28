<?php

declare(strict_types=1);

namespace YorvoID\Tests\Flows;

use YorvoID\Flows\StepView;

final readonly class TestView implements StepView
{
    public function __construct(
        public string $step,
        public mixed $entered,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['step' => $this->step, 'entered' => $this->entered];
    }
}
