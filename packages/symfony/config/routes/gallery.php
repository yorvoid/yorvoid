<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use YorvoID\Symfony\Controller\TemplateGalleryController;

/*
 * The template gallery: every flow page with your templates, in every state and language. For
 * development only:
 *
 *   # config/routes/yorvoid.yaml
 *   when@dev:
 *       yorvoid_template_gallery:
 *           resource: '@YorvoIDBundle/config/routes/gallery.php'
 *           prefix: /_yorvoid/templates
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('yorvoid_template_gallery', '/')->controller([TemplateGalleryController::class, 'index'])->methods(['GET']);
    $routes->add('yorvoid_template_gallery_page', '/{template}/{state}/{locale}')->controller([TemplateGalleryController::class, 'page'])->methods(['GET'])
        ->requirements(['template' => '[a-z_]+', 'state' => '[a-z_]+', 'locale' => '[a-z]{2}']);
};
