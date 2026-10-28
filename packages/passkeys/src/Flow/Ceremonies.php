<?php

declare(strict_types=1);

namespace YorvoID\Passkeys\Flow;

use YorvoID\Flows\FlowState;
use YorvoID\Flows\Problem;
use YorvoID\Passkeys\BegunCeremony;
use YorvoID\Passkeys\PasskeyRefused;

/**
 * Where passkey steps keep their ceremony in the flow's state.
 *
 * @internal
 */
final class Ceremonies
{
    public static function keep(FlowState $state, string $step, BegunCeremony $ceremony): FlowState
    {
        return $state->with([$step.'.ceremony' => $ceremony->id, $step.'.options' => $ceremony->options]);
    }

    public static function id(FlowState $state, string $step): string
    {
        return $state->string($step.'.ceremony') ?? '';
    }

    public static function options(FlowState $state, string $step): ?string
    {
        return $state->string($step.'.options');
    }

    /**
     * @param bool $reason Whether to say why: not when signing in, which mustn't help guess which passkeys exist
     */
    public static function problem(PasskeyRefused $refused, bool $reason = true): Problem
    {
        return new Problem($refused->refusal->messageId(), reason: $reason ? $refused->refusal->value : null);
    }

    /**
     * The options as the browser client takes them.
     */
    public static function decode(?string $options): mixed
    {
        return null === $options ? null : json_decode($options, flags: \JSON_THROW_ON_ERROR);
    }
}
