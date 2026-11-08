<?php

declare(strict_types=1);

namespace YorvoID\Policy\Attribute;

use YorvoID\Core\Authorization\MissingData;

/**
 * An attribute a decision needs that isn't known: the decision is Indeterminate (missing_data).
 *
 * @api
 */
final class MissingAttribute extends \RuntimeException implements MissingData
{
    public function __construct(
        public readonly string $attribute,
        string $whose,
    ) {
        parent::__construct(\sprintf('The %s has no "%s".', $whose, $attribute));
    }
}
