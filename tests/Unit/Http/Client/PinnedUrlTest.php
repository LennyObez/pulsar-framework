<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Client\PinnedUrl;

use function parse_url;

use const PHP_URL_HOST;

#[CoversClass(PinnedUrl::class)]
final class PinnedUrlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function pinProvider(): iterable
    {
        yield 'simple host' => [
            'http://example.com/path',
            '203.0.113.9',
            'http://203.0.113.9/path',
        ];

        yield 'userinfo repeating the host pins the AUTHORITY, not userinfo' => [
            'http://rebind.test@rebind.test/latest/meta-data/',
            '203.0.113.9',
            'http://rebind.test@203.0.113.9/latest/meta-data/',
        ];

        yield 'user:pass preserved, host pinned' => [
            'http://user:pass@example.com/x',
            '203.0.113.9',
            'http://user:pass@203.0.113.9/x',
        ];

        yield 'port, query and fragment preserved' => [
            'http://example.com:8080/p?q=1&r=2#frag',
            '203.0.113.9',
            'http://203.0.113.9:8080/p?q=1&r=2#frag',
        ];

        yield 'ipv6 replacement is bracketed' => [
            'http://example.com/',
            '2001:db8::1',
            'http://[2001:db8::1]/',
        ];

        yield 'https scheme preserved' => [
            'https://example.com/secure',
            '203.0.113.9',
            'https://203.0.113.9/secure',
        ];

        yield 'no host is returned unchanged' => [
            '/relative/path',
            '203.0.113.9',
            '/relative/path',
        ];
    }

    #[Test]
    #[DataProvider('pinProvider')]
    public function withHostPinsTheAuthorityHost(string $url, string $ip, string $expected): void
    {
        self::assertSame($expected, PinnedUrl::withHost($url, $ip));
    }

    #[Test]
    public function theUserinfoAttackNoLongerLeavesTheConnectHostUnpinned(): void
    {
        // DNS-rebinding TOCTOU: on `http://h@h/` a naive first-occurrence string
        // replace pins the userinfo and leaves the connect host resolvable — the
        // rebind window stays open. The connect host (parse_url host) must be the IP.
        $pinned = PinnedUrl::withHost('http://rebind.test@rebind.test/', '203.0.113.9');

        self::assertSame('203.0.113.9', parse_url($pinned, PHP_URL_HOST));
    }
}
