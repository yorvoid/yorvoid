<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

/**
 * One parameter of an operation, read from its command's constructor.
 *
 * @api
 */
final readonly class Parameter
{
    /**
     * @param string $type      The PHP type as declared, e.g. "string", "?int" or "YorvoID\Core\Duration"
     * @param bool   $sensitive Marked #[\SensitiveParameter]: never logged, shown or put on a command line
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
        public bool $optional,
        public bool $sensitive,
    ) {
    }
}
