<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

/**
 * Whether production runs as production: without debug, whose error pages show source code and
 * stack traces to anyone, and with a secret of its own, which signs what Symfony hands out.
 *
 * @internal
 */
final readonly class EnvironmentCheck implements Check
{
    public function __construct(
        private string $environment,
        private bool $debug,
        private string $secret,
    ) {
    }

    public function findings(): iterable
    {
        if ('prod' !== $this->environment) {
            return;
        }

        if ($this->debug) {
            yield Finding::problem('Production runs with debug on: error pages show source code and stack traces to anyone.', 'Set APP_DEBUG=0, or leave it out: APP_ENV=prod turns it off.', 'tutorials/symfony-quickstart.md#whats-next');
        }

        if ('' === trim($this->secret)) {
            yield Finding::problem('Production has no APP_SECRET: what Symfony signs with it, e.g. links and CSRF tokens, can be made by anyone.', 'Set APP_SECRET to a long random value, e.g. from: php -r "echo bin2hex(random_bytes(32));"', 'tutorials/symfony-quickstart.md#whats-next');
        }
    }
}
