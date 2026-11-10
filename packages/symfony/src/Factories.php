<?php

declare(strict_types=1);

namespace YorvoID\Symfony;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use YorvoID\Authn\Attempt\AttemptLimits;
use YorvoID\Authn\Session\Session;
use YorvoID\Authn\Session\SessionPolicy;
use YorvoID\Core\Authorization\PolicyElement;
use YorvoID\Core\Crypto\CryptoMode;
use YorvoID\Core\Crypto\CryptoPolicy;
use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Core\Operation\Actor;
use YorvoID\Core\Principal\Principal;
use YorvoID\Flows\Flow;
use YorvoID\Notifications\NotificationSettings;
use YorvoID\Passkeys\ClonePolicy;
use YorvoID\Passkeys\Flow\PasskeyFlows;
use YorvoID\Passkeys\PasskeyPolicy;
use YorvoID\Passkeys\UserVerification;
use YorvoID\Policy\Ability;
use YorvoID\Policy\PolicyCatalogue;
use YorvoID\Policy\Rule\Rule;
use YorvoID\Policy\Rule\RuleElements;
use YorvoID\Symfony\Controller\FlowController;
use YorvoID\Symfony\Flow\FlowCustomizer;
use YorvoID\Symfony\Flow\FlowSource;
use YorvoID\Symfony\Key\KeyFiles;
use YorvoID\Symfony\Security\CurrentSession;

/**
 * YorvoID's typed configuration from the bundle's: the container calls these.
 *
 * @internal
 */
final class Factories
{
    /** The session presets (SES-003): absolute and idle limits */
    public const array SESSION_PRESETS = ['aal2' => ['24h', '1h'], 'aal3' => ['12h', '15m'], 'console' => ['8h', '1h']];

    public static function sessionPolicy(string $preset, ?string $absolute, ?string $idle): SessionPolicy
    {
        [$presetAbsolute, $presetIdle] = self::SESSION_PRESETS[$preset];

        return new SessionPolicy(Duration::parse($absolute ?? $presetAbsolute), Duration::parse($idle ?? $presetIdle));
    }

    /**
     * @param list<string> $origins
     */
    public static function passkeyPolicy(string $relyingPartyId, array $origins, string $userVerification, string $clonePolicy, string $timeout, ?CryptoPolicy $crypto = null): PasskeyPolicy
    {
        return new PasskeyPolicy($relyingPartyId, $origins, UserVerification::from($userVerification), ClonePolicy::from($clonePolicy), Duration::parse($timeout), $crypto);
    }

    /**
     * The crypto policy, refusing a FIPS mode in which the configured signing keys couldn't sign: without
     * them, the security log's checkpoints stop (AUD-003), and a FIPS deployment would run without.
     *
     * @throws IntegratorError When the FIPS mode can't be had, or its provider can't use the keys
     */
    public static function cryptoPolicy(string $mode, KeyFiles $keys): CryptoPolicy
    {
        $policy = CryptoPolicy::of($mode);

        if (CryptoMode::Fips === $policy->mode && !$policy->allowsEdDsa() && $keys->isConfigured()) {
            throw new IntegratorError(\sprintf('The FIPS crypto mode is configured with signing keys, but %s has no Ed25519, which they sign with: the security log\'s checkpoints would stop.', $policy->provider()->openssl), 'Use a FIPS provider with EdDSA (FIPS 186-5), or leave the FIPS mode until YorvoID\'s ES256 keys are released.', 'concepts/cryptography.md#fips-mode');
        }

        return $policy;
    }

    public static function attemptLimits(string $perAccountAndAddress, string $perAccount, string $perAddress): AttemptLimits
    {
        return new AttemptLimits(Limits::parse($perAccountAndAddress), Limits::parse($perAccount), Limits::parse($perAddress));
    }

    public static function duration(?string $duration): ?Duration
    {
        return null === $duration ? null : Duration::parse($duration);
    }

