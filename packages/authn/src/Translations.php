<?php

declare(strict_types=1);

namespace YorvoID\Authn;

/**
 * Where yorvoid/authn's messages are: the "yorvoid" domain, in ICU MessageFormat, in English and
 * German (PLT-050). Give the directory to yorvoid/translation's Translator, or to Symfony's.
 *
 * @api
 */
final class Translations
{
    public const string DIRECTORY = __DIR__.'/../translations';

    public const string DOMAIN = 'yorvoid';
}
