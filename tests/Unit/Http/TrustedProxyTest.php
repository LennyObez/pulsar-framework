<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\HeaderBag;
use Pulsar\Http\Method;
use Pulsar\Http\Request;
use Pulsar\Http\TrustedProxy;

#[CoversClass(TrustedProxy::class)]
final class TrustedProxyTest extends TestCase
{
    #[Test]
    public function returnsRemoteAddrWhenNotTrusted(): void
    {
        $proxy = new TrustedProxy(['10.0.0.0/8']);
        $request = $this->makeRequest('203.0.113.50', '198.51.100.1, 203.0.113.50');

        self::assertSame('203.0.113.50', $proxy->resolveClientIp($request));
    }

    #[Test]
    public function resolvesClientIpFromForwardedForWhenTrusted(): void
    {
        $proxy = new TrustedProxy(['127.0.0.1/32']);
        $request = $this->makeRequest('127.0.0.1', '203.0.113.50');

        self::assertSame('203.0.113.50', $proxy->resolveClientIp($request));
    }

    #[Test]
    public function walksRightToLeftToFindFirstUntrustedIp(): void
    {
        $proxy = new TrustedProxy(['10.0.0.0/8', '127.0.0.1/32']);
        $request = $this->makeRequest('127.0.0.1', '203.0.113.50, 10.0.0.1, 10.0.0.2');

        // 10.0.0.2 is trusted, 10.0.0.1 is trusted, 203.0.113.50 is not
        self::assertSame('203.0.113.50', $proxy->resolveClientIp($request));
    }

    #[Test]
    public function returnsRemoteAddrWhenNoForwardedFor(): void
    {
        $proxy = new TrustedProxy(['127.0.0.1/32']);
        $request = $this->makeRequest('127.0.0.1');

        self::assertSame('127.0.0.1', $proxy->resolveClientIp($request));
    }

    #[Test]
    public function returnsRemoteAddrWhenAllForwardedIpsAreTrusted(): void
    {
        $proxy = new TrustedProxy(['10.0.0.0/8', '127.0.0.1/32']);
        $request = $this->makeRequest('127.0.0.1', '10.0.0.1, 10.0.0.2');

        self::assertSame('127.0.0.1', $proxy->resolveClientIp($request));
    }

    #[Test]
    public function handlesIpv6Cidrs(): void
    {
        $proxy = new TrustedProxy(['::1/128']);
        $request = $this->makeRequest('::1', '2001:db8::1');

        self::assertSame('2001:db8::1', $proxy->resolveClientIp($request));
    }

    #[Test]
    public function defaultTrustsLoopback(): void
    {
        $proxy = new TrustedProxy();
        $request = $this->makeRequest('127.0.0.1', '203.0.113.50');

        self::assertSame('203.0.113.50', $proxy->resolveClientIp($request));
    }

    #[Test]
    public function trimsWhitespaceInForwardedFor(): void
    {
        $proxy = new TrustedProxy(['127.0.0.1/32']);
        $request = $this->makeRequest('127.0.0.1', ' 203.0.113.50 , 10.0.0.1 ');

        self::assertSame('10.0.0.1', $proxy->resolveClientIp($request));
    }

    private function makeRequest(string $remoteAddr, ?string $forwardedFor = null): Request
    {
        $headers = $forwardedFor !== null ? ['X-Forwarded-For' => $forwardedFor] : [];

        return new Request(
            method: Method::GET,
            uri: '/',
            path: '/',
            queryString: '',
            headers: new HeaderBag($headers),
            body: '',
            server: ['REMOTE_ADDR' => $remoteAddr],
        );
    }
}
