<?php

declare(strict_types=1);

namespace YorvoID\Core\Crypto;

/**
 * Which algorithms YorvoID may use (CRY-01).
 *
 * @api
 */
enum CryptoMode: string
{
    /** Every algorithm YorvoID supports. The default. */
    case Standard = 'standard';

    /**
     * Only what OpenSSL's provider offers, with only a FIPS provider active: YorvoID refuses to start
     * otherwise. Whether that provider is validated is for the operator to check against its certificate.
     */
    case Fips = 'fips';
}
