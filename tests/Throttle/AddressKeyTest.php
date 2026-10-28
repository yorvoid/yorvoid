<?php

declare(strict_types=1);

namespace YorvoID\Tests\Throttle;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YorvoID\Core\Exception\IntegratorError;
use YorvoID\Throttle\AddressKey;

final class AddressKeyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addresses(): iterable
    {
        yield 'IPv4' => ['192.0.2.10', '192.0.2.10'];
        yield 'IPv6, by its /64' => ['2001:db8:1:2:aaaa:bbbb:cccc:dddd', '2001:db8:1:2::/64'];
        yield 'IPv6, another address in the /64' => ['2001:db8:1:2::1', '2001:db8:1:2::/64'];
        yield 'IPv6, another /64' => ['2001:db8:1:3::1', '2001:db8:1:3::/64'];
        yield 'IPv4 written as IPv6' => ['::ffff:192.0.2.10', '192.0.2.10'];
        yield 'loopback' => ['::1', '::/64'];
    }

    #[DataProvider('addresses')]
    public function testKeysAnAddressByWhatOneClientControls(string $address, string $key): void
    {
        self::assertSame($key, AddressKey::of($address));
    }

    public function testRefusesWhatIsntAnAddress(): void
    {
        $this->expectException(IntegratorError::class);
        $this->expectExceptionMessage('"192.0.2" isn\'t an IP address.');

        AddressKey::of('192.0.2');
    }
}
