<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\PayconiqConfig;

final class PayconiqConfigTest extends TestCase
{
    #[Test]
    public function fromArrayUsesProvidedValues(): void
    {
        $config = PayconiqConfig::fromArray([
            'merchant_id' => 'merch_123',
            'api_key' => 'key_456',
            'webhook_secret' => 'whsec_789',
            'environment' => 'prod',
            'enabled' => true,
            'callback_url' => 'https://example.com/callback',
            'payment_expiry_seconds' => 600,
        ]);

        self::assertSame('merch_123', $config->merchantId);
        self::assertSame('key_456', $config->apiKey);
        self::assertSame('whsec_789', $config->webhookSecret);
        self::assertSame('prod', $config->environment);
        self::assertTrue($config->enabled);
        self::assertSame('https://example.com/callback', $config->callbackUrl);
        self::assertSame(600, $config->paymentExpirySeconds);
    }

    #[Test]
    public function fromArrayAppliesDefaults(): void
    {
        $config = PayconiqConfig::fromArray([]);

        self::assertSame('', $config->merchantId);
        self::assertSame('', $config->apiKey);
        self::assertSame('', $config->webhookSecret);
        self::assertSame('ext', $config->environment);
        self::assertFalse($config->enabled);
        self::assertSame('', $config->callbackUrl);
        self::assertSame(900, $config->paymentExpirySeconds);
    }

    #[Test]
    #[DataProvider('apiBaseUrlProvider')]
    public function apiBaseUrlResolvesCorrectly(string $environment, string $expectedUrl): void
    {
        $config = PayconiqConfig::fromArray(['environment' => $environment]);

        self::assertSame($expectedUrl, $config->apiBaseUrl());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function apiBaseUrlProvider(): iterable
    {
        yield 'production environment' => ['prod', 'https://api.payconiq.com/v3'];
        yield 'production long name' => ['production', 'https://api.payconiq.com/v3'];
        yield 'ext environment' => ['ext', 'https://api.ext.payconiq.com/v3'];
        yield 'test environment falls back to ext' => ['test', 'https://api.ext.payconiq.com/v3'];
        yield 'empty environment falls back to ext' => ['', 'https://api.ext.payconiq.com/v3'];
    }

    #[Test]
    public function fromArrayHandlesNonStringValues(): void
    {
        $config = PayconiqConfig::fromArray([
            'merchant_id' => 12345,
            'api_key' => null,
            'enabled' => 1,
        ]);

        self::assertSame('', $config->merchantId);
        self::assertSame('', $config->apiKey);
        self::assertTrue($config->enabled);
    }
}
