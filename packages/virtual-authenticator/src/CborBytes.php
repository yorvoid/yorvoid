<?php

declare(strict_types=1);

namespace YorvoID\VirtualAuthenticator;

/**
 * Bytes, to encode as a CBOR byte string rather than a text string.
 *
 * @internal
 */
final readonly class CborBytes
{
    public function __construct(
        public string $bytes,
    ) {
    }
}
