<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\WebhookConfig;

final class WebhookConfigTest extends TestCase
{
    #[Test]
    public function fromArrayUsesProvidedValues(): void
    {
        $config = WebhookConfig::fromArray([
            'secret' => 'whsec_abc',
            'path' => '/hooks',
            'tolerance_seconds' => 600,
            'signature_header' => 'X-Custom-Sig',
        ]);

        self::assertSame('whsec_abc', $config->secret);
        self::assertSame('/hooks', $config->path);
        self::assertSame(600, $config->toleranceSeconds);
        self::assertSame('X-Custom-Sig', $config->signatureHeader);
    }

    #[Test]
    public function fromArrayAppliesDefaults(): void
    {
        $config = WebhookConfig::fromArray([]);

        self::assertSame('', $config->secret);
        self::assertSame('/webhooks/payments', $config->path);
        self::assertSame(300, $config->toleranceSeconds);
        self::assertSame('X-Payments-Signature', $config->signatureHeader);
    }
}
