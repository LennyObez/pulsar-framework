<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\FunnelStepType;

#[CoversNothing]
final class FunnelStepTypeTest extends TestCase
{
    #[Test]
    #[DataProvider('validValuesProvider')]
    public function fromStringReturnsCorrectCase(string $value, FunnelStepType $expected): void
    {
        self::assertSame($expected, FunnelStepType::from($value));
    }

    /** @return iterable<string, array{string, FunnelStepType}> */
    public static function validValuesProvider(): iterable
    {
        yield 'page_visit' => ['page_visit', FunnelStepType::PageVisit];
        yield 'custom_event' => ['custom_event', FunnelStepType::CustomEvent];
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        self::assertNull(FunnelStepType::tryFrom('invalid'));
    }

    #[Test]
    public function hasTwoCases(): void
    {
        self::assertCount(2, FunnelStepType::cases());
    }
}
