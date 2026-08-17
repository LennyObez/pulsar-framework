<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\RuntimeType;
use ValueError;

#[CoversNothing]
final class RuntimeTypeTest extends TestCase
{
    #[Test]
    public function it_has_all_expected_cases(): void
    {
        self::assertSame('fpm', RuntimeType::Fpm->value);
        self::assertSame('persistent', RuntimeType::Persistent->value);
        self::assertSame('frankenphp', RuntimeType::FrankenPhp->value);
        self::assertSame('roadrunner', RuntimeType::RoadRunner->value);
    }

    #[Test]
    public function it_creates_from_valid_string_value(): void
    {
        self::assertSame(RuntimeType::Fpm, RuntimeType::from('fpm'));
        self::assertSame(RuntimeType::Persistent, RuntimeType::from('persistent'));
        self::assertSame(RuntimeType::FrankenPhp, RuntimeType::from('frankenphp'));
        self::assertSame(RuntimeType::RoadRunner, RuntimeType::from('roadrunner'));
    }

    #[Test]
    public function it_throws_for_invalid_string_value(): void
    {
        $this->expectException(ValueError::class);

        RuntimeType::from('invalid');
    }

    #[Test]
    public function try_from_returns_null_for_invalid_value(): void
    {
        self::assertNull(RuntimeType::tryFrom('invalid'));
    }

    #[Test]
    public function it_has_four_cases(): void
    {
        self::assertCount(4, RuntimeType::cases());
    }
}
