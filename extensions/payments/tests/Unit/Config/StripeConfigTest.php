<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\StripeConfig;

final class StripeConfigTest extends TestCase
{
    #[Test]
    public function fromArrayParsesAllFields(): void
    {
        $config = StripeConfig::fromArray([
            'secret_key' => 'sk_test_123',
            'publishable_key' => 'pk_test_123',
            'webhook_secret' => 'whsec_test',
            'api_version' => '2025-01-01.beta',
            'test_mode' => false,
        ]);

        self::assertSame('sk_test_123', $config->secretKey);
        self::assertSame('pk_test_123', $config->publishableKey);
        self::assertSame('whsec_test', $config->webhookSecret);
        self::assertSame('2025-01-01.beta', $config->apiVersion);
        self::assertFalse($config->testMode);
    }

    #[Test]
    public function fromArrayUsesDefaults(): void
    {
        $config = StripeConfig::fromArray([]);

        self::assertSame('', $config->secretKey);
        self::assertSame('', $config->publishableKey);
        self::assertSame('', $config->webhookSecret);
        self::assertSame('2024-12-18.acacia', $config->apiVersion);
        self::assertTrue($config->testMode);
    }
}
