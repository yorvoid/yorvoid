<?php

declare(strict_types=1);

namespace YorvoID\Core\Key;

/**
 * What a signing key signs with. A signature never says: the key that's named by its ID does, so one
 * algorithm's signature is never taken for the other's, though both are 64 bytes.
 *
 * @api
 */
enum KeyAlgorithm: string
{
    /** Ed25519 (RFC 8032): the default */
    case EdDSA = 'EdDSA';

    /** ECDSA on P-256 with SHA-256 (RFC 7518 §3.4), for a FIPS provider without EdDSA */
    case ES256 = 'ES256';
}
