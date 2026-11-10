<?php

declare(strict_types=1);

namespace YorvoID\E2e\Acme;

use YorvoID\Policy\Ability;
use YorvoID\Policy\PolicyDeclarations;

/**
 * Acme Team's ability: removing projects. Strict, so no super role gives it: only RecentProofToRemove does.
 */
final readonly class AcmeDeclarations implements PolicyDeclarations
{
    public function abilities(): iterable
    {
        yield new Ability('acme.projects.remove', 'Remove projects', 'acme', [], 'projects', strict: true);
    }

    public function roles(): iterable
    {
        return [];
    }
}
