<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * What an element's truth rests on (AUZ-020), for explainers to build their own text from: a role and
 * how it has the ability, a relation, a rule, an attribute (by name, never its value), evidence, or the
 * actor's authority. Not prose: the truth's detail is the text for developers.
 *
 * ```php
 * Ground::role('editor', RoleGrant::Default, on: new ResourceRef('site', '1'), group: 'writers');
 * ```
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's explainer uses it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class Ground
{
    /**
     * @param string           $name   The role's, relation's, rule's or attribute's name, the evidence's kind or the authority's status
     * @param RoleGrant|null   $grant  A role's: how it has the ability
     * @param ResourceRef|null $on     A role's: the resource it's assigned on, the request's or one it's within; null for the whole realm.
     *                                 A relation's: its object
     * @param string|null      $group  A role's: the group it's given to, when the actor has it through one
     * @param string|null      $source A role's: the role source it comes from, when it isn't assigned
     */
    private function __construct(
        public GroundKind $kind,
        public string $name,
        public ?RoleGrant $grant = null,
        public ?ResourceRef $on = null,
        public ?string $group = null,
        public ?string $source = null,
    ) {
    }

    /**
     * A role the actor has, and how it has the ability.
     *
     * @param ResourceRef|null $on     The resource it's assigned on; null for the whole realm
     * @param string|null      $group  The group it's given to, if the actor has it through one
     * @param string|null      $source The role source it comes from, if it isn't assigned
     */
    public static function role(string $role, RoleGrant $grant, ?ResourceRef $on = null, ?string $group = null, ?string $source = null): self
    {
        return new self(GroundKind::Role, $role, $grant, $on, $group, $source);
    }

    /**
     * A relation the actor has to an object, e.g. editor of page:1.
     */
    public static function relation(ResourceRef $object, string $relation): self
    {
        return new self(GroundKind::Relation, $relation, on: $object);
    }

    public static function rule(string $name): self
    {
        return new self(GroundKind::Rule, $name);
    }

    /**
     * An attribute the truth read, by name: never its value.
     */
    public static function attribute(string $name): self
    {
        return new self(GroundKind::Attribute, $name);
    }

    /**
     * Evidence the actor gave, e.g. "step_up" for a recent proof of who they are.
     */
    public static function evidence(string $kind): self
    {
        return new self(GroundKind::Evidence, $kind);
    }

    /**
     * The actor's authority: the acting principal's status ("active", "suspended"...), "none" when no
     * principal acts, "other_realm" when it acts in another, or "missing" when it isn't on record.
     */
    public static function authority(string $status): self
    {
        return new self(GroundKind::Authority, $status);
    }
}
