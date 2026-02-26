<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ThreadType;

#[CoversClass(ThreadType::class)]
final class ThreadTypeTest extends TestCase
{
    #[Test]
    public function allTypesHaveExpectedStringValues(): void
    {
        self::assertSame('discussion', ThreadType::Discussion->value);
        self::assertSame('question', ThreadType::Question->value);
        self::assertSame('bug_report', ThreadType::BugReport->value);
        self::assertSame('feature_request', ThreadType::FeatureRequest->value);
        self::assertSame('showcase', ThreadType::Showcase->value);
        self::assertSame('announcement', ThreadType::Announcement->value);
    }

    #[Test]
    #[DataProvider('labelProvider')]
    public function labelReturnsHumanReadableString(ThreadType $type, string $expected): void
    {
        self::assertSame($expected, $type->label());
    }

    /**
     * @return iterable<string, array{ThreadType, string}>
     */
    public static function labelProvider(): iterable
    {
        yield 'Discussion' => [ThreadType::Discussion, 'Discussion'];
        yield 'Question' => [ThreadType::Question, 'Question'];
        yield 'BugReport' => [ThreadType::BugReport, 'Bug Report'];
        yield 'FeatureRequest' => [ThreadType::FeatureRequest, 'Feature Request'];
        yield 'Showcase' => [ThreadType::Showcase, 'Showcase'];
        yield 'Announcement' => [ThreadType::Announcement, 'Announcement'];
    }

    #[Test]
    public function supportsSolutionOnlyForQuestionAndBugReport(): void
    {
        self::assertTrue(ThreadType::Question->supportsSolution());
        self::assertTrue(ThreadType::BugReport->supportsSolution());
        self::assertFalse(ThreadType::Discussion->supportsSolution());
        self::assertFalse(ThreadType::FeatureRequest->supportsSolution());
        self::assertFalse(ThreadType::Showcase->supportsSolution());
        self::assertFalse(ThreadType::Announcement->supportsSolution());
    }

    #[Test]
    public function fromValidValue(): void
    {
        self::assertSame(ThreadType::Question, ThreadType::from('question'));
    }

    #[Test]
    public function tryFromInvalidValueReturnsNull(): void
    {
        self::assertNull(ThreadType::tryFrom('nonexistent'));
    }
}
