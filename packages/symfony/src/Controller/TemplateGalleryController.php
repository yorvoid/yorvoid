<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use YorvoID\Symfony\Templates\FlowPages;
use YorvoID\Symfony\Templates\Samples;

/**
 * The template gallery (UX-006): every flow page, with the application's templates, in every state and
 * language, with made-up data. Only in debug mode: import its routes for development only.
 *
 * @internal
 */
final readonly class TemplateGalleryController
{
    /** The languages YorvoID's pages are written in */
    public const array LOCALES = ['en', 'de'];

    public function __construct(
        private FlowPages $pages,
        private UrlGeneratorInterface $urls,
        private bool $debug,
    ) {
    }

    public function index(): Response
    {
        $this->guard();
        $items = '';

        foreach (Samples::all() as $sample) {
            $links = implode(' ', array_map(fn (string $locale): string => \sprintf('<a href="%s" hreflang="%s">%2$s</a>', htmlspecialchars($this->urls->generate('yorvoid_template_gallery_page', ['template' => $sample->template, 'state' => $sample->state, 'locale' => $locale])), $locale), self::LOCALES));
            $items .= \sprintf('<li>%s, %s: %s</li>', htmlspecialchars($sample->template), htmlspecialchars($sample->state), $links);
        }

        return new Response('<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>YorvoID\'s flow pages</title></head><body><main><h1>YorvoID\'s flow pages</h1><p>Every page with your templates, in every state, with made-up data. Their forms go nowhere.</p><ul>'.$items.'</ul></main></body></html>');
    }

    public function page(Request $request, string $template, string $state, string $locale): Response
    {
        $this->guard();
        $sample = Samples::find($template, $state);

        if (null === $sample || !\in_array($locale, self::LOCALES, true)) {
            throw new NotFoundHttpException('There\'s no such page in the gallery.');
        }

        $request->setLocale($locale);

        return new Response($this->pages->sample($sample, $locale));
    }

    private function guard(): void
    {
        if (!$this->debug) {
            throw new NotFoundHttpException('The template gallery is for development only.');
        }
    }
}
