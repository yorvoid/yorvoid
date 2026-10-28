<?php

declare(strict_types=1);

namespace YorvoID\Tests\Translation;

use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Translation\Translator;

final class TranslatorTest extends TestCase
{
    public function testTranslatesIcuMessagesWithTheirParameters(): void
    {
        $translator = new Translator([__DIR__.'/catalogues']);

        self::assertSame('Hello, Ana!', $translator->trans('greeting', ['name' => 'Ana'], 'test'));
        self::assertSame('Hello, Ana!', $translator->trans('greeting', ['{name}' => 'Ana'], 'test'), 'Symfony\'s way of naming parameters works too.');
        self::assertSame('1 code left', $translator->trans('codes', ['count' => 1], 'test'));
        self::assertSame('3 Codes übrig', $translator->trans('codes', ['count' => 3], 'test', 'de'));
        self::assertSame('en', $translator->getLocale());
    }

    public function testFallsBackToLessSpecificLocalesThenTheFallback(): void
    {
        $translator = new Translator([__DIR__.'/catalogues'], 'de_AT');

        self::assertSame('Servus, Ana!', $translator->trans('greeting', ['name' => 'Ana'], 'test'));
        self::assertSame('2 Codes übrig', $translator->trans('codes', ['count' => 2], 'test'));
        self::assertSame('Only in English', $translator->trans('only_english', [], 'test'));
        self::assertSame('Hallo, Ana!', $translator->trans('greeting', ['name' => 'Ana'], 'test', 'de_CH'));
        self::assertSame('Hola', new Translator([__DIR__.'/catalogues'], 'es', 'es')->trans('Hola', [], 'test'), 'A message missing everywhere is its ID.');
        self::assertSame('greeting', $translator->trans('greeting', [], 'unknown'), 'A domain without catalogues has no messages.');
        self::assertSame('greeting', new Translator([__DIR__.'/catalogues'])->trans('greeting'), 'The default domain is "messages", as Symfony\'s.');
    }

    public function testLaterDirectoriesOverrideEarlierOnes(): void
    {
        $directory = sys_get_temp_dir().'/yorvoid-catalogues-'.bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory.'/test+intl-icu.en.php', "<?php return ['greeting' => 'Hi, {name}.'];");

        try {
            $translator = new Translator([__DIR__.'/catalogues', $directory]);
            self::assertSame('Hi, Ana.', $translator->trans('greeting', ['name' => 'Ana'], 'test'));
            self::assertSame('1 code left', $translator->trans('codes', ['count' => 1], 'test'));
        } finally {
            unlink($directory.'/test+intl-icu.en.php');
            rmdir($directory);
        }
    }

    public function testFormatsObjectsThatAreText(): void
    {
        $translator = new Translator([__DIR__.'/catalogues']);
        $name = new class implements \Stringable {
            public function __toString(): string
            {
                return 'Ana';
            }
        };

        self::assertSame('Hello, Ana!', $translator->trans('greeting', ['name' => $name], 'test'));
        self::assertSame('Hello, Ana!', $translator->trans('positional', ['Ana'], 'test'), 'ICU\'s numbered arguments work too.');
        self::assertSame('In 2026', $translator->trans('year', ['at' => new \DateTimeImmutable('2026-06-15 12:00:00 UTC')], 'test'), 'A date was turned into text before ICU could format it.');
    }

    public function testRefusesAnInvalidMessage(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('The message "broken" in en isn\'t valid ICU MessageFormat.');

        new Translator([__DIR__.'/catalogues'])->trans('broken', ['name' => 'Ana'], 'test');
    }

    public function testRefusesACatalogueThatIsntMessages(): void
    {
        foreach (['invalid', 'scalar'] as $domain) {
            try {
                new Translator([__DIR__.'/catalogues'])->trans('fine', [], $domain);
                self::fail(\sprintf('The catalogue "%s" was accepted.', $domain));
            } catch (IntegratorError $exception) {
                self::assertStringContainsString($domain.'+intl-icu.en.php isn\'t a list of message IDs and messages.', $exception->getMessage());
            }
        }
    }
}
