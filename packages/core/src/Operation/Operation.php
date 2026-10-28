<?php

declare(strict_types=1);

namespace YorvoID\Core\Operation;

use YorvoID\Core\Exception\IntegratorError;

/**
 * Marks a command class as an operation: something that changes YorvoID's state, with a stable name, a
 * description, the ability it needs, and the operation that undoes it, if any (PLT-041). Applications
 * list operations from the {@see OperationCatalogue} to build their own action catalogues, admin pages
 * and console commands, as Quire does with its actions.
 *
 * ```php
 * #[Operation('yorvoid.session.end', 'End a session', ability: 'yorvoid.sessions.manage')]
 * final readonly class EndSession { public function __construct(public string $sessionId) {} }
 * ```
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Operation
{
    /** Dot-separated lowercase segments, e.g. "yorvoid.session.end" or "acme.badge.issue" */
    public const string NAME = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){2,}$/D';

    /**
     * @param string      $name        Stable, dot-separated, starting with the owner, e.g. "yorvoid.session.end"
     * @param string      $description What it does, for people, e.g. "End a session"
     * @param string|null $ability     The ability a principal needs to run it, if any
     * @param string|null $inverse     The operation that undoes it, if any
     */
    public function __construct(
        public string $name,
        public string $description,
        public ?string $ability = null,
        public ?string $inverse = null,
    ) {
        foreach (['name' => $name, 'inverse' => $inverse] as $field => $value) {
            if (null !== $value && 1 !== preg_match(self::NAME, $value)) {
                throw new IntegratorError(\sprintf('The operation %s "%s" is invalid.', $field, $value), 'Use at least three dot-separated lowercase segments starting with the owner, e.g. "yorvoid.session.end".', 'concepts/operations-and-events.md#operations');
            }
        }

        if ('' === trim($description)) {
            throw new IntegratorError(\sprintf('The operation "%s" has no description.', $name), 'Describe what it does in a few words, e.g. "End a session".', 'concepts/operations-and-events.md#operations');
        }
    }
}
