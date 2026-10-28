<?php

declare(strict_types=1);

namespace YorvoID\Tests\Core\Exception;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;

final class IntegratorErrorTest extends TestCase
{
    public function testTheMessageSaysWhatWentWrongHowToFixItAndWhere(): void
    {
        $previous = new \RuntimeException('cause');
        $error = new IntegratorError('The thing is wrong.', 'Do the other thing.', 'guides/things.md', $previous);

        self::assertSame(
            "The thing is wrong.\n\nHow to fix it: Do the other thing.\nDocumentation: https://github.com/yorvoid/yorvoid/blob/main/docs/guides/things.md",
            $error->getMessage(),
        );
        self::assertSame('The thing is wrong.', $error->problem);
        self::assertSame('Do the other thing.', $error->fix);
        self::assertSame('guides/things.md', $error->docs);
        self::assertSame($previous, $error->getPrevious());
    }
}
