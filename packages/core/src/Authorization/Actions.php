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
 * Operations and abilities are kept apart, and a request is matched by its kind and name: an operation's
 * name for an operation, never its ability, so a delegable ability doesn't let a non-delegable
 * operation through; and an ability's for an ability, so an operation named like it changes nothing.
 *
 * @api
 *
 * @experimental
 */
final readonly class Actions
{
    /** @var array<string, Delegable> By name */
    private array $operations;

    /** @var array<string, Delegable> By name */
    private array $abilities;

    /**
     * @param iterable<ListsActions> $abilities
     */
    public function __construct(OperationCatalogue $operations, iterable $abilities = [])
    {
        $listed = [];

        foreach ($abilities as $catalogue) {
            $listed = [...$listed, ...$catalogue->actions()];
        }

        // Apart from the abilities: an operation named like an ability never changes how the ability is
        // delegated, nor the other way round.
        $kept = [];

        foreach ($operations->all() as $operation) {
            $kept[$operation->name] = self::ruleOf($operation, $listed);
        }

        ksort($listed, \SORT_STRING);
        $this->abilities = $listed;
        // The catalogue's are sorted already.
        $this->operations = $kept;
    }

    /**
     * To whom the request's action may be delegated: its operation's for an operation, its ability's
     * otherwise; null for one nobody lists.
     */
    public function forRequest(Request $request): ?Delegable
    {
        return null === $request->operation ? $this->ability($request->name()) : $this->operation($request->name());
    }

    public function operation(string $name): ?Delegable
    {
        return $this->operations[$name] ?? null;
    }

    public function ability(string $name): ?Delegable
    {
        return $this->abilities[$name] ?? null;
    }

    /**
     * @return list<string> The operations' names, sorted
     */
    public function operations(): array
    {
        return array_keys($this->operations);
    }

    /**
     * @return list<string> The abilities' names, sorted
     */
    public function abilities(): array
    {
        return array_keys($this->abilities);
    }

    /**
     * @param array<string, Delegable> $abilities
     */
    private static function ruleOf(OperationDescription $operation, array $abilities): Delegable
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
