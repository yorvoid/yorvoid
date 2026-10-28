<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

/**
 * Where yorvoid/passkeys' messages are: the "yorvoid" domain, in ICU MessageFormat, in English and
 * German (PLT-050), e.g. the summaries of the intent verifier's own operations.
 *
 * @api
 */
final class Translations
{
    public const string DIRECTORY = __DIR__.'/../translations';

    public const string DOMAIN = 'yorvoid';
}
