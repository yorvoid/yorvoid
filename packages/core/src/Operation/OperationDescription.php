<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

/**
 * An operation as the catalogue describes it: its metadata and the parameters of its command.
 *
 * @api
 */
final readonly class OperationDescription
{
    /**
     * @param non-empty-list<AuthorizedBy> $authorizedBy
     * @param class-string                 $command
     * @param list<Parameter>              $parameters   In the constructor's order
     * @param list<string>                 $causes       The operations it may run as its consequences
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $authorizedBy,
        public ?string $ability,
        public ?string $inverse,
        public string $command,
        public array $parameters,
        public array $causes = [],
    ) {
    }
}
