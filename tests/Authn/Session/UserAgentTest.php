<?php

declare(strict_types=1);

namespace YorvoID\Tests\Authn\Session;

use PHPUnit\Framework\TestCase;
use YorvoID\Authn\Session\UserAgent;

final class UserAgentTest extends TestCase
{
    public function testNamesTheBrowserAndTheSystemFromItsLists(): void
    {
        $agents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0' => ['Edge', 'Windows'],
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36 EdgA/131.0.0.0' => ['Edge', 'Android'],
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) EdgiOS/131.0 Mobile/15E148 Safari/605.1.15' => ['Edge', 'iOS'],
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 OPR/115.0.0.0' => ['Opera', 'macOS'],
            'Mozilla/5.0 (Linux; Android 14; SM-S921B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36' => ['Samsung Internet', 'Android'],
            'Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0' => ['Firefox', 'Linux'],
            'Mozilla/5.0 (iPad; CPU OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/133.0 Mobile/15E148 Safari/605.1.15' => ['Firefox', 'iPadOS'],
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/131.0.0.0 Mobile/15E148 Safari/604.1' => ['Chrome', 'iOS'],
            'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36' => ['Chrome', 'ChromeOS'],
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15' => ['Safari', 'macOS'],
            'Mozilla/5.0 (Mac OS X 10_15_7) Safari/605.1.15' => ['Safari', 'macOS'],
            'Firefox on Linux' => ['Firefox', 'Linux'],
            'curl/8.11.0' => [null, null],
            'Acme Security Team: all good' => [null, null],
            '' => [null, null],
        ];

        foreach ($agents as $agent => $expected) {
            self::assertSame($expected, UserAgent::describe($agent), $agent);
        }

        self::assertSame([null, null], UserAgent::describe(null));
    }
}
