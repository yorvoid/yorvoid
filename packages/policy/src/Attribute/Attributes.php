<?php

declare(strict_types=1);

namespace YorvoID\Policy\Attribute;

use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Exception\IntegratorError;

/**
 * The attributes a policy element reads (AUZ-013): the acting principal's declared security attributes,
 * and the request's resource and environment through resolvers. The principal's are read once per
 * request.
 *
 * ```php
 * $department = $attributes->principal($request, 'acme.department'); // MissingAttribute when it has none
 * ```
 *
 * @api
 */
final readonly class Attributes
{
    /** @var array<string, SecurityAttribute> */
    private array $declared;

    /** @var list<AttributeResolver> */
    private array $resolvers;

    /** @var \WeakMap<Request, array<string, string|int|bool|list<string>>> */
    private \WeakMap $memo;

    /**
     * @param iterable<AttributeDeclarations> $declarations
     * @param iterable<AttributeResolver>     $resolvers
     *
     * @throws IntegratorError When an attribute is declared twice
     */
    public function __construct(
        iterable $declarations,
        private AttributeStore $store,
        iterable $resolvers = [],
    ) {
        $declared = [];

        foreach ($declarations as $declaration) {
            foreach ($declaration->attributes() as $attribute) {
                if (isset($declared[$attribute->name])) {
                    throw new IntegratorError(\sprintf('The attribute "%s" is declared twice.', $attribute->name), 'Declare each attribute once, in its owner\'s namespace.', 'concepts/authorization.md#attributes');
                }

                $declared[$attribute->name] = $attribute;
            }
        }

        $list = [];

        foreach ($resolvers as $resolver) {
            $list[] = $resolver;
        }

        $this->declared = $declared;
        $this->resolvers = $list;
        $this->memo = new \WeakMap();
    }

    public function declared(string $name): ?SecurityAttribute
    {
        return $this->declared[$name] ?? null;
    }

    /**
     * The acting principal's attribute.
     *
     * @return string|int|bool|list<string>
     *
     * @throws MissingAttribute When the actor isn't a principal of the realm, or has none
     * @throws IntegratorError  When it isn't declared
     */
    public function principal(Request $request, string $name): string|int|bool|array
    {
        if (!isset($this->declared[$name])) {
            throw new IntegratorError(\sprintf('The attribute "%s" isn\'t declared.', $name), 'Declare it in an AttributeDeclarations of its owner.', 'concepts/authorization.md#attributes');
        }

        if (!$request->actorInRealm()) {
            throw new MissingAttribute($name, 'actor, who isn\'t a principal of the realm,');
        }

        \assert(null !== $request->actor->principalId);
        $this->memo[$request] ??= $this->store->of($request->realm, $request->actor->principalId);

        return $this->memo[$request][$name] ?? throw new MissingAttribute($name, 'acting principal');
    }

    /**
     * An attribute of the request's resource or environment, from the first resolver that resolves it.
     *
     * @return string|int|bool|list<string>
     *
     * @throws MissingAttribute When no resolver knows it for the request
     */
    public function resolve(Request $request, string $name): string|int|bool|array
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->resolves($name)) {
                return $resolver->resolve($request, $name) ?? throw new MissingAttribute($name, 'request');
            }
        }

        throw new MissingAttribute($name, 'request');
    }
}
