<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Client\HttpClientConfig;

#[CoversClass(HttpClientConfig::class)]
final class HttpClientConfigTest extends TestCase
{
    #[Test]
    public function defaultValues(): void
    {
        $config = new HttpClientConfig();

        self::assertSame(30.0, $config->timeout);
        self::assertSame(0, $config->retries);
        self::assertSame(1.0, $config->retryDelay);
        self::assertNull($config->baseUrl);
        self::assertTrue($config->verifySsl);
        self::assertNull($config->proxy);
        self::assertTrue($config->ssrfProtection);
        self::assertSame(5, $config->maxRedirects);
        self::assertSame(10_485_760, $config->maxResponseSize);
        self::assertSame([], $config->defaultHeaders);
    }

    #[Test]
    public function constructorAcceptsAllParameters(): void
    {
        $config = new HttpClientConfig(
            timeout: 5.0,
            retries: 3,
            retryDelay: 0.5,
            baseUrl: 'https://api.example.com',
            verifySsl: false,
            proxy: 'http://proxy:8080',
            ssrfProtection: false,
            maxRedirects: 10,
            maxResponseSize: 1024,
            defaultHeaders: ['Accept' => 'application/json'],
        );

        self::assertSame(5.0, $config->timeout);
        self::assertSame(3, $config->retries);
        self::assertSame(0.5, $config->retryDelay);
        self::assertSame('https://api.example.com', $config->baseUrl);
        self::assertFalse($config->verifySsl);
        self::assertSame('http://proxy:8080', $config->proxy);
        self::assertFalse($config->ssrfProtection);
        self::assertSame(10, $config->maxRedirects);
        self::assertSame(1024, $config->maxResponseSize);
        self::assertSame(['Accept' => 'application/json'], $config->defaultHeaders);
    }

    #[Test]
    public function fromArrayCreatesConfigWithDefaults(): void
    {
        $config = HttpClientConfig::fromArray([]);

        self::assertSame(30.0, $config->timeout);
        self::assertSame(0, $config->retries);
        self::assertTrue($config->verifySsl);
        self::assertTrue($config->ssrfProtection);
    }

    #[Test]
    public function fromArrayCreatesConfigFromValues(): void
    {
        $config = HttpClientConfig::fromArray([
            'timeout' => 10,
            'retries' => 2,
            'retry_delay' => 0.25,
            'base_url' => 'https://api.test.com',
            'verify_ssl' => false,
            'proxy' => 'socks5://proxy:1080',
            'ssrf_protection' => false,
            'max_redirects' => 3,
            'max_response_size' => 2048,
            'default_headers' => ['X-Custom' => 'value'],
        ]);

        self::assertSame(10.0, $config->timeout);
        self::assertSame(2, $config->retries);
        self::assertSame(0.25, $config->retryDelay);
        self::assertSame('https://api.test.com', $config->baseUrl);
        self::assertFalse($config->verifySsl);
        self::assertSame('socks5://proxy:1080', $config->proxy);
        self::assertFalse($config->ssrfProtection);
        self::assertSame(3, $config->maxRedirects);
        self::assertSame(2048, $config->maxResponseSize);
        self::assertSame(['X-Custom' => 'value'], $config->defaultHeaders);
    }

    #[Test]
    public function withTimeoutReturnsNewInstance(): void
    {
        $original = new HttpClientConfig(timeout: 30.0);
        $modified = $original->withTimeout(5.0);

        self::assertSame(30.0, $original->timeout);
        self::assertSame(5.0, $modified->timeout);
        self::assertNotSame($original, $modified);
    }

    #[Test]
    public function withRetriesReturnsNewInstanceWithBothValues(): void
    {
        $original = new HttpClientConfig();
        $modified = $original->withRetries(3, 0.5);

        self::assertSame(0, $original->retries);
        self::assertSame(3, $modified->retries);
        self::assertSame(0.5, $modified->retryDelay);
    }

    #[Test]
    public function withBaseUrlTrimsTrailingSlash(): void
    {
        $config = new HttpClientConfig();
        $modified = $config->withBaseUrl('https://api.example.com/');

        self::assertSame('https://api.example.com', $modified->baseUrl);
    }

    #[Test]
    public function withSsrfProtectionToggles(): void
    {
        $config = new HttpClientConfig(ssrfProtection: true);

        $disabled = $config->withSsrfProtection(false);
        self::assertFalse($disabled->ssrfProtection);
        self::assertTrue($config->ssrfProtection);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, mixed}>
     */
    public static function fromArrayEdgeCasesProvider(): iterable
    {
        yield 'null base_url stays null' => [[], 'baseUrl', null];
        yield 'null proxy stays null' => [[], 'proxy', null];
        yield 'string timeout cast to float' => [['timeout' => '15'], 'timeout', 15.0];
        yield 'string retries cast to int' => [['retries' => '4'], 'retries', 4];
    }

    /**
     * @param array<string, mixed> $values
     */
    #[Test]
    #[DataProvider('fromArrayEdgeCasesProvider')]
    public function fromArrayEdgeCases(array $values, string $property, mixed $expected): void
    {
        $config = HttpClientConfig::fromArray($values);
        self::assertSame($expected, $config->$property);
    }
}
