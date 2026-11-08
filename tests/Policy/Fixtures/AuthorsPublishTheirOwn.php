<?php

declare(strict_types=1);

namespace YorvoID\Tests\Policy\Fixtures;

use YorvoID\Core\Authorization\Request;
use YorvoID\Policy\Attribute\Attributes;
use YorvoID\Policy\Rule\Rule;
use YorvoID\Policy\Rule\RuleAnswer;

/**
 * "Edit your own", read from the subject without loading the page again: its author publishes it,
 * unless it's classified secret, which a resolver reads from the subject too.
 */
final readonly class AuthorsPublishTheirOwn implements Rule
{
    public function __construct(
        private Attributes $attributes,
    ) {
    }

    public function name(): string
    {
        return 'acme.authors_publish';
    }

    public function matches(Request $request): bool
    {
        return 'acme.pages.publish' === $request->ability && $request->subject instanceof Authored;
    }

    public function decide(Request $request): RuleAnswer
    {
        \assert($request->subject instanceof Authored);

        if ('secret' === $this->attributes->resolve($request, 'acme.classification')) {
            return RuleAnswer::forbid('Secret pages aren\'t published.');
        }

        $actor = $request->actor->principalId;

        return null !== $actor && $actor->equals($request->subject->author()) ? RuleAnswer::permit('Its author publishes it.') : RuleAnswer::abstain();
    }
}
