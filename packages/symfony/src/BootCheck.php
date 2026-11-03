<?php

declare(strict_types=1);

namespace YorvoID\Symfony;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Realm;
use YorvoID\Core\Storage\StorageLocation;

/**
 * Builds what the configuration describes once, when the container is compiled: a mistake then stops the
 * application from starting, with the option's name, rather than failing every request later (BND-07,
 * FUZZ-09). A value from an environment variable is checked when it's used.
 *
 * @internal
 */
final class BootCheck
{
    /** RFC 6265's cookie name: a token, nothing that ends the name or the header */
    private const string COOKIE_NAME = '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D';

    /**
     * @param array<string, mixed>  $settings      The options, flattened, presets applied
     * @param \Closure(mixed): bool $isPlaceholder Whether a value comes from an environment variable
     *
     * @throws InvalidConfigurationException
     */
    public static function check(array $settings, \Closure $isPlaceholder): void
    {
        $value = static fn (string $name): mixed => $settings[$name] ?? null;
        $known = static fn (string ...$names): bool => [] === array_filter($names, static fn (string $name): bool => $isPlaceholder($value($name)));

        foreach (['connection', 'realm', 'cookies.device', 'storage.prefix', 'passkeys.timeout', 'step_up.window', 'flows.script', 'intents.summaries_domain'] as $name) {
            if (!\is_string($value($name)) || ('' === $value($name) && 'storage.prefix' !== $name)) {
                throw new InvalidConfigurationException(\sprintf('yorvoid.%s must be text.', $name));
            }
        }

        $text = static function (string $name) use ($value): string {
            $text = $value($name);
            \assert(\is_string($text));

            return $text;
        };
        $optional = static fn (string $name): ?string => \is_string($value($name)) ? $value($name) : null;
        $build = static function (string $option, \Closure $build): void {
            try {
                $build();
            } catch (IntegratorError $error) {
                throw new InvalidConfigurationException(\sprintf('yorvoid.%s: %s %s', $option, $error->problem, $error->fix), previous: $error);
            }
        };

        if ($known('realm')) {
            $build('realm', static fn () => new Realm($text('realm')));
        }

        if ($known('storage.prefix', 'storage.schema')) {
            $build('storage', static fn () => new StorageLocation($text('storage.prefix'), $optional('storage.schema')));
        }

        if ($known('sessions.absolute', 'sessions.idle')) {
            $build('sessions', static fn () => Factories::sessionPolicy($text('sessions.preset'), $optional('sessions.absolute'), $optional('sessions.idle')));
        }

        $origins = $value('passkeys.origins');

        if (\is_array($origins) && $known('passkeys.relying_party_id', 'passkeys.timeout') && [] === array_filter($origins, $isPlaceholder)) {
            $build('passkeys', static fn () => Factories::passkeyPolicy($text('passkeys.relying_party_id'), array_values(array_filter($origins, \is_string(...))), $text('passkeys.user_verification'), $text('passkeys.clone_policy'), $text('passkeys.timeout')));
        }

        if ($known('step_up.window', 'step_up.cool_down')) {
            $build('step_up', static fn () => [Duration::parse($text('step_up.window')), Factories::duration($optional('step_up.cool_down'))]);
        }

        if ($known('cookies.device') && 1 !== preg_match(self::COOKIE_NAME, $text('cookies.device'))) {
            throw new InvalidConfigurationException(\sprintf('yorvoid.cookies.device: "%s" isn\'t a cookie name. Use letters, digits and "_" or "-", e.g. "__Host-yorvoid_device".', $text('cookies.device')));
        }

        if ($known('flows.script') && !self::isScript($text('flows.script'), \is_array($origins) ? $origins : [])) {
            throw new InvalidConfigurationException(\sprintf('yorvoid.flows.script: "%s" isn\'t the browser client on this site. Use a path on this site, e.g. "/bundles/yorvoid/browser/forms.js", or an https:// address on one of passkeys.origins.', $text('flows.script')));
        }

        if (true === $value('notifications.enabled') && $known('notifications.application_name', 'notifications.not_me_url', 'notifications.from')) {
            $build('notifications', static fn () => Factories::notificationSettings($text('notifications.application_name'), $text('notifications.not_me_url'), null));

            if (false === filter_var($text('notifications.from'), \FILTER_VALIDATE_EMAIL)) {
                throw new InvalidConfigurationException(\sprintf('yorvoid.notifications.from: "%s" isn\'t an email address.', $text('notifications.from')));
            }
        }
    }

    /**
     * The browser client comes from this site: a path, or an address on one of the passkeys' origins. A
     * script from elsewhere would run in the sign-in pages.
     *
     * @param array<mixed> $origins
     */
    private static function isScript(string $script, array $origins): bool
    {
        if (1 === preg_match('#^/(?![/\\\\])[^\\\\\x00-\x20\x7f]*$#D', $script)) {
            return true;
        }

        $parts = parse_url($script);

        return \is_array($parts) && 'https' === ($parts['scheme'] ?? null) && !isset($parts['user']) && \in_array('https://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : ''), $origins, true);
    }
}
