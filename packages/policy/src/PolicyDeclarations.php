<?php

declare(strict_types=1);

namespace YorvoID\Policy;

/**
 * The abilities and roles an owner declares: an application, an extension, YorvoID itself. In Symfony,
 * a service implementing it is collected by itself.
 *
 * @api
 */
interface PolicyDeclarations
{
    /**
     * @return iterable<Ability>
     */
    public function abilities(): iterable;

    /**
     * @return iterable<Role>
     */
    public function roles(): iterable;
}
