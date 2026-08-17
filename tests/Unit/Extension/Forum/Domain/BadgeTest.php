<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Forum\Domain;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Forum\Domain\Badge;

#[CoversNothing]
final class BadgeTest extends TestCase
{
    #[Test]
    public function labelsAreHumanReadable(): void
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
    public function descriptionsAreNotEmpty(): void
    {
        foreach (Badge::cases() as $badge) {
            self::assertNotEmpty($badge->description(), "Badge {$badge->value} should have a description");
        }
    }

    #[Test]
    public function specificDescriptions(): void
    {
        self::assertSame('Created your first forum post', Badge::FirstPost->description());
        self::assertSame('Had an answer marked as the solution', Badge::Solver->description());
        self::assertSame('Reported a confirmed bug', Badge::BugHunter->description());
    }

    #[Test]
    public function backingValues(): void
    {
        self::assertSame('first_post', Badge::FirstPost->value);
        self::assertSame('helpful', Badge::Helpful->value);
        self::assertSame('solver', Badge::Solver->value);
        self::assertSame('bug_hunter', Badge::BugHunter->value);
    }

    #[Test]
    public function totalCaseCount(): void
    {
        self::assertCount(8, Badge::cases());
    }
}
