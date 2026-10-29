<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

use Doctrine\DBAL\Exception as DbalException;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Passkeys\PasskeyPolicy;

/**
 * What the configuration tree can't check: YorvoID builds its policies, the relying party ID and the
 * origins among them, and its database, only when they're first used. The doctor builds them now.
 *
 * @internal
 */
final readonly class ConfigurationCheck implements Check
{
    /**
     * @param list<\Closure(): object> $services The services to build
     */
    public function __construct(
        private array $services,
        private bool $debug,
    ) {
    }

    public function findings(): iterable
    {
        foreach ($this->services as $build) {
            try {
                $service = $build();
            } catch (IntegratorError $error) {
                yield Finding::of($error);

                continue;
            } catch (DbalException) {
                // Building the database asks it its version: the migrations check reports not reaching it.
                continue;
            }

            if ($service instanceof PasskeyPolicy && !$this->debug && 'localhost' === $service->relyingPartyId) {
                yield Finding::warning('Passkeys are created for "localhost" outside development: they won\'t work on the site\'s real domain.', 'Set passkeys.relying_party_id and passkeys.origins to the site\'s domain, e.g. "example.com" and ["https://example.com"].', 'concepts/passkeys.md#configuration');
            }
        }
    }
}
