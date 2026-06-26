<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\Risk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\Risk\AdaptiveRiskConfig;

#[CoversClass(AdaptiveRiskConfig::class)]
final class AdaptiveRiskConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreOptIn(): void
    {
        $config = AdaptiveRiskConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame(0.5, $config->challengeThreshold);
        self::assertSame(0.9, $config->blockThreshold);
    }

    #[Test]
    public function parsesCustomThresholds(): void
    {
        $config = AdaptiveRiskConfig::fromArray([
            'enabled' => true,
            'challenge_threshold' => 0.4,
            'block_threshold' => 0.85,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame(0.4, $config->challengeThreshold);
        self::assertSame(0.85, $config->blockThreshold);
    }
}
