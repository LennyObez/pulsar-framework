<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\JustifiedAccess;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\JustifiedAccess\ReviewStatus;

#[CoversClass(ReviewStatus::class)]
final class ReviewStatusTest extends TestCase
{
    #[Test]
    public function hasFiveCases(): void
    {
        self::assertCount(5, ReviewStatus::cases());
    }

    #[Test]
    #[DataProvider('statusProvider')]
    public function backedValues(ReviewStatus $status, string $expected): void
    {
        self::assertSame($expected, $status->value);
    }

    /**
     * @return iterable<string, array{ReviewStatus, string}>
     */
    public static function statusProvider(): iterable
    {
        yield 'Pending' => [ReviewStatus::Pending, 'pending'];
        yield 'Approved' => [ReviewStatus::Approved, 'approved'];
        yield 'Flagged' => [ReviewStatus::Flagged, 'flagged'];
        yield 'Reviewed' => [ReviewStatus::Reviewed, 'reviewed'];
        yield 'Escalated' => [ReviewStatus::Escalated, 'escalated'];
    }

    #[Test]
    public function fromBackedValueRoundTrips(): void
    {
        foreach (ReviewStatus::cases() as $status) {
            self::assertSame($status, ReviewStatus::from($status->value));
        }
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        self::assertNull(ReviewStatus::tryFrom('dismissed'));
    }
}
