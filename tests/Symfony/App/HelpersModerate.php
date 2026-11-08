<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony\App;

use YorvoID\Core\Authorization\Request;
use YorvoID\Policy\Rule\Rule;
use YorvoID\Policy\Rule\RuleAnswer;

/**
 * An application's own rule, registered by itself: anyone may view drafts of the help pages, given as
 * the resource or as the subject.
 */
final readonly class HelpersModerate implements Rule
{
    public function name(): string
    {
        return 'acme.help_drafts';
    }

    public function matches(Request $request): bool
    {
        return 'acme.pages.view_drafts' === $request->ability;
    }

    public function decide(Request $request): RuleAnswer
    {
        return 'help' === $request->resource?->id || $request->subject instanceof HelpDraft ? RuleAnswer::permit('Help drafts are public.') : RuleAnswer::abstain();
    }
}
