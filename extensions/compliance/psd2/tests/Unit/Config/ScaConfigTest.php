<?php

declare(strict_types=1);

namespace Pulsar\Extension\Psd2\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Psd2\Config\ScaConfig;

#[CoversClass(ScaConfig::class)]
final class ScaConfigTest extends TestCase
{
    #[Test]
    public function defaults(): void
    {
        $config = new ScaConfig();

        self::assertSame(300, $config->challengeTimeoutSeconds);
        self::assertSame('memory', $config->challengeStore);
        self::assertSame(8, $config->codeLength);
    }

    #[Test]
    public function fromArrayWithFullData(): void
    {
        $config = ScaConfig::fromArray([
            'challenge_timeout_seconds' => 600,
            'challenge_store' => 'redis',
            'code_length' => 6,
        ]);

        self::assertSame(600, $config->challengeTimeoutSeconds);
        self::assertSame('redis', $config->challengeStore);
        self::assertSame(6, $config->codeLength);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = ScaConfig::fromArray([]);

        self::assertSame(300, $config->challengeTimeoutSeconds);
        self::assertSame('memory', $config->challengeStore);
        self::assertSame(8, $config->codeLength);
    }

    #[Test]
    public function fromArrayIgnoresWrongTypes(): void
    {
        $config = ScaConfig::fromArray([
            'challenge_timeout_seconds' => 'not-int',
            'challenge_store' => 42,
            'code_length' => 'eight',
        ]);

        self::assertSame(300, $config->challengeTimeoutSeconds);
        self::assertSame('memory', $config->challengeStore);
        self::assertSame(8, $config->codeLength);
    }
}
