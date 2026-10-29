<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

use YorvoID\Symfony\Templates\TemplateLint;
use YorvoID\Symfony\Templates\TemplatePublisher;
use YorvoID\Symfony\Templates\TemplateStatus;

/**
 * The flow pages: that each has what its step needs, and that templates the application published
 * kept up with YorvoID's.
 *
 * @internal
 */
final readonly class TemplatesCheck implements Check
{
    public function __construct(
        private TemplateLint $lint,
        private TemplatePublisher $publisher,
    ) {
    }

    public function findings(): iterable
    {
        foreach ($this->lint->lint() as $violation) {
            yield Finding::problem((string) $violation, 'Put back what the page needs; yorvoid:templates:lint checks it again.', 'concepts/symfony.md#changing-the-templates');
        }

        foreach ($this->publisher->published() as $template) {
            $finding = match ($this->publisher->status($template)) {
                TemplateStatus::Upgradable => Finding::warning(\sprintf('YorvoID changed the "%s" template since you published it, and your copy didn\'t change.', $template), \sprintf('Take YorvoID\'s with yorvoid:templates:diff --accept %s.', $template), 'concepts/symfony.md#changing-the-templates'),
                TemplateStatus::Diverged => Finding::warning(\sprintf('YorvoID changed the "%s" template since you published it, and so did you.', $template), \sprintf('Merge its changes into yours: yorvoid:templates:diff %s shows them; then --accept.', $template), 'concepts/symfony.md#changing-the-templates'),
                TemplateStatus::Removed => Finding::warning(\sprintf('YorvoID no longer has the "%s" template you published: nothing uses your copy.', $template), \sprintf('Delete your copy; yorvoid:templates:diff --accept %s removes its base copy.', $template), 'concepts/symfony.md#changing-the-templates'),
                default => null,
            };

            if (null !== $finding) {
                yield $finding;
            }
        }
    }
}
