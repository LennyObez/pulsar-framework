<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ControlStatus;

#[CoversNothing]
final class ControlStatusTest extends TestCase
{
    #[Test]
    public function hasFourCases(): void
    {
        self::assertCount(4, ControlStatus::cases());
    }

    #[Test]
    #[DataProvider('statusProvider')]
    public function backedValues(ControlStatus $status, string $expected): void
    {
        self::assertSame($expected, $status->value);
    }

    /**
     * @return iterable<string, array{ControlStatus, string}>
     */
    public static function statusProvider(): iterable
    {
        yield 'Implemented' => [ControlStatus::Implemented, 'implemented'];
        yield 'Partial' => [ControlStatus::Partial, 'partial'];
        yield 'Planned' => [ControlStatus::Planned, 'planned'];
        yield 'NotApplicable' => [ControlStatus::NotApplicable, 'not_applicable'];
    }

    #[Test]
    public function fromBackedValueRoundTrips(): void
    {
        foreach (ControlStatus::cases() as $status) {
            self::assertSame($status, ControlStatus::from($status->value));
        }
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(ControlStatus::tryFrom('deprecated'));
    }
}
