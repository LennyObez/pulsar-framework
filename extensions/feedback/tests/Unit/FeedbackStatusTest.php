<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Feedback\FeedbackStatus;

final class FeedbackStatusTest extends TestCase
{
    #[Test]
    #[DataProvider('allCasesProvider')]
    public function allCasesHaveStringValues(FeedbackStatus $case, string $expected): void
    {
        self::assertSame($expected, $case->value);
    }

    /**
     * @return iterable<string, array{FeedbackStatus, string}>
     */
    public static function allCasesProvider(): iterable
    {
        yield 'Received' => [FeedbackStatus::Received, 'received'];
        yield 'Investigating' => [FeedbackStatus::Investigating, 'investigating'];
        yield 'Resolved' => [FeedbackStatus::Resolved, 'resolved'];
        yield 'WontFix' => [FeedbackStatus::WontFix, 'wont_fix'];
        yield 'Duplicate' => [FeedbackStatus::Duplicate, 'duplicate'];
    }

    #[Test]
    public function fromStringBackedValue(): void
    {
        self::assertSame(FeedbackStatus::Received, FeedbackStatus::from('received'));
        self::assertSame(FeedbackStatus::WontFix, FeedbackStatus::from('wont_fix'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(FeedbackStatus::tryFrom('unknown'));
    }

    #[Test]
    public function casesReturnsAllFiveValues(): void
    {
        self::assertCount(5, FeedbackStatus::cases());
    }
}
