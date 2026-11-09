<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting;

use YorvoID\Core\Authorization\IndeterminateReason;
use YorvoID\Core\Authorization\Precedence;
use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\Command;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Core\Principal\PrincipalStatus;
use YorvoID\Core\Realm;

/**
 * One decision the policy must give (AUZ-043): who the actor is, what it asks, and what the policy
 * answers. Given the actor's roles, groups, relations, attributes and status; asks an ability, on a
 * resource, about a subject; then permits, denies or can't decide.
 *
 * ```php
 * yield PolicyCase::that('An editor publishes a page')
 *     ->given(roles: ['editor'])
 *     ->asks('acme.pages.publish', new ResourceRef('page', '1'))
 *     ->permits();
 * ```
 *
 * A case passes on its verdict; the remedies, the precedence row and the reasons it couldn't decide
 * count only when it states them.
 *
 * @api
 *
 * @experimental Outside the 1.0 promise until an application's policy tests use it: it may change in a
 *               minor release ([compatibility](docs/compatibility.md)).
 */
final readonly class PolicyCase
{
    /**
     * @param list<string>                                           $roles      In the whole realm
     * @param list<array{string, ResourceRef}>                       $on         Roles on a resource, e.g. ['editor', new ResourceRef('page', '1')]
     * @param array<string, list<string|array{string, ResourceRef}>> $groups     Groups the actor is a member of, with their roles, by group name
     * @param list<array{ResourceRef, string}>                       $relations  The actor's relations to resources, e.g. [new ResourceRef('page', '1'), 'editor']
     * @param array<string, string|int|bool|list<string>>            $attributes Its security attributes, by name
     * @param list<Command<mixed>|\Closure(Realm): Command<mixed>>   $before     Run first as the host's operator, e.g. a role created in the realm
     * @param list<string>|null                                      $remedies   The remedies' names, when stated
     * @param list<IndeterminateReason>|null                         $reasons    Why it couldn't decide, when stated
     */
    private function __construct(
        public string $description,
        public array $roles = [],
        public array $on = [],
        public array $groups = [],
        public array $relations = [],
        public array $attributes = [],
        public PrincipalStatus $status = PrincipalStatus::Active,
        public bool $anonymous = false,
        public array $before = [],
        public ?string $ability = null,
        public ?ResourceRef $resource = null,
        public ?object $subject = null,
        public ?Verdict $verdict = null,
        public ?array $remedies = null,
        public ?Precedence $precedence = null,
        public ?array $reasons = null,
    ) {
    }

    /**
     * @param string $description What it checks, in a sentence: the test's name, and the report's
     */
    public static function that(string $description): self
    {
        if ('' === trim($description)) {
            throw new IntegratorError('A policy case has no description.', 'Say what it checks, e.g. "An editor publishes a page".', 'how-to/test-your-policy.md#cases');
        }

        return new self($description);
    }

    /**
     * Who the actor is: a principal of the realm, created for the case.
     *
     * @param list<string>                                           $roles      In the whole realm
     * @param list<array{string, ResourceRef}>                       $on         Roles on a resource
     * @param array<string, list<string|array{string, ResourceRef}>> $groups     Group name => its roles, in the realm or [role, resource]
     * @param list<array{ResourceRef, string}>                       $relations  [resource, relation]
     * @param array<string, string|int|bool|list<string>>            $attributes By name
     */
    public function given(array $roles = [], array $on = [], array $groups = [], array $relations = [], array $attributes = [], PrincipalStatus $status = PrincipalStatus::Active): self
    {
        return $this->with(['roles' => $roles, 'on' => $on, 'groups' => $groups, 'relations' => $relations, 'attributes' => $attributes, 'status' => $status, 'anonymous' => false]);
    }

    /**
     * The actor is someone not signed in: no principal, only the "everyone" role.
     */
    public function anonymous(): self
    {
        return $this->with(['anonymous' => true]);
    }

    /**
     * Operations the host's operator runs before the actor is set up, e.g. creating a role in the realm
     * or granting it an ability: each a command, or a function that makes it for the policy's realm.
     *
     * ```php
     * ->before(static fn (Realm $realm): Command => new CreateRole($realm, 'reviewer', 'Reviewer'))
     * ```
     *
     * @param Command<mixed>|\Closure(Realm): Command<mixed> ...$commands
     */
    public function before(Command|\Closure ...$commands): self
    {
        $before = $this->before;

        foreach ($commands as $command) {
            $before[] = $command;
        }

        return $this->with(['before' => $before]);
    }

    /**
     * @param object|null $subject The application's own object it's about, for rules
     */
    public function asks(string $ability, ?ResourceRef $resource = null, ?object $subject = null): self
    {
        return $this->with(['ability' => $ability, 'resource' => $resource, 'subject' => $subject]);
    }

    public function permits(): self
    {
        return $this->expects(Verdict::Permit);
    }

    /**
     * @param list<string>|null $remedies Their names, e.g. ['step_up'], or [] for none; null for any
     */
    public function denies(?array $remedies = null, ?Precedence $precedence = null): self
    {
        return $this->expects(Verdict::Deny, $remedies, $precedence);
    }

    /**
     * @param list<IndeterminateReason>|null $reasons Why it can't decide; null for any
     */
    public function indeterminate(?array $reasons = null, ?Precedence $precedence = null): self
    {
        return $this->expects(Verdict::Indeterminate, null, $precedence, $reasons);
    }

    /**
     * @param list<string>|null              $remedies
     * @param list<IndeterminateReason>|null $reasons
     */
    private function expects(Verdict $verdict, ?array $remedies = null, ?Precedence $precedence = null, ?array $reasons = null): self
    {
        return $this->with(['verdict' => $verdict, 'remedies' => $remedies, 'precedence' => $precedence, 'reasons' => $reasons]);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function with(array $changes): self
    {
        $fields = get_object_vars($this);

        /* @phpstan-ignore argument.type (the fields are this class's own) */
        return new self(...array_replace($fields, $changes));
    }
}
