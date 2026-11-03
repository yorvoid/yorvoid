<?php

declare(strict_types=1);

namespace YorvoID\Symfony;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use YorvoID\Authn\Attempt\AttemptLimits;
use YorvoID\Authn\Notification\NotificationSettings;
use YorvoID\Authn\Session\SessionPolicy;
use YorvoID\Core\Duration;
use YorvoID\Flows\Flow;
use YorvoID\Passkeys\ClonePolicy;
use YorvoID\Passkeys\Flow\PasskeyFlows;
use YorvoID\Passkeys\PasskeyPolicy;
use YorvoID\Passkeys\UserVerification;
use YorvoID\Symfony\Flow\FlowCustomizer;

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
    public static function passkeyPolicy(string $relyingPartyId, array $origins, string $userVerification, string $clonePolicy, string $timeout): PasskeyPolicy
    {
        return new PasskeyPolicy($relyingPartyId, $origins, UserVerification::from($userVerification), ClonePolicy::from($clonePolicy), Duration::parse($timeout));
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
     * YorvoID's journeys, as the application's customizers changed them.
     *
     * @param iterable<FlowCustomizer> $customizers
     *
     * @return list<Flow>
     */
    public static function flows(PasskeyFlows $journeys, iterable $customizers): array
    {
        $flows = $journeys->all();

        foreach ($customizers as $customizer) {
            $flows = array_map($customizer->customize(...), $flows);
        }

        return $flows;
    }
}
