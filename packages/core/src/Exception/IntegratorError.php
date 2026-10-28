<?php

declare(strict_types=1);

namespace YorvoID\Core\Exception;

/**
 * A mistake in how an application uses or configures YorvoID, as opposed to something an end user did.
 *
 * Its message says what went wrong, how to fix it, and where the documentation explains it (PLT-085),
 * so the developer who sees it in a log or a stack trace can act on it without searching. Never shown
 * to end users.
 *
 * @api
 */
final class IntegratorError extends \LogicException implements YorvoIDException
{
    public const string DOCS = 'https://github.com/yorvoid/yorvoid/blob/main/docs/';

    /**
     * @param string $problem What went wrong, e.g. 'The realm name "Main" is invalid.'
     * @param string $fix     How to fix it, e.g. 'Use lowercase letters, digits and hyphens.'
     * @param string $docs    The documentation page that explains it, relative to the docs folder
     */
    public function __construct(
        public readonly string $problem,
        public readonly string $fix,
        public readonly string $docs,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf("%s\n\nHow to fix it: %s\nDocumentation: %s%s", $problem, $fix, self::DOCS, $docs), previous: $previous);
    }
}
