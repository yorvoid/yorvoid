<?php

declare(strict_types=1);

namespace YorvoID\Tests\Tools;

use PHPUnit\Framework\TestCase;

/**
 * The benchmarks' gate and record (tools/bench/bench.php): it refuses what isn't a measurement, says what
 * it doesn't gate, and trusts itself only on a clean A/A record.
 */
final class BenchTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = \sprintf('%s/yorvoid-bench-test-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
    }

    public function testGatesOnTheLowerQuartile(): void
    {
        $base = $this->dump('base', ['A::benchX' => [10, 10, 10, 10], 'A::benchY' => [10, 10, 10, 10], 'A::benchGone' => [5]]);
        $head = $this->dump('head', ['A::benchX' => [11, 30, 30, 30], 'A::benchY' => [13, 13, 13, 13], 'A::benchNew' => [1]]);

        [$status, $output] = self::bench('compare', $base, $head, $this->directory.'/none.txt', $this->directory.'/changes.json');
        self::assertSame(1, $status, $output);
        self::assertStringStartsWith('2 of 4 subjects not gated.', $output);
        self::assertStringContainsString('| A::benchX | 0.010 ms | 0.011 ms | +10.0 % | ok |', $output, 'The lower quartile: three slow iterations of four don\'t move it.');
        self::assertStringContainsString('| A::benchY | 0.010 ms | 0.013 ms | +30.0 % | **slower** |', $output);
        self::assertStringContainsString('| A::benchNew | new | 0.001 ms | | changed, not gated |', $output);
        self::assertStringContainsString('| A::benchGone | 0.005 ms | | | removed, not gated |', $output);
        self::assertSame(['A::benchX' => 10, 'A::benchY' => 30], json_decode((string) file_get_contents($this->directory.'/changes.json'), true));

        file_put_contents($this->directory.'/changed.txt', "A::*\n");
        [$status, $output] = self::bench('compare', $base, $head, $this->directory.'/changed.txt');
        self::assertSame(0, $status, 'A benchmark that changed isn\'t gated.');
        self::assertStringStartsWith('4 of 4 subjects not gated.', $output);
        self::assertSame(1, self::bench('compare', $this->dump('one', ['A::benchX' => [10, 10]]).','.$this->dump('two', ['A::benchX' => [20, 20]]), $this->dump('three', ['A::benchX' => [13, 14]]))[0], 'Dumps pooled per side: 10 against 13.');
    }

    public function testRefusesWhatIsntAMeasurement(): void
    {
        $good = $this->dump('good', ['A::benchX' => [10]]);

        foreach ([
            'no times for A::benchX' => ['A::benchX' => []],
            'a time for A::benchX that isn\'t a number: "fast"' => ['A::benchX' => ['fast']],
        ] as $message => $subjects) {
            [$status, $output] = self::bench('compare', $good, $this->dump('bad', $subjects));
            self::assertNotSame(0, $status);
            self::assertStringContainsString($message, $output);
        }

        // An empty base never reaches the comparison, whose quartile it would divide by.
        [$status, $output] = self::bench('compare', $this->dump('empty', ['A::benchX' => []]), $good);
        self::assertNotSame(0, $status);
        self::assertStringContainsString('has no times for A::benchX', $output);
        self::assertStringNotContainsString('Division', $output);

        file_put_contents($this->directory.'/nothing.json', '{"php": "8.5"}');
        self::assertStringContainsString('isn\'t a dump of tools/bench/run.php.', self::bench('report', $this->directory.'/nothing.json')[1]);
    }

    public function testTellsTheDriftSinceARelease(): void
    {
        $release = $this->dump('release', ['A::benchX' => [10, 10, 10, 10]]);
        $head = $this->dump('head', ['A::benchX' => [10, 10, 12, 40], 'A::benchNew' => [1]]);

        [$status, $output] = self::bench('drift', $release, $head);
        self::assertSame(0, $status, 'It gates nothing.');
        self::assertStringContainsString('| A::benchX | +0.0 % | +10.0 % | +300.0 % |', $output, 'The tail the quartile doesn\'t see.');
        self::assertStringContainsString('| A::benchNew | new | new | new |', $output);
    }

    public function testTheRecordTrustsOnlyAQuietGate(): void
    {
        mkdir($this->directory.'/record');
        file_put_contents($this->directory.'/record/1.json', '{"A::benchX": 3.5, "A::benchY": -14.9}');
        file_put_contents($this->directory.'/record/2.json', '{"A::benchX": -1.0, "A::benchY": 2.0}');

        [$status, $output] = self::bench('record', $this->directory.'/record', '2');
        self::assertSame(0, $status, $output);
        self::assertStringContainsString('| A::benchY | 14.9 % | 0 |', $output, 'Its worst, either way.');
        self::assertSame(1, self::bench('record', $this->directory.'/record', '3')[0], 'A run is missing.');

        file_put_contents($this->directory.'/record/3.json', '{"A::benchX": 15.1}');
        self::assertSame(1, self::bench('record', $this->directory.'/record', '3')[0], 'Over three quarters of the gate.');

        file_put_contents($this->directory.'/record/3.json', '{"A::benchX": 21}');
        [$failed, $output] = self::bench('record', $this->directory.'/record', '3');
        self::assertStringContainsString('| A::benchX | 21.0 % | 1 |', $output, 'A failure, counted.');
        self::assertGreaterThan(0, $failed);
        self::removeDirectory($this->directory.'/record');
    }

    /**
     * @param array<string, list<mixed>> $subjects
     */
    private function dump(string $name, array $subjects): string
    {
        $file = \sprintf('%s/%s.json', $this->directory, $name);
        file_put_contents($file, json_encode(['subjects' => $subjects]));

        return $file;
    }

    private static function removeDirectory(string $directory): void
    {
        $files = glob($directory.'/*');

        foreach (false === $files ? [] : $files as $file) {
            is_dir($file) ? self::removeDirectory($file) : unlink($file);
        }

        rmdir($directory);
    }

    /**
     * @return array{int, string}
     */
    private static function bench(string ...$arguments): array
    {
        $process = proc_open([\PHP_BINARY, \dirname(__DIR__, 2).'/tools/bench/bench.php', ...array_values($arguments)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        \assert(\is_resource($process));
        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
