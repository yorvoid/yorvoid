<?php

declare(strict_types=1);

namespace YorvoID\Relations;

use YorvoID\Core\Exception\IntegratorError;

/**
 * A relation of a resource type: who has it directly, which of the type's other relations imply it
 * (an editor is a viewer too), which relation of a parent it's inherited from (a site's viewers include
 * its server's), and which abilities it gives on the resource.
 *
 * ```php
 * new RelationDefinition('viewer', impliedBy: ['editor'], fromParents: ['parent' => 'viewer'], grants: ['acme.pages.view']);
 * ```
 *
 * @api
 */
final readonly class RelationDefinition
{
    public const string NAME = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/D';

    /**
     * @param list<string>          $impliedBy   Relations of the same resource that imply this one
     * @param array<string, string> $fromParents The relation that leads to a parent => the parent's relation that gives this one
     * @param list<string>          $grants      Abilities whoever has the relation holds on the resource
     *
     * @throws IntegratorError When a name isn't valid
     */
    public function __construct(
        public string $name,
        public array $impliedBy = [],
        public array $fromParents = [],
        public array $grants = [],
    ) {
        self::check($name);

        foreach ($impliedBy as $relation) {
            self::check($relation);
        }

        foreach ($fromParents as $via => $relation) {
            self::check($via);
            self::check($relation);
        }
    }

    /**
     * @throws IntegratorError When it isn't a relation's name
     */
    public static function check(string $relation): void
    {
        if (1 !== preg_match(self::NAME, $relation) || \strlen($relation) > 64) {
            throw new IntegratorError(\sprintf('The relation name "%s" is invalid.', $relation), 'Use up to 64 lowercase letters, digits and underscores, starting with a letter, e.g. "editor".', 'concepts/authorization.md#relations');
        }
    }
}
