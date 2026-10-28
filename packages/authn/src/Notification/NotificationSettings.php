<?php

declare(strict_types=1);

namespace YorvoID\Authn\Notification;

use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Principal\PrincipalId;
use YorvoID\Core\Realm;

/**
 * What security notifications need from the application: its name, the page that handles "this wasn't
 * me", and each principal's language.
 *
 * @api
 */
final readonly class NotificationSettings
{
    public Duration $notMeLifetime;

    /**
     * @param string                                    $applicationName What people call the application, e.g. "Quire"
     * @param string                                    $notMeUrl        The page that confirms "this wasn't me", with {token} where the token goes and,
     *                                                                   if it's about a sign-in method, {credential}: e.g.
     *                                                                   "https://example.com/security/not-me?token={token}&credential={credential}"
     * @param \Closure(Realm, PrincipalId): string|null $localeOf        Each principal's language, e.g. "de"; English if not given
     * @param Duration|null                             $notMeLifetime   How long a "this wasn't me" link works: 7 days if not given
     */
    public function __construct(
        public string $applicationName,
        public string $notMeUrl,
        public ?\Closure $localeOf = null,
        ?Duration $notMeLifetime = null,
    ) {
        if (!str_contains($notMeUrl, '{token}') || !\in_array(parse_url($notMeUrl, \PHP_URL_SCHEME), ['https', 'http'], true)) {
            throw new IntegratorError(\sprintf('"%s" isn\'t a web address with {token} in it.', $notMeUrl), 'Give the absolute address of the page that confirms "this wasn\'t me", with {token} where the token goes.', 'concepts/notifications.md#configuration');
        }

        $this->notMeLifetime = $notMeLifetime ?? Duration::days(7);
    }

    public function localeOf(Realm $realm, PrincipalId $principalId): string
    {
        return null === $this->localeOf ? 'en' : ($this->localeOf)($realm, $principalId);
    }
}
