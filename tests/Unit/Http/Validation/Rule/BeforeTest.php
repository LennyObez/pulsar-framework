<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Validation\Rule;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Validation\Rule\Before;

#[CoversClass(Before::class)]
final class BeforeTest extends TestCase
{
    #[Test]
    public function dateBeforeBoundaryPasses(): void
    {
        $rule = new Before('2024-06-01');
        self::assertNull($rule->validate('field', '2024-01-15', []));
    }

    #[Test]
    public function dateAfterBoundaryFails(): void
    {
        $rule = new Before('2024-06-01');
        $violation = $rule->validate('field', '2024-12-01', []);
        self::assertNotNull($violation);
        self::assertSame('before', $violation->rule);
    }

    #[Test]
    public function dateEqualToBoundaryFails(): void
    {
        $rule = new Before('2024-06-01');
        $violation = $rule->validate('field', '2024-06-01', []);
        self::assertNotNull($violation);
    }

    #[Test]
    public function dateTimeInterfaceBoundary(): void
    {
        $boundary = new DateTimeImmutable('2024-06-01');
        $rule = new Before($boundary);
        self::assertNull($rule->validate('field', '2024-01-01', []));
    }

    #[Test]
    public function nullSkips(): void
    {
        $rule = new Before('2024-06-01');
        self::assertNull($rule->validate('field', null, []));
    }

    #[Test]
    public function customMessageUsed(): void
    {
        $rule = new Before('2024-06-01', message: 'Too late');
        $violation = $rule->validate('field', '2024-12-01', []);
        self::assertNotNull($violation);
        self::assertSame('Too late', $violation->message);
    }

    #[Test]
    public function nonStringFails(): void
    {
        $rule = new Before('2024-06-01');
        $violation = $rule->validate('field', 12345, []);
        self::assertNotNull($violation);
    }
}
