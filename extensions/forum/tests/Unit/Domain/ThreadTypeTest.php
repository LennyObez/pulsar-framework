<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\ThreadType;

final class ThreadTypeTest extends TestCase
{
    #[Test]
    public function labelReturnsHumanReadable(): void
    {
        self::assertSame('Discussion', ThreadType::Discussion->label());
        self::assertSame('Question', ThreadType::Question->label());
        self::assertSame('Bug Report', ThreadType::BugReport->label());
        self::assertSame('Feature Request', ThreadType::FeatureRequest->label());
        self::assertSame('Showcase', ThreadType::Showcase->label());
        self::assertSame('Announcement', ThreadType::Announcement->label());
    }

    #[Test]
    public function questionAndBugReportSupportSolution(): void
    {
        self::assertTrue(ThreadType::Question->supportsSolution());
        self::assertTrue(ThreadType::BugReport->supportsSolution());
    }

    #[Test]
    public function otherTypesDoNotSupportSolution(): void
    {
        self::assertFalse(ThreadType::Discussion->supportsSolution());
        self::assertFalse(ThreadType::FeatureRequest->supportsSolution());
        self::assertFalse(ThreadType::Showcase->supportsSolution());
        self::assertFalse(ThreadType::Announcement->supportsSolution());
    }

    #[Test]
    public function backingValues(): void
    {
        self::assertSame('discussion', ThreadType::Discussion->value);
        self::assertSame('question', ThreadType::Question->value);
        self::assertSame('bug_report', ThreadType::BugReport->value);
        self::assertSame('feature_request', ThreadType::FeatureRequest->value);
        self::assertSame('showcase', ThreadType::Showcase->value);
        self::assertSame('announcement', ThreadType::Announcement->value);
    }
}
