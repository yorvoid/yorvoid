# Authorization

Every operation runs through `Operations::run()` ([operations](operations-and-events.md#running-an-operation)), which asks the evaluator whether the actor may. The evaluator weighs the policy's elements and the actor's authority, and gives one of three outcomes:

- **Permit:** the operation runs.
- **Deny:** YorvoID knows it isn't allowed now. When only evidence is missing, e.g. a recent step-up, the decision says what would satisfy it (a remedy).
- **Indeterminate:** something the decision needs couldn't be established, e.g. a record is missing or a store is unreachable. It never permits, and it's logged as a warning: someone should look.

A fourth, **Permit-offline**, exists only where you've set it up for a store that can't be reached ([offline](#offline)).

A refusal throws `NotPermitted`, which carries the decision, and is recorded in the security log with its verdict, reason, remedies and what couldn't be established.

## How it decides

The policy is a set of **elements**: permits, which let a request through, and forbids, which refuse it. Each says which requests it's about (its target) and whether it applies to this one (its condition): holds, fails or unknown. The **authority** check says whether the actor may act at all: valid, invalid or unknown.

The first row that applies decides:

| # | When | Outcome |
|---|---|---|
| 1 | A forbid holds | Deny |
| 2 | A forbid is unknown | Indeterminate: a forbid can't be ruled out |
| 3 | The authority is invalid | Deny |
| 4 | The authority is unknown | Indeterminate |
| 5 | A permit holds | Permit |
| 6 | A permit is unknown | Indeterminate |
| 7 | Otherwise | Deny, with the remedies of the permits that failed |

Every element that's about the request is evaluated, so neither the outcome nor its reasons depend on the order of the elements. An element that throws counts as unknown (`evaluation_error`), never as holding. The table is checked against [the formal model](https://github.com/yorvoid/yorvoid/blob/main/spec/decisions/precedence.als).

Row 2 gives Indeterminate even when nothing could permit the request: an unknown forbid is reported, and the reason says the request would be refused regardless.

Each decision has an ID, its time, and the versions of what it read (`Decision::$versions`): the realm's policy version and its relations version, from the `VersionSource`s the evaluator is given. Every change to roles, abilities, assignments, groups and attributes increments the policy version; every change to relations, the relations version. Decisions are recorded in the [decision log](security-log.md#the-decision-log).

## The built-in permits

YorvoID's ways to authorize, which each operation names in `#[Operation(authorizedBy: ...)]`, are permits:

| Permit | Holds when |
|---|---|
| `Authentication` | Always: the handler checks the secret the person brings, e.g. a passkey or a recovery code |
| `Operator` | The host's `Operator`, from a console command or a job on the command line, or YorvoID itself |
| `Self` | The actor's own account, in its realm, with what the self-service policy asks, e.g. a recent step-up (`StepUpSelfService`) |
| `Ability` | A role the actor has has the operation's ability ([roles](#roles)), or an `AbilityPolicy` you give the evaluator says the actor holds it |

A permit speaks only for the operations that name its way to authorize: an ability never permits an operation only the host's operator runs. When several permits hold, the decision's basis is the first the operation names.

## Authority

`PrincipalAuthority` checks the actor's own authority: a principal that exists, is active and acts in the command's realm. A suspended or deleted principal is denied whatever the permits say; one that isn't on record is Indeterminate (`missing_data`). The host's operator and someone not signed in claim no authority, so there's nothing to invalidate.

## Abilities

An ability is what a role can have, e.g. "publish pages". Its owner declares it in a `PolicyDeclarations`, with a label, the roles that have it by default, a group for pages that list them, and whether it's **strict**:

```php
use YorvoID\Policy\Ability;
use YorvoID\Policy\PolicyDeclarations;
use YorvoID\Policy\Role;

final readonly class AcmeDeclarations implements PolicyDeclarations
{
    public function abilities(): iterable
    {
        yield new Ability('acme.pages.view', 'View pages', 'acme', ['everyone']);
        yield new Ability('acme.pages.publish', 'Publish pages', 'acme', ['editor']);
        yield new Ability('acme.pages.purge', 'Purge pages for good', 'acme', strict: true);
    }

    public function roles(): iterable
    {
        yield new Role('editor', 'Editor', 'acme');
        yield new Role('admin', 'Admin', 'acme', super: true);
    }
}
```

Names start with the owner's namespace (`acme.`). Every ability an operation needs (`#[Operation(ability: ...)]`) must be declared; YorvoID declares its own (`YorvoIDDeclarations`): managing principals, issuing recovery links (strict: it hands an account over), signing others out, managing roles, and changing which roles have which abilities. A declaration that doesn't fit (a name twice, a default role that isn't declared, an operation's undeclared ability) fails when YorvoID is built. In Symfony, a service implementing `PolicyDeclarations` is collected by itself.

Ask about one of your own abilities with `Policy::decide()` or `allows()`, or in Symfony with `isGranted()`, which YorvoID decides for every declared ability:

```php
use YorvoID\Core\Authorization\ResourceRef;

$this->denyAccessUnlessGranted('acme.pages.publish', new ResourceRef('page', $page->id));
```

## Roles

The `everyone` role is every actor's, signed in or not, and is never assigned. The other roles are declared, or created in a realm while it runs (they can be relabelled and deleted; declared ones can't). A **super role** has every ability that isn't strict; a strict one reaches it only when it's granted to it.

A realm changes the defaults by giving a role an ability or taking one away. Only the differences are kept (`AbilityChanges`): giving a role back what it has by default leaves nothing, so a later change of the defaults still reaches every role nobody changed.

A principal has the roles assigned to it in its realm (`RoleAssignment`): in the whole realm, or on one resource, e.g. a manager of one site, and until a moment if it expires. A `RoleSource` adds roles for one request without storing them, e.g. mapped from the host application's own roles. A suspended or deleted principal has no role at all: its authority is invalid. Deleting a principal takes its roles.

`Roles` reads it all, for pages that show the policy: the realm's roles, the roles with an ability, a role's abilities, the changes.

### Changing the policy

Roles and their abilities change through operations, each recorded in the security log and incrementing the realm's policy version:

| Operation | Command | Ability |
|---|---|---|
| `yorvoid.role.create`, `.relabel`, `.delete` | `CreateRole` (optionally from another role's abilities), `RelabelRole`, `DeleteRole` | `yorvoid.roles.manage` |
| `yorvoid.role.assign`, `.unassign` | `AssignRole` (in the realm or on a resource, with an expiry), `UnassignRole` | `yorvoid.roles.manage` |
| `yorvoid.ability.grant`, `.revoke`, `.set`, `.reset` | `GrantAbilities`, `RevokeAbilities`, `SetAbilities`, `ResetAbilities` | `yorvoid.permissions.manage` |

```php
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Policy\Change\AssignRole;

$operations->run(new AssignRole($realm, $principalId, 'manager', new ResourceRef('site', '42')), $session, $origin);
```

Two rules hold for everyone but the host's operator:

- **Nobody gives what they don't hold** (`NoEscalation`): a role whose abilities they don't all have, a super role unless they have one, an ability they lack.
- **Giving a strict ability or a super role takes a recent proof** of who one is (AUZ-052): the role permit asks a `StepUpCheck` (in Symfony, the session's step-up, within `step_up.window`), and a refusal carries a `StepUpRemedy`. Without a `StepUpCheck`, only the operator gives them.

## Relations

Relations (`yorvoid/relations`) say how people and resources relate, e.g. Ada is an editor of page 42, or site 42's parent is server 1. Each resource type declares its relations, in a service implementing `RelationDeclarations`:

```php
use YorvoID\Relations\RelationDeclarations;
use YorvoID\Relations\RelationDefinition;
use YorvoID\Relations\ResourceType;

final readonly class AcmeRelations implements RelationDeclarations
{
    public function types(): iterable
    {
        yield new ResourceType('page', [
            new RelationDefinition('parent'),
            new RelationDefinition('editor', grants: ['acme.pages.publish']),
            new RelationDefinition('viewer', impliedBy: ['editor'], fromParents: ['parent' => 'viewer'], grants: ['acme.pages.view']),
        ]);
    }
}
```

- **A relation can imply another** (`impliedBy`): an editor is a viewer.
- **It can be inherited from a parent** (`fromParents`): whoever views a page's parent views the page.
- **It can be to a set of subjects**: `group:writers#member` is every member of the group, so `page:42#editor@group:writers#member` makes them all editors.
- **It gives abilities on its resource** (`grants`): the relation permit (`RelationPermit`) lets an editor of page 42 publish page 42, and no other page.

Relations are added and removed with `AddRelation` and `RemoveRelation` (`yorvoid.relation.add`, `.remove`, ability `yorvoid.relations.manage`), each recorded in the security log; nobody gives a relation that gives what they don't hold on the resource themselves. A deleted principal's relations go.

A role assigned on a resource also counts for what's within it, through `parent`: a manager of server 1 manages its sites. Relations are followed a level at a time, one query per level, at most 16 levels deep (`Relations::MAX_DEPTH`); what goes deeper can't be told, and the decision is Indeterminate, never a Permit.

## Groups

A group's members are principals, or another group's members, so groups nest. A role given to a group (`AssignGroupRole`) is every member's: in the whole realm, or on one resource, with an expiry.

| Operation | Command |
|---|---|
| `yorvoid.group.create`, `.delete` | `CreateGroup` (again: relabels it), `DeleteGroup` (its members leave it, its roles go) |
| `yorvoid.group.add_member`, `.remove_member` | `AddGroupMember`, `RemoveGroupMember`, with `Subject::principal($id)` or `GroupRelations::members('writers')` |
| `yorvoid.group.assign_role`, `.unassign_role` | `AssignGroupRole`, `UnassignGroupRole` |

They need `yorvoid.roles.manage`. Adding someone to a group gives them its roles, so the same rules hold as for giving a role: nobody adds a member to a group with a role they don't hold, and a group with a super role takes a recent proof. Groups are relations of the type `group` (`GroupRelations`, which the Symfony bundle declares).

## Attributes

Security attributes are what decisions may read about a principal (IDN-055), e.g. a department or a clearance, kept apart from profile data, which decisions never read. Their owner declares them in a service implementing `AttributeDeclarations` (`SecurityAttribute`: a namespaced name, a label, a type: string, integer, boolean or a list of strings). `SetAttribute` (`yorvoid.attribute.set`, ability `yorvoid.principals.manage`) sets one or, with null, removes it; the security log names the attribute, never its value. A deleted principal's attributes go.

A rule reads them through `Attributes`: the acting principal's (`principal()`), and the request's resource or environment (`resolve()`), which an `AttributeResolver` answers, e.g. whether a page is classified, or whether the request comes from the office. What isn't known throws `MissingAttribute`, and the decision is Indeterminate (`missing_data`), never a Permit. Any exception of yours implementing `MissingData` is read the same way.

```php
use YorvoID\Core\Authorization\Request;
use YorvoID\Policy\Attribute\Attributes;
use YorvoID\Policy\Rule\Rule;
use YorvoID\Policy\Rule\RuleAnswer;

final readonly class ResearchPublishes implements Rule
{
    public function __construct(
        private Attributes $attributes,
    ) {
    }

    public function name(): string
    {
        return 'acme.research_publishes';
    }

    public function matches(Request $request): bool
    {
        return 'acme.pages.publish' === $request->ability;
    }

    public function decide(Request $request): RuleAnswer
    {
        return 'R&D' === $this->attributes->principal($request, 'acme.department') ? RuleAnswer::permit('R&D publishes.') : RuleAnswer::abstain();
    }
}
```

## Resources

A request may be about one resource, by its type and ID (`ResourceRef`, e.g. `site:42`); YorvoID never loads it. A role assigned on that resource counts for the request. A command about one resource implements `ConcernsResource`, and isGranted()'s subject is the resource when it's a `ResourceRef` or a `ConcernsResource`.

## Rules

A rule in PHP (`Rule`) permits, forbids, abstains, or can't tell (`RuleAnswer`). A refusal wins over every role, super roles included; a permit lets the request through without a role; "can't tell" makes the decision Indeterminate, never a Permit. Give it to the evaluator as `RuleElements::of($rule)`; in Symfony, a service implementing `Rule` is added by itself.

## Remedies

A Deny caused only by missing evidence carries remedies: what could satisfy the requirement, never a permission by itself. Satisfying one creates evidence, and the next request is a new decision.

| Remedy | Asks for |
|---|---|
| `StepUpRemedy` | A recent proof of who you are: within a time, and possibly two factors or a phishing-resistant one |
| `WaitRemedy` | Trying again after a time, e.g. after the cool-down that follows an account recovery |

```php
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Operation\NotPermitted;

try {
    $operations->run($command, $session, $origin);
} catch (NotPermitted $refused) {
    foreach ($refused->decision->remedies as $remedy) {
        if ($remedy instanceof StepUpRemedy) {
            // Ask for a passkey again, then retry.
        }
    }
}
```

## Why it couldn't decide

Every Indeterminate names what couldn't be established (`IndeterminateReason`): `store_unreachable`, `unknown_forbid`, `unverifiable_signature`, `missing_data`, `conflicting_records` or `evaluation_error`. New reasons can come in a minor release, so a `match` of yours on them needs a default arm.

## When a store can't be reached

A store that can't be reached makes the decision Indeterminate with the reason `store_unreachable`, never a Permit: the connection was refused or lost, a statement timed out, or the server is starting, failing over or shutting down. That's told apart from other failures (`evaluation_error`) and from records that aren't there (`missing_data`). Waiting for a lock is contention, not unreachability.

YorvoID's DBAL stores report it through DBAL's exceptions, which `StoreUnreachable::of()` recognises; a store of your own throws `StoreUnreachable` itself ([stores of your own](storage.md#stores-of-your-own)).

## Offline

Some deployments must keep a few operations going while the store they decide from is out of reach, e.g. a door controller whose link home is down. That's off by default, and stays off unless both sides say so when YorvoID is built:

- **An `OfflineDecider`**, given to the evaluator (`Evaluator::builtIn(..., offline: $decider)`; in Symfony, a service with that interface). It's asked only when a decision is Indeterminate and every reason is `store_unreachable`, never for a forbid that couldn't be ruled out, and only where a permit could have applied.
- **The operations that accept it**, `#[Operation(..., acceptsOffline: true)]`: only these run on what the decider allows. There's no switch for all of them.

What the decider allows is **Permit-offline**, a verdict of its own, not a Permit: `Decision::permits()` is false for it, and only an operation that accepts it runs. Its permit says so (`$permit->offline`, as do its consequences), the security log records `yorvoid.decision.offline`, and it's logged as a warning. A decider without such an operation, or such an operation without a decider, fails when `Operations` is built.

## Policy elements

Your own rules are elements: a class implementing `PermitElement` (with the way it authorizes by) or `ForbidElement`, with a stable name for reasons and the log.

```php
use YorvoID\Core\Authorization\ForbidElement;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;

final readonly class NoBadgesAtNight implements ForbidElement
{
    public function name(): string
    {
        return 'acme.badges.no_night_issue';
    }

    public function matches(Request $request): bool
    {
        return 'acme.badge.issue' === $request->operation?->name;
    }

    public function evaluate(Request $request): Truth
    {
        $hour = (int) $request->at->format('G');

        return $hour < 6 ? Truth::holds('Badges aren\'t issued at night.') : Truth::fails('It\'s daytime.');
    }
}
```

Read the time from `$request->at`: one moment for the whole decision. In Symfony, a service implementing `PolicyElement` is added to the evaluator by itself; in plain PHP, pass it to `Evaluator::builtIn(..., elements: [new NoBadgesAtNight()])`. Two elements with one name, or an element that's both a permit and a forbid, fail when the evaluator is built.
