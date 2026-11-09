<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting\Mutation;

use YorvoID\Core\Authorization\ChecksCatalogue;
use YorvoID\Core\Authorization\PermitElement;
use YorvoID\Core\Authorization\Preloads;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Operation\AuthorizedBy;
use YorvoID\Core\Operation\OperationCatalogue;

/**
 * A permit of the application's, as the mutant on changes it.
 *
 * @internal
 */
final readonly class MutatedPermit implements PermitElement, Preloads, ChecksCatalogue
{
    public function __construct(
        public PermitElement $inner,
        private Mutants $mutants,
    ) {
    }

    public function name(): string
    {
        return $this->inner->name();
    }

    public function basis(): AuthorizedBy
    {
        return $this->inner->basis();
    }

    public function matches(Request $request): bool
    {
        return $this->inner->matches($request);
    }

    public function evaluate(Request $request): Truth
    {
        return $this->mutants->truth($this->inner, $this->inner->evaluate($request));
    }

    public function preload(array $requests): void
    {
        if ($this->inner instanceof Preloads) {
            $this->inner->preload($requests);
        }
    }

    public function checkCatalogue(OperationCatalogue $catalogue): void
    {
        if ($this->inner instanceof ChecksCatalogue) {
            $this->inner->checkCatalogue($catalogue);
        }
    }
}
