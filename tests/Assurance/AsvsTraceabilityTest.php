<?php

declare(strict_types=1);

namespace YorvoID\Tests\Assurance;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The ASVS traceability file (QA-002) keeps its own rules: every one of the standard's 345
 * requirements is listed once; a covered or partly covered one names tests that exist; one left to
 * the application names a docs page that exists; partly covered and not applicable ones say why.
 * (That the IDs match the official list is checked against OWASP's file by assurance/check-asvs.php.).
 */
final class AsvsTraceabilityTest extends TestCase
{
    private const string FILE = __DIR__.'/../../assurance/asvs-5.0.yaml';

    private const array STATUSES = ['open', 'covered', 'partly', 'host', 'not-applicable'];

    public function testEveryRequirementIsListedWithAValidAssessment(): void
    {
        $requirements = $this->requirements();

        self::assertCount(345, $requirements);

        foreach ($requirements as $id => $requirement) {
            self::assertMatchesRegularExpression('/^V[0-9]{1,2}\.[0-9]{1,2}\.[0-9]{1,2}$/', $id);
            self::assertContains($requirement['level'] ?? null, [1, 2, 3], $id);
            self::assertContains($requirement['status'] ?? null, self::STATUSES, $id);

            $tests = $requirement['tests'] ?? [];
            $docs = $requirement['docs'] ?? [];
            \assert(\is_array($tests) && \is_array($docs));

            match ($requirement['status']) {
                'covered' => self::assertNotEmpty($tests, \sprintf('%s is covered but names no tests.', $id)),
                'partly' => self::assertTrue([] !== $tests && '' !== ($requirement['note'] ?? ''), \sprintf('%s is partly covered: it names tests and says what\'s missing.', $id)),
                'host' => self::assertNotEmpty($docs, \sprintf('%s is left to the application but no docs page says how.', $id)),
                'not-applicable' => self::assertNotEmpty($requirement['note'] ?? '', \sprintf('%s is not applicable but doesn\'t say why.', $id)),
                default => null,
            };
        }
    }

    public function testEveryNamedTestAndPageExists(): void
    {
        foreach ($this->requirements() as $id => $requirement) {
            $tests = $requirement['tests'] ?? [];
            $docs = $requirement['docs'] ?? [];
            \assert(\is_array($tests) && \is_array($docs));

            foreach ($tests as $test) {
                \assert(\is_string($test));
                [$class, $method] = explode('::', $test) + [1 => ''];
                self::assertTrue(class_exists($class) && method_exists($class, $method), \sprintf('%s names the test %s, which doesn\'t exist.', $id, $test));
            }

            foreach ($docs as $page) {
                \assert(\is_string($page));
                self::assertFileExists(__DIR__.'/../../docs/'.$page, \sprintf('%s names docs/%s, which doesn\'t exist.', $id, $page));
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function requirements(): array
    {
        $file = Yaml::parseFile(self::FILE);
        \assert(\is_array($file) && \is_array($file['requirements']));
        $requirements = [];

        foreach ($file['requirements'] as $id => $requirement) {
            \assert(\is_string($id) && \is_array($requirement));
            $requirements[$id] = array_filter($requirement, is_string(...), \ARRAY_FILTER_USE_KEY);
        }

        return $requirements;
    }
}
