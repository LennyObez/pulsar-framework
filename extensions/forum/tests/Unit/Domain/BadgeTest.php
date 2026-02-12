<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\Badge;

final class BadgeTest extends TestCase
{
    #[Test]
    public function hasEightCases(): void
    {
        self::assertCount(8, Badge::cases());
    }

    #[Test]
    public function labelReturnsHumanReadable(): void
    {
        self::assertSame('First Post', Badge::FirstPost->label());
        self::assertSame('First Answer', Badge::FirstAnswer->label());
        self::assertSame('Helpful', Badge::Helpful->label());
        self::assertSame('Popular Thread', Badge::PopularThread->label());
        self::assertSame('Solver', Badge::Solver->label());
        self::assertSame('Bug Hunter', Badge::BugHunter->label());
        self::assertSame('Contributor', Badge::Contributor->label());
        self::assertSame('Multilingual', Badge::Multilingual->label());
    }

    #[Test]
    public function descriptionReturnsNonEmptyString(): void
    {
        foreach (Badge::cases() as $badge) {
            self::assertNotEmpty($badge->description(), "Badge {$badge->value} should have a description");
        }
    }

    #[Test]
    public function backingValues(): void
    {
        self::assertSame('first_post', Badge::FirstPost->value);
        self::assertSame('first_answer', Badge::FirstAnswer->value);
        self::assertSame('helpful', Badge::Helpful->value);
        self::assertSame('popular_thread', Badge::PopularThread->value);
        self::assertSame('solver', Badge::Solver->value);
        self::assertSame('bug_hunter', Badge::BugHunter->value);
        self::assertSame('contributor', Badge::Contributor->value);
        self::assertSame('multilingual', Badge::Multilingual->value);
    }
}
