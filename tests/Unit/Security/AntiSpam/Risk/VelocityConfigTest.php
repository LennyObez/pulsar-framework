<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\Risk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\Risk\VelocityConfig;

#[CoversClass(VelocityConfig::class)]
final class VelocityConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreDisabled(): void
    {
        $config = new VelocityConfig();

        self::assertFalse($config->enabled);
        self::assertSame(120, $config->threshold);
        self::assertSame(60, $config->windowSeconds);
        self::assertSame(0.7, $config->maxScore);
    }

    #[Test]
    public function fromArrayParsesEveryField(): void
    {
        $config = VelocityConfig::fromArray([
            'enabled' => true,
            'threshold' => '30',
            'window_seconds' => '10',
            'max_score' => '0.85',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(30, $config->threshold);
        self::assertSame(10, $config->windowSeconds);
        self::assertSame(0.85, $config->maxScore);
    }
}
