<?php

declare(strict_types=1);

namespace YorvoID\Flows;

/**
 * What the person sent for a step: a form's fields, or the browser client's JSON.
 *
 * @api
 */
final readonly class Input
{
    /**
     * The action that passes over a step that offers it, e.g. adding a passkey on a borrowed device.
     */
    public const string SKIP = 'skip';

    /**
     * @param array<array-key, mixed> $fields
     */
    public function __construct(
        private array $fields = [],
    ) {
    }

    public function string(string $field): ?string
    {
        $value = $this->fields[$field] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * Whether a checkbox was ticked, or the browser client sent true.
     */
    public function isChecked(string $field): bool
    {
        return \in_array($this->fields[$field] ?? null, [true, '1', 'on'], true);
    }

    /**
     * A field as JSON: what the browser gave for a passkey ceremony, whether it was sent as an object
     * (the browser client) or as a JSON string (a form).
     */
    public function json(string $field): ?string
    {
        $value = $this->fields[$field] ?? null;

        return match (true) {
            \is_string($value) => $value,
            \is_array($value), $value instanceof \stdClass => json_encode($value, \JSON_THROW_ON_ERROR),
            default => null,
        };
    }

    /**
     * The action, e.g. Input::SKIP; null to submit the step.
     */
    public function action(): ?string
    {
        return $this->string('action');
    }
}
