<?php

declare(strict_types=1);

namespace YorvoID\Flows;

use YorvoID\Core\Duration;
use YorvoID\Core\Exception\IntegratorError;

/**
 * A flow's steps, in order (PLT-070). Applications insert their own (accept the terms, collect a
 * company name), replace YorvoID's, or remove the ones that aren't required, without copying
 * controllers: each change gives a new flow.
 *
 * @api
 */
final readonly class Flow
{
    /**
     * Lowercase segments separated by dots, e.g. "yorvoid.sign_in" or "acme.onboarding".
     */
    public const string NAME = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/D';

    /** @var list<Step> */
    public array $steps;

    public Duration $lifetime;

    /**
     * @param list<Step>    $steps    In order
     * @param list<string>  $required The steps that can't be removed, because the flow isn't safe without them
     * @param Duration|null $lifetime How long a flow may take; 30 minutes if not given
     *
     * @throws IntegratorError If the name or the steps aren't valid
     */
    public function __construct(
        public string $name,
        array $steps,
        public array $required = [],
        ?Duration $lifetime = null,
    ) {
        if (1 !== preg_match(self::NAME, $name)) {
            throw new IntegratorError(\sprintf('"%s" isn\'t a flow\'s name.', $name), 'Name flows in lowercase segments separated by dots, e.g. "acme.onboarding".', 'concepts/flows.md#changing-a-flow');
        }

        $names = array_map(static fn (Step $step): string => $step->name(), $steps);

        if ([] === $steps || \count($names) !== \count(array_unique($names))) {
            throw new IntegratorError(\sprintf('The flow "%s" needs steps, each with a name of its own.', $name), 'Give every step of the flow a different name.', 'concepts/flows.md#changing-a-flow');
        }

        foreach ($required as $step) {
            if (!\in_array($step, $names, true)) {
                throw new IntegratorError(\sprintf('The flow "%s" requires the step "%s", which it doesn\'t have.', $name, $step), 'List only the flow\'s own steps as required.', 'concepts/flows.md#changing-a-flow');
            }
        }

        $this->steps = $steps;
        $this->lifetime = $lifetime ?? Duration::minutes(30);
    }

    public function step(string $name): ?Step
    {
        foreach ($this->steps as $step) {
            if ($name === $step->name()) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @throws IntegratorError If there's no such step, or one with the new step's name already
     */
    public function withStepBefore(string $before, Step $step): self
    {
        return $this->inserted($before, $step, 0);
    }

    /**
     * @throws IntegratorError If there's no such step, or one with the new step's name already
     */
    public function withStepAfter(string $after, Step $step): self
    {
        return $this->inserted($after, $step, 1);
    }

    /**
     * Replaces a step with one of the same name, e.g. YorvoID's with the application's own.
     *
     * @throws IntegratorError If there's no such step, or the new one is named otherwise
     */
    public function withStepReplaced(Step $step): self
    {
        $this->position($step->name());

        return new self($this->name, array_map(static fn (Step $existing): Step => $existing->name() === $step->name() ? $step : $existing, $this->steps), $this->required, $this->lifetime);
    }

    /**
     * @throws IntegratorError If there's no such step, or the flow requires it
     */
    public function withoutStep(string $name): self
    {
        $position = $this->position($name);

        if (\in_array($name, $this->required, true)) {
            throw new IntegratorError(\sprintf('The flow "%s" can\'t do without the step "%s".', $this->name, $name), 'Keep the step, or replace it with one of your own that does what it does.', 'concepts/flows.md#changing-a-flow');
        }

        $steps = $this->steps;
        array_splice($steps, $position, 1);

        return new self($this->name, $steps, $this->required, $this->lifetime);
    }

    public function withLifetime(Duration $lifetime): self
    {
        return new self($this->name, $this->steps, $this->required, $lifetime);
    }

    private function inserted(string $next, Step $step, int $offset): self
    {
        $steps = $this->steps;
        array_splice($steps, $this->position($next) + $offset, 0, [$step]);

        return new self($this->name, $steps, $this->required, $this->lifetime);
    }

    private function position(string $name): int
    {
        foreach ($this->steps as $position => $step) {
            if ($name === $step->name()) {
                return $position;
            }
        }

        throw new IntegratorError(\sprintf('The flow "%s" has no step "%s".', $this->name, $name), \sprintf('Use one of its steps: %s.', implode(', ', array_map(static fn (Step $step): string => $step->name(), $this->steps))), 'concepts/flows.md#changing-a-flow');
    }
}
