<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * The cookies YorvoID relies on: the PHP session's, which carries the flows and who's signed in, and
 * the one that recognises devices.
 *
 * @internal
 */
final readonly class CookiesCheck implements Check
{
    public function __construct(
        private ParameterBagInterface $parameters,
        private string $deviceCookie,
        private bool $recogniseDevices,
    ) {
    }

    public function findings(): iterable
    {
        if ($this->recogniseDevices && !str_starts_with($this->deviceCookie, '__Host-')) {
            yield Finding::warning(\sprintf('The device cookie "%s" has no "__Host-" prefix: a subdomain could set or overwrite it.', $this->deviceCookie), 'Name it "__Host-" and a name, e.g. the default "__Host-yorvoid_device", in cookies.device.', 'concepts/sessions.md#recognising-devices');
        }

        if (!$this->parameters->has('session.storage.options')) {
            yield Finding::problem('The application has no PHP session: YorvoID\'s flows and sign-in keep their state in it.', 'Turn it on with framework.session.', 'concepts/symfony.md#configuration');

            return;
        }

        $options = $this->parameters->get('session.storage.options');
        \assert(\is_array($options));

        if (false === ($options['cookie_secure'] ?? 'auto')) {
            yield Finding::warning('The session cookie is sent over plain HTTP too, where anyone on the network can take it and act as whoever is signed in.', 'Set framework.session.cookie_secure to "auto" (the default) or true.', 'concepts/symfony.md#configuration');
        }

        if (false === ($options['cookie_httponly'] ?? true)) {
            yield Finding::warning('Scripts can read the session cookie: a cross-site scripting flaw would leak sessions.', 'Set framework.session.cookie_httponly to true (the default).', 'concepts/symfony.md#configuration');
        }

        $sameSite = $options['cookie_samesite'] ?? null;

        if (!\in_array($sameSite, ['lax', 'strict'], true)) {
            yield Finding::warning(\sprintf('The session cookie\'s SameSite is %s: other sites\' requests carry it.', null === $sameSite ? 'not set' : \sprintf('"%s"', \is_string($sameSite) ? $sameSite : '?')), 'Set framework.session.cookie_samesite to "lax" (the default) or "strict".', 'concepts/symfony.md#configuration');
        }
    }
}
