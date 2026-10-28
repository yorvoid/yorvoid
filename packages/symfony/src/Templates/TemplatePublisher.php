<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Templates;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\StrictUnifiedDiffOutputBuilder;
use YorvoID\Core\Exception\IntegratorError;

/**
 * Copies YorvoID's flow templates into the application to change them (UX-006), and keeps the
 * version each was copied from, so that after an upgrade it can tell who changed what: YorvoID, the
 * application, or both.
 *
 * Copies go where Symfony looks for them first, templates/bundles/YorvoIDBundle/flow/; the versions
 * they came from, to templates/bundles/YorvoIDBundle/.upstream/flow/. Commit both.
 *
 * @internal
 */
final readonly class TemplatePublisher
{
    public const string OVERRIDES = 'templates/bundles/YorvoIDBundle/flow';

    public const string UPSTREAM = 'templates/bundles/YorvoIDBundle/.upstream/flow';

    private string $source;

    /**
     * @param string|null $source YorvoID's templates; the bridge's own if not given
     */
    public function __construct(
        private string $projectDir,
        ?string $source = null,
    ) {
        $this->source = $source ?? \dirname(__DIR__, 2).'/templates/flow';
    }

    /**
     * @return list<string> YorvoID's templates, e.g. "passkey"
     */
    public function templates(): array
    {
        // In order: glob() sorts.
        $files = glob($this->source.'/*.html.twig');
        $files = false === $files ? [] : $files;

        return array_map(static fn (string $file): string => basename($file, '.html.twig'), $files);
    }

    /**
     * @throws IntegratorError If YorvoID has no such template, or the application has its own already
     */
    public function publish(string $template, bool $overwrite = false): void
    {
        $upstream = $this->read($this->sourceFile($template)) ?? throw new IntegratorError(\sprintf('YorvoID has no flow template "%s".', $template), \sprintf('Publish one of: %s.', implode(', ', $this->templates())), 'concepts/symfony.md#changing-the-templates');

        if (!$overwrite && is_file($this->override($template))) {
            throw new IntegratorError(\sprintf('The application has its own "%s" already, in %s.', $template, self::OVERRIDES), 'Keep it, or publish with --force to replace it with YorvoID\'s.', 'concepts/symfony.md#changing-the-templates');
        }

        $this->write($this->override($template), $upstream);
        $this->write($this->base($template), $upstream);
    }

    public function status(string $template): TemplateStatus
    {
        $base = $this->read($this->base($template));
        $upstream = $this->read($this->sourceFile($template));

        return match (true) {
            null === $base => TemplateStatus::NotPublished,
            null === $upstream => TemplateStatus::Removed,
            $upstream === $base => TemplateStatus::UpToDate,
            $this->read($this->override($template)) === $base => TemplateStatus::Upgradable,
            default => TemplateStatus::Diverged,
        };
    }

    /**
     * @return list<string> The templates the application published
     */
    public function published(): array
    {
        $files = glob($this->projectDir.'/'.self::UPSTREAM.'/*.html.twig');
        $files = false === $files ? [] : $files;

        return array_map(static fn (string $file): string => basename($file, '.html.twig'), $files);
    }

    /**
     * What YorvoID changed since the template was published, as a unified diff.
     */
    public function changes(string $template): string
    {
        return new Differ(new StrictUnifiedDiffOutputBuilder(['fromFile' => 'published', 'toFile' => 'YorvoID\'s now']))->diff($this->read($this->base($template)) ?? '', $this->read($this->sourceFile($template)) ?? '');
    }

    /**
     * Takes YorvoID's current version: the application's copy too, if it hadn't changed it; otherwise
     * only as the version it's now based on, once YorvoID's changes are merged into it.
     */
    public function accept(string $template): void
    {
        $status = $this->status($template);
        $upstream = $this->read($this->sourceFile($template));

        if (TemplateStatus::Removed === $status) {
            unlink($this->base($template));
        } elseif (null !== $upstream && TemplateStatus::NotPublished !== $status) {
            if (TemplateStatus::Upgradable === $status) {
                $this->write($this->override($template), $upstream);
            }

            $this->write($this->base($template), $upstream);
        }
    }

    private function sourceFile(string $template): string
    {
        return $this->source.'/'.basename($template).'.html.twig';
    }

    private function override(string $template): string
    {
        return $this->projectDir.'/'.self::OVERRIDES.'/'.basename($template).'.html.twig';
    }

    private function base(string $template): string
    {
        return $this->projectDir.'/'.self::UPSTREAM.'/'.basename($template).'.html.twig';
    }

    private function read(string $file): ?string
    {
        $contents = is_file($file) ? file_get_contents($file) : false;

        return false === $contents ? null : $contents;
    }

    private function write(string $file, string $contents): void
    {
        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), recursive: true);
        }

        file_put_contents($file, $contents);
    }
}
