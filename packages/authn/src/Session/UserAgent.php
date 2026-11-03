<?php

declare(strict_types=1);

namespace YorvoID\Authn\Session;

/**
 * Which browser and system a User-Agent names, from short lists: what an email can say about a device
 * without quoting the header, which whoever signs in writes as they like.
 *
 * @api
 */
final class UserAgent
{
    /** The browsers it knows, the more specific first: Edge and Opera also say "Chrome", Chrome also says "Safari" */
    private const array BROWSERS = [
        'Edg/' => 'Edge',
        'EdgA/' => 'Edge',
        'EdgiOS/' => 'Edge',
        'OPR/' => 'Opera',
        'SamsungBrowser/' => 'Samsung Internet',
        'Firefox' => 'Firefox',
        'FxiOS/' => 'Firefox',
        'CriOS/' => 'Chrome',
        'Chrome' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    /** The systems it knows, the more specific first: iPhones and Android say "like Mac OS X" and "Linux" */
    private const array SYSTEMS = [
        'iPhone' => 'iOS',
        'iPad' => 'iPadOS',
        'Android' => 'Android',
        'CrOS' => 'ChromeOS',
        'Windows' => 'Windows',
        'Macintosh' => 'macOS',
        'Mac OS X' => 'macOS',
        'Linux' => 'Linux',
    ];

    /**
     * @return array{string|null, string|null} The browser and the system, each null if it isn't one of the known ones
     */
    public static function describe(?string $userAgent): array
    {
        return [self::find($userAgent ?? '', self::BROWSERS), self::find($userAgent ?? '', self::SYSTEMS)];
    }

    /**
     * @param array<string, string> $names
     */
    private static function find(string $userAgent, array $names): ?string
    {
        foreach ($names as $marker => $name) {
            if (str_contains($userAgent, $marker)) {
                return $name;
            }
        }

        return null;
    }
}
