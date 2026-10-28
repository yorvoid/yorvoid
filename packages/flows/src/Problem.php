<?php

declare(strict_types=1);

namespace YorvoID\Flows;

/**
 * Why a step refused what the person sent, as a message to translate (domain "yorvoid"), and the
 * field it's about, if any.
 *
 * @api
 */
final readonly class Problem implements \JsonSerializable
{
    /**
     * @param string                          $message    The message's ID, e.g. "yorvoid.flow.recovery_code.invalid"
     * @param array<string, string|int|float> $parameters The message's parameters
     * @param string|null                     $field      The input field it's about
     * @param string|null                     $reason     Why, for logs and the browser client, not for people
     */
    public function __construct(
        public string $message,
        public array $parameters = [],
        public ?string $field = null,
        public ?string $reason = null,
    ) {
    }

    /**
     * @return array{message: string, parameters: array<string, string|int|float>, field: string|null, reason: string|null}
     */
    public function jsonSerialize(): array
    {
        return ['message' => $this->message, 'parameters' => $this->parameters, 'field' => $this->field, 'reason' => $this->reason];
    }
}
