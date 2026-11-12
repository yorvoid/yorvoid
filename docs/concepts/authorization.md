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

An actor needn't be a YorvoID principal. Your application's own users can ask as they are: implement `Actor` on them, with the `PrincipalId` you map each to if their roles and relations are stored in YorvoID, or none if a `RoleSource` of yours gives their roles. Their authority is then yours to check: an `AuthorityCheck` that answers for your actors and leaves the others to `PrincipalAuthority`.

```php
use Acme\Security\AcmeUser;
use YorvoID\Core\Authorization\AuthorityCheck;
use YorvoID\Core\Authorization\Ground;
use YorvoID\Core\Authorization\PrincipalAuthority;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;

final readonly class AcmeAuthority implements AuthorityCheck
{
    public function __construct(private PrincipalAuthority $principals) {}

    public function check(Request $request): Truth
    {
        $actor = $request->actor;

        if (!$actor instanceof AcmeUser) {
            return $this->principals->check($request);
        }

        return $actor->blocked
            ? Truth::fails('The user is blocked.')->grounded(Ground::authority('blocked'))
            : Truth::holds('The user is active.', Ground::authority('active'));
    }
}
```

Give it to the evaluator in place of `PrincipalAuthority`; in Symfony, decorate the `PrincipalAuthority` service with it.

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

By convention a name starts with its owner's namespace (`acme.`). That's a convention, not a check: the owner is what the declaration says, and an application may name abilities after what they're about (`content.edit`) and declare them as its core's. Every ability an operation needs (`#[Operation(ability: ...)]`) must be declared; YorvoID declares its own (`YorvoIDDeclarations`): managing principals, issuing recovery links (strict: it hands an account over), signing others out, managing roles, changing which roles have which abilities, seeing why someone else may or may not do something (`yorvoid.policy.explain`), and seeing the application as someone else sees it (`yorvoid.policy.view_as`). A declaration that doesn't fit (a name twice, a default role that isn't declared, an operation's undeclared ability) fails when YorvoID is built. In Symfony, a service implementing `PolicyDeclarations` is collected by itself.

Ask about one of your own abilities with `Policy::decide()` or `allows()`, or in Symfony with `isGranted()`, which YorvoID decides for every declared ability:

```php
use YorvoID\Core\Authorization\ResourceRef;

$this->denyAccessUnlessGranted('acme.pages.publish', new ResourceRef('page', $page->id));
```

### Many at once

A list that shows what each row allows, e.g. an "Edit" link on every page, asks for all of them at once with `Policy::decideMany()`. Each resource is decided as `decide()` would decide it, at one moment, and what the roles and relations need is read for all of them together: the queries don't grow with the number of resources (AUZ-032).

```php
$decisions = $policy->decideMany($session, 'acme.pages.edit', $realm, $pages, $origin);

foreach ($decisions as $page => $decision) {
    // $page is the ResourceRef asked about, in the order asked.
}

$editable = $decisions->permitted();
```

