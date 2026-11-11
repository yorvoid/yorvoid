<?php

declare(strict_types=1);

namespace YorvoID\Symfony\Remedy;

use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use YorvoID\Core\Authorization\Remedy;
use YorvoID\Core\Authorization\StepUpRemedy;
use YorvoID\Core\Authorization\WaitReason;
use YorvoID\Core\Authorization\WaitRemedy;
use YorvoID\Core\Operation\Decision;
use YorvoID\Core\Operation\Verdict;
use YorvoID\Passkeys\Flow\PasskeyFlows;

/**
 * A refusal as a person is shown it: what it was, and what would satisfy it, each with what to do if
 * there's something to do now (M2-10). A Deny's reason and an Indeterminate's reasons stay in the log:
 * which rule refused would describe the policy to whoever asks.
 *
 * Waits come first, and while there's one, a step-up has no button: a proof made during the wait would
 * be stale when it ends. Whatever's done, the person tries again, and that's a new decision.
 *
 * @phpstan-type Prompt array{name: string, parameters: array<string, scalar|null>, message: string, action: array{url: string, label: string}|null}
 *
 * @internal
 */
final readonly class RemedyPrompts
{
    public function __construct(
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private ClockInterface $clock,
    ) {
    }

    /**
     * What would satisfy the refusal, in the documented shape (the JSON's "remedies").
     *
     * @param string|null $return Where the person goes back to once a step-up is done: a path on this site
     *
     * @return list<Prompt>
     */
    public function of(Decision $decision, ?string $return, string $locale): array
    {
        $remedies = $decision->remedies;
        usort($remedies, static fn (Remedy $a, Remedy $b): int => self::rank($a) <=> self::rank($b));
        $waiting = [] !== array_filter($remedies, static fn (Remedy $remedy): bool => $remedy instanceof WaitRemedy);

        return array_map(fn (Remedy $remedy): array => [
            'name' => $remedy->name(),
            'parameters' => $remedy->parameters(),
            'message' => $remedy instanceof StepUpRemedy && $waiting ? $this->trans('yorvoid.remedy.step_up.after_wait', [], $locale) : $this->message($remedy, $locale),
            'action' => $remedy instanceof StepUpRemedy && !$waiting ? [
                'url' => $this->urls->generate('yorvoid_flow_start', array_filter([
                    'flow' => PasskeyFlows::STEP_UP,
                    'operation' => $remedy->operation,
                    'multi_factor' => $remedy->multiFactor ? '1' : null,
                    'within' => (string) $remedy->within->seconds,
                    'return' => $return,
                ], static fn (?string $value): bool => null !== $value)),
                'label' => $this->trans('yorvoid.remedy.step_up.action', [], $locale),
            ] : null,
        ], $remedies);
    }

    /**
     * The page's title and what it says, by verdict: "yorvoid.ui.refused.{remedies,denied,indeterminate}".
     *
     * @return array{title: string, text: string|null}
     */
    public function summary(Decision $decision, string $locale): array
    {
        $kind = match (true) {
            self::unavailable($decision->verdict) => 'indeterminate',
            [] !== $decision->remedies => 'remedies',
            default => 'denied',
        };

        return [
            'title' => $this->trans('yorvoid.ui.refused.'.$kind.'.title', [], $locale),
            'text' => 'remedies' === $kind ? null : $this->trans('yorvoid.ui.refused.'.$kind.'.text', [], $locale),
        ];
    }

    /**
     * Whether the refusal is that it couldn't be decided, not that it's refused: Indeterminate, or a
     * Permit-offline the operation doesn't accept. A 503, so monitoring sees a failure.
     */
    public static function unavailable(Verdict $verdict): bool
    {
        return match ($verdict) {
            Verdict::Indeterminate, Verdict::PermitOffline => true,
            Verdict::Deny, Verdict::Permit => false,
        };
    }

    public function reference(Decision $decision, string $locale): ?string
    {
        return null === $decision->id ? null : $this->trans('yorvoid.ui.refused.reference', ['reference' => $decision->id], $locale);
    }

    private static function rank(Remedy $remedy): int
    {
        return match (true) {
            $remedy instanceof WaitRemedy => 0,
            $remedy instanceof StepUpRemedy => 1,
            default => 2,
        };
    }

    private function message(Remedy $remedy, string $locale): string
    {
        if ($remedy instanceof WaitRemedy) {
            $when = $this->wait($remedy->until, $locale);

            return match ($remedy->reason) {
                WaitReason::Recovered => $this->trans('yorvoid.remedy.wait.recovered', [], $locale).' '.$when,
                WaitReason::Unspecified => $when,
            };
        }

        if ($remedy instanceof StepUpRemedy) {
            $operation = null === $remedy->operation ? null : $this->known('yorvoid.operation.'.$remedy->operation, [], $locale);

            return null === $operation
                ? $this->trans('yorvoid.remedy.step_up.text', [], $locale)
                : $this->trans('yorvoid.remedy.step_up.operation', ['operation' => $operation], $locale);
        }

        // The application's own: its message, if it has one.
        $parameters = array_map(static fn (string|int|float|bool|null $value): string|int|float => \is_bool($value) ? (int) $value : $value ?? '', $remedy->parameters());

        return $this->known('yorvoid.remedy.'.$remedy->name(), $parameters, $locale) ?? $this->trans('yorvoid.remedy.unknown', [], $locale);
    }

    /**
     * How long until then, rounded up: minutes under an hour, hours under a day, then days. The time zone
     * isn't known, so never a time of day.
     */
    private function wait(\DateTimeImmutable $until, string $locale): string
    {
        $seconds = $until->getTimestamp() - $this->clock->now()->getTimestamp();

        if ($seconds <= 0) {
            return $this->trans('yorvoid.remedy.wait.now', [], $locale);
        }

        $minutes = intdiv($seconds + 59, 60);
        $hours = intdiv($seconds + 3599, 3600);

        return match (true) {
            $minutes < 60 => $this->trans('yorvoid.remedy.wait.minutes', ['count' => $minutes], $locale),
            $hours < 24 => $this->trans('yorvoid.remedy.wait.hours', ['count' => $hours], $locale),
            default => $this->trans('yorvoid.remedy.wait.days', ['count' => intdiv($seconds + 86399, 86400)], $locale),
        };
    }

    /**
     * @param array<string, string|int|float> $parameters
     */
    private function known(string $id, array $parameters, string $locale): ?string
    {
        $text = $this->trans($id, $parameters, $locale);

        return $id === $text ? null : $text;
    }

    /**
     * @param array<string, string|int|float> $parameters
     */
    private function trans(string $id, array $parameters, string $locale): string
    {
        return $this->translator->trans($id, $parameters, 'yorvoid', $locale);
    }
}
