<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Authorization\Fixtures;

use YorvoID\Core\Authorization\AuthorityCheck;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Preloads;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Operation\AuthorizedBy;

/**
 * A permit, and an authority check, that hold for what was preloaded, and say so: it matches every
 * request but those about "page:other", and its preloading throws what it's told to.
 */
final class PreloadingPermit implements PermitElement, Preloads, AuthorityCheck
{
    /** @var list<list<Request>> Each batch it was given */
    public array $batches = [];

    /** @var \WeakMap<Request, true> */
    private \WeakMap $preloaded;

    public function __construct(
        private readonly string $name = 'preloading',
        private readonly ?\Throwable $failure = null,
        private readonly AuthorizedBy $basis = AuthorizedBy::Ability,
        private readonly ?\Throwable $matchFailure = null,
    ) {
        $this->preloaded = new \WeakMap();
    }

    public function name(): string
    {
        return $this->name;
    }

    public function basis(): AuthorizedBy
    {
        return $this->basis;
    }

    public function matches(Request $request): bool
    {
        if (null !== $this->matchFailure) {
            throw $this->matchFailure;
        }

        return 'page:other' !== (string) $request->resource;
    }

    public function preload(array $requests): void
    {
        $this->batches[] = $requests;

        if (null !== $this->failure) {
            throw $this->failure;
        }

        foreach ($requests as $request) {
            $this->preloaded[$request] = true;
        }
    }

    public function evaluate(Request $request): Truth
    {
        return isset($this->preloaded[$request]) ? Truth::holds('Preloaded.') : Truth::fails('Read alone.');
    }

    public function check(Request $request): Truth
    {
        return $this->evaluate($request);
    }
}
