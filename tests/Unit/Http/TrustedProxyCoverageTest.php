<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\TrustedProxy;

#[CoversClass(TrustedProxy::class)]
final class TrustedProxyCoverageTest extends TestCase
{
    #[Test]
    public function ipv4CidrWithZeroBitsMatchesAll(): void
    {
        $proxy = new TrustedProxy(['0.0.0.0/0']);
        $request = $this->makeRequest('192.168.1.1', '10.0.0.1');

        // 0.0.0.0/0 matches ALL IPv4 → every IP is trusted, including XFF
        // When all XFF IPs are trusted, resolveClientIp returns remoteAddr
        self::assertSame('192.168.1.1', $proxy->resolveClientIp($request));
    }

    #[Test]
    public function exactIpMatchWithoutCidr(): void
    {
        $proxy = new TrustedProxy(['192.168.1.1']);
        $request = $this->makeRequest('192.168.1.1', '10.0.0.1');

        self::assertSame('10.0.0.1', $proxy->resolveClientIp($request));
    }

    #[Test]
    public function exactIpMatchWithoutCidrDoesNotMatchDifferentIp(): void
    {
        $proxy = new TrustedProxy(['192.168.1.1']);
        $request = $this->makeRequest('192.168.1.2', '10.0.0.1');

        // Not trusted → returns REMOTE_ADDR
        self::assertSame('192.168.1.2', $proxy->resolveClientIp($request));
    }

    #[Test]
    public function ipv6WithSubnetBitMaskPartialByte(): void
    {
        // /48 means 6 full bytes, 0 remaining bits
        $proxy = new TrustedProxy(['2001:db8:1::/48']);
        $request = $this->makeRequest('2001:db8:1::100', '203.0.113.50');

        self::assertSame('203.0.113.50', $proxy->resolveClientIp($request));
    }

    #[Test]
    public function ipv6WithSubnetBitMaskPartialByteNoMatch(): void
    {
        $proxy = new TrustedProxy(['2001:db8:1::/48']);
        $request = $this->makeRequest('2001:db8:2::100', '203.0.113.50');

        // Different /48 → not trusted
        self::assertSame('2001:db8:2::100', $proxy->resolveClientIp($request));
    }

    #[Test]
    public function ipv6WithRemainingBits(): void
    {
        // /52 = 6 full bytes + 4 remaining bits
        $proxy = new TrustedProxy(['2001:db8:abcd:1000::/52']);
        $request = $this->makeRequest('2001:db8:abcd:1fff::1', '10.0.0.1');

        self::assertSame('10.0.0.1', $proxy->resolveClientIp($request));
    }

    #[Test]
    public function ipv6WithRemainingBitsNoMatch(): void
    {
        // /52 = 6 full bytes + 4 remaining bits
        $proxy = new TrustedProxy(['2001:db8:abcd:1000::/52']);
        $request = $this->makeRequest('2001:db8:abcd:2000::1', '10.0.0.1');

        // 0x1 vs 0x2 in the nibble → not matched
        self::assertSame('2001:db8:abcd:2000::1', $proxy->resolveClientIp($request));
    }

    #[Test]
    public function invalidIpAddressIsNotTrusted(): void
    {
        $proxy = new TrustedProxy(['10.0.0.0/8']);
        $request = $this->makeRequest('not-an-ip', '10.0.0.1');

        self::assertSame('not-an-ip', $proxy->resolveClientIp($request));
    }

    #[Test]
    public function nonStringRemoteAddrDefaultsToLoopback(): void
    {
        // When REMOTE_ADDR is not set, resolveClientIp defaults to 127.0.0.1
        $proxy = new TrustedProxy(['127.0.0.1/32']);
        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['X-Forwarded-For' => '203.0.113.50'],
        );

        self::assertSame('203.0.113.50', $proxy->resolveClientIp($request));
    }

    #[Test]
    public function ipv6SubnetWithInvalidCidrSubnet(): void
    {
        // IPv6 CIDR where the ip is IPv6 but subnet is invalid
        // This tests the filter_var check on the subnet
        $proxy = new TrustedProxy(['not-valid-v6/64']);
        $request = $this->makeRequest('2001:db8::1');

        // ip2long fails for IPv6, then filter_var($ip) passes but filter_var($subnet) fails
        self::assertSame('2001:db8::1', $proxy->resolveClientIp($request));
    }

    #[Test]
    public function ipv6CidrFullByteBoundaryMatch(): void
    {
        // /64 = 8 full bytes, 0 remaining bits
        $proxy = new TrustedProxy(['fd00::/64']);
        $request = $this->makeRequest('fd00::1', '10.0.0.1');

        self::assertSame('10.0.0.1', $proxy->resolveClientIp($request));
    }

    #[Test]
    public function ipv6CidrFullByteBoundaryNoMatch(): void
    {
        $proxy = new TrustedProxy(['fd00::/64']);
        $request = $this->makeRequest('fd00:0:0:1::1', '10.0.0.1');

        // Different /64 → not trusted
        self::assertSame('fd00:0:0:1::1', $proxy->resolveClientIp($request));
    }

    private function makeRequest(string $remoteAddr, ?string $forwardedFor = null): ServerRequest
    {
        $headers = $forwardedFor !== null ? ['X-Forwarded-For' => $forwardedFor] : [];

        return new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: $headers,
            serverParams: ['REMOTE_ADDR' => $remoteAddr],
        );
    }
}
