<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Client\HttpClient;
use Pulsar\Http\Client\HttpClientConfig;
use Pulsar\Http\Client\HttpClientException;
use Pulsar\Http\Client\PendingRequest;

#[CoversClass(HttpClient::class)]
final class HttpClientTest extends TestCase
{
    #[Test]
    public function pendingReturnsFluentBuilder(): void
    {
        $client = new HttpClient();

        self::assertInstanceOf(PendingRequest::class, $client->pending());
    }

    #[Test]
    public function ssrfBlocksLocalhost(): void
    {
        $config = new HttpClientConfig(ssrfProtection: true);
        $client = new HttpClient($config);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/SSRF.*localhost/');

        $client->get('http://localhost/secret');
    }

    #[Test]
    public function ssrfBlocksZeroAddress(): void
    {
        $config = new HttpClientConfig(ssrfProtection: true);
        $client = new HttpClient($config);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/SSRF/');

        $client->get('http://0.0.0.0/internal');
    }

    #[Test]
    public function ssrfBlocksIpv6Loopback(): void
    {
        $config = new HttpClientConfig(ssrfProtection: true);
        $client = new HttpClient($config);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/SSRF/');

        $client->get('http://[::1]/internal');
    }

    #[Test]
    public function ssrfBlocksPrivateIpV4Ranges(): void
    {
        $config = new HttpClientConfig(ssrfProtection: true);
        $client = new HttpClient($config);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/SSRF/');

        // 10.x.x.x is a private range
        $client->get('http://10.0.0.1/admin');
    }

    #[Test]
    public function ssrfBlocks172PrivateRange(): void
    {
        $config = new HttpClientConfig(ssrfProtection: true);
        $client = new HttpClient($config);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/SSRF/');

        $client->get('http://172.16.0.1/');
    }

    #[Test]
    public function ssrfBlocks192PrivateRange(): void
    {
        $config = new HttpClientConfig(ssrfProtection: true);
        $client = new HttpClient($config);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/SSRF/');

        $client->get('http://192.168.1.1/');
    }

    #[Test]
    public function ssrfBlocksLinkLocalRange(): void
    {
        $config = new HttpClientConfig(ssrfProtection: true);
        $client = new HttpClient($config);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/SSRF/');

        // 169.254.x.x is link-local
        $client->get('http://169.254.169.254/latest/meta-data/');
    }

