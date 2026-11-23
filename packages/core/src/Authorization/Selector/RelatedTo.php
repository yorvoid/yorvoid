<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\Selector;

use YorvoID\Core\Authorization\ResourceRef;

/**
 * The resource has this relation to a subject, e.g. it's a page the delegator is an editor of.
 *
 * @api
 *
 * @experimental
 */
final readonly class RelatedTo implements Atom
{
    public function __construct(
        public string $relation,
        public ResourceRef $subject,
    ) {
    }

    public function toArray(): array
    {
        return ['related' => $this->relation, 'to' => [$this->subject->type, $this->subject->id]];
    }
}
