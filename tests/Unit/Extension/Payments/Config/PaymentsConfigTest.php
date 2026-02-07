<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\IdempotencyConfig;
use Pulsar\Extension\Payments\Config\PaymentsConfig;
use Pulsar\Extension\Payments\Config\WebhookConfig;
use Pulsar\Extension\Payments\Config\WebhookLogConfig;

#[CoversClass(PaymentsConfig::class)]
#[CoversClass(WebhookConfig::class)]
#[CoversClass(IdempotencyConfig::class)]
#[CoversClass(WebhookLogConfig::class)]
final class PaymentsConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithDefaults(): void
    {
        $config = PaymentsConfig::fromArray([]);

        self::assertSame('null', $config->provider);
        self::assertSame('USD', $config->defaultCurrency);

        // Webhook defaults
        self::assertSame('', $config->webhook->secret);
        self::assertSame('/webhooks/payments', $config->webhook->path);
        self::assertSame(300, $config->webhook->toleranceSeconds);
        self::assertSame('X-Payments-Signature', $config->webhook->signatureHeader);

        // Idempotency defaults
        self::assertSame(86400, $config->idempotency->ttlSeconds);
        self::assertSame('memory', $config->idempotency->store);
        self::assertSame(256, $config->idempotency->maxKeyLength);

        // Webhook log defaults
        self::assertSame(259200, $config->webhookLog->ttlSeconds);
        self::assertSame('memory', $config->webhookLog->store);
    }

    #[Test]
    public function fromArrayWithOverrides(): void
    {
        $config = PaymentsConfig::fromArray([
            'provider' => 'simulator',
            'default_currency' => 'EUR',
            'webhook' => [
                'secret' => 'my-secret',
                'path' => '/hooks/pay',
                'tolerance_seconds' => 600,
                'signature_header' => 'X-Custom-Sig',
            ],
            'idempotency' => [
                'ttl_seconds' => 1800,
                'store' => 'redis',
                'max_key_length' => 128,
            ],
            'webhook_log' => [
                'ttl_seconds' => 86400,
                'store' => 'database',
            ],
        ]);

        self::assertSame('simulator', $config->provider);
        self::assertSame('EUR', $config->defaultCurrency);
        self::assertSame('my-secret', $config->webhook->secret);
        self::assertSame('/hooks/pay', $config->webhook->path);
        self::assertSame(600, $config->webhook->toleranceSeconds);
        self::assertSame('X-Custom-Sig', $config->webhook->signatureHeader);
        self::assertSame(1800, $config->idempotency->ttlSeconds);
        self::assertSame('redis', $config->idempotency->store);
        self::assertSame(128, $config->idempotency->maxKeyLength);
        self::assertSame(86400, $config->webhookLog->ttlSeconds);
        self::assertSame('database', $config->webhookLog->store);
    }

    #[Test]
    public function webhookConfigFromArray(): void
    {
        $config = WebhookConfig::fromArray([
            'secret' => 'sec',
            'path' => '/wh',
        ]);

        self::assertSame('sec', $config->secret);
        self::assertSame('/wh', $config->path);
        self::assertSame(300, $config->toleranceSeconds);
    }

    #[Test]
    public function idempotencyConfigFromArray(): void
    {
        $config = IdempotencyConfig::fromArray([
            'ttl_seconds' => 900,
        ]);

        self::assertSame(900, $config->ttlSeconds);
        self::assertSame('memory', $config->store);
        self::assertSame(256, $config->maxKeyLength);
    }

    #[Test]
    public function webhookLogConfigFromArray(): void
    {
        $config = WebhookLogConfig::fromArray([]);

        self::assertSame(259200, $config->ttlSeconds);
        self::assertSame('memory', $config->store);
    }
}