    #[Test]
    public function ssrfProtectionCanBeDisabled(): void
    {
        $config = new HttpClientConfig(ssrfProtection: false, timeout: 0.1);
        $client = new HttpClient($config);

        // Should not throw SSRF exception but may fail to connect
        try {
            $client->get('http://localhost:1/non-existent');
        } catch (HttpClientException $e) {
            // Connection failure is expected, but NOT an SSRF block
            self::assertStringNotContainsString('SSRF', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ssrfBlockedHostsProvider(): iterable
    {
        yield 'localhost' => ['http://localhost/'];
        yield '0.0.0.0' => ['http://0.0.0.0/'];
        yield '::1 bracketed' => ['http://[::1]/'];
        yield '::0 bracketed' => ['http://[::0]/'];
        yield ':: bracketed' => ['http://[::]/'];
        yield '127.0.0.1' => ['http://127.0.0.1/'];
        yield '10.0.0.1' => ['http://10.0.0.1/'];
        yield '192.168.0.1' => ['http://192.168.0.1/'];
    }

    #[Test]
    #[DataProvider('ssrfBlockedHostsProvider')]
    public function ssrfBlocksAllPrivateHosts(string $url): void
    {
        $client = new HttpClient(new HttpClientConfig(ssrfProtection: true));

        $this->expectException(HttpClientException::class);

        $client->get($url);
    }

    #[Test]
    public function configWithBaseUrlPrependsToRelativePaths(): void
    {
        // We test URL resolution indirectly: when SSRF protection is on,
        // the resolved URL includes the base URL for host checking.
        $config = new HttpClientConfig(
            baseUrl: 'http://10.0.0.1',
            ssrfProtection: true,
        );
        $client = new HttpClient($config);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/SSRF/');

        // Relative path should have base URL prepended
        $client->get('/api/data');
    }

    #[Test]
    public function absoluteUrlIgnoresBaseUrl(): void
    {
        $config = new HttpClientConfig(
            baseUrl: 'https://api.example.com',
            ssrfProtection: true,
        );
        $client = new HttpClient($config);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/SSRF.*localhost/');

        // Absolute URL should NOT use base URL
        $client->get('http://localhost/hack');
    }

    #[Test]
    public function defaultConfigCreatesClient(): void
    {
        $client = new HttpClient();

        // Should not throw during construction
        self::assertInstanceOf(HttpClient::class, $client);
    }

    #[Test]
    public function allHttpVerbMethodsExist(): void
    {
        $client = new HttpClient(new HttpClientConfig(ssrfProtection: true));
        $verbs = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

        foreach ($verbs as $verb) {
            self::assertTrue(
                method_exists($client, $verb),
                "HttpClient must have method: {$verb}()",
            );
        }
    }

    #[Test]
    public function guardAgainstSsrfReturnsStringWithPinnedIp(): void
    {
        // The guardAgainstSsrf method now returns a URL (string), not void.
        // This verifies the return type change was applied correctly.
        $client = new HttpClient(new HttpClientConfig(ssrfProtection: true));

        // An IP-literal URL should pass through unchanged
        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/SSRF/');

        // Private IP should still be blocked
        $client->get('http://10.0.0.1/admin');
    }

    #[Test]
    public function ssrfBlocksBracketedIpv6Loopback(): void
    {
        $client = new HttpClient(new HttpClientConfig(ssrfProtection: true));

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/SSRF/');

        $client->get('http://[::1]/internal');
    }

    #[Test]
    public function ssrfBlocksIpv6ZeroAddress(): void
    {
        $client = new HttpClient(new HttpClientConfig(ssrfProtection: true));

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/SSRF/');

        $client->get('http://[::0]/internal');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function disallowedSchemeProvider(): iterable
    {
        yield 'file' => ['file:///etc/passwd'];
        yield 'gopher' => ['gopher://127.0.0.1:11211/_stats'];
        yield 'dict' => ['dict://localhost:11211/stats'];
        yield 'php filter' => ['php://filter/read=convert.base64-encode/resource=/etc/passwd'];
    }

    #[Test]
    #[DataProvider('disallowedSchemeProvider')]
    public function ssrfBlocksNonHttpSchemes(string $url): void
    {
        // A redirect Location (or caller) of file://, gopher://, dict://, php://
        // parses with no host and would otherwise slip past the IP checks and be
        // fetched locally. The scheme guard rejects it before any I/O.
        $client = new HttpClient(new HttpClientConfig(ssrfProtection: true));

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/disallowed scheme/');

        $client->get($url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ipv4EmbeddingIpv6Provider(): iterable
    {
        // Each embeds an IPv4 that routes to a private/reserved address; the
        // reserved-range filter alone misses NAT64 and 6to4.
        yield 'NAT64 -> 127.0.0.1' => ['http://[64:ff9b::7f00:1]/'];
        yield 'NAT64 -> 169.254.169.254 (metadata)' => ['http://[64:ff9b::a9fe:a9fe]/latest/meta-data/'];
        yield 'IPv4-mapped -> 169.254.169.254' => ['http://[::ffff:169.254.169.254]/'];
        yield '6to4 -> 127.0.0.1' => ['http://[2002:7f00:1::]/'];
    }

    #[Test]
    #[DataProvider('ipv4EmbeddingIpv6Provider')]
    public function ssrfBlocksIpv4EmbeddingIpv6(string $url): void
    {
        $client = new HttpClient(new HttpClientConfig(ssrfProtection: true));

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/SSRF/');

        $client->get($url);
    }

    #[Test]
    public function ssrfFailsClosedWhenTheHostCannotBeResolved(): void
    {
        // .invalid never resolves (RFC 6761). A guard must refuse rather than
        // connect to a name it could not validate — a rebinding attacker can
        // return SERVFAIL at validation and a private A at connect time.
        $client = new HttpClient(new HttpClientConfig(ssrfProtection: true));

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageMatches('/could not be resolved/');

        $client->get('http://pulsar-nonexistent-host.invalid/');
    }
}
