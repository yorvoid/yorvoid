<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization;

use YorvoID\Core\Operation\OperationCatalogue;
use YorvoID\Core\Operation\OperationDescription;

/**
 * Every action a delegation may name: the abilities the catalogues list, and the operations. An
 * operation is delegable as it declares (#[Operation(delegable:)]); without that, as its ability, and
 * not to agents when it has none. YorvoID's own operations aren't delegable to agents unless they say
 * so: an agent doesn't manage principals, credentials, recovery or delegations.
 *
 * Matched by the request's name: an operation's name for an operation, never its ability, so a
 * delegable ability doesn't let a non-delegable operation through.
 *
 * @api
 *
 * @experimental
 */
final readonly class Actions
{
    /** @var array<string, Delegable> By name */
    private array $actions;

    /**
     * @param iterable<ListsActions> $abilities
     */
    public function __construct(OperationCatalogue $operations, iterable $abilities = [])
    {
        $listed = [];

        foreach ($abilities as $catalogue) {
            $listed = [...$listed, ...$catalogue->actions()];
        }

        foreach ($operations->all() as $operation) {
            $listed[$operation->name] = self::operation($operation, $listed);
        }

        ksort($listed, \SORT_STRING);
        $this->actions = $listed;
    }

    /**
     * To whom it may be delegated; null for an action nobody lists.
     */
    public function delegable(string $name): ?Delegable
    {
        return $this->actions[$name] ?? null;
    }

    /**
     * @return list<string> Every action's name, sorted
     */
    public function names(): array
    {
        return array_keys($this->actions);
    }

    /**
     * @param array<string, Delegable> $abilities
     */
    private static function operation(OperationDescription $operation, array $abilities): Delegable
    {
        if (null !== $operation->delegable) {
            return $operation->delegable;
        }

        if (str_starts_with($operation->name, 'yorvoid.')) {
            return Delegable::NotToAgents;
        }

        return null === $operation->ability ? Delegable::NotToAgents : ($abilities[$operation->ability] ?? Delegable::NotToAgents);
    }
}
