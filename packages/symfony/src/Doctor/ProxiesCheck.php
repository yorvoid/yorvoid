<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Whose word the application takes for the client's address (framework.trusted_proxies): throttling counts
 * attempts per address, and a sign-in records where it came from.
 *
 * @internal
 */
final readonly class ProxiesCheck implements Check
{
    public function __construct(
        private ParameterBagInterface $parameters,
    ) {
    }

    public function findings(): iterable
    {
        $trusted = $this->parameters->has('kernel.trusted_proxies') ? $this->parameters->get('kernel.trusted_proxies') : null;
        $ranges = array_map(trim(...), \is_array($trusted) ? array_map(strval(...), array_filter($trusted, \is_scalar(...))) : explode(',', \is_string($trusted) ? $trusted : ''));
        $ranges = array_values(array_filter($ranges, static fn (string $range): bool => '' !== $range));

        // Ranges that hold every address: every sender's X-Forwarded-For counts.
        if ([] !== array_intersect($ranges, ['0.0.0.0/0', '::/0'])) {
            yield Finding::problem('Every sender is trusted as a proxy: anyone can name any address in X-Forwarded-For, and so slip past the limits per address, or exhaust someone else\'s.', 'List only your proxies\' addresses in framework.trusted_proxies, or "REMOTE_ADDR" if nothing reaches the application except through them.', 'concepts/symfony.md#behind-a-proxy');
        }
    }
}
