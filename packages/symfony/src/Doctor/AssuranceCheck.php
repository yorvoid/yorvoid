<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Symfony\Factories;

/**
 * Whether the configuration reaches the assurance level its session preset targets (NIST SP 800-63B-4,
 * docs/security/assurance-levels.md). Passkeys, which YorvoID always offers, are phishing-resistant
 * (ATN-062); what can fall short is user verification, session lifetimes, and synced passkeys at AAL3.
 *
 * @internal
 */
final readonly class AssuranceCheck implements Check
{
    /**
     * The level each preset targets, and that level's re-authentication and idle limits.
     */
    private const array LEVELS = ['aal2' => ['AAL2', '24h', '1h'], 'aal3' => ['AAL3', '12h', '15m'], 'console' => ['AAL2', '24h', '1h']];

    public function __construct(
        private string $preset,
        private ?string $absolute,
        private ?string $idle,
        private string $userVerification,
    ) {
    }

    public function findings(): iterable
    {
        [$level, $maxAbsolute, $maxIdle] = self::LEVELS[$this->preset];
        [$presetAbsolute, $presetIdle] = Factories::SESSION_PRESETS[$this->preset];

        if ('required' !== $this->userVerification) {
            yield Finding::warning(\sprintf('Passkeys don\'t require user verification: one used without it is a single factor, below the %s that sessions.preset "%s" targets.', $level, $this->preset), 'Set passkeys.user_verification to "required".', 'security/assurance-levels.md#the-rules-that-matter-most');
        }

        try {
            $absolute = Duration::parse($this->absolute ?? $presetAbsolute);
            $idle = Duration::parse($this->idle ?? $presetIdle);
        } catch (IntegratorError) {
            // The configuration check reports it.
            return;
        }

        if ($absolute->isLongerThan(Duration::parse($maxAbsolute))) {
            yield Finding::warning(\sprintf('Sessions last %s: %s asks to re-authenticate at least every %s.', $absolute, $level, $maxAbsolute), \sprintf('Set sessions.absolute to %s or less, or leave it out to use the "%s" preset\'s.', $maxAbsolute, $this->preset), 'concepts/sessions.md#how-long-sessions-last');
        }

        if ($idle->isLongerThan(Duration::parse($maxIdle))) {
            yield Finding::warning(\sprintf('Sessions end after %s idle: %s asks for at most %s.', $idle, $level, $maxIdle), \sprintf('Set sessions.idle to %s or less, or leave it out to use the "%s" preset\'s.', $maxIdle, $this->preset), 'concepts/sessions.md#how-long-sessions-last');
        }

        if ('AAL3' === $level) {
            yield Finding::warning('Sessions target AAL3, but YorvoID accepts synced passkeys, which AAL3 doesn\'t allow.', 'Until YorvoID can require device-bound passkeys, treat this configuration as AAL2, or check each passkey\'s backup eligibility yourself.', 'security/assurance-levels.md#configurations');
        }
    }
}
