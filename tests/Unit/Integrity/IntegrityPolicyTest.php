<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Integrity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\IntegrityConfig;
use Pulsar\Config\IntegrityPolicyMode;
use Pulsar\Integrity\IntegrityPolicy;
use ReflectionClass;

#[CoversClass(IntegrityPolicy::class)]
final class IntegrityPolicyTest extends TestCase
{
    #[Test]
    public function it_constructs_with_warn_mode(): void
    {
        $policy = new IntegrityPolicy(mode: IntegrityPolicyMode::Warn);

        self::assertSame(IntegrityPolicyMode::Warn, $policy->mode);
    }

    #[Test]
    public function it_constructs_with_strict_mode(): void
    {
        $policy = new IntegrityPolicy(mode: IntegrityPolicyMode::Strict);

        self::assertSame(IntegrityPolicyMode::Strict, $policy->mode);
    }

    #[Test]
    public function it_creates_from_config_with_warn_mode(): void
    {
        $config = new IntegrityConfig(
            enabled: true,
            mode: IntegrityPolicyMode::Warn,
        );

        $policy = IntegrityPolicy::fromConfig($config);

        self::assertSame(IntegrityPolicyMode::Warn, $policy->mode);
    }

    #[Test]
    public function it_creates_from_config_with_strict_mode(): void
    {
        $config = new IntegrityConfig(
            enabled: true,
            mode: IntegrityPolicyMode::Strict,
        );

        $policy = IntegrityPolicy::fromConfig($config);

        self::assertSame(IntegrityPolicyMode::Strict, $policy->mode);
    }

    #[Test]
    public function it_creates_from_default_config(): void
    {
        $config = new IntegrityConfig();

        $policy = IntegrityPolicy::fromConfig($config);

        self::assertSame(IntegrityPolicyMode::Warn, $policy->mode);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $policy = new IntegrityPolicy(mode: IntegrityPolicyMode::Warn);

        $reflection = new ReflectionClass($policy);
        self::assertTrue($reflection->isReadOnly());
    }
}
