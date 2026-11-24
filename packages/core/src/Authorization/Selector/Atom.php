<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\Selector;

/**
 * One condition of a resource selector: the resource's ID among some, an attribute's value, or a
 * relation to a subject. A selector is their conjunction.
 *
 * @api
 *
 * @experimental
 */
interface Atom
{
    /**
     * Its canonical form, for storage, comparison and the record.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
