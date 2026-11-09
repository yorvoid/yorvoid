<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

/**
 * What a ground is: a role, a relation, a rule, an attribute, evidence or the actor's authority.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's explainer uses it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
enum GroundKind: string
{
    case Role = 'role';
    case Relation = 'relation';
    case Rule = 'rule';
    case Attribute = 'attribute';
    case Evidence = 'evidence';
    case Authority = 'authority';
}
