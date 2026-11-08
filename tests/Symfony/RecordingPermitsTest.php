<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use YorvoID\Policy\Ability;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Policy\PolicyDeclarations;
use YorvoID\Symfony\Factories;

/**
 * The abilities declared as always recording their permits are the decision log's.
 */
final class RecordingPermitsTest extends TestCase
{
    public function testTheDeclaredAbilitiesAlwaysRecordTheirPermits(): void
    {
        $declarations = new class implements PolicyDeclarations {
            public function abilities(): iterable
            {
                yield new Ability('acme.pages.view', 'View pages', 'acme');
                yield new Ability('acme.access.explain', 'Explain someone\'s access', 'acme', recordsPermits: true);
                yield new Ability('acme.access.view_as', 'View as someone', 'acme', recordsPermits: true);
            }

            public function roles(): iterable
            {
                return [];
            }
        };

        self::assertSame(['acme.access.explain', 'acme.access.view_as'], Factories::recordingPermits(PolicyCatalogue::of([$declarations])));
    }
}
