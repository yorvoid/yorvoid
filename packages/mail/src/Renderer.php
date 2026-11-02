<?php

declare(strict_types=1);

namespace YorvoID\Mail;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
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
 * The HTML is a Twig template, "@yorvoid_mail/email.html.twig" (TEMPLATES): to give emails your look,
 * give a Twig environment whose loader has your template first, then these.
 *
 * @api
 */
final readonly class Renderer
{
    /** The emails' templates, for a Twig loader's "yorvoid_mail" namespace */
    public const string TEMPLATES = __DIR__.'/../templates';

    /** Languages written right to left */
    private const array RIGHT_TO_LEFT = ['ar', 'dv', 'fa', 'he', 'ku', 'ps', 'ur', 'yi'];

    private Environment $twig;

    /**
     * @param Environment|null $twig Renders the HTML: by default this package's template, escaped, with nothing cached
     */
    public function __construct(?Environment $twig = null)
    {
        $this->twig = $twig ?? self::twig();
    }

    public function text(Email $email): string
    {
        return mb_scrub(\sprintf("%s\n", implode("\n\n", array_map(self::textOf(...), $email->body))), 'UTF-8');
    }

    public function html(Email $email): string
    {
        $language = strtolower(explode('_', str_replace('-', '_', $email->locale))[0]);

        return $this->twig->render('@yorvoid_mail/email.html.twig', [
            'lang' => str_replace('_', '-', $email->locale),
            'dir' => \in_array($language, self::RIGHT_TO_LEFT, true) ? 'rtl' : 'ltr',
            'subject' => $email->subject,
            'blocks' => array_map(self::blockOf(...), $email->body),
        ]);
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

    /**
     * A block, as the template takes it.
     *
     * @return array<string, mixed>
     */
    private static function blockOf(Block $block): array
    {
        return match (true) {
            $block instanceof Paragraph => ['kind' => 'paragraph', 'text' => $block->text],
            $block instanceof Facts => ['kind' => 'facts', 'facts' => $block->facts],
            $block instanceof Action => ['kind' => 'action', 'label' => $block->label, 'url' => $block->url],
            $block instanceof Footnote => ['kind' => 'footnote', 'text' => $block->text],
            default => throw new \LogicException(\sprintf('Unknown block %s.', $block::class)),
        };
    }

    private static function twig(): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(self::TEMPLATES, 'yorvoid_mail');

        return new Environment($loader, ['autoescape' => 'html', 'strict_variables' => true, 'cache' => false]);
    }
}
