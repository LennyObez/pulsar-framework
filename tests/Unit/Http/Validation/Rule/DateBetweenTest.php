<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\DateBetween;

#[CoversClass(DateBetween::class)]
final class DateBetweenTest extends TestCase
{
    #[Test]
    public function dateWithinRangePasses(): void
    {
        $rule = new DateBetween('2024-01-01', '2024-12-31');
        self::assertNull($rule->validate('field', '2024-06-15', []));
    }

    #[Test]
    public function dateOnFromBoundaryPasses(): void
    {
        $rule = new DateBetween('2024-01-01', '2024-12-31');
        self::assertNull($rule->validate('field', '2024-01-01', []));
    }

    #[Test]
    public function dateOnToBoundaryPasses(): void
    {
        $rule = new DateBetween('2024-01-01', '2024-12-31');
        self::assertNull($rule->validate('field', '2024-12-31', []));
    }

    #[Test]
    public function dateBeforeRangeFails(): void
    {
        $rule = new DateBetween('2024-01-01', '2024-12-31');
        $violation = $rule->validate('field', '2023-12-31', []);
        self::assertNotNull($violation);
        self::assertSame('date_between', $violation->rule);
    }

    #[Test]
    public function dateAfterRangeFails(): void
    {
        $rule = new DateBetween('2024-01-01', '2024-12-31');
        $violation = $rule->validate('field', '2025-01-01', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new DateBetween('2024-01-01', '2024-12-31');
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new DateBetween('2024-01-01', '2024-12-31', message: 'Out of range');
        $violation = $rule->validate('field', '2025-06-01', []);
        self::assertNotNull($violation);
        self::assertSame('Out of range', $violation->message);
    }

    #[Test]
    public function nonStringFails(): void
    {
        $rule = new DateBetween('2024-01-01', '2024-12-31');
        $violation = $rule->validate('field', 12345, []);
        self::assertNotNull($violation);
    }
}
