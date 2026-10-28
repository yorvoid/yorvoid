<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * The verifier is set up or called wrongly: a mistake in the application, not something a request did.
 *
 * @api
 */
final class ConfigurationError extends \LogicException
{
}
