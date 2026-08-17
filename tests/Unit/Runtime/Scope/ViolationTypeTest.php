<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Scope;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\Scope\ViolationType;

#[CoversNothing]
final class ViolationTypeTest extends TestCase
{
    #[Test]
    public function writable_property_case(): void
    {
        $type = ViolationType::WritableProperty;

        self::assertSame('writable_property', $type->value);
    }

    #[Test]
    public function mutable_static_case(): void
    {
        $type = ViolationType::MutableStatic;

        self::assertSame('mutable_static', $type->value);
    }

    #[Test]
    public function reset_method_case(): void
    {
        $type = ViolationType::ResetMethod;

        self::assertSame('reset_method', $type->value);
    }

    #[Test]
    public function all_cases_are_covered(): void
    {
        $cases = ViolationType::cases();

        self::assertCount(3, $cases);
    }

    #[Test]
    public function from_valid_string(): void
    {
        self::assertSame(ViolationType::WritableProperty, ViolationType::from('writable_property'));
        self::assertSame(ViolationType::MutableStatic, ViolationType::from('mutable_static'));
        self::assertSame(ViolationType::ResetMethod, ViolationType::from('reset_method'));
    }

    #[Test]
    public function try_from_invalid_string_returns_null(): void
    {
        self::assertNull(ViolationType::tryFrom('nonexistent'));
    }
}
