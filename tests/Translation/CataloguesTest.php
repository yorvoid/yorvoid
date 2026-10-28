<?php

declare(strict_types=1);

namespace YorvoID\Tests\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every catalogue YorvoID ships (PLT-050): English and German have the same messages, each valid ICU
 * MessageFormat, with the same parameters.
 */
final class CataloguesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function englishCatalogues(): iterable
    {
        $files = glob(__DIR__.'/../../packages/*/translations/*.en.php');

        foreach (false === $files ? [] : $files as $file) {
            yield basename(\dirname($file, 2)).'/'.basename($file) => [$file];
        }
    }

    #[DataProvider('englishCatalogues')]
    public function testGermanHasTheSameMessagesWithTheSameParameters(string $english): void
    {
        $en = self::messages($english);
        $de = self::messages(str_replace('.en.php', '.de.php', $english));

        self::assertSame(array_keys($en), array_keys($de), 'The German catalogue has other messages, or another order.');

        foreach ($en as $id => $message) {
            self::assertSame(self::parameters($message), self::parameters($de[$id]), \sprintf('"%s" has other parameters in German.', $id));
        }
    }

    #[DataProvider('englishCatalogues')]
    public function testEveryMessageIsValidIcu(string $english): void
    {
        foreach (['en' => $english, 'de' => str_replace('.en.php', '.de.php', $english)] as $locale => $file) {
            foreach (self::messages($file) as $id => $message) {
                try {
                    new \MessageFormatter($locale, $message);
                    $this->addToAssertionCount(1);
                } catch (\IntlException) {
                    self::fail(\sprintf('"%s" in %s isn\'t valid ICU MessageFormat.', $id, $locale));
                }
            }
        }
    }

    public function testEveryMessageTheCodeShowsIsInACatalogue(): void
    {
        $known = [];

        foreach (self::englishCatalogues() as [$file]) {
            $known += self::messages($file);
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../packages', \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);

            if ('php' !== $file->getExtension() || !str_contains($file->getPathname(), '/src/')) {
                continue;
            }

            // Problems, and the passkey refusals' messages.
            preg_match_all('/(?:Problem\(|=> )\'(yorvoid\.(?:flow|passkey)\.[a-z_.]*[a-z])\'/', (string) file_get_contents($file->getPathname()), $matches);

            foreach ($matches[1] as $id) {
                self::assertArrayHasKey($id, $known, \sprintf('%s shows "%s", which no catalogue has.', $file->getFilename(), $id));
            }
        }
    }

    public function testThereAreCatalogues(): void
    {
        self::assertNotSame([], iterator_to_array(self::englishCatalogues()));
    }

    /**
     * @return array<string, string>
     */
    private static function messages(string $file): array
    {
        self::assertFileExists($file);
        $messages = [];

        foreach ((array) require $file as $id => $message) {
            self::assertIsString($id);
            self::assertIsString($message);
            $messages[$id] = $message;
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    private static function parameters(string $message): array
    {
        preg_match_all('/\{\s*([a-z_][a-z0-9_]*)\s*[,}]/i', $message, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
