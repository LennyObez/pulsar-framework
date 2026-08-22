<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Accessibility\Contrast;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Contrast\ContrastReport;
use Pulsar\Extension\Accessibility\Contrast\ContrastResult;
use Pulsar\Extension\Accessibility\Contrast\ParsedColor;

#[CoversClass(ContrastReport::class)]
#[CoversClass(ContrastResult::class)]
final class ContrastReportDeepTest extends TestCase
{
    #[Test]
    public function emptyReportHasZeroCounts(): void
    {
        $report = new ContrastReport([]);

        self::assertSame(0, $report->totalPairs);
        self::assertSame(0, $report->passingAa);
        self::assertSame(0, $report->failingAa);
    }

    #[Test]
    public function reportCountsPassingAndFailing(): void
    {
        $black = new ParsedColor(0, 0, 0);
        $white = new ParsedColor(255, 255, 255);
        $gray = new ParsedColor(200, 200, 200);

        $results = [
            new ContrastResult($black, $white, '--text', '--bg', 21.0),   // passes AA
            new ContrastResult($gray, $white, '--muted', '--bg', 1.4),    // fails AA
        ];

        $report = new ContrastReport($results);

        self::assertSame(2, $report->totalPairs);
        self::assertSame(1, $report->passingAa);
        self::assertSame(1, $report->failingAa);
    }

    #[Test]
    public function failuresReturnsFailingResultsForAaNormal(): void
    {
        $black = new ParsedColor(0, 0, 0);
        $white = new ParsedColor(255, 255, 255);

        $passing = new ContrastResult($black, $white, '--a', '--b', 21.0);
        $failing = new ContrastResult($black, $white, '--c', '--d', 3.0);

        $report = new ContrastReport([$passing, $failing]);

        $failures = $report->failures('aa_normal');
        self::assertCount(1, $failures);
        self::assertSame('--c', $failures[0]->foregroundToken);
    }

    #[Test]
    public function failuresForAaLarge(): void
    {
        $black = new ParsedColor(0, 0, 0);
        $white = new ParsedColor(255, 255, 255);

        $passesAaLarge = new ContrastResult($black, $white, '--a', '--b', 3.5);
        $failsAaLarge = new ContrastResult($black, $white, '--c', '--d', 2.0);

        $report = new ContrastReport([$passesAaLarge, $failsAaLarge]);

        $failures = $report->failures('aa_large');
        self::assertCount(1, $failures);
        self::assertSame('--c', $failures[0]->foregroundToken);
    }

    #[Test]
    public function failuresForAaaNormal(): void
    {
        $black = new ParsedColor(0, 0, 0);
        $white = new ParsedColor(255, 255, 255);

        $passes = new ContrastResult($black, $white, '--a', '--b', 8.0);
        $fails = new ContrastResult($black, $white, '--c', '--d', 5.0);

        $report = new ContrastReport([$passes, $fails]);

        $failures = $report->failures('aaa_normal');
        self::assertCount(1, $failures);
        self::assertSame('--c', $failures[0]->foregroundToken);
    }

    #[Test]
    public function failuresForAaaLarge(): void
    {
        $black = new ParsedColor(0, 0, 0);
        $white = new ParsedColor(255, 255, 255);

        $passes = new ContrastResult($black, $white, '--a', '--b', 5.0);
        $fails = new ContrastResult($black, $white, '--c', '--d', 4.0);

        $report = new ContrastReport([$passes, $fails]);

        $failures = $report->failures('aaa_large');
        self::assertCount(1, $failures);
    }

    #[Test]
    public function failuresWithDefaultLevelUsesAaNormal(): void
    {
        $black = new ParsedColor(0, 0, 0);
        $white = new ParsedColor(255, 255, 255);

        $result = new ContrastResult($black, $white, '--a', '--b', 3.0);
        $report = new ContrastReport([$result]);

        // default level should be aa_normal (4.5 threshold)
        self::assertCount(1, $report->failures());
    }

    #[Test]
    public function failuresWithUnknownLevelFallsBackToAaNormal(): void
    {
        $black = new ParsedColor(0, 0, 0);
        $white = new ParsedColor(255, 255, 255);

        $result = new ContrastResult($black, $white, '--a', '--b', 3.0);
        $report = new ContrastReport([$result]);

        self::assertCount(1, $report->failures('nonexistent_level'));
    }

