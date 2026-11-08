<?php

declare(strict_types=1);

namespace YorvoID\Policy\Attribute;

/**
 * The security attributes an owner declares. In Symfony, a service implementing it is collected by itself.
 *
 * @api
 */
interface AttributeDeclarations
{
    /**
     * @return iterable<SecurityAttribute>
     */
    public function attributes(): iterable;
}
