<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AntiSpamCheckResult;
use Pulsar\Security\AntiSpam\AntiSpamResult;

#[CoversClass(AntiSpamResult::class)]
final class AntiSpamResultTest extends TestCase
{
    #[Test]
    public function allPassedReturnsPassingResult(): void
    {
        $result = AntiSpamResult::fromCheckResults([
            AntiSpamCheckResult::pass('honeypot'),
            AntiSpamCheckResult::pass('link_density'),
        ]);

        self::assertTrue($result->passed);
        self::assertSame(0, $result->score);
        self::assertSame([], $result->failedChecks());
    }

    #[Test]
    public function anyFailureReturnsFailingResult(): void
    {
        $result = AntiSpamResult::fromCheckResults([
            AntiSpamCheckResult::pass('honeypot'),
            AntiSpamCheckResult::fail('link_density', 30, 'Too many links'),
        ]);

        self::assertFalse($result->passed);
        self::assertSame(30, $result->score);
        self::assertSame(['link_density'], $result->failedChecks());
    }

    #[Test]
    public function multipleFailuresAggregateScores(): void
    {
        $result = AntiSpamResult::fromCheckResults([
            AntiSpamCheckResult::fail('honeypot', 50, 'Bot detected'),
            AntiSpamCheckResult::fail('link_density', 30, 'Link spam'),
            AntiSpamCheckResult::pass('duplicate'),
        ]);

        self::assertFalse($result->passed);
        self::assertSame(80, $result->score);
        self::assertSame(['honeypot', 'link_density'], $result->failedChecks());
    }

    #[Test]
    public function scoreIsCappedAt100(): void
    {
        $result = AntiSpamResult::fromCheckResults([
            AntiSpamCheckResult::fail('a', 60, 'r1'),
            AntiSpamCheckResult::fail('b', 60, 'r2'),
        ]);

        self::assertSame(100, $result->score);
    }

    #[Test]
    public function emptyCheckResultsReturnsPassing(): void
    {
        $result = AntiSpamResult::fromCheckResults([]);

        self::assertTrue($result->passed);
        self::assertSame(0, $result->score);
        self::assertSame([], $result->failedChecks());
    }
}
