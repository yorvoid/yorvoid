<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Symfony\LocalPath;

/**
 * Where a flow may send the browser: a path on this site, and nothing a browser would read as another
 * site's address (M2-10, the step-up's return).
 */
final class LocalPathTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function paths(): iterable
    {
        yield 'the root' => ['/', true];
        yield 'a path' => ['/projects', true];
        yield 'with a query' => ['/projects?page=2&sort=name', true];
        yield 'with a fragment' => ['/projects#old', true];
        yield 'encoded slashes stay a path' => ['/%2F/evil.example', true];
        yield 'an encoded tab stays a path' => ['/%09/evil.example', true];
        yield 'a fullwidth solidus is a character of the path' => ['/／evil.example', true];
        yield 'the longest' => ['/'.str_repeat('a', LocalPath::MAX_LENGTH - 1), true];

        yield 'empty' => ['', false];
        yield 'relative' => ['projects', false];
        yield 'another site' => ['https://evil.example/', false];
        yield 'scheme-relative' => ['//evil.example', false];
        yield 'a backslash after the slash' => ['/\\evil.example', false];
        yield 'a backslash first' => ['\\/evil.example', false];
        yield 'a backslash later' => ['/a\\b', false];
        yield 'a tab' => ["/\t/evil.example", false];
        yield 'a line break' => ["/\n/evil.example", false];
        yield 'a carriage return' => ["/\r/evil.example", false];
        yield 'a null byte' => ["/\0", false];
        yield 'a space' => ['/a b', false];
        yield 'DEL' => ["/a\x7f", false];
        yield 'a javascript: address' => ['javascript:alert(1)', false];
        yield 'not UTF-8' => ["/\xff", false];
        yield 'too long' => ['/'.str_repeat('a', LocalPath::MAX_LENGTH), false];
    }

    #[DataProvider('paths')]
    public function testOnlyAPathOnThisSiteIsLocal(string $path, bool $local): void
    {
        self::assertSame($local, LocalPath::is($path));
    }
}
