<?php

declare(strict_types=1);

namespace YorvoID\IntentVerifier;

/**
 * Who the verifier is, and which passkeys' signatures it can check.
 *
 * @api
 */
final readonly class VerifierConfig
{
    /**
     * @param string       $audience       This verifier's name in intents, e.g. "quire-server:host-1": a signature for
     *                                     another verifier doesn't work here
     * @param string       $relyingPartyId The passkeys' relying party, e.g. "console.example.com"
     * @param list<string> $origins        Where the signing pages are, e.g. ["https://console.example.com"]
     * @param positive-int $intentLifetime How long, in seconds, an intent can be signed after it's prepared
     */
    public function __construct(
        public string $audience,
        public string $relyingPartyId,
        public array $origins,
        public int $intentLifetime = 300,
    ) {
        if ('' === trim($audience) || [] === $origins || $intentLifetime < 10 || $intentLifetime > 3600) {
            throw new ConfigurationError('The verifier needs an audience, at least one origin, and an intent lifetime between 10 seconds and an hour.');
        }

        foreach ($origins as $origin) {
            $scheme = parse_url($origin, \PHP_URL_SCHEME);
            $host = parse_url($origin, \PHP_URL_HOST);

            if (!\is_string($host) || !('https' === $scheme || ('http' === $scheme && 'localhost' === $host)) || !($host === $relyingPartyId || str_ends_with($host, '.'.$relyingPartyId))) {
                throw new ConfigurationError(\sprintf('The origin "%s" isn\'t an https:// origin at or under %s.', $origin, $relyingPartyId));
            }
        }
    }
}
