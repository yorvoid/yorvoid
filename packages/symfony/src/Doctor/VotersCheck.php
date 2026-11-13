<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

/**
 * Which of the application's Symfony voters the bundle wrapped: none of them can grant a declared
 * ability, whatever the access decision strategy (M-7), and their refusals count where it weighs them.
 *
 * @internal
 */
final readonly class VotersCheck implements Check, Reports
{
    /**
     * @param list<string> $wrapped The class of each voter wrapped
     */
    public function __construct(
        private array $wrapped,
    ) {
    }

    public function findings(): iterable
    {
        return [];
    }

    public function summary(): string
    {
        return \sprintf('other voters can\'t grant a declared ability; their refusals count where the strategy weighs them (%s)', [] === $this->wrapped ? 'there are none' : implode(', ', $this->wrapped));
    }
}
