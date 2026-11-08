<?php

declare(strict_types=1);

namespace YorvoID\Policy\Attribute;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Policy\Ability;

/**
 * An attribute of principals that decisions may read (IDN-055), e.g. a department or a clearance; kept
 * apart from profile data (D-05), which decisions never read.
 *
 * @api
 */
final readonly class SecurityAttribute
{
    /**
     * @throws IntegratorError When the name isn't namespaced like an ability's, e.g. "acme.department"
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $owner,
        public AttributeType $type = AttributeType::String,
    ) {
        if (1 !== preg_match(Ability::NAME, $name)) {
            throw new IntegratorError(\sprintf('The attribute name "%s" is invalid.', $name), 'Use lowercase words separated by dots, the owner\'s namespace first, e.g. "acme.department".', 'concepts/authorization.md#attributes');
        }
    }
}
