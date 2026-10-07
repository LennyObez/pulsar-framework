<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Config\SepaConfig;

final class SepaConfigTest extends TestCase
{
    #[Test]
    public function fromArrayParsesAllFields(): void
    {
        $config = SepaConfig::fromArray([
            'creditor_id' => 'BE68ZZZ0123456789',
            'creditor_name' => 'Acme Corp',
            'creditor_iban' => 'BE71096123456769',
            'creditor_bic' => 'KREDBEBB',
            'pre_notification_days' => 7,
            'enabled' => true,
        ]);

        self::assertSame('BE68ZZZ0123456789', $config->creditorId);
        self::assertSame('Acme Corp', $config->creditorName);
        self::assertSame('BE71096123456769', $config->creditorIban);
        self::assertSame('KREDBEBB', $config->creditorBic);
        self::assertSame(7, $config->preNotificationDays);
        self::assertTrue($config->enabled);
    }

    #[Test]
    public function fromArrayUsesDefaults(): void
    {
        $config = SepaConfig::fromArray([]);

        self::assertSame('', $config->creditorId);
        self::assertSame('', $config->creditorName);
        self::assertSame('', $config->creditorIban);
        self::assertSame('', $config->creditorBic);
        self::assertSame(14, $config->preNotificationDays);
        self::assertFalse($config->enabled);
    }
}
