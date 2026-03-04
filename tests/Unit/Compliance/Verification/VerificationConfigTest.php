<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Verification;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Verification\VerificationConfig;

#[CoversClass(VerificationConfig::class)]
final class VerificationConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new VerificationConfig();

        self::assertTrue($config->enabled);
        self::assertTrue($config->bootCheck);
        self::assertSame(3600, $config->evidenceIntervalSeconds);
        self::assertFalse($config->strictMode);
    }

    public function testFromArrayWithValues(): void
    {
        $config = VerificationConfig::fromArray([
            'enabled' => false,
            'boot_check' => false,
            'evidence_interval' => 1800,
            'strict_mode' => true,
        ]);

        self::assertFalse($config->enabled);
        self::assertFalse($config->bootCheck);
        self::assertSame(1800, $config->evidenceIntervalSeconds);
        self::assertTrue($config->strictMode);
    }

    public function testFromArrayWithDefaults(): void
    {
        $config = VerificationConfig::fromArray([]);

        self::assertTrue($config->enabled);
        self::assertTrue($config->bootCheck);
        self::assertSame(3600, $config->evidenceIntervalSeconds);
        self::assertFalse($config->strictMode);
    }

    public function testFromArrayIgnoresInvalidTypes(): void
    {
        $config = VerificationConfig::fromArray([
            'enabled' => 'yes',
            'boot_check' => 1,
            'evidence_interval' => 'fast',
            'strict_mode' => 'true',
        ]);

        self::assertTrue($config->enabled);
        self::assertTrue($config->bootCheck);
        self::assertSame(3600, $config->evidenceIntervalSeconds);
        self::assertFalse($config->strictMode);
    }
}
