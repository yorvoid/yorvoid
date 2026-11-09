<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy\Fixtures;

use YorvoID\Core\Authorization\Ground;
use YorvoID\Core\Authorization\StepUpCheck;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Operation\Actor;

final class StubStepUpCheck implements StepUpCheck
{
    /** @var list<StepUpRemedy> */
    public array $asked = [];

    public function __construct(
        public bool $proven = true,
    ) {
    }

    public function check(Actor $actor, StepUpRemedy $required): Truth
    {
        $this->asked[] = $required;

        return $this->proven ? Truth::holds('Proved recently.', Ground::evidence('step_up')) : Truth::fails('Not proved recently.', $required);
    }
}
