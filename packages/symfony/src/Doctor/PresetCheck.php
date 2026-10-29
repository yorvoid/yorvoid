<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Doctor;

use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Symfony\Factories;
use YorvoID\Symfony\Limits;
use YorvoID\Symfony\Presets;

/**
 * Where the application's settings are weaker than its preset's (PLT-090). Stronger ones are fine: a
 * preset is a starting point, not a ceiling.
 *
 * @internal
 */
final readonly class PresetCheck implements Check
{
    /**
     * The settings it compares. User verification and session lifetimes are the assurance check's: it
     * holds them to the level the session preset targets.
     */
    public const array SETTINGS = ['sessions.preset', 'sessions.recognise_devices', 'step_up.window', 'step_up.cool_down', 'limits.per_account_and_address', 'limits.per_account', 'limits.per_address'];

    /**
     * @param array<string, mixed> $settings The effective settings, as dotted names under "yorvoid:"
     */
    public function __construct(
        private string $preset,
        private array $settings,
    ) {
    }

    public function findings(): iterable
    {
        $preset = Presets::ALL[$this->preset];

        try {
            [$absolute, $idle] = Factories::SESSION_PRESETS[$this->string('sessions.preset')];
            [$presetAbsolute, $presetIdle] = Factories::SESSION_PRESETS[$preset['sessions.preset']];

            if (Duration::parse($absolute)->isLongerThan(Duration::parse($presetAbsolute)) || Duration::parse($idle)->isLongerThan(Duration::parse($presetIdle))) {
                yield $this->weaker('sessions.preset', \sprintf('"%s"', $this->string('sessions.preset')), \sprintf('"%s"', $preset['sessions.preset']));
            }

            // Every preset recognises devices: not recognising them is weaker.
            if (true !== $this->settings['sessions.recognise_devices']) {
                yield $this->weaker('sessions.recognise_devices', 'false', 'true');
            }

            $window = $this->duration('step_up.window');

            if ($window->isLongerThan(Duration::parse($preset['step_up.window']))) {
                yield $this->weaker('step_up.window', (string) $window, $preset['step_up.window']);
            }

            if (null !== $preset['step_up.cool_down']) {
                $coolDown = $this->settings['step_up.cool_down'];
                $presetCoolDown = Duration::parse($preset['step_up.cool_down']);

                if (null === $coolDown) {
                    yield $this->weaker('step_up.cool_down', 'none', (string) $presetCoolDown);
                } elseif ($presetCoolDown->isLongerThan($this->duration('step_up.cool_down'))) {
                    yield $this->weaker('step_up.cool_down', (string) $this->duration('step_up.cool_down'), (string) $presetCoolDown);
                }
            }

            foreach (['limits.per_account_and_address', 'limits.per_account', 'limits.per_address'] as $name) {
                $limit = Limits::parse($this->string($name));
                $presetLimit = Limits::parse($preset[$name]);

                // More attempts per second than the preset's.
                if ($limit->attempts * $presetLimit->per->seconds > $presetLimit->attempts * $limit->per->seconds) {
                    yield $this->weaker($name, $this->string($name), $preset[$name]);
                }
            }
        } catch (IntegratorError) {
            // The configuration check reports settings YorvoID can't read.
            return;
        }
    }

    private function weaker(string $name, string $value, string $presets): Finding
    {
        return Finding::warning(\sprintf('%s is %s, weaker than the "%s" preset\'s %s.', $name, $value, $this->preset, $presets), \sprintf('Use the preset\'s, by leaving %s out, unless you chose yours knowingly.', $name), 'concepts/symfony.md#presets');
    }

    private function duration(string $name): Duration
    {
        return Duration::parse($this->string($name));
    }

    private function string(string $name): string
    {
        $value = $this->settings[$name];
        \assert(\is_string($value));

        return $value;
    }
}
