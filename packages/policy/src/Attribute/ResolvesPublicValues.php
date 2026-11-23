<?php

declare(strict_types=1);

namespace YorvoID\Policy\Attribute;

/**
 * A resolver that says which of its attributes aren't personal data: a decision records those as they are
 * (M-9). Every other value it gives, integers too, is recorded as a MAC, which confirms a value given to
 * it and discloses none. Without this interface, every value but a boolean is MACed.
 *
 * @api
 */
interface ResolvesPublicValues
{
    /**
     * Whether the attribute's values can be recorded as they are: e.g. a page's status, not a person's
     * department or age.
     */
    public function publicValue(string $name): bool;
}
