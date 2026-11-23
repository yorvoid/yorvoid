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

## Stores of your own

A `DelegationStore` of your own passes `DelegationStoreContract` from `yorvoid/testing` ([storage](storage.md#stores-of-your-own)). In Symfony it's `yorvoid.store.delegation`.
