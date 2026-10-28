<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;

/**
 * Where passkeys work and how strict they are.
 *
 * @api
 */
final readonly class PasskeyPolicy
{
    /** @var non-empty-list<string> */
    public array $origins;

    public Duration $timeout;

    /** @var positive-int The timeout, as WebAuthn's options give it */
    public int $timeoutMilliseconds;

    /**
     * @param string        $relyingPartyId The domain passkeys belong to, e.g. "example.com": they then work on it
     *                                      and its subdomains, and can't be used anywhere else. Browsers show it
     *                                      when a passkey is created
     * @param list<string>  $origins        Where the pages that use them are, e.g. ["https://example.com"]
     * @param Duration|null $timeout        How long a ceremony may take; 5 minutes if not given
     */
    public function __construct(
        public string $relyingPartyId,
        array $origins,
        public UserVerification $userVerification = UserVerification::Required,
        public ClonePolicy $clonePolicy = ClonePolicy::RefuseSignIn,
        ?Duration $timeout = null,
    ) {
        if (1 !== preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D', $relyingPartyId)) {
            throw new IntegratorError(\sprintf('"%s" isn\'t a domain to use as the relying party ID.', $relyingPartyId), 'Use the site\'s domain in lowercase, without a scheme, port or path, e.g. "example.com".', 'concepts/passkeys.md#configuration');
        }

        if ([] === $origins) {
            throw new IntegratorError('No origins are allowed to use passkeys.', 'List the origins of the pages that use them, e.g. ["https://example.com"].', 'concepts/passkeys.md#configuration');
        }

        foreach ($origins as $origin) {
            $parts = parse_url($origin);
            $host = \is_array($parts) ? ($parts['host'] ?? '') : '';
            $secure = 'https' === ($parts['scheme'] ?? null) || ('http' === ($parts['scheme'] ?? null) && 'localhost' === $host);

            if (!\is_array($parts) || !$secure || isset($parts['path']) || isset($parts['query']) || isset($parts['user']) || ($host !== $relyingPartyId && !str_ends_with($host, '.'.$relyingPartyId))) {
                throw new IntegratorError(\sprintf('"%s" can\'t use passkeys of "%s".', $origin, $relyingPartyId), 'Origins are "https://" and a host at or under the relying party ID, with an optional port and nothing else, e.g. "https://example.com" or "https://app.example.com:8443"; "http://localhost" only for development.', 'concepts/passkeys.md#configuration');
            }
        }

        $this->origins = $origins;
        $this->timeout = $timeout ?? Duration::minutes(5);
        $milliseconds = $this->timeout->seconds * 1000;
        \assert($milliseconds > 0);
        $this->timeoutMilliseconds = $milliseconds;
    }
}