Each is a `ResourceRef`, a subject that implements `ConcernsResource`, or a resource with its [subject](#the-subject), `[ResourceRef, $item]`, for rules that read the rows themselves:

```php
use App\Content\Item;
use YorvoID\Core\Authorization\ResourceRef;

$decisions = $policy->decideMany($session, 'acme.pages.edit', $realm, array_map(static fn (Item $item): array => [new ResourceRef('content', $item->id), $item], $items), $origin);
```

An element of your own can read for a batch too: it implements `Preloads` (experimental), and is given the batch's requests it matches before they're decided. It keeps what it read by request, and if it fails, each request is decided as on its own.

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

- **Nobody gives or takes what they don't hold** (`NoEscalation`). A change is weighed by what it does, to anyone, directly or through what follows from it:
  - a role, as its abilities, and a super role only by someone with one; an ability, as itself;
  - adding to or taking from a group, as the roles of the group and of the groups it's within;
  - a relation, as what it gives on its resource; a link (`parent`, or a relation others are inherited through) also as what it brings along ([links](#relations));
  - deleting a group or a role, as everything it takes away: the group's roles, and what's given to its members;
  - nobody sets their own security attributes ([attributes](#attributes)).
- **Giving a strict ability or a super role takes a recent proof** of who one is (`StepUpForSensitiveChanges`, AUZ-052), whatever permits the change: a role, a relation, a rule or an `AbilityPolicy`. That's giving one directly, a role that has one, a group with such a role, a role copied from one, a reset that gives one back, or a relation that gives one, directly or through what follows from it. So does setting or removing a security attribute, whoever's it is: what it gives is the rules' that read it, which can't be weighed. Taking never waits for a proof. It asks a `StepUpCheck` (in Symfony, the session's step-up, within `step_up.window`), and a refusal carries a `StepUpRemedy`, or a `WaitRemedy` after a recovery. Without a `StepUpCheck`, it refuses them to everyone but the operator.

Both are forbids, `yorvoid.policy.no_escalation` and `yorvoid.policy.step_up`, and neither is optional, whatever permits the changes: an evaluator with the role permit or the relation permit but without both fails when it's built, and so does `Operations` with the role, group or relation changes (`PolicyChanges`, `GroupChanges`, `RelationChanges`) under an evaluator without both, or under an `Authorization` of your own, which can't be checked. So do attribute changes (`AttributeChanges`). The Symfony bundle adds them all.

```php
use YorvoID\Authn\StepUp\SessionStepUpCheck;
use YorvoID\Core\Authorization\Evaluator;
use YorvoID\Policy\NoEscalation;
use YorvoID\Policy\RolePermit;
use YorvoID\Policy\StepUpForSensitiveChanges;

$evaluator = Evaluator::builtIn($authority, elements: [
    new RolePermit($roles),
    new NoEscalation($roles),
    new StepUpForSensitiveChanges($roles, new SessionStepUpCheck($stepUp)),
]);
```

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

Relations are added and removed with `AddRelation` and `RemoveRelation` (`yorvoid.relation.add`, `.remove`, ability `yorvoid.relations.manage`), each recorded in the security log. Nobody gives or takes a relation that gives what they don't hold on the resource themselves. A deleted principal's relations go.

A link also brings along what follows through it, so it's weighed by that too. Making page 42 the child of page 7 needs, on page 42:

- the abilities of the relations inherited through `parent` (page 7's viewers view page 42);
- what those carry further down, to whatever is within page 42, which only a role (in the realm, or on page 42 or above it) holds for every descendant;
- every ability of the roles given, to anyone, on page 7 and what it's within, and a super role among them only for someone with one.

Taking a link away is weighed the same way. A type can be **reserved** (`new ResourceType('group', [...], reserved: true)`): its relations change only through its owner's operations, and `AddRelation` and `RemoveRelation` throw `ReservedRelation`, for the operator too; in Symfony, that and `UnknownRelation` answer 400.

A role assigned on a resource also counts for what's within it, through `parent`: a manager of server 1 manages its sites. Relations are followed a level at a time, one query per level, at most 16 levels deep (`Relations::MAX_DEPTH`); what goes deeper can't be told, and the decision is Indeterminate, never a Permit.

## Groups

A group's members are principals, or another group's members, so groups nest. A role given to a group (`AssignGroupRole`) is every member's: in the whole realm, or on one resource, with an expiry.

| Operation | Command |
|---|---|
| `yorvoid.group.create`, `.delete` | `CreateGroup` (again: relabels it), `DeleteGroup` (its members leave it, its roles go) |
| `yorvoid.group.add_member`, `.remove_member` | `AddGroupMember`, `RemoveGroupMember`, with `Subject::principal($id)` or `GroupRelations::members('writers')` |
| `yorvoid.group.assign_role`, `.unassign_role` | `AssignGroupRole`, `UnassignGroupRole` |

They need `yorvoid.roles.manage`. Adding someone to a group gives them its roles, so the same rules hold as for giving a role: nobody adds a member to a group with a role they don't hold, and a group with a super role takes a recent proof. Deleting a group takes its roles and what's given to its members, and is weighed by that. Groups are relations of the type `group` (`GroupRelations`, which the Symfony bundle declares). The type is reserved, so the group operations are the only way to change a group.

## Attributes

Security attributes are what decisions may read about a principal (IDN-055), e.g. a department or a clearance, kept apart from profile data, which decisions never read. Their owner declares them in a service implementing `AttributeDeclarations` (`SecurityAttribute`: a namespaced name, a label, a type: string, integer, boolean or a list of strings). `SetAttribute` (`yorvoid.attribute.set`, ability `yorvoid.attributes.manage`) sets one or, with null, removes it. What an attribute decides is the application's rules', so nobody but the operator sets their own, setting anyone's takes a recent proof, and the ability is apart from managing accounts: no role has it until you give it; the security log names the attribute, never its value. A deleted principal's attributes go.

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

A request may be about one resource, by its type and ID (`ResourceRef`, e.g. `site:42`); YorvoID never loads it. A role assigned on that resource counts for the request. A command about one resource implements `ConcernsResource`.

### The subject

A request about an ability may carry its subject: the application's own object, e.g. the page being edited. Rules and attribute resolvers read it from `$request->subject`, so "edit your own" reads the page's author without loading the page again:

```php
$policy->allows($session, 'acme.pages.edit', $realm, null, $origin, $page);
```

```php
use App\Pages\Page;
use YorvoID\Core\Authorization\Request;
use YorvoID\Policy\Rule\RuleAnswer;

public function decide(Request $request): RuleAnswer
{
    $page = $request->subject;

    return $page instanceof Page && $page->authorId === (string) $request->actor->principalId ? RuleAnswer::permit('Its author edits it.') : RuleAnswer::abstain();
}
```

- **Its resource** is the one given, or else the one the subject concerns when it implements `ConcernsResource`. Any subject may come with a resource; one that implements `ConcernsResource` and concerns another is a mistake (`IntegratorError`).
- **A subject with no resource** is about none, so only roles on the whole realm count for it: a role on a page doesn't. Give the resource if roles on it should count.
- **It stays the application's:** it's never recorded in the decision log, written to the log or the security log, or part of a cache key, and nothing keeps it beyond the request. The decision records its resource.
- **In Symfony**, `isGranted()`'s subject is the resource when it's a `ResourceRef`, and the request's subject when it's any other object: `isGranted('acme.pages.edit', $page)`, with `Page` implementing `ConcernsResource` so roles on it count.

An element's detail texts are the decision's reason, which is recorded: don't put the subject's data in them.

## Rules

A rule in PHP (`Rule`) permits, forbids, abstains, or can't tell (`RuleAnswer`). A refusal wins over every role, super roles included; a permit lets the request through without a role; "can't tell" makes the decision Indeterminate, never a Permit. Give it to the evaluator as `RuleElements::of($rule)`; in Symfony, a service implementing `Rule` is added by itself.

## Remedies

A Deny caused only by missing evidence carries remedies: what could satisfy the requirement, never a permission by itself. Satisfying one creates evidence, and the next request is a new decision.

A forbid can hold for want of evidence too (`Truth::holdsUnless()`), as the step-up for sensitive changes does. Its Deny carries the remedies only when they'd be enough: every forbid that holds has one, and without them the request would be permitted. A remedy is a promise.

| Remedy | Asks for |
|---|---|
| `StepUpRemedy` | A recent proof of who you are: within a time, and possibly two factors or a phishing-resistant one |
| `WaitRemedy` | Trying again after a time (`until`), and why (`reason`, a `WaitReason`): e.g. `Recovered`, the cool-down that follows an account recovery. A wait for a reason of your own is a remedy of your own, with its own message |

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

In Symfony, you don't have to catch it: a refusal nothing catches is answered for the person ([refusals for people](symfony.md#refusals-for-people)).

## Explanations

Every element's truth says what it rests on (`Truth::$grounds`, a list of `Ground`), and a decision says which row of the precedence decided (`Decision::$precedence`). `Explanation::of($decision)` puts them together for an explainer of yours to build its own text from: the row, the elements behind it (the forbids that held, the permits that held, the authority check...), their grounds, the remedies and the versions it read. It has no text for people. Both are experimental.

```php
use YorvoID\Core\Authorization\Explanation;
use YorvoID\Core\Authorization\GroundKind;

$explanation = Explanation::of($policy->decide($session, 'acme.pages.publish', $realm, $page, $origin));

foreach ($explanation->grounds as $ground) {
    if (GroundKind::Role === $ground->kind) {
        // $ground->name: "editor"; $ground->grant: how it has the ability; $ground->on: site:1; $ground->group: "writers"
    }
}
```

What each element names:

- **The role permit:** every role the actor has that has the ability (`Ground::role()`), and how (`RoleGrant`): by its declared default, granted in the realm, or as a super role. With the resource it's assigned on, the request's or one it's within (null for the whole realm), the group the actor has it through, and the role source it comes from. Each way the actor has a role is named: in the realm and on the site is twice.
- **A sensitive change:** the proof of who they are too (`Ground::evidence('step_up')`).
- **The relation permit:** the relation to the resource that gave the ability (`Ground::relation()`).
- **A rule:** itself (`Ground::rule()`), and what its answer names, e.g. the attributes it read, by name and never their value: `RuleAnswer::permit('R&D publishes.', Ground::attribute('acme.department'))`.
- **The authority check:** the acting principal's status (`Ground::authority('active')`), or `none`, `other_realm`, `missing`.

### Why not

`Policy::whyNot()` answers why an actor may not do something (AUZ-021): the decision and its explanation, the remedies, and what would permit it: the roles that have the ability in the realm, the super roles when it isn't strict, the relations to the resource that give it, and the forbids that held, which would refuse it whatever else changed. It never names who has those roles.

```php
use YorvoID\Core\Authorization\ResourceRef;

$why = $policy->whyNot($session, $member, 'acme.pages.publish', $realm, new ResourceRef('page', $id), $origin);
```

An actor asks about themselves. Asking about someone else in the realm takes `yorvoid.policy.explain`, decided as any ability and, permitted or not, always recorded in the decision log; without it, `whyNot()` throws `NotPermitted`. `Policy` needs the `Roles` it decides with; in Symfony it has them.

### View-as

An administrator can see the application as someone else sees it (AUZ-023), e.g. to check what a member may see. `ViewingAs` is that actor: it acts as the target, and the evaluator decides each request for the viewer and for the target, each as above, and intersects them. So the view never exceeds the viewer:

| Viewer | Target | View |
|---|---|---|
| Permit | Permit | Permit |
| Indeterminate, or anything | Indeterminate, or anything | Indeterminate, if either is |
| Permit or Deny | Deny | Deny, with the target's reasons and remedies |
| Deny | Permit | Deny, with the viewer's reasons and remedies: none of the target's |

The decision carries the reasons of the side that decided, and the viewer's own decision (`Decision::$viewer`). The decision log records both: the target as the actor, and the viewer with its verdict and elements.

A view is read-only: the forbid `yorvoid.view_as.read_only` refuses every operation to it, and the security log names the viewer as the one who tried, with the target in `viewing_as`.

Begin one with the operation `yorvoid.view_as.begin` (`BeginViewAs`), which takes the ability `yorvoid.policy.view_as`. It refuses a target that isn't active or is the viewer, is recorded in the security log with the viewer as the actor and the target as the subject, and returns the actor and what's beyond the viewer: the abilities the target's roles give and the viewer's don't, in the whole realm and on each resource the target has a role on. Decide whether to show such a view at all (refuse it, as Quire does) or to warn that it's partial:

```php
use YorvoID\Policy\ViewAs\BeginViewAs;

$view = $operations->run(new BeginViewAs($realm, $member), $session, $origin);

if (!$view->complete()) {
    // $view->beyond: each Beyond's ability, and the resource it's on (null for the whole realm)
}

$policy->allows($view->actor, 'acme.pages.edit', $realm, $page, $origin);
```

Only roles are compared for what's beyond: a relation or a rule that permits the target isn't listed, though the view refuses it too. Nothing is stored: a view lasts as long as you hold the actor, and every decision reads the policy as it is then, so a viewer who loses a role during the view loses it in the view too. All of it is experimental.

## Why it couldn't decide

Every Indeterminate names what couldn't be established (`IndeterminateReason`): `store_unreachable`, `unknown_forbid`, `unverifiable_signature`, `missing_data`, `conflicting_records` or `evaluation_error`. New reasons can come in a minor release, so a `match` of yours on them needs a default arm.

## What a request reads

A request reads the realm's versions once, and the policy and relations once at those versions, however many decisions it makes: `CachedPolicyStore` and `CachedRelationStore` keep what they read in a `VersionedCache`. The whole request decides by one state of the policy. A change it makes itself counts at once; one made elsewhere counts from its next request.

Given a shared PSR-6 pool, the cache keeps what was read for the other servers too, against the version it was read at. A change increments the version, so what was read before it is never read again, on any server; nothing waits for an entry to expire (D-10). Only data is kept, never decisions: those depend on the moment, the session's evidence and the attributes.

- **Keys** start with the installation's ID, then the realm: `yorvoid.v2.{installation}.{realm}.policy.{version}.assignments-{hash}`, where the hash is of the read's arguments, here the principal, so no two reads share an entry whatever their IDs hold. The ID is random, made by the migration and kept in the database (`Installation`), so installations sharing a pool never read each other's entries, though each has a realm "default" at version 1.
- **A pool that fails** is passed over until the request ends, with a warning: the stores are read instead, slower and as correct.
- **The pool is trusted as the database is**: what's in it decides who holds which role. Give it a store only YorvoID's servers reach.
- **Between requests**, and between a worker's messages, the cache and the stores forget (`reset()`; in Symfony, `kernel.reset` does it). A long console command decides by the state it started with, apart from its own changes.

In Symfony, the stores are the cached ones, with the pool `yorvoid.cache`, which the bundle defines on `cache.app`. `cache.pool` names another, or with `null` keeps reads for the request only.

## When a store can't be reached

A store that can't be reached makes the decision Indeterminate with the reason `store_unreachable`, never a Permit: the connection was refused or lost, a statement timed out, or the server is starting, failing over or shutting down. That's told apart from other failures (`evaluation_error`) and from records that aren't there (`missing_data`). Waiting for a lock is contention, not unreachability.

YorvoID's DBAL stores report it through DBAL's exceptions, which `StoreUnreachable::of()` recognises; a store of your own throws `StoreUnreachable` itself ([stores of your own](storage.md#stores-of-your-own)).

## Offline

Some deployments must keep a few operations going while the store they decide from is out of reach, e.g. a door controller whose link home is down. That's off by default, and stays off unless both sides say so when YorvoID is built:

- **An `OfflineDecider`**, given to the evaluator (`Evaluator::builtIn(..., offline: $decider)`; in Symfony, a service with that interface). It's asked only when a decision is Indeterminate and every reason is `store_unreachable`, never for a forbid that couldn't be ruled out, and only where a permit could have applied.
- **The operations that accept it**, `#[Operation(..., acceptsOffline: true)]`: only these run on what the decider allows. There's no switch for all of them.

What the decider allows is **Permit-offline**, a verdict of its own, not a Permit: `Decision::permits()` is false for it, and only an operation that accepts it runs. Its permit says so (`$permit->offline`, as do its consequences), the security log records `yorvoid.decision.offline`, and it's logged as a warning. A decider without such an operation, or such an operation without a decider, fails when `Operations` is built.

### Offline limits

How long a node may decide offline is measured by **elapsed time**, never the wall clock, so setting the clock back or forward can't stretch it (experimental, XP-14):

- **`Elapsed`** gives a mark (`Instant`) and, later, the nanoseconds since it. It has no "now": decisions still read the PSR clock. It returns null, never a guess or a negative duration, for a mark of another boot, of another process where marks are per process, or ahead of now.
- **`BootTime`** is the one to use. On Linux it reads `/proc/uptime` with the boot's ID, so time the machine spends suspended counts. PHP's `hrtime()` doesn't count it (it reads `CLOCK_MONOTONIC`), so `BootTime` doesn't use it. Without `/proc`, marks are per process, for development only: under PHP-FPM every worker has its own, so each refuses the others' marks. `BootTime::boots()` says which it is.
- **`WithinOfflineLimit`** wraps your `OfflineDecider`: what it allows stands only while less than the limit has passed since a mark, e.g. when the link was lost. It can only refuse what the decider it wraps would allow, never allow more. A mark it can't measure from refuses, so a reboot never restarts the limit.
- **A stored mark is trusted data:** whoever can rewrite it can extend the limit. Where you keep it and how it's protected, e.g. with the grant, in the store, is yours.
- **In tests,** `YorvoID\Testing\MockElapsed` moves apart from a `MockClock`, and reboots.

```php
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\WithinOfflineLimit;
use YorvoID\Core\Clock\BootTime;
use YorvoID\Core\Clock\Instant;
use YorvoID\Core\Duration;

$offline = new WithinOfflineLimit($decider, new BootTime(), static fn (Request $request): ?Instant => $link->lostAt(), Duration::hours(4));
```

## Ordered conditions

A rule may depend on a fact on an ordered scale, e.g. a readiness level, with `OrderedConditions` (experimental, XP-15):

- **The fact** is an `OrderedFact` on a declared scale: `OrderedFact::known('readiness', 0, 3, $level)`, or `OrderedFact::unknown('readiness', 0, 3, $lastKnown)` when it isn't known now. **Higher is worse:** a scale that runs the other way (DEFCON 1 is the most severe) is mapped so that it does.
- **The condition** is `OrderedCondition::atLeast($k)` or `atMost($k)`. `$conditions->forbid($fact, $condition, $detail)` refuses when it holds; `$conditions->permit(...)` permits when the requirement is met. Both answer as a rule does.
- **By default, an unknown fact is missing data:** the rule can't tell, and the decision is Indeterminate (`missing_data`), as for any unknown.
- **Pessimistic** (`new OrderedConditions(pessimistic: true)`; in Symfony, `ordered_conditions.pessimistic: true`), an unknown fact is decided at its worst, by two views taken from two requirements:
  - **A forbid** applies if it applies at any value from the last known one up to the scale's highest (XP-15). This view assumes the fact hasn't fallen below its last known value while unknown.
  - **A requirement** counts as unmet unless every value of the scale meets it (RDY-04: "a requirement satisfied by readiness counts as unmet"). This view assumes nothing, so assuming the worst is never a reason to meet one.
  - **The asymmetry is deliberate:** each side is as strict as its requirement asks.
- **A Deny with no history is the point:** pessimistic, an unknown fact with no last known value gives a Deny under a forbid on any value of the scale, not Indeterminate. That's deliberate (RDY-04), not a bug to fix.

```php
use YorvoID\Core\Authorization\OrderedFact;
use YorvoID\Policy\Ordered\OrderedCondition;

return $this->conditions->forbid(OrderedFact::unknown('readiness', 0, 3, $this->lastReadiness()), OrderedCondition::atLeast(3), 'At readiness 3 or worse, nothing is deleted.');
```

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

Read the time from `$request->at`: one moment for the whole decision. In Symfony, a service implementing `PolicyElement` is added to the evaluator by itself; in plain PHP, pass it to `Evaluator::builtIn(..., elements: [new NoBadgesAtNight()])`. Two elements with one name, an element that's both a permit and a forbid, or an element without a forbid it requires (`RequiresForbids`) fail when the evaluator is built.
