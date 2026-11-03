<?php

declare(strict_types=1);

namespace YorvoID\Tests\Symfony;

use PHPUnit\Framework\TestCase;
use YorvoID\Symfony\Templates\UnifiedDiff;

/**
 * yorvoid:templates:diff's diffs, as diff -u writes them.
 */
final class UnifiedDiffTest extends TestCase
{
    public function testTheSameTextsHaveNoDiff(): void
    {
        self::assertSame('', UnifiedDiff::of("a\nb\n", "a\nb\n", 'old', 'new'));
    }

    public function testAChangedLineWithItsContext(): void
    {
        $from = "1\n2\n3\n4\n5\n6\n7\n8\n9\n";
        $to = "1\n2\n3\n4\nfive\n6\n7\n8\n9\n";

        self::assertSame("--- old\n+++ new\n@@ -2,7 +2,7 @@\n 2\n 3\n 4\n-5\n+five\n 6\n 7\n 8\n", UnifiedDiff::of($from, $to, 'old', 'new'));
    }

    public function testChangesFarApartAreHunksOfTheirOwnAndNearOnesShareOne(): void
    {
        $lines = array_map(strval(...), range(1, 20));
        $from = implode("\n", $lines)."\n";
        $far = implode("\n", array_replace($lines, [1 => 'two', 17 => 'eighteen']))."\n";
        $near = implode("\n", array_replace($lines, [1 => 'two', 7 => 'eight']))."\n";

        self::assertSame("--- a\n+++ b\n@@ -1,5 +1,5 @@\n 1\n-2\n+two\n 3\n 4\n 5\n@@ -15,6 +15,6 @@\n 15\n 16\n 17\n-18\n+eighteen\n 19\n 20\n", UnifiedDiff::of($from, $far, 'a', 'b'));
        self::assertSame("--- a\n+++ b\n@@ -1,11 +1,11 @@\n 1\n-2\n+two\n 3\n 4\n 5\n 6\n 7\n-8\n+eight\n 9\n 10\n 11\n", UnifiedDiff::of($from, $near, 'a', 'b'));
    }

    /**
     * As GNU diff -u writes them.
     */
    public function testAsDiffWritesThem(): void
    {
        $from = self::numbered([]);

        self::assertSame("--- a\n+++ b\n@@ -1,4 +1,4 @@\n-1\n+one\n 2\n 3\n 4\n@@ -15,6 +15,6 @@\n 15\n 16\n 17\n-18\n+eighteen\n 19\n 20\n", UnifiedDiff::of($from, self::numbered([0 => 'one', 17 => 'eighteen']), 'a', 'b'), 'The first line changed.');
        self::assertSame("--- a\n+++ b\n@@ -1,12 +1,12 @@\n 1\n-2\n+two\n 3\n 4\n 5\n 6\n 7\n 8\n-9\n+nine\n 10\n 11\n 12\n", UnifiedDiff::of($from, self::numbered([1 => 'two', 8 => 'nine']), 'a', 'b'), 'Six lines apart: one hunk.');
        self::assertSame("--- a\n+++ b\n@@ -1,5 +1,5 @@\n 1\n-2\n+two\n 3\n 4\n 5\n@@ -7,7 +7,7 @@\n 7\n 8\n 9\n-10\n+ten\n 11\n 12\n 13\n", UnifiedDiff::of($from, self::numbered([1 => 'two', 9 => 'ten']), 'a', 'b'), 'Seven lines apart: two hunks.');
        self::assertSame("--- a\n+++ b\n@@ -1,5 +1,4 @@\n-x\n a\n b\n c\n-y\n+z\n", UnifiedDiff::of("x\na\nb\nc\ny\n", "a\nb\nc\nz\n", 'a', 'b'), 'The longest run in common stays.');
    }

    /**
     * A diff that keeps the longest run of lines in common, whichever way the lines moved.
     */
    public function testKeepsTheMostLinesInCommon(): void
    {
        self::assertSame("--- a\n+++ b\n@@ -1,7 +1,6 @@\n-a\n-b\n c\n-a\n b\n+a\n b\n a\n+c\n", UnifiedDiff::of("a\nb\nc\na\nb\nb\na\n", "c\nb\na\nb\na\nc\n", 'a', 'b'), 'Four lines of seven kept.');
        self::assertSame("--- a\n+++ b\n@@ -1,6 +1,6 @@\n-A\n-B\n-C\n D\n E\n F\n+A\n+B\n+C\n", UnifiedDiff::of("A\nB\nC\nD\nE\nF\n", "D\nE\nF\nA\nB\nC\n", 'a', 'b'));
        self::assertSame("--- a\n+++ b\n@@ -1,3 +1,3 @@\n-q\n r\n s\n+q\n", UnifiedDiff::of("q\nr\ns\n", "r\ns\nq\n", 'a', 'b'));
    }

    public function testAddedToAndRemovedFromNothing(): void
    {
        self::assertSame("--- a\n+++ b\n@@ -0,0 +1,2 @@\n+x\n+y\n", UnifiedDiff::of('', "x\ny\n", 'a', 'b'));
        self::assertSame("--- a\n+++ b\n@@ -1,2 +0,0 @@\n-x\n-y\n", UnifiedDiff::of("x\ny", '', 'a', 'b'));
        self::assertSame("--- a\n+++ b\n@@ -1,2 +1,3 @@\n x\n+new\n y\n", UnifiedDiff::of("x\ny\n", "x\nnew\ny\n", 'a', 'b'));
    }

    /**
     * The lines "1" to "20", with some changed.
     *
     * @param array<int, string> $changes By index
     */
    private static function numbered(array $changes): string
    {
        $lines = array_map(static fn (int $line): string => (string) $line, range(1, 20));

        foreach ($changes as $index => $change) {
            $lines[$index] = $change;
        }

        return implode("\n", $lines)."\n";
    }
}
