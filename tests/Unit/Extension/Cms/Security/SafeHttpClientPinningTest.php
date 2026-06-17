<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Extension\Cms\Config\CmsSecurityConfig;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Internal\Security\SafeHttpClient;

use function array_keys;
use function is_array;
use function is_string;
use function stream_context_get_options;

/**
 * DNS-rebinding mitigation: a hostname is resolved and validated once, then the
 * request connects to the *validated IP* (pinned), so the transport cannot
 * re-resolve the name to a private address between validation and connection.
 *
 * Uses the resolver/transport seams to drive the DNS path deterministically
 * without real network or DNS.
 */
#[CoversClass(SafeHttpClient::class)]
final class SafeHttpClientPinningTest extends TestCase
{
    private CmsSecurityConfig $config;

    protected function setUp(): void
    {
        $this->config = new CmsSecurityConfig(
            ssrfEnabled: true,
            allowedOutboundPorts: [80, 443],
            maxRedirects: 3,
        );
    }

    #[Test]
    public function connectsToTheValidatedIpAndPresentsTheOriginalHost(): void
    {
        $url = '';
        $header = '';
        $peerName = '';
        $verifyPeer = false;

        $resolver = static fn(string $host): array => ['93.184.216.34']; // public
        $transport = static function (string $requestUrl, $context) use (&$url, &$header, &$peerName, &$verifyPeer): string {
            /** @var resource $context */
            $opts = stream_context_get_options($context);
            $http = is_array($opts['http'] ?? null) ? $opts['http'] : [];
            $ssl = is_array($opts['ssl'] ?? null) ? $opts['ssl'] : [];
            $url = $requestUrl;
            $header = is_string($http['header'] ?? null) ? $http['header'] : '';
            $peerName = is_string($ssl['peer_name'] ?? null) ? $ssl['peer_name'] : '';
            $verifyPeer = ($ssl['verify_peer'] ?? null) === true;

            return 'body';
        };

        $client = new SafeHttpClient($this->config, new NullLogger(), $resolver, $transport);
        $client->request('GET', 'https://example.com:443/path?q=1');

        // Connection is pinned to the validated IP — not re-resolved by name.
        self::assertSame('https://93.184.216.34:443/path?q=1', $url);
        // Host header and TLS identity remain the original hostname.
        self::assertStringContainsString('Host: example.com', $header);
        self::assertSame('example.com', $peerName);
        self::assertTrue($verifyPeer);
    }

    #[Test]
    public function aHostnameResolvingToAPrivateIpIsBlockedBeforeAnyConnection(): void
    {
        // The rebinding vector: the name resolves to an internal address.
        $transportRan = false;
        $resolver = static fn(string $host): array => ['10.0.0.5'];
        $transport = static function () use (&$transportRan): string {
            $transportRan = true;

            return 'body';
        };

        $client = new SafeHttpClient($this->config, new NullLogger(), $resolver, $transport);

        try {
            $client->request('GET', 'https://rebind.example/');
            self::fail('Expected CmsException for a host resolving to a private IP');
        } catch (CmsException $e) {
            self::assertStringContainsString('blocked IP', $e->getMessage());
        }

        self::assertFalse($transportRan, 'No connection may be attempted to a blocked host');
    }

    #[Test]
    public function metadataIpBehindAHostnameIsBlocked(): void
    {
        $resolver = static fn(string $host): array => ['169.254.169.254'];
        $transport = static fn(): string => 'body';

        $client = new SafeHttpClient($this->config, new NullLogger(), $resolver, $transport);

        $this->expectException(CmsException::class);

        $client->request('GET', 'http://metadata-proxy.example/latest/');
    }

    #[Test]
    public function ipLiteralUrlIsNeitherResolvedNorRewritten(): void
    {
        $url = '';
        $resolverCalls = 0;
        $sslKeys = [];

        $resolver = static function (string $host) use (&$resolverCalls): array {
            $resolverCalls++;

            return ['1.1.1.1'];
        };
        $transport = static function (string $requestUrl, $context) use (&$url, &$sslKeys): string {
            /** @var resource $context */
            $opts = stream_context_get_options($context);
            $ssl = is_array($opts['ssl'] ?? null) ? $opts['ssl'] : [];
            $url = $requestUrl;
            $sslKeys = array_keys($ssl);

            return 'body';
        };

        $client = new SafeHttpClient($this->config, new NullLogger(), $resolver, $transport);
        $client->request('GET', 'http://93.184.216.34/x'); // public IP literal

        self::assertSame(0, $resolverCalls, 'An IP-literal host must not be DNS-resolved');
        self::assertSame('http://93.184.216.34/x', $url);
        // No host pinning for an IP literal (nothing to rebind).
        self::assertNotContains('peer_name', $sslKeys);
    }
}
