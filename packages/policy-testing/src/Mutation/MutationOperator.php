<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting\Mutation;

/**
 * One way to break a policy (AUZ-045), after XACMUT's operators: what a mutant changes.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's policy tests use it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
enum MutationOperator: string
{
    /** A forbid that held never holds */
    case DropForbid = 'drop_forbid';

    /** A forbid holds whenever it matches */
    case ForbidEverything = 'forbid_everything';

    /** A permit holds whenever it matches */
    case PermitAlways = 'permit_always';

    /** A permit never holds */
    case PermitNever = 'permit_never';

    /** An element that couldn't tell fails instead */
    case CanTell = 'can_tell';

    /** A role has an ability it doesn't */
    case Widen = 'widen';

    /** A role lacks an ability it has */
    case Narrow = 'narrow';

    /** A super role has a strict ability */
    case StrictOff = 'strict_off';

    /** A role is a super role */
    case Super = 'super';

    /** A role assigned on a resource counts in the whole realm */
    case ScopeWidens = 'scope_widens';

    /** Roles and relations through a group don't reach its members */
    case GroupsDontCount = 'groups_dont_count';

    /** Roles and relations on a parent don't reach its children */
    case ParentsDontCount = 'parents_dont_count';

    /** A relation is no longer implied by another: one of a relation's impliedBy goes */
    case DropImplication = 'drop_implication';

    /** A suspended, deleted or invited actor passes the authority check */
    case AuthorityOff = 'authority_off';
}
