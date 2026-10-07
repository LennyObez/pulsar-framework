<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\CorsConfig;
use Pulsar\Config\Exception\ConfigException;

#[CoversClass(CorsConfig::class)]
final class CorsConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreDisabled(): void
    {
        $config = new CorsConfig();

        self::assertFalse($config->enabled);
        self::assertSame([], $config->allowedOrigins);
        self::assertFalse($config->allowCredentials);
        self::assertSame(0, $config->maxAge);
    }

    #[Test]
    public function fromArrayParsesAllFields(): void
    {
        $config = CorsConfig::fromArray([
            'enabled' => true,
            'allowed_origins' => ['https://example.com', 'https://app.example.com'],
            'allowed_methods' => ['GET', 'POST'],
            'allowed_headers' => ['Content-Type'],
            'exposed_headers' => ['X-Request-Id'],
            'allow_credentials' => true,
            'max_age' => 3600,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(['https://example.com', 'https://app.example.com'], $config->allowedOrigins);
        self::assertSame(['GET', 'POST'], $config->allowedMethods);
        self::assertSame(['Content-Type'], $config->allowedHeaders);
        self::assertSame(['X-Request-Id'], $config->exposedHeaders);
        self::assertTrue($config->allowCredentials);
        self::assertSame(3600, $config->maxAge);
    }

    #[Test]
    public function fromArrayHandlesEmptyArray(): void
    {
        $config = CorsConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame([], $config->allowedOrigins);
        // HEAD is served wherever GET is (Route constructor normalization), so
        // the default CORS policy must not contradict the router.
        self::assertSame(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $config->allowedMethods);
    }

    #[Test]
    public function fromArrayParsesCommaSeparatedString(): void
    {
        $config = CorsConfig::fromArray([
            'enabled' => true,
            'allowed_origins' => 'https://a.com, https://b.com',
        ]);

        self::assertSame(['https://a.com', 'https://b.com'], $config->allowedOrigins);
    }

    #[Test]
    public function isOriginAllowedWithEmptyListDeniesAll(): void
    {
        $config = new CorsConfig(enabled: true, allowedOrigins: []);

        // Empty allowedOrigins means deny all — explicit origins required
        self::assertFalse($config->isOriginAllowed('https://anything.com'));
    }

    #[Test]
    public function isOriginAllowedWithWildcard(): void
    {
        $config = new CorsConfig(enabled: true, allowedOrigins: ['*']);

        self::assertTrue($config->isOriginAllowed('https://anything.com'));
    }

    #[Test]
    public function isOriginAllowedWithExplicitList(): void
    {
        $config = new CorsConfig(
            enabled: true,
            allowedOrigins: ['https://example.com', 'https://app.example.com'],
        );

        self::assertTrue($config->isOriginAllowed('https://example.com'));
        self::assertTrue($config->isOriginAllowed('https://app.example.com'));
        self::assertFalse($config->isOriginAllowed('https://evil.com'));
    }

    #[Test]
    public function fromArrayThrowsForWildcardWithCredentials(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageIsOrContains('allowedOrigins cannot be ["*"]');

        (void) CorsConfig::fromArray([
            'enabled' => true,
            'allowed_origins' => ['*'],
            'allow_credentials' => true,
        ]);
    }

    #[Test]
    public function fromArrayAllowsWildcardWithoutCredentials(): void
    {
        $config = CorsConfig::fromArray([
            'enabled' => true,
            'allowed_origins' => ['*'],
            'allow_credentials' => false,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(['*'], $config->allowedOrigins);
        self::assertFalse($config->allowCredentials);
    }

    #[Test]
    public function fromArrayAllowsCredentialsWithExplicitOrigins(): void
    {
        $config = CorsConfig::fromArray([
            'enabled' => true,
            'allowed_origins' => ['https://example.com'],
            'allow_credentials' => true,
        ]);

        self::assertTrue($config->enabled);
        self::assertTrue($config->allowCredentials);
        self::assertSame(['https://example.com'], $config->allowedOrigins);
    }

    #[Test]
    public function fromArraySkipsValidationWhenDisabled(): void
    {
        // When CORS is disabled, wildcard + credentials should not throw
        $config = CorsConfig::fromArray([
            'enabled' => false,
            'allowed_origins' => ['*'],
            'allow_credentials' => true,
        ]);

        self::assertFalse($config->enabled);
        self::assertSame(['*'], $config->allowedOrigins);
        self::assertTrue($config->allowCredentials);
    }
}
