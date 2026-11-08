<?php

declare(strict_types=1);

namespace YorvoID\Policy;

use YorvoID\Core\Authorization\ResourceRef;
use YorvoID\Core\Operation\Decision;

/**
 * The decisions of a batch: one for each resource asked about, in the order asked.
 *
 * ```php
 * $decisions = $policy->decideMany($session, 'acme.pages.edit', $realm, $pages, $origin);
 * foreach ($decisions as $page => $decision) { ... }
 * ```
 *
 * @implements \IteratorAggregate<ResourceRef, Decision>
 *
 * @api
 */
final readonly class Decisions implements \IteratorAggregate, \Countable
{
    /**
     * @param list<array{ResourceRef, Decision}> $decisions
     */
    public function __construct(
        private array $decisions,
    ) {
    }

    /**
     * The decision about the resource.
     *
     * @throws \OutOfBoundsException When it wasn't asked about
     */
    public function for(ResourceRef $resource): Decision
    {
        foreach ($this->decisions as [$asked, $decision]) {
            if ($asked->equals($resource)) {
                return $decision;
            }
        }

        throw new \OutOfBoundsException(\sprintf('%s wasn\'t asked about.', $resource));
    }

    /**
     * Whether the decision about the resource is a Permit, and only that.
     *
     * @throws \OutOfBoundsException When it wasn't asked about
     */
    public function allows(ResourceRef $resource): bool
    {
        return $this->for($resource)->permits();
    }

    /**
     * The resources whose decision is a Permit, in the order asked.
     *
     * @return list<ResourceRef>
     */
    public function permitted(): array
    {
        $permitted = [];

        foreach ($this->decisions as [$resource, $decision]) {
            if ($decision->permits()) {
                $permitted[] = $resource;
            }
        }

        return $permitted;
    }

    public function getIterator(): \Generator
    {
        foreach ($this->decisions as [$resource, $decision]) {
            yield $resource => $decision;
        }
    }

    public function count(): int
    {
        return \count($this->decisions);
    }
}
