<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\After;

#[CoversClass(After::class)]
final class AfterTest extends TestCase
{
    #[Test]
    public function dateAfterBoundaryPasses(): void
    {
        $rule = new After('2024-01-01');
        self::assertNull($rule->validate('field', '2024-06-01', []));
    }

    #[Test]
    public function dateBeforeBoundaryFails(): void
    {
        $rule = new After('2024-06-01');
        $violation = $rule->validate('field', '2024-01-01', []);
        self::assertNotNull($violation);
        self::assertSame('after', $violation->rule);
    }

    #[Test]
    public function dateEqualToBoundaryFails(): void
    {
        $rule = new After('2024-06-01');
        $violation = $rule->validate('field', '2024-06-01', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function dateTimeInterfaceBoundary(): void
    {
        $boundary = new DateTimeImmutable('2024-01-01');
        $rule = new After($boundary);
        self::assertNull($rule->validate('field', '2024-06-01', []));
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new After('2024-01-01');
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new After('2024-01-01', message: 'Too early');
        $violation = $rule->validate('field', '2023-01-01', []);
        self::assertNotNull($violation);
        self::assertSame('Too early', $violation->message);
    }

    #[Test]
    public function nonStringFails(): void
    {
        $rule = new After('2024-01-01');
        $violation = $rule->validate('field', 12345, []);
        self::assertNotNull($violation);
    }
}
