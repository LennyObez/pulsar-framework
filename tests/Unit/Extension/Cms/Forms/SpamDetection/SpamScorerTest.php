<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Forms\SpamDetection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamDetectorInterface;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamResult;
use Pulsar\Extension\Cms\Forms\SpamDetection\SpamScorer;

#[CoversClass(SpamScorer::class)]
#[CoversClass(SpamResult::class)]
final class SpamScorerTest extends TestCase
{
    #[Test]
    public function scoreWithNoDetectorsReturnsZero(): void
    {
        $scorer = new SpamScorer();
        $result = $scorer->score([], []);

        self::assertFalse($result->isSpam);
        self::assertSame(0.0, $result->score);
        self::assertNull($result->reason);
    }

    #[Test]
    public function scoreAggregatesMultipleDetectors(): void
    {
        $detector1 = $this->createStub(SpamDetectorInterface::class);
        $detector1->method('detect')->willReturn(new SpamResult(false, 2.0, 'Suspicious link'));

        $detector2 = $this->createStub(SpamDetectorInterface::class);
        $detector2->method('detect')->willReturn(new SpamResult(false, 1.5, 'Fast submission'));

        $scorer = new SpamScorer(threshold: 5.0);
        $scorer->addDetector($detector1);
        $scorer->addDetector($detector2);

        $result = $scorer->score(['body' => 'test'], ['ip' => '127.0.0.1']);

        self::assertFalse($result->isSpam);
        self::assertSame(3.5, $result->score);
        self::assertSame('Suspicious link; Fast submission', $result->reason);
    }

    #[Test]
    public function scoreExceedsThresholdMarksAsSpam(): void
    {
        $detector = $this->createStub(SpamDetectorInterface::class);
        $detector->method('detect')->willReturn(new SpamResult(true, 10.0, 'Honeypot filled'));

        $scorer = new SpamScorer(threshold: 5.0);
        $scorer->addDetector($detector);

        $result = $scorer->score([], []);

        self::assertTrue($result->isSpam);
        self::assertSame(10.0, $result->score);
        self::assertSame('Honeypot filled', $result->reason);
    }

    #[Test]
    public function scoreExactlyAtThresholdIsSpam(): void
    {
        $detector = $this->createStub(SpamDetectorInterface::class);
        $detector->method('detect')->willReturn(new SpamResult(false, 5.0, null));

        $scorer = new SpamScorer(threshold: 5.0);
        $scorer->addDetector($detector);

        $result = $scorer->score([], []);

        self::assertTrue($result->isSpam);
        self::assertSame(5.0, $result->score);
        self::assertNull($result->reason);
    }

    #[Test]
    public function scoreJustBelowThresholdIsNotSpam(): void
    {
        $detector = $this->createStub(SpamDetectorInterface::class);
        $detector->method('detect')->willReturn(new SpamResult(false, 4.9, null));

        $scorer = new SpamScorer(threshold: 5.0);
        $scorer->addDetector($detector);

        $result = $scorer->score([], []);

        self::assertFalse($result->isSpam);
    }

    #[Test]
    public function scoreOmitsNullReasons(): void
    {
        $detector1 = $this->createStub(SpamDetectorInterface::class);
        $detector1->method('detect')->willReturn(new SpamResult(false, 1.0, null));

        $detector2 = $this->createStub(SpamDetectorInterface::class);
        $detector2->method('detect')->willReturn(new SpamResult(false, 1.0, 'Some reason'));

        $scorer = new SpamScorer();
        $scorer->addDetector($detector1);
        $scorer->addDetector($detector2);

        $result = $scorer->score([], []);

        self::assertSame('Some reason', $result->reason);
    }

    #[Test]
    public function scoreWithCustomThreshold(): void
    {
        $detector = $this->createStub(SpamDetectorInterface::class);
        $detector->method('detect')->willReturn(new SpamResult(false, 2.0, null));

        $scorer = new SpamScorer(threshold: 1.0);
        $scorer->addDetector($detector);

        $result = $scorer->score([], []);

        self::assertTrue($result->isSpam);
    }
}
