<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * Who the verifier is, where its signing pages are, and where the console is.
 *
 * Signing has its own relying party and origin, served by the privileged side, e.g.
 * https://sign.example.com, and its own passkeys: a console that could ask for signatures with them,
 * at its own address, could get anything signed (T-INT-3). So the console's origins must be neither the
 * signing relying party nor under it.
 *
 * @api
 */
final readonly class VerifierConfig
{
    /**
     * @param string       $audience       This verifier's name in intents, e.g. "quire-server:host-1": a signature for
     *                                     another verifier doesn't work here
     * @param string       $relyingPartyId The signing passkeys' relying party, e.g. "sign.example.com"
     * @param list<string> $origins        Where the signing pages are, e.g. ["https://sign.example.com"]
     * @param list<string> $consoleOrigins Where the console is, e.g. ["https://console.example.com"], which mustn't be at
     *                                     or under the relying party
     * @param positive-int $intentLifetime How long, in seconds, an intent can be signed after it's prepared
     * @param int          $keyChangeDelay How long, in seconds, a change to the keys waits before it takes effect, while
     *                                     the owners can cancel it: a day by default, at most 30
     */
    public function __construct(
        public string $audience,
        public string $relyingPartyId,
        public array $origins,
        public array $consoleOrigins,
        public int $intentLifetime = 300,
        public int $keyChangeDelay = 86400,
    ) {
        if ('' === trim($audience) || [] === $origins || [] === $consoleOrigins || $intentLifetime < 10 || $intentLifetime > 3600) {
            throw new ConfigurationError('The verifier needs an audience, at least one signing origin and one console origin, and an intent lifetime between 10 seconds and an hour.');
        }

        if ($keyChangeDelay < 0 || $keyChangeDelay > 30 * 86400) {
            throw new ConfigurationError('A key change waits between no time and 30 days.');
        }

        foreach ($origins as $origin) {
            $host = self::host($origin);

            if (null === $host || !self::isAtOrUnder($host, $relyingPartyId)) {
                throw new ConfigurationError(\sprintf('The origin "%s" isn\'t an https:// origin at or under %s.', $origin, $relyingPartyId));
            }
        }

        foreach ($consoleOrigins as $origin) {
            $host = self::host($origin);

            if (null === $host) {
                throw new ConfigurationError(\sprintf('The console origin "%s" isn\'t an https:// origin.', $origin));
            }

            if (self::isAtOrUnder($host, $relyingPartyId)) {
                throw new ConfigurationError(\sprintf('The console at "%s" could ask for signatures for %s itself: give signing a relying party of its own, e.g. "sign.%s", that the console isn\'t at or under.', $origin, $relyingPartyId, $host));
            }
        }
    }

    /**
     * The origin's host, if it's an https:// origin, or http:// on localhost while developing.
     */
    private static function host(string $origin): ?string
    {
        $scheme = parse_url($origin, \PHP_URL_SCHEME);
        $host = parse_url($origin, \PHP_URL_HOST);

        if (!\is_string($host) || !('https' === $scheme || ('http' === $scheme && self::isAtOrUnder($host, 'localhost')))) {
            return null;
        }

        return $host;
    }

    private static function isAtOrUnder(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.'.$domain);
    }
}
