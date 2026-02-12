<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\PaymentsConfig;

final class PaymentsConfigTest extends TestCase
{
    #[Test]
    public function fromArrayUsesProvidedValues(): void
    {
        $config = PaymentsConfig::fromArray([
            'provider' => 'stripe',
            'default_currency' => 'EUR',
            'webhook' => ['secret' => 'whsec_test', 'path' => '/hooks/pay'],
            'idempotency' => ['ttl_seconds' => 3600, 'store' => 'redis'],
            'webhook_log' => ['ttl_seconds' => 86400],
        ]);

        self::assertSame('stripe', $config->provider);
        self::assertSame('EUR', $config->defaultCurrency);
        self::assertSame('whsec_test', $config->webhook->secret);
        self::assertSame('/hooks/pay', $config->webhook->path);
        self::assertSame(3600, $config->idempotency->ttlSeconds);
        self::assertSame('redis', $config->idempotency->store);
        self::assertSame(86400, $config->webhookLog->ttlSeconds);
    }

    #[Test]
    public function fromArrayAppliesDefaults(): void
    {
        $config = PaymentsConfig::fromArray([]);

        self::assertSame('null', $config->provider);
        self::assertSame('USD', $config->defaultCurrency);
        self::assertSame('', $config->webhook->secret);
        self::assertSame('/webhooks/payments', $config->webhook->path);
        self::assertSame(86400, $config->idempotency->ttlSeconds);
        self::assertSame('memory', $config->idempotency->store);
    }
}
