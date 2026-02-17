<?php

declare(strict_types=1);

namespace Pulsar\Extension\Feedback\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Feedback\FeedbackCategory;

final class FeedbackCategoryTest extends TestCase
{
    #[Test]
    #[DataProvider('allCasesProvider')]
    public function allCasesHaveStringValues(FeedbackCategory $case, string $expected): void
    {
        self::assertSame($expected, $case->value);
    }

    /**
     * @return iterable<string, array{FeedbackCategory, string}>
     */
    public static function allCasesProvider(): iterable
    {
        yield 'Bug' => [FeedbackCategory::Bug, 'bug'];
        yield 'Feature' => [FeedbackCategory::Feature, 'feature'];
        yield 'Improvement' => [FeedbackCategory::Improvement, 'improvement'];
        yield 'Question' => [FeedbackCategory::Question, 'question'];
        yield 'Other' => [FeedbackCategory::Other, 'other'];
    }

    #[Test]
    public function fromStringBackedValue(): void
    {
        self::assertSame(FeedbackCategory::Bug, FeedbackCategory::from('bug'));
        self::assertSame(FeedbackCategory::Feature, FeedbackCategory::from('feature'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(FeedbackCategory::tryFrom('nonexistent'));
    }

    #[Test]
    public function casesReturnsAllFiveValues(): void
    {
        self::assertCount(5, FeedbackCategory::cases());
    }
}
