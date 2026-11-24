<?php

declare(strict_types=1);

namespace YorvoID\Core\Authorization\Selector;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Exception\IntegratorError;

/**
 * Which resources a delegation covers: a type, and a conjunction of conditions on it (M3-1's selector
 * language). A child delegation's selector is within its parent's when it has the same type and the
 * parent's conditions, with more, or with a smaller set of IDs in place of the parent's: syntactic, so
 * decidable, and incomplete on purpose (an equivalent selector written differently is refused).
 *
 * @api
 *
 * @experimental
 */
final readonly class Selector
{
    /** @var list<Atom> In their canonical order */
    public array $atoms;

    /**
     * @param list<Atom> $atoms
     *
     * @throws IntegratorError When the type is empty, or two conditions are about IDs
     */
    public function __construct(
        public string $type,
        array $atoms = [],
    ) {
        if ('' === $type) {
            throw new IntegratorError('A selector needs a resource type.', 'Name the type of the resources it covers, e.g. "page".', 'concepts/delegation.md#resources');
        }

        if (\count(array_filter($atoms, static fn (Atom $atom): bool => $atom instanceof IdIn)) > 1) {
            throw new IntegratorError('A selector has one set of IDs at most.', 'Give the IDs in one IdIn.', 'concepts/delegation.md#resources');
        }

        $unique = [];

        foreach ($atoms as $atom) {
            $unique[self::key($atom)] = $atom;
        }

        ksort($unique, \SORT_STRING);
        $this->atoms = array_values($unique);
    }

    /**
     * Whether it's within another: the same type, every one of the other's conditions, and a set of IDs
     * only where the other has one or none, a subset of the other's.
     */
    public function within(self $parent): bool
    {
        if ($this->type !== $parent->type) {
            return false;
        }

        $mine = $this->keyed();

        foreach ($parent->atoms as $atom) {
            if ($atom instanceof IdIn) {
                $ids = $this->ids();

                if (null === $ids || [] !== array_diff($ids->ids, $atom->ids)) {
                    return false;
                }

                continue;
            }

            if (!isset($mine[self::key($atom)])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a resource is of its type and among its IDs; its other conditions need data (MatchesSelectors).
     */
    public function admitsTypeAndId(ResourceRef $resource): bool
    {
        return $resource->type === $this->type && (null === ($ids = $this->ids()) || \in_array($resource->id, $ids->ids, true));
    }

    public function ids(): ?IdIn
    {
        foreach ($this->atoms as $atom) {
            if ($atom instanceof IdIn) {
                return $atom;
            }
        }

        return null;
    }

    /**
     * @return array{type: string, atoms: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['type' => $this->type, 'atoms' => array_map(static fn (Atom $atom): array => $atom->toArray(), $this->atoms)];
    }

    /**
     * @param array<mixed> $data
     *
     * @throws \UnexpectedValueException When it isn't one
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;
        $atoms = $data['atoms'] ?? null;

        if (!\is_string($type) || !\is_array($atoms) || !array_is_list($atoms)) {
            throw new \UnexpectedValueException('A stored selector is malformed.');
        }

        return new self($type, array_map(self::atom(...), $atoms));
    }

    /**
     * @return array<string, Atom>
     */
    private function keyed(): array
    {
        $keyed = [];

        foreach ($this->atoms as $atom) {
            $keyed[self::key($atom)] = $atom;
        }

        return $keyed;
    }

    /**
     * @throws IntegratorError When it holds text that isn't valid UTF-8, which has no one form to compare
     */
    private static function key(Atom $atom): string
    {
        try {
            return json_encode($atom->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new IntegratorError('A selector\'s condition holds text that isn\'t valid UTF-8.', 'Give names, values and IDs as UTF-8 text.', 'concepts/delegation.md#resources');
        }
    }

    /**
     * @param array<mixed> $ids
     *
     * @return list<string>
     */
    private static function idsOf(array $ids): array
    {
        $strings = [];

        foreach ($ids as $key => $id) {
            $strings[] = \is_int($key) && \is_string($id) ? $id : throw new \UnexpectedValueException('A stored selector\'s IDs are malformed.');
        }

        return $strings;
    }

    private static function atom(mixed $data): Atom
    {
        if (!\is_array($data)) {
            throw new \UnexpectedValueException('A stored selector\'s condition is malformed.');
        }

        return match (true) {
            \is_array($data['id_in'] ?? null) => new IdIn(self::idsOf($data['id_in'])),
            \is_string($data['attribute'] ?? null) && (\is_string($data['is'] ?? null) || \is_int($data['is'] ?? null) || \is_bool($data['is'] ?? null)) => new AttributeIs($data['attribute'], $data['is']),
            \is_string($data['related'] ?? null) && \is_array($data['to'] ?? null) && \is_string($data['to'][0] ?? null) && \is_string($data['to'][1] ?? null) => new RelatedTo($data['related'], new ResourceRef($data['to'][0], $data['to'][1])),
            default => throw new \UnexpectedValueException('A stored selector\'s condition is malformed.'),
        };
    }
}
