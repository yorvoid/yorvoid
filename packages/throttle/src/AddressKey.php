<?php

declare(strict_types=1);

namespace YorvoID\Throttle;

use YorvoID\Core\Exception\IntegratorError;

/**
 * The part of a throttling key for a client's IP address. An IPv4 address counts on its own, also when
 * written as IPv6; an IPv6 address counts with its /64 network, since one connection usually has a
 * whole /64 to choose from.
 *
 * @api
 */
final class AddressKey
{
    public static function of(string $address): string
    {
        $packed = false === filter_var($address, \FILTER_VALIDATE_IP) ? false : inet_pton($address);

        if (false === $packed) {
            throw new IntegratorError(\sprintf('"%s" isn\'t an IP address.', $address), 'Pass the IP address of the request, e.g. from Request::getClientIp() in Symfony.', 'concepts/throttling.md#keys');
        }

        // An IPv4 address written as IPv6 (::ffff:192.0.2.1) is that IPv4 address: every such address is in one /64.
        if (16 === \strlen($packed) && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }

        if (16 === \strlen($packed)) {
            return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
        }

        $ipv4 = inet_ntop($packed);
        \assert(\is_string($ipv4));

        return $ipv4;
    }
}
