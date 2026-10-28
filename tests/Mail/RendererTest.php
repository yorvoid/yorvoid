<?php

declare(strict_types=1);

namespace YorvoID\Tests\Mail;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Mail\Block\Action;
use YorvoID\Mail\Block\Block;
use YorvoID\Mail\Block\Facts;
use YorvoID\Mail\Block\Footnote;
use YorvoID\Mail\Block\Paragraph;
use YorvoID\Mail\Email;
use YorvoID\Mail\InMemoryMailer;
use YorvoID\Mail\Renderer;

/**
 * Emails as plain text and accessible HTML (UX-015).
 */
final class RendererTest extends TestCase
{
    public function testRendersPlainText(): void
    {
        self::assertSame(<<<'TEXT'
            Someone signed in.

            When: 28 Oct 2026, 18:00 UTC
            Device: Firefox on Linux

            This wasn't me:
            https://example.com/not-me?token=abc

            -- 
            You get these for your security.

            TEXT, Renderer::text(self::email()));
    }

    /**
     * The rendering, whole: tests/Mail/expected/ holds it as reviewed. Change those files only for a
     * deliberate change to how emails look.
     */
    public function testRendersTheReferenceEmailExactly(): void
    {
        $email = new Email('ana@example.com', 'Ana\'s new sign-in', [
            new Paragraph("It's you? Bad byte: \xC3\x28 end."),
            new Facts(['When' => '28 Oct 2026, 18:00 UTC', 'Device' => 'Firefox']),
            new Action('This wasn\'t me', 'https://example.com/not-me?token=a&b=c'),
            new Footnote('Small print.'),
        ]);

        self::assertStringEqualsFile(__DIR__.'/expected/email.html', Renderer::html($email));
        self::assertStringEqualsFile(__DIR__.'/expected/email.txt', Renderer::text($email));
    }

    public function testRefusesABlockItDoesntKnow(): void
    {
        $email = new Email('ana@example.com', 'Hi', [new class implements Block {}]);

        foreach ([static fn () => Renderer::text($email), static fn () => Renderer::html($email)] as $render) {
            try {
                $render();
                self::fail('An unknown block was rendered.');
            } catch (\LogicException $exception) {
                self::assertStringStartsWith('Unknown block ', $exception->getMessage());
            }
        }
    }

    public function testRendersAccessibleHtmlWithEverythingEscaped(): void
    {
        $html = Renderer::html(new Email('ana@example.com', 'New sign-in <b>', [
            new Paragraph('Tom & <script>alert(1)</script>'),
            new Facts(['Device "x"' => '<img src=x onerror=alert(1)>']),
            new Action('Go <now>', 'https://example.com/a?b=1&c="2"'),
            new Footnote('Small & print'),
        ], 'de'));

        self::assertStringStartsWith("<!DOCTYPE html>\n<html lang=\"de\" dir=\"ltr\">", $html);
        self::assertStringContainsString('<title>New sign-in &lt;b&gt;</title>', $html);
        self::assertStringContainsString('aria-label="New sign-in &lt;b&gt;"', $html);
        self::assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
        self::assertStringContainsString('>Tom &amp; &lt;script&gt;alert(1)&lt;/script&gt;</p>', $html);
        self::assertStringContainsString('<th scope="row" ', $html);
        self::assertStringContainsString('>Device &quot;x&quot;</th><td style="padding:4px 0;">&lt;img src=x onerror=alert(1)&gt;</td>', $html);
        self::assertStringContainsString('<a href="https://example.com/a?b=1&amp;c=&quot;2&quot;" ', $html);
        self::assertStringContainsString('>Go &lt;now&gt;</a>', $html);
        self::assertStringContainsString('>https://example.com/a?b=1&amp;c=&quot;2&quot;</p>', $html, 'The address is shown as text too, for clients that hide buttons.');
        self::assertStringContainsString('>Small &amp; print</p>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function locales(): iterable
    {
        yield 'English' => ['en', 'en', 'ltr'];
        yield 'Austrian German' => ['de_AT', 'de-AT', 'ltr'];
        yield 'Arabic' => ['ar', 'ar', 'rtl'];
        yield 'Hebrew, by country' => ['he-IL', 'he-IL', 'rtl'];
        yield 'Persian' => ['fa', 'fa', 'rtl'];
        yield 'Arabic, in capitals' => ['AR', 'AR', 'rtl'];

        foreach (['dv', 'ku', 'ps', 'ur', 'yi'] as $language) {
            yield $language => [$language, $language, 'rtl'];
        }
    }

    #[DataProvider('locales')]
    public function testSaysTheLanguageAndItsDirection(string $locale, string $lang, string $dir): void
    {
        self::assertStringContainsString(\sprintf('<html lang="%s" dir="%s">', $lang, $dir), Renderer::html(new Email('ana@example.com', 'Hi', [], $locale)));
    }

    public function testKeepsTheBodysOrder(): void
    {
        $email = self::email();

        self::assertSame([Paragraph::class, Facts::class, Action::class, Footnote::class], array_map(static fn (Block $block): string => $block::class, $email->body));
        self::assertSame('en', $email->locale);
        self::assertSame([0], array_keys(new Email('ana@example.com', 'Hi', ['a' => new Paragraph('x')])->body), 'The body is a list, whatever its keys were.');
    }

    public function testRefusesWhatIsntAnEmailAddress(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('"ana" isn\'t an email address.');

        new Email('ana', 'Hi', []);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badLinks(): iterable
    {
        yield 'script' => ['javascript:alert(1)'];
        yield 'relative' => ['/not-me?token=abc'];
        yield 'mail' => ['mailto:ana@example.com'];
        yield 'not a URL' => ['https://'];
    }

    #[DataProvider('badLinks')]
    public function testAnActionLinksToAWebPage(string $url): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage(\sprintf('"%s" isn\'t a web address for an email\'s action.', $url));

        new Action('Go', $url);
    }

    public function testTheInMemoryMailerKeepsWhatItsGiven(): void
    {
        $mailer = new InMemoryMailer();
        $mailer->send(self::email());

        self::assertEquals([self::email()], $mailer->sent);
        self::assertSame('http://localhost:8000/a', new Action('Local', 'http://localhost:8000/a')->url);
    }

    private static function email(): Email
    {
        return new Email('ana@example.com', 'New sign-in', [
            new Paragraph('Someone signed in.'),
            new Facts(['When' => '28 Oct 2026, 18:00 UTC', 'Device' => 'Firefox on Linux']),
            new Action('This wasn\'t me', 'https://example.com/not-me?token=abc'),
            new Footnote('You get these for your security.'),
        ]);
    }
}
