<?php

declare(strict_types=1);

/*
 * Runs the benchmarks (M2-8): every public bench*() method of the tools/bench/*Bench.php classes, in one
 * process, each measured as its #[Measure] says (the method's, else the class's):
 *
 *   php tools/bench/run.php --out=var/bench/run.json [--filter=Class::subject] [--iterations=N]
 *
 * A subject's class is made once; its setUp($subject), if it has one, runs before the warm-up and
 * before each iteration, untimed. The warm-up runs once, then each iteration's revolutions are timed
 * together with hrtime(). The dump holds every iteration's time, in microseconds, for bench.php.
 */

use YorvoID\Bench\Measure;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$options = getopt('', ['out:', 'filter:', 'iterations:']);
$out = is_string($options['out'] ?? null) ? $options['out'] : 'var/bench/run.json';
$filter = is_string($options['filter'] ?? null) ? $options['filter'] : null;
$iterations = is_string($options['iterations'] ?? null) ? (int) $options['iterations'] : null;
$subjects = [];

$files = glob(__DIR__.'/*Bench.php');

foreach (false === $files ? [] : $files as $file) {
    $class = 'YorvoID\\Bench\\'.basename($file, '.php');

    if (!class_exists($class)) {
        throw new LogicException(sprintf('%s doesn\'t declare %s.', $file, $class));
    }

    $reflection = new ReflectionClass($class);
    $instance = null;

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $subject = $reflection->getShortName().'::'.$method->getName();

        if (!str_starts_with($method->getName(), 'bench') || (null !== $filter && $filter !== $subject)) {
            continue;
        }

        $attributes = [...$method->getAttributes(Measure::class), ...$reflection->getAttributes(Measure::class)];
        $measure = [] === $attributes ? new Measure() : $attributes[0]->newInstance();
        $instance ??= $reflection->newInstance();
        $setUp = method_exists($instance, 'setUp') ? $instance->setUp(...) : static fn (): null => null;
        $run = $method->getClosure($instance);
        $setUp($method->getName());

        for ($i = 0; $i < $measure->warmup; ++$i) {
            $run();
        }

        $times = [];

        for ($i = 0; $i < ($iterations ?? $measure->iterations); ++$i) {
            $setUp($method->getName());
            $start = hrtime(true);

            for ($r = 0; $r < $measure->revolutions; ++$r) {
                $run();
            }

            $times[] = (hrtime(true) - $start) / $measure->revolutions / 1000;
        }

        $subjects[$subject] = $times;
    }
}

$status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
$dump = ['php' => \PHP_VERSION, 'opcache' => is_array($status) && true === $status['opcache_enabled'], 'subjects' => $subjects];
is_dir(dirname($out)) || mkdir(dirname($out), 0777, true);
file_put_contents($out, json_encode($dump, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n");
fwrite(\STDERR, sprintf("%d subjects measured into %s\n", count($subjects), $out));
