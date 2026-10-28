<?php

declare(strict_types=1);

namespace YorvoID\Symfony;

use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * Each principal's language, from the application's own profiles: YorvoID keeps none (D-05). Give its
 * service ID as yorvoid.notifications.locales.
 *
 * @api
 */
interface PrincipalLocales
{
    /**
     * @return string E.g. "de"
     */
    public function localeOf(Realm $realm, PrincipalId $principalId): string;
}