    #[Test]
    public function passesReturnsPassingResultsForAaNormal(): void
    {
        $black = new ParsedColor(0, 0, 0);
        $white = new ParsedColor(255, 255, 255);

        $passing = new ContrastResult($black, $white, '--a', '--b', 21.0);
        $failing = new ContrastResult($black, $white, '--c', '--d', 3.0);

        $report = new ContrastReport([$passing, $failing]);

        $passes = $report->passes('aa_normal');
        self::assertCount(1, $passes);
        self::assertSame('--a', $passes[0]->foregroundToken);
    }

    #[Test]
    public function passesForAaLarge(): void
    {
        $black = new ParsedColor(0, 0, 0);
        $white = new ParsedColor(255, 255, 255);

        $report = new ContrastReport([
            new ContrastResult($black, $white, '--a', '--b', 3.5),
            new ContrastResult($black, $white, '--c', '--d', 2.0),
        ]);

        self::assertCount(1, $report->passes('aa_large'));
    }

    #[Test]
    public function toArraySerializesAllFields(): void
    {
        $fg = new ParsedColor(0, 0, 0);
        $bg = new ParsedColor(255, 255, 255);

        $result = new ContrastResult($fg, $bg, '--text', '--bg', 21.0);
        $report = new ContrastReport([$result]);

        $array = $report->toArray();

        self::assertSame(1, $array['total_pairs']);
        self::assertSame(1, $array['passing_aa']);
        self::assertSame(0, $array['failing_aa']);
        self::assertCount(1, $array['results']);

        $r = $array['results'][0];
        self::assertSame('#000000', $r['foreground']);
        self::assertSame('#ffffff', $r['background']);
        self::assertSame('--text', $r['foreground_token']);
        self::assertSame('--bg', $r['background_token']);
        self::assertSame(21.0, $r['ratio']);
        self::assertTrue($r['passes_aa_normal']);
        self::assertTrue($r['passes_aa_large']);
        self::assertTrue($r['passes_aaa_normal']);
        self::assertTrue($r['passes_aaa_large']);
    }

    // --- ContrastResult threshold tests ---

    #[Test]
    public function contrastResultBelowAllThresholds(): void
    {
        $fg = new ParsedColor(200, 200, 200);
        $bg = new ParsedColor(210, 210, 210);

        $result = new ContrastResult($fg, $bg, '--a', '--b', 1.1);

        self::assertFalse($result->passesAaNormal);
        self::assertFalse($result->passesAaLarge);
        self::assertFalse($result->passesAaaNormal);
        self::assertFalse($result->passesAaaLarge);
    }

    #[Test]
    public function contrastResultAtAaLargeThreshold(): void
    {
        $fg = new ParsedColor(0, 0, 0);
        $bg = new ParsedColor(255, 255, 255);

        $result = new ContrastResult($fg, $bg, '--a', '--b', 3.0);

        self::assertFalse($result->passesAaNormal);
        self::assertTrue($result->passesAaLarge);
        self::assertFalse($result->passesAaaNormal);
        self::assertFalse($result->passesAaaLarge);
    }

    #[Test]
    public function contrastResultAtAaNormalThreshold(): void
    {
        $fg = new ParsedColor(0, 0, 0);
        $bg = new ParsedColor(255, 255, 255);

        $result = new ContrastResult($fg, $bg, '--a', '--b', 4.5);

        self::assertTrue($result->passesAaNormal);
        self::assertTrue($result->passesAaLarge);
        self::assertFalse($result->passesAaaNormal);
        self::assertTrue($result->passesAaaLarge);
    }

    #[Test]
    public function contrastResultAtAaaNormalThreshold(): void
    {
        $fg = new ParsedColor(0, 0, 0);
        $bg = new ParsedColor(255, 255, 255);

        $result = new ContrastResult($fg, $bg, '--a', '--b', 7.0);

        self::assertTrue($result->passesAaNormal);
        self::assertTrue($result->passesAaLarge);
        self::assertTrue($result->passesAaaNormal);
        self::assertTrue($result->passesAaaLarge);
    }
}
