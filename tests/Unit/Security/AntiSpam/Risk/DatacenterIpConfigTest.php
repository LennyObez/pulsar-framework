<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\Risk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\Risk\DatacenterIpConfig;

#[CoversClass(DatacenterIpConfig::class)]
final class DatacenterIpConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreDisabled(): void
    {
        $config = new DatacenterIpConfig();

        self::assertFalse($config->enabled);
        self::assertSame([], $config->ranges);
        self::assertSame(0.5, $config->score);
    }

    #[Test]
    public function fromArrayParsesAndFiltersRanges(): void
    {
        /** @psalm-suppress InvalidArgument intentionally malformed config */
        $config = DatacenterIpConfig::fromArray([
            'enabled' => true,
            'ranges' => ['203.0.113.0/24', 42, null, '2001:db8::/32'],
            'score' => '0.65',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(['203.0.113.0/24', '2001:db8::/32'], $config->ranges);
        self::assertSame(0.65, $config->score);
    }
}
