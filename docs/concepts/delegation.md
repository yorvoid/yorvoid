# Delegation

A **delegation** passes part of a principal's authority to another principal, e.g. an agent: for some actions on some resources, for a while, and maybe for one purpose or task. It's `yorvoid/delegation`'s, which depends on core only, and can only narrow what core decides. Experimental: it's being built in 0.5, and this page grows with it.

## What a delegation holds

- **Who:** the delegator, who passes it on, and the delegate, who receives it. A delegation of a delegation names its parent; the chain's top is its **root**, whose authority it is.
- **What:** the actions, expanded against the catalogue when it's issued, so an action added later isn't delegated, and the patterns as they were written.
- **Which resources:** a selector, below.
- **For what and how long:** a purpose, if any, and a window, from not-before up to its expiry.
- **Passed on again:** which of its actions may be, and how many more times.
- **A task:** an execution reference, if it's bound to one.
- **A proof each use needs:** a step-up, if any.
- **Its issuance:** the decision that permitted issuing it, and the policy version then, as provenance.
- **Its state:** active, or revoked, with when, by whom and why.

Every issue and revocation increments the realm's `delegation` version, in core's versions table, so a decision that read it sees the change at its next use.

## Resources

A delegation's resources are a **selector**: a type, and a conjunction of conditions on it.

- `IdIn`: the resource's ID is one of these.
- `AttributeIs`: one of its attributes has this value.
- `RelatedTo`: it has this relation to a subject.

A child delegation's selector is within its parent's when it has the same type and every one of the parent's conditions, with more, or with a smaller set of IDs in place of the parent's. That's a comparison of what's written, so it's decidable, and incomplete on purpose: a selector that means the same written differently is refused, never taken for narrower.

## Issuing a delegation

```php
use YorvoID\Core\Authorization\Selector\IdIn;
use YorvoID\Core\Authorization\Selector\Selector;
use YorvoID\Delegation\Change\IssueDelegation;

$delegation = $operations->run(new IssueDelegation($realm, $agent->id, ['content.report.*'], new Selector('report', [new IdIn(['r-1', 'r-2'])]), $now->modify('+7 days'), purpose: 'triage', execution: $run), $session, $origin);
```

It's `yorvoid.delegation.issue`, which needs the ability `yorvoid.delegations.issue` (everyone's by default), and it's decided like any change of authority:

- **A root delegator gives only what they hold** on the resources it names: on each ID, or in the whole realm when it names none. No-escalation refuses the rest, and giving a strict ability takes a recent proof, as for roles.
- **Its actions are expanded** against the catalogue when it's issued: an exact name, or a pattern of whole segments (`content.report.*`, everything under `content.report.`). An action added later isn't delegated. A name that matches nothing is refused.
- **What may not be delegated is refused**, never dropped: an ability or operation that isn't delegable to the delegate ([abilities](authorization.md#abilities)). YorvoID's own operations aren't delegable to agents.
- **An agent passes nothing on**, and nobody delegates to themselves. Its delegator and delegate are active principals of the realm.

### Passed on

A delegate who isn't an agent passes a delegation on by naming it as `parent:`. It's within its parent on every dimension: actions the parent may pass on, its resources narrowed, its purpose the parent's or below it, its window inside the parent's, less depth to pass on, the parent's task, at least the parent's proof. Its parent's chain must hold now. No-escalation doesn't weigh it, since what it gives is the parent's, not the delegate's own roles.

## Limits

`DelegationLimits` bounds what's issued: a year at most, 30 days to an agent, a chain of 3, 100 actions and 100 IDs. A delegation past them is refused.

## Purposes

`Purposes` registers purposes as a tree, each with the one above it: a delegation passed on keeps its parent's purpose or one below it. Without any registered, purposes are compared for equality.

## Revoking

- `RevokeDelegation` (`yorvoid.delegation.revoke`): by its delegator, one above it in its chain, or the operator. Everything passed on from it stops at its next use: its chain no longer holds.
- `RevokeDelegationsOf` (`yorvoid.delegation.revoke_all`, AGT-042): every active delegation issued by or to a principal, at once. It needs `yorvoid.delegations.manage`, which is strict and nobody's by default.

Neither waits for a proof: taking authority back never escalates.

## The abilities

The package names its abilities (`DelegationAbilities::NAMES`); whatever installs it declares them. The Symfony bundle does. In plain PHP, declare them with the rest of your policy:

```php
use YorvoID\Core\Authorization\Delegable;
use YorvoID\Delegation\DelegationAbilities;
use YorvoID\Policy\Ability;

yield new Ability(DelegationAbilities::ISSUE, DelegationAbilities::NAMES[DelegationAbilities::ISSUE], 'yorvoid', ['everyone'], delegable: Delegable::Nobody);
yield new Ability(DelegationAbilities::REVOKE, DelegationAbilities::NAMES[DelegationAbilities::REVOKE], 'yorvoid', ['everyone'], delegable: Delegable::Nobody);
yield new Ability(DelegationAbilities::MANAGE, DelegationAbilities::NAMES[DelegationAbilities::MANAGE], 'yorvoid', strict: true, delegable: Delegable::Nobody);
```

## Stores of your own

A `DelegationStore` of your own passes `DelegationStoreContract` from `yorvoid/testing` ([storage](storage.md#stores-of-your-own)). In Symfony it's `yorvoid.store.delegation`.
