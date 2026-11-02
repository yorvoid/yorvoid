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

        return new Response($this->pages->gallery(array_map(fn ($sample): array => [
            'template' => $sample->template,
            'state' => $sample->state,
            'links' => array_combine(self::LOCALES, array_map(fn (string $locale): string => $this->urls->generate('yorvoid_template_gallery_page', ['template' => $sample->template, 'state' => $sample->state, 'locale' => $locale]), self::LOCALES)),
        ], Samples::all())));
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
