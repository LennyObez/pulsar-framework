<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\AntiSpamCheckResult;
use Pulsar\Security\AntiSpam\AntiSpamResult;
use Pulsar\Security\AntiSpam\AntiSpamVerdict;
use Pulsar\Security\AntiSpam\AntiSpamVerdictPolicy;

#[CoversClass(AntiSpamVerdict::class)]
#[CoversClass(AntiSpamVerdictPolicy::class)]
final class AntiSpamVerdictTest extends TestCase
{
    #[Test]
    public function hardGateFailureRejects(): void
    {
        $result = AntiSpamResult::fromCheckResults([
            AntiSpamCheckResult::fail('honeypot', 50, 'filled'),
            AntiSpamCheckResult::pass('content_quality'),
        ]);

        $verdict = AntiSpamVerdict::from($result, AntiSpamVerdictPolicy::default());

        self::assertTrue($verdict->hardFailed);
        self::assertTrue($verdict->shouldReject());
        self::assertSame('honeypot', $verdict->hardCheck);
    }

    #[Test]
    public function contentSignalsFlagButNeverReject(): void
    {
        $result = AntiSpamResult::fromCheckResults([
            AntiSpamCheckResult::pass('honeypot'),
            AntiSpamCheckResult::fail('link_density', 30, 'too many links'),
            AntiSpamCheckResult::fail('content_quality', 25, 'low quality'),
        ]);

        $verdict = AntiSpamVerdict::from($result, AntiSpamVerdictPolicy::default());

        self::assertFalse($verdict->hardFailed);
        self::assertFalse($verdict->shouldReject());
        self::assertSame(55, $verdict->contentScore);
        self::assertTrue($verdict->flagged); // 55 >= 50 threshold
    }

    #[Test]
    public function belowThresholdIsNeitherRejectedNorFlagged(): void
    {
        $result = AntiSpamResult::fromCheckResults([
            AntiSpamCheckResult::fail('content_quality', 20, 'meh'),
        ]);

        $verdict = AntiSpamVerdict::from($result, AntiSpamVerdictPolicy::default());

        self::assertFalse($verdict->hardFailed);
        self::assertFalse($verdict->flagged);
        self::assertSame(20, $verdict->contentScore);
    }

    #[Test]
    public function captchaIsAHardGateOnlyWhenATokenWasSent(): void
    {
        $result = AntiSpamResult::fromCheckResults([
            AntiSpamCheckResult::fail('captcha', 40, 'no token'),
        ]);

        // No token (JS-off client): captcha neither blocks nor scores.
        $lenient = AntiSpamVerdict::from($result, AntiSpamVerdictPolicy::default(captchaTokenPresent: false));
        self::assertFalse($lenient->hardFailed);
        self::assertSame(0, $lenient->contentScore);

        // Token present but invalid: captcha is a hard gate.
        $strict = AntiSpamVerdict::from($result, AntiSpamVerdictPolicy::default(captchaTokenPresent: true));
        self::assertTrue($strict->hardFailed);
        self::assertSame('captcha', $strict->hardCheck);
    }

    #[Test]
    public function hardGateScoresDoNotCountTowardContentScore(): void
    {
        // The honeypot's advisory score must not inflate the content review score.
        $result = AntiSpamResult::fromCheckResults([
            AntiSpamCheckResult::fail('honeypot', 50, 'filled'),
            AntiSpamCheckResult::fail('link_density', 10, 'links'),
        ]);

        $verdict = AntiSpamVerdict::from($result, AntiSpamVerdictPolicy::default());

        self::assertSame(10, $verdict->contentScore);
    }
}