    /**
     * The principal of the request's own session, as its check loaded it, for that session as the actor.
     *
     * @param \Closure(): CurrentSession $current
     *
     * @return \Closure(Actor): ?Principal
     */
    public static function loadedPrincipal(\Closure $current): \Closure
    {
        return static function (Actor $actor) use ($current): ?Principal {
            if (!$actor instanceof Session) {
                return null;
            }

            $session = $current()->get();

            return null !== $session && $session->id->equals($actor->id) ? $current()->principal() : null;
        };
    }

    /**
     * Each rule as its two elements, a permit and a forbid.
     *
     * @param iterable<Rule> $rules
     *
     * @return list<PolicyElement>
     */
    public static function ruleElements(iterable $rules): array
    {
        $elements = [];

        foreach ($rules as $rule) {
            array_push($elements, ...RuleElements::of($rule));
        }

        return $elements;
    }

    /**
     * The abilities whose permits the decision log records whatever it's configured to.
     *
     * @return list<string>
     */
    public static function recordingPermits(PolicyCatalogue $catalogue): array
    {
        return array_values(array_map(static fn (Ability $ability): string => $ability->name, array_filter($catalogue->abilities(), static fn (Ability $ability): bool => $ability->recordsPermits)));
    }

    /**
     * @param iterable<PolicyElement> $elements
     * @param list<PolicyElement>     $rules
     *
     * @return list<PolicyElement>
     */
    public static function elements(iterable $elements, array $rules): array
    {
        $all = [];

        foreach ($elements as $element) {
            $all[] = $element;
        }

        return [...$all, ...$rules];
    }

    /**
     * Where an email verification link leads: the flow's page, as an absolute address.
     *
     * @return \Closure(string): string
     */
    public static function emailVerificationLink(UrlGeneratorInterface $urls): \Closure
    {
        return static fn (string $token): string => $urls->generate('yorvoid_flow_start', ['flow' => PasskeyFlows::VERIFY_EMAIL, 'token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    public static function notificationSettings(string $applicationName, string $notMeUrl, ?PrincipalLocales $locales): NotificationSettings
    {
        return new NotificationSettings($applicationName, $notMeUrl, null === $locales ? null : $locales->localeOf(...));
    }

    /**
     * The flows a request may start, and what it may give them: YorvoID's, and the application's.
     *
     * @param iterable<FlowSource> $sources
     *
     * @return array<string, array<string, string>>
     */
    public static function entrances(iterable $sources): array
    {
        $entrances = FlowController::ENTRANCES;

        foreach ($sources as $source) {
            foreach ($source->entrances() as $flow => $fields) {
                if (str_starts_with($flow, 'yorvoid.') || array_any($fields, static fn (string $key): bool => str_starts_with($key, 'yorvoid.') || str_starts_with($key, 'flows.'))) {
                    throw new IntegratorError(\sprintf('%s opens "%s" to requests, or gives a request a key of YorvoID\'s.', $source::class, $flow), 'Open only your own flows, and give requests keys of your own prefix, e.g. "acme.plan".', 'concepts/flows.md#your-own-flows');
                }

                $entrances[$flow] = $fields;
            }
        }

        return $entrances;
    }

    /**
     * YorvoID's journeys and the application's own, as the application's customizers changed them.
     *
     * @param iterable<FlowCustomizer> $customizers
     * @param iterable<FlowSource>     $sources
     *
     * @return list<Flow>
     */
    public static function flows(PasskeyFlows $journeys, iterable $customizers, iterable $sources = []): array
    {
        $flows = $journeys->all();

        foreach ($sources as $source) {
            foreach ($source->flows() as $flow) {
                if (str_starts_with($flow->name, 'yorvoid.')) {
                    throw new IntegratorError(\sprintf('%s adds the flow "%s": names starting with "yorvoid." are YorvoID\'s.', $source::class, $flow->name), 'Name your flows with your own prefix, e.g. "acme.signup"; change YorvoID\'s flows with a FlowCustomizer.', 'concepts/flows.md#your-own-flows');
                }

                $flows[] = $flow;
            }
        }

        foreach ($customizers as $customizer) {
            $flows = array_map($customizer->customize(...), $flows);
        }

        return $flows;
    }
}
