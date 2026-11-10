<?php

declare(strict_types=1);

namespace YorvoID\E2e\Acme;

use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\StepUpCheck;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Duration;
use YorvoID\Core\Operation\AuthorizedBy;

/**
 * Acme Team's own policy element: anyone signed in removes a project, with a proof of who they are from
 * the last 5 minutes. Without one, the refusal's remedy is a step-up; just after a recovery, a wait.
 * (A generic "these operations need a recent proof" element is M3's.).
 */
final readonly class RecentProofToRemove implements PermitElement
{
    public function __construct(
        private StepUpCheck $stepUp,
    ) {
    }

    public function name(): string
    {
        return 'acme.recent_proof_to_remove';
    }

    public function basis(): AuthorizedBy
    {
        return AuthorizedBy::Ability;
    }

    public function matches(Request $request): bool
    {
        return 'acme.project.remove' === $request->name();
    }

    public function evaluate(Request $request): Truth
    {
        return $this->stepUp->check($request->actor, new StepUpRemedy(Duration::minutes(5), operation: 'acme.project.remove'));
    }
}
