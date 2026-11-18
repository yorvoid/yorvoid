<?php

declare(strict_types=1);

namespace YorvoID\Tests\Support;

/**
 * A database test that gets a database migrated for it alone, not a reset copy of the process's: for
 * one that changes the schema, e.g. by running migrations, or needs a database no test has used.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
final class FreshDatabase
{
}
