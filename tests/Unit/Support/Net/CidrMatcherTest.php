<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support\Net;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Support\Net\CidrMatcher;

#[CoversClass(CidrMatcher::class)]
final class CidrMatcherTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'ipv4 in /24' => ['203.0.113.42', '203.0.113.0/24', true];
        yield 'ipv4 outside /24' => ['203.0.114.42', '203.0.113.0/24', false];
        yield 'ipv4 /32 exact' => ['203.0.113.42', '203.0.113.42/32', true];
        yield 'ipv4 /0 matches all' => ['8.8.8.8', '0.0.0.0/0', true];
        yield 'ipv4 bare equal' => ['203.0.113.42', '203.0.113.42', true];
        yield 'ipv4 bare unequal' => ['203.0.113.43', '203.0.113.42', false];
        yield 'ipv6 in /32' => ['2001:db8::1', '2001:db8::/32', true];
        yield 'ipv6 outside /32' => ['2001:dba::1', '2001:db8::/32', false];
        yield 'ipv6 /128 exact' => ['2001:db8::1', '2001:db8::1/128', true];
        yield 'ipv4 vs ipv6 range' => ['203.0.113.42', '2001:db8::/32', false];
        yield 'malformed ip' => ['not-an-ip', '203.0.113.0/24', false];
        yield 'malformed range' => ['203.0.113.42', 'garbage/24', false];
        yield 'oversized v4 prefix' => ['203.0.113.42', '203.0.113.0/40', false];
    }

    #[Test]
    #[DataProvider('cases')]
    public function matchesCidr(string $ip, string $cidr, bool $expected): void
    {
        self::assertSame($expected, CidrMatcher::matches($ip, $cidr));
    }

    #[Test]
    public function matchesAnyReturnsTrueWhenOneRangeMatches(): void
    {
        self::assertTrue(CidrMatcher::matchesAny('203.0.113.42', ['10.0.0.0/8', '203.0.113.0/24']));
        self::assertFalse(CidrMatcher::matchesAny('8.8.8.8', ['10.0.0.0/8', '203.0.113.0/24']));
        self::assertFalse(CidrMatcher::matchesAny('203.0.113.42', []));
    }
}
