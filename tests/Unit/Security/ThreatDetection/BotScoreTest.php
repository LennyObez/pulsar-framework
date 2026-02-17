<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ThreatDetection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ThreatDetection\BotScore;

#[CoversClass(BotScore::class)]
final class BotScoreTest extends TestCase
{
    public function testConstructor(): void
    {
        $score = new BotScore(score: 75, signals: ['user_agent' => 50, 'missing_headers' => 25]);

        self::assertSame(75, $score->score);
        self::assertSame(['user_agent' => 50, 'missing_headers' => 25], $score->signals);
    }

    #[DataProvider('botThresholdProvider')]
    public function testIsBot(int $score, int $threshold, bool $expected): void
    {
        $botScore = new BotScore(score: $score, signals: []);
        self::assertSame($expected, $botScore->isBot($threshold));
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function botThresholdProvider(): iterable
    {
        yield 'below default threshold' => [69, 70, false];
        yield 'at default threshold' => [70, 70, true];
        yield 'above default threshold' => [80, 70, true];
        yield 'zero score' => [0, 70, false];
        yield 'max score' => [100, 70, true];
        yield 'custom low threshold' => [30, 25, true];
    }

    #[DataProvider('suspiciousThresholdProvider')]
    public function testIsSuspicious(int $score, int $threshold, bool $expected): void
    {
        $botScore = new BotScore(score: $score, signals: []);
        self::assertSame($expected, $botScore->isSuspicious($threshold));
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function suspiciousThresholdProvider(): iterable
    {
        yield 'below threshold' => [39, 40, false];
        yield 'at threshold' => [40, 40, true];
        yield 'above threshold' => [60, 40, true];
    }

    #[DataProvider('humanThresholdProvider')]
    public function testIsHuman(int $score, int $threshold, bool $expected): void
    {
        $botScore = new BotScore(score: $score, signals: []);
        self::assertSame($expected, $botScore->isHuman($threshold));
    }

    /**
     * @return iterable<string, array{int, int, bool}>
     */
    public static function humanThresholdProvider(): iterable
    {
        yield 'below threshold is human' => [20, 40, true];
        yield 'at threshold is not human' => [40, 40, false];
        yield 'above threshold is not human' => [80, 40, false];
        yield 'zero score is human' => [0, 40, true];
    }

    public function testDefaultThresholds(): void
    {
        $human = new BotScore(score: 10, signals: []);
        self::assertTrue($human->isHuman());
        self::assertFalse($human->isSuspicious());
        self::assertFalse($human->isBot());

        $bot = new BotScore(score: 80, signals: []);
        self::assertFalse($bot->isHuman());
        self::assertTrue($bot->isSuspicious());
        self::assertTrue($bot->isBot());
    }
}
