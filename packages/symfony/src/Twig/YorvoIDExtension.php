<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use YorvoID\Core\Operation\Decision;
use YorvoID\Symfony\LocalPath;
use YorvoID\Symfony\Remedy\RemedyPrompts;

/**
 * The flow pages' "yorvoid_trans" filter: YorvoID's messages, in the request's language, with the
 * translator YorvoID uses, whether or not the application has Symfony's. And "yorvoid_remedies", for an
 * application that shows a refusal on its own page.
 *
 * @phpstan-import-type Prompt from RemedyPrompts
 *
 * @internal
 */
final class YorvoIDExtension extends AbstractExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requests,
        private readonly ?RemedyPrompts $remedies = null,
    ) {
    }

    public function getFunctions(): array
    {
        return null === $this->remedies ? [] : [new TwigFunction('yorvoid_remedies', $this->remedies(...))];
    }

    /**
     * yorvoid_remedies(decision, return): what would satisfy a refusal, in the request's language, in the
     * shape of the refusal's JSON "remedies" (@api): name, parameters, message, and action (url and
     * label) or null. Print them as they are: Twig escapes them.
     *
     * @param string|null $return Where a step-up comes back to: a path on this site, or it's left out
     *
     * @return list<Prompt>
     */
    public function remedies(Decision $decision, ?string $return = null): array
    {
        \assert(null !== $this->remedies);

        return $this->remedies->of($decision, null !== $return && LocalPath::is($return) ? $return : null, $this->requests->getCurrentRequest()?->getLocale() ?? 'en');
    }

    public function getFilters(): array
    {
        return [new TwigFilter('yorvoid_trans', $this->trans(...))];
    }

    /**
     * @param array<string, string|int|float> $parameters
     */
    private function trans(string $id, array $parameters = []): string
    {
        return $this->translator->trans($id, $parameters, 'yorvoid', $this->requests->getCurrentRequest()?->getLocale());
    }
}
