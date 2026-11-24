<?php

declare(strict_types=1);

namespace YorvoID\Policy\Attribute;

use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\MatchesSelectors;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Selector\Atom;
use YorvoID\Core\Authorization\Selector\AttributeIs;
use YorvoID\Core\Authorization\Truth;

/**
 * A delegation's condition on a resource's attribute, as the attribute resolvers give it for the request:
 * one they can't give is unknown.
 *
 * @api
 *
 * @experimental
 */
final readonly class AttributeSelectors implements MatchesSelectors
{
    public function __construct(
        private Attributes $attributes,
    ) {
    }

    public function answers(Atom $atom): bool
    {
        return $atom instanceof AttributeIs;
    }

    public function match(Atom $atom, Request $request): Truth
    {
        \assert($atom instanceof AttributeIs);

        try {
            $value = $this->attributes->resolve($request, $atom->name);
        } catch (MissingAttribute) {
            return Truth::unknown(IndeterminateReason::MissingData, \sprintf('The resource\'s "%s" isn\'t known.', $atom->name));
        }

        return $value === $atom->value ? Truth::holds(\sprintf('Its "%s" is as the delegation names it.', $atom->name)) : Truth::fails(\sprintf('Its "%s" isn\'t as the delegation names it.', $atom->name));
    }
}
