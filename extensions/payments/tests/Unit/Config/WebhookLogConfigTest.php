<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\WebhookLogConfig;

final class WebhookLogConfigTest extends TestCase
{
    #[Test]
    public function fromArrayUsesProvidedValues(): void
    {
        $config = WebhookLogConfig::fromArray([
            'ttl_seconds' => 86400,
            'store' => 'redis',
        ]);

        self::assertSame(86400, $config->ttlSeconds);
        self::assertSame('redis', $config->store);
    }

    #[Test]
    public function fromArrayAppliesDefaults(): void
    {
        $config = WebhookLogConfig::fromArray([]);

        self::assertSame(259200, $config->ttlSeconds);
        self::assertSame('memory', $config->store);
    }
}
