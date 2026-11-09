<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting\Symfony;

use YorvoID\PolicyTesting\PolicyCases;

/**
 * The application's PolicyCases services, collected.
 *
 * @internal
 */
final readonly class CasesOf
{
    /** @var list<PolicyCases> */
    public array $all;

    /**
     * @param iterable<PolicyCases> $cases
     */
    public function __construct(iterable $cases)
    {
        $all = [];

        foreach ($cases as $one) {
            $all[] = $one;
        }

        $this->all = $all;
    }
}
