<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * The flow pages' "yorvoid_trans" filter: YorvoID's messages, in the request's language, with the
 * translator YorvoID uses, whether or not the application has Symfony's.
 *
 * @internal
 */
final class YorvoIDExtension extends AbstractExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requests,
    ) {
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
