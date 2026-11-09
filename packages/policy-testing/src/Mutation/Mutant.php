<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting\Mutation;

/**
 * One change to the policy. Its ID is made from what it changes, e.g. "widen:acme.pages.publish:member",
 * so it stays the same when the policy is edited, and an ignored one stays the one ignored.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's policy tests use it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class Mutant
{
    /**
     * @param string|null $subject The element or the ability it changes
     * @param string|null $role    The role it changes
     */
    public function __construct(
        public MutationOperator $operator,
        public ?string $subject = null,
        public ?string $role = null,
    ) {
    }

    public function id(): string
    {
        return implode(':', array_filter([$this->operator->value, $this->subject, $this->role], static fn (?string $part): bool => null !== $part));
    }

    /**
     * What it changes, in a sentence.
     */
    public function description(): string
    {
        return match ($this->operator) {
            MutationOperator::DropForbid => \sprintf('The forbid "%s" never holds.', $this->subject),
            MutationOperator::ForbidEverything => \sprintf('The forbid "%s" holds whenever it matches.', $this->subject),
            MutationOperator::PermitAlways => \sprintf('The permit "%s" holds whenever it matches.', $this->subject),
            MutationOperator::PermitNever => \sprintf('The permit "%s" never holds.', $this->subject),
            MutationOperator::CanTell => \sprintf('"%s" fails where it couldn\'t tell.', $this->subject),
            MutationOperator::Widen => \sprintf('The role "%s" has "%s".', $this->role, $this->subject),
            MutationOperator::Narrow => \sprintf('The role "%s" doesn\'t have "%s".', $this->role, $this->subject),
            MutationOperator::StrictOff => \sprintf('The super role "%s" has the strict "%s".', $this->role, $this->subject),
            MutationOperator::Super => \sprintf('The role "%s" is a super role.', $this->role),
            MutationOperator::ScopeWidens => 'A role on a resource counts in the whole realm.',
            MutationOperator::GroupsDontCount => 'Roles and relations through a group don\'t reach its members.',
            MutationOperator::ParentsDontCount => 'Roles and relations on a parent don\'t reach its children.',
            MutationOperator::AuthorityOff => 'An actor that isn\'t active passes the authority check.',
        };
    }
}
