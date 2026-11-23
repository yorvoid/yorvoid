<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\Selector;

use YorvoID\Core\Exception\IntegratorError;

/**
 * The resource's ID is one of these. A child selector narrows it with a subset.
 *
 * @api
 *
 * @experimental
 */
final readonly class IdIn implements Atom
{
    /** @var list<string> Sorted, each once */
    public array $ids;

    /**
     * @param list<string> $ids
     *
     * @throws IntegratorError When there's none, or one is empty
     */
    public function __construct(array $ids)
    {
        $ids = array_values(array_unique($ids));
        sort($ids, \SORT_STRING);

        if ([] === $ids || \in_array('', $ids, true)) {
            throw new IntegratorError('A selector\'s IDs are at least one, none of them empty.', 'Name the resources by their IDs, or leave the condition out.', 'concepts/delegation.md#resources');
        }

        $this->ids = $ids;
    }

    public function toArray(): array
    {
        return ['id_in' => $this->ids];
    }
}
