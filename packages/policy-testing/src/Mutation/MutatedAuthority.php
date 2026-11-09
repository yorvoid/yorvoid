<?php

declare(strict_types=1);

namespace YorvoID\PolicyTesting\Mutation;

use YorvoID\Core\Authorization\AuthorityCheck;
use YorvoID\Core\Authorization\Preloads;
use YorvoID\Core\Authorization\Request;
use YorvoID\Core\Authorization\Truth;
use YorvoID\Core\Authorization\TruthValue;

/**
 * The authority check, as the mutant on changes it.
 *
 * @internal
 */
final readonly class MutatedAuthority implements AuthorityCheck, Preloads
{
    public function __construct(
        public AuthorityCheck $inner,
        private Mutants $mutants,
    ) {
    }

    public function check(Request $request): Truth
    {
        $truth = $this->inner->check($request);

        return TruthValue::Fails === $truth->value && $this->mutants->is(MutationOperator::AuthorityOff) ? Truth::holds('Mutant: the authority check passes.') : $truth;
    }

    public function preload(array $requests): void
    {
        if ($this->inner instanceof Preloads) {
            $this->inner->preload($requests);
        }
    }
}
