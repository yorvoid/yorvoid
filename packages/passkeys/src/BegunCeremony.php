<?php

declare(strict_types=1);

namespace YorvoID\Passkeys;

/**
 * What the browser needs to run a ceremony: its ID, to send back with the answer, and the options to
 * pass to navigator.credentials.create() or .get() (the browser client does both).
 *
 * @api
 */
final readonly class BegunCeremony
{
    /**
     * @param string $id      The ceremony's ID
     * @param string $options The options, as JSON
     */
    public function __construct(
        public string $id,
        public string $options,
    ) {
    }
}
