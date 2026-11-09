<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting;

use YorvoID\Core\Operation\Decision;

/**
 * What a case's decision was, and whether it's the one the case states.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's policy tests use it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class PolicyResult
{
    /**
     * @param string        $expected The verdict the case states, e.g. "permit"
     * @param Decision|null $decision Null when the case couldn't be set up
     * @param string|null   $failure  How it differs from the case, or why it couldn't be set up; null when it passed
     */
    public function __construct(
        public PolicyCase $case,
        public string $expected,
        public ?Decision $decision,
        public ?string $failure,
    ) {
    }

    public function passed(): bool
    {
        return null === $this->failure;
    }
}
