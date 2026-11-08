<?php

declare(strict_types=1);

namespace YorvoID\Policy\Attribute;

use YorvoID\Core\Authorization\Request;

/**
 * Attributes of a request's resource or environment (AUZ-013), e.g. a page's classification or whether
 * the request comes from the office network. In Symfony, a service implementing it is collected by itself.
 *
 * @api
 */
interface AttributeResolver
{
    /**
     * @return bool Whether it resolves this attribute at all
     */
    public function resolves(string $name): bool;

    /**
     * The attribute's value for the request; null when it's not known for it.
     *
     * @return string|int|bool|list<string>|null
     */
    public function resolve(Request $request, string $name): string|int|bool|array|null;
}
