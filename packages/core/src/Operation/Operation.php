<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Exception\IntegratorError;

/**
 * Marks a command class as an operation: something that changes YorvoID's state, with a stable name, a
 * description, what authorizes it (D-38), the ability it needs, and the operation that undoes it, if any
 * (PLT-041). Applications list operations from the {@see OperationCatalogue} to build their own action
 * catalogues, admin pages and console commands, as Quire does with its actions; {@see Operations::run()}
 * runs them.
 *
 * ```php
 * #[Operation('yorvoid.principal.suspend', 'Suspend a principal', [AuthorizedBy::Ability, AuthorizedBy::Operator], ability: 'yorvoid.principals.manage', causes: ['yorvoid.session.end_all'])]
 * final readonly class SuspendPrincipal implements Command { public function __construct(public Realm $realm, public PrincipalId $principalId) {} }
 * ```
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Operation
{
    /** Dot-separated lowercase segments, e.g. "yorvoid.session.end" or "acme.badge.issue" */
    public const string NAME = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){2,}$/D';

    /** @var non-empty-list<AuthorizedBy> */
    public array $authorizedBy;

    /**
     * @param string                          $name           Stable, dot-separated, starting with the owner, e.g. "yorvoid.session.end"
     * @param string                          $description    What it does, for people, e.g. "End a session"
     * @param AuthorizedBy|list<AuthorizedBy> $authorizedBy   What lets an actor run it; the first that permits wins
     * @param string|null                     $ability        The ability a principal needs to run it, for AuthorizedBy::Ability
     * @param string|null                     $inverse        The operation that undoes it, if any
     * @param list<string>                    $causes         The operations it may run as its consequences, e.g. ending sessions:
     *                                                        a permit gives a consequence for these only
     * @param bool                            $acceptsOffline Whether it runs on Permit-offline, which an OfflineDecider may give
     *                                                        while a store is unreachable (XP-03, P1′); no operation does by default
     */
    public function __construct(
        public string $name,
        public string $description,
        AuthorizedBy|array $authorizedBy,
        public ?string $ability = null,
        public ?string $inverse = null,
        public array $causes = [],
        public bool $acceptsOffline = false,
    ) {
        foreach (['name' => $name, 'inverse' => $inverse] as $field => $value) {
            if (null !== $value && 1 !== preg_match(self::NAME, $value)) {
                throw new IntegratorError(\sprintf('The operation %s "%s" is invalid.', $field, $value), 'Use at least three dot-separated lowercase segments starting with the owner, e.g. "yorvoid.session.end".', 'concepts/operations-and-events.md#operations');
            }
        }

        foreach ($causes as $cause) {
            if (1 !== preg_match(self::NAME, $cause)) {
                throw new IntegratorError(\sprintf('The operation "%s" names "%s" among what it causes, which isn\'t an operation\'s name.', $name, $cause), 'Name the operations it runs as consequences, e.g. "yorvoid.session.end_all".', 'concepts/operations-and-events.md#permits');
            }
        }

        if ('' === trim($description)) {
            throw new IntegratorError(\sprintf('The operation "%s" has no description.', $name), 'Describe what it does in a few words, e.g. "End a session".', 'concepts/operations-and-events.md#operations');
        }

        $authorizedBy = \is_array($authorizedBy) ? array_values(array_unique($authorizedBy, \SORT_REGULAR)) : [$authorizedBy];

        if ([] === $authorizedBy) {
            throw new IntegratorError(\sprintf('The operation "%s" doesn\'t say what authorizes it.', $name), 'Name at least one AuthorizedBy, e.g. AuthorizedBy::Ability with the ability.', 'concepts/operations-and-events.md#operations');
        }

        if (\in_array(AuthorizedBy::Ability, $authorizedBy, true) !== (null !== $ability)) {
            throw new IntegratorError(\sprintf('The operation "%s" needs an ability exactly when AuthorizedBy::Ability authorizes it.', $name), 'Name the ability together with AuthorizedBy::Ability, and neither without the other.', 'concepts/operations-and-events.md#operations');
        }

        if (\in_array(AuthorizedBy::Consequence, $authorizedBy, true) && [AuthorizedBy::Consequence] !== $authorizedBy) {
            throw new IntegratorError(\sprintf('The operation "%s" is a consequence only, and something else.', $name), 'An operation that runs only as a consequence of others has AuthorizedBy::Consequence alone.', 'concepts/operations-and-events.md#operations');
        }

        $this->authorizedBy = $authorizedBy;
    }

    /**
     * The operation a command class declares.
     *
     * @param class-string $command
     *
     * @throws IntegratorError When the class has no #[Operation]
     */
    public static function of(string $command): self
    {
        $attributes = new \ReflectionClass($command)->getAttributes(self::class);

        if ([] === $attributes) {
            throw new IntegratorError(\sprintf('The class %s has no #[Operation] attribute.', $command), 'Add #[Operation(name, description, authorizedBy)] to the command class, or leave it out of the catalogue.', 'concepts/operations-and-events.md#operations');
        }

        return $attributes[0]->newInstance();
    }
}
