<?php

declare(strict_types=1);

namespace YorvoID\Flows;

/**
 * A step's view model: what its template and the browser client render. Each step has its own,
 * typed and documented, and covered by the compatibility promise. It holds data, never copy: wording
 * comes from translations.
 *
 * @api
 */
interface StepView extends \JsonSerializable
{
    /**
     * @return array<string, mixed> What the browser client receives
     */
    public function jsonSerialize(): array;
}
