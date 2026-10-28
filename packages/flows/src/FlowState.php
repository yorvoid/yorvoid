<?php

declare(strict_types=1);

namespace YorvoID\Flows;

use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Realm;

/**
 * Where a flow is: its steps done or passed over, the current one, and what the steps keep. It's kept
 * between requests by a FlowStore, so what steps keep is plain data: strings, numbers, booleans,
 * null, and lists and maps of them.
 *
 * @api
 */
final readonly class FlowState
{
    /**
     * @param string               $id      Random: whoever has it can continue the flow, so it stays server-side
     * @param string               $flow    The flow's name
     * @param list<string>         $passed  The steps done or passed over, in order
     * @param string|null          $current The step the person is on
     * @param array<string, mixed> $data    What the steps keep
     */
    public function __construct(
        public string $id,
        public string $flow,
        public Realm $realm,
        public \DateTimeImmutable $expiresAt,
        public array $passed = [],
        public ?string $current = null,
        public array $data = [],
    ) {
    }

    /**
     * As plain data, for a FlowStore to keep.
     *
     * @return array{id: string, flow: string, realm: string, expires_at: int, passed: list<string>, current: string|null, data: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'flow' => $this->flow, 'realm' => $this->realm->name, 'expires_at' => $this->expiresAt->getTimestamp(), 'passed' => $this->passed, 'current' => $this->current, 'data' => $this->data];
    }

    /**
     * What toArray() gave; null if it isn't that.
     */
    public static function fromArray(mixed $stored): ?self
    {
        if (!\is_array($stored)) {
            return null;
        }

        ['id' => $id, 'flow' => $flow, 'realm' => $realm, 'expires_at' => $expiresAt, 'passed' => $passed, 'current' => $current, 'data' => $data] = $stored + ['id' => null, 'flow' => null, 'realm' => null, 'expires_at' => null, 'passed' => null, 'current' => null, 'data' => null];

        if (!\is_string($id) || !\is_string($flow) || !\is_string($realm) || !\is_int($expiresAt) || !\is_array($passed) || !array_is_list($passed) || (null !== $current && !\is_string($current)) || !\is_array($data)) {
            return null;
        }

        $kept = array_filter($data, \is_string(...), \ARRAY_FILTER_USE_KEY);

        if (\count(array_filter($passed, \is_string(...))) !== \count($passed) || \count($kept) !== \count($data)) {
            return null;
        }

        // A list, and every step a string.
        /** @var list<string> $steps */
        $steps = $passed;

        return new self($id, $flow, new Realm($realm), new \DateTimeImmutable()->setTimestamp($expiresAt), $steps, $current, $kept);
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function string(string $key): ?string
    {
        $value = $this->get($key);

        return \is_string($value) ? $value : null;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws IntegratorError If a value isn't plain data
     */
    public function with(array $data): self
    {
        array_walk_recursive($data, static function (mixed $value, int|string $key): void {
            if (null !== $value && !\is_scalar($value)) {
                throw new IntegratorError(\sprintf('A flow step kept "%s", a %s.', $key, get_debug_type($value)), 'Keep plain data in the flow\'s state: strings, numbers, booleans, null, and lists and maps of them. Keep an object\'s ID, and load it again.', 'concepts/flows.md#writing-a-step');
            }
        });

        return new self($this->id, $this->flow, $this->realm, $this->expiresAt, $this->passed, $this->current, array_replace($this->data, $data));
    }

    /**
     * @param list<string> $keys
     */
    public function without(array $keys): self
    {
        return new self($this->id, $this->flow, $this->realm, $this->expiresAt, $this->passed, $this->current, array_diff_key($this->data, array_flip($keys)));
    }

    /**
     * @internal
     */
    public function at(?string $step): self
    {
        return new self($this->id, $this->flow, $this->realm, $this->expiresAt, $this->passed, $step, $this->data);
    }

    /**
     * @internal
     */
    public function passing(string $step): self
    {
        return new self($this->id, $this->flow, $this->realm, $this->expiresAt, [...$this->passed, $step], null, $this->data);
    }
}
