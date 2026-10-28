<?php

declare(strict_types=1);

namespace YorvoID\Mail;

use YorvoID\Mail\Block\Action;
use YorvoID\Mail\Block\Block;
use YorvoID\Mail\Block\Facts;
use YorvoID\Mail\Block\Footnote;
use YorvoID\Mail\Block\Paragraph;

/**
 * Plain text and HTML of an email, from its blocks (UX-015). The HTML is accessible: a language and
 * direction, a heading-free single column readable at 320 px, real text rather than images, a facts
 * table with row headers, links that say where they go, and contrast that holds in dark mode. Everything
 * from the email is escaped.
 *
 * @api
 */
final class Renderer
{
    /** Languages written right to left */
    private const array RIGHT_TO_LEFT = ['ar', 'dv', 'fa', 'he', 'ku', 'ps', 'ur', 'yi'];

    public static function text(Email $email): string
    {
        return mb_scrub(\sprintf("%s\n", implode("\n\n", array_map(self::textOf(...), $email->body))), 'UTF-8');
    }

    public static function html(Email $email): string
    {
        $language = strtolower(explode('_', str_replace('-', '_', $email->locale))[0]);

        return \sprintf(
            <<<'HTML'
                <!DOCTYPE html>
                <html lang="%s" dir="%s">
                <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light dark"><title>%s</title></head>
                <body style="margin:0;padding:24px 16px;background:#ffffff;color:#1a1a1a;font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;font-size:16px;line-height:1.5;">
                <div role="article" aria-roledescription="email" aria-label="%3$s" style="max-width:560px;margin:0 auto;">
                %s</div>
                </body>
                </html>

                HTML,
            self::escape(str_replace('_', '-', $email->locale)),
            \in_array($language, self::RIGHT_TO_LEFT, true) ? 'rtl' : 'ltr',
            self::escape($email->subject),
            implode('', array_map(self::htmlOf(...), $email->body)),
        );
    }

    private static function textOf(Block $block): string
    {
        return match (true) {
            $block instanceof Paragraph => $block->text,
            $block instanceof Facts => implode("\n", array_map(static fn (string $label, string $value): string => \sprintf('%s: %s', $label, $value), array_keys($block->facts), $block->facts)),
            $block instanceof Action => \sprintf("%s:\n%s", $block->label, $block->url),
            $block instanceof Footnote => \sprintf("-- \n%s", $block->text),
            default => throw new \LogicException(\sprintf('Unknown block %s.', $block::class)),
        };
    }

    private static function htmlOf(Block $block): string
    {
        return match (true) {
            $block instanceof Paragraph => \sprintf("<p style=\"margin:0 0 16px;\">%s</p>\n", self::escape($block->text)),
            $block instanceof Facts => \sprintf("<table style=\"margin:0 0 16px;border-collapse:collapse;\">\n%s</table>\n", implode('', array_map(
                static fn (string $label, string $value): string => \sprintf("<tr><th scope=\"row\" style=\"padding:4px 16px 4px 0;text-align:start;font-weight:600;vertical-align:top;\">%s</th><td style=\"padding:4px 0;\">%s</td></tr>\n", self::escape($label), self::escape($value)),
                array_keys($block->facts),
                $block->facts,
            ))),
            $block instanceof Action => \sprintf("<p style=\"margin:24px 0;\"><a href=\"%s\" style=\"display:inline-block;padding:12px 20px;border-radius:6px;background:#1a1a1a;color:#ffffff;font-weight:600;text-decoration:none;\">%s</a></p>\n<p style=\"margin:0 0 16px;font-size:14px;color:#4a4a4a;word-break:break-all;\">%1\$s</p>\n", self::escape($block->url), self::escape($block->label)),
            $block instanceof Footnote => \sprintf("<hr style=\"margin:24px 0 16px;border:0;border-top:1px solid #d0d0d0;\">\n<p style=\"margin:0;font-size:14px;color:#4a4a4a;\">%s</p>\n", self::escape($block->text)),
            default => throw new \LogicException(\sprintf('Unknown block %s.', $block::class)),
        };
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }
}
