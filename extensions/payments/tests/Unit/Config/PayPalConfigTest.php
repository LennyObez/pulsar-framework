<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\PayPalConfig;

final class PayPalConfigTest extends TestCase
{
    #[Test]
    public function fromArrayParsesAllFields(): void
    {
        $config = PayPalConfig::fromArray([
            'client_id' => 'ppid-123',
            'client_secret' => 'ppsecret-456',
            'webhook_id' => 'wh-789',
            'sandbox' => false,
        ]);

        self::assertSame('ppid-123', $config->clientId);
        self::assertSame('ppsecret-456', $config->clientSecret);
        self::assertSame('wh-789', $config->webhookId);
        self::assertFalse($config->sandbox);
    }

    #[Test]
    public function fromArrayUsesDefaults(): void
    {
        $config = PayPalConfig::fromArray([]);

        self::assertSame('', $config->clientId);
        self::assertSame('', $config->clientSecret);
        self::assertSame('', $config->webhookId);
        self::assertTrue($config->sandbox);
    }
}
