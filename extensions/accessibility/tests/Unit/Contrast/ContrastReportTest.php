<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Tests\Unit\Contrast;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Accessibility\Contrast\ContrastReport;
use Pulsar\Extension\Accessibility\Contrast\ContrastResult;
use Pulsar\Extension\Accessibility\Contrast\ParsedColor;

final class ContrastReportTest extends TestCase
{
    private function makeResult(float $ratio): ContrastResult
    {
        return new ContrastResult(
            foreground: new ParsedColor(0, 0, 0),
            background: new ParsedColor(255, 255, 255),
            foregroundToken: 'text',
            backgroundToken: 'bg',
            ratio: $ratio,
        );
    }

    #[Test]
    public function constructorCountsPassingAndFailing(): void
    {
        $results = [
            $this->makeResult(5.0),  // passes AA normal (>= 4.5)
            $this->makeResult(3.0),  // fails AA normal (< 4.5)
            $this->makeResult(7.0),  // passes AA normal
            $this->makeResult(2.0),  // fails AA normal
        ];

        $report = new ContrastReport($results);

        self::assertSame(4, $report->totalPairs);
        self::assertSame(2, $report->passingAa);
        self::assertSame(2, $report->failingAa);
    }

    #[Test]
    public function emptyResultsReport(): void
    {
        $report = new ContrastReport([]);

        self::assertSame(0, $report->totalPairs);
        self::assertSame(0, $report->passingAa);
        self::assertSame(0, $report->failingAa);
    }

    #[Test]
    public function failuresReturnsResultsBelowLevel(): void
    {
        $results = [
            $this->makeResult(5.0),  // passes AA normal
            $this->makeResult(3.0),  // fails AA normal
        ];

        $report = new ContrastReport($results);
        $failures = $report->failures('aa_normal');

        self::assertCount(1, $failures);
        self::assertEqualsWithDelta(3.0, $failures[0]->ratio, 0.01);
    }

    #[Test]
    public function failuresAaLargeLevel(): void
    {
        $results = [
            $this->makeResult(3.5),  // passes AA large (>= 3.0)
            $this->makeResult(2.0),  // fails AA large
        ];

        $report = new ContrastReport($results);
        $failures = $report->failures('aa_large');

        self::assertCount(1, $failures);
        self::assertEqualsWithDelta(2.0, $failures[0]->ratio, 0.01);
    }

    #[Test]
    public function failuresAaaNormalLevel(): void
    {
        $results = [
            $this->makeResult(7.5),  // passes AAA normal (>= 7.0)
            $this->makeResult(6.0),  // fails AAA normal
        ];

        $report = new ContrastReport($results);
        $failures = $report->failures('aaa_normal');

        self::assertCount(1, $failures);
    }

    #[Test]
    public function failuresAaaLargeLevel(): void
    {
        $results = [
            $this->makeResult(5.0),  // passes AAA large (>= 4.5)
            $this->makeResult(4.0),  // fails AAA large
        ];

        $report = new ContrastReport($results);
        $failures = $report->failures('aaa_large');

        self::assertCount(1, $failures);
    }

    #[Test]
    public function failuresDefaultsToAaNormalForUnknownLevel(): void
    {
        $results = [
            $this->makeResult(5.0),
            $this->makeResult(3.0),
        ];

        $report = new ContrastReport($results);
        $failures = $report->failures('unknown_level');

        self::assertCount(1, $failures);
    }

    #[Test]
    public function passesReturnsResultsAboveLevel(): void
    {
        $results = [
            $this->makeResult(5.0),  // passes AA normal
            $this->makeResult(3.0),  // fails AA normal
        ];

        $report = new ContrastReport($results);
        $passes = $report->passes('aa_normal');

        self::assertCount(1, $passes);
        self::assertEqualsWithDelta(5.0, $passes[0]->ratio, 0.01);
    }

    #[Test]
    public function passesAaLarge(): void
    {
        $results = [
            $this->makeResult(3.5),  // passes AA large
            $this->makeResult(2.0),  // fails AA large
        ];

        $report = new ContrastReport($results);
        $passes = $report->passes('aa_large');

        self::assertCount(1, $passes);
    }

    #[Test]
    public function passesAaaNormal(): void
    {
        $results = [
            $this->makeResult(7.5),
            $this->makeResult(6.0),
        ];

        $report = new ContrastReport($results);
        $passes = $report->passes('aaa_normal');

        self::assertCount(1, $passes);
    }

    #[Test]
    public function passesAaaLarge(): void
    {
        $results = [
            $this->makeResult(5.0),
            $this->makeResult(4.0),
        ];

        $report = new ContrastReport($results);
        $passes = $report->passes('aaa_large');

        self::assertCount(1, $passes);
    }

    #[Test]
    public function passesDefaultsToAaNormalForUnknownLevel(): void
    {
        $results = [
            $this->makeResult(5.0),
            $this->makeResult(3.0),
        ];

        $report = new ContrastReport($results);
        $passes = $report->passes('unknown');

        self::assertCount(1, $passes);
    }

    #[Test]
    public function toArrayReturnsCorrectStructure(): void
    {
        $fg = new ParsedColor(0, 0, 0);
        $bg = new ParsedColor(255, 255, 255);
        $result = new ContrastResult($fg, $bg, 'text-primary', 'bg-white', 21.0);

        $report = new ContrastReport([$result]);
        $data = $report->toArray();

        self::assertSame(1, $data['total_pairs']);
        self::assertSame(1, $data['passing_aa']);
        self::assertSame(0, $data['failing_aa']);
        self::assertCount(1, $data['results']);

        $r = $data['results'][0];
        self::assertSame('#000000', $r['foreground']);
        self::assertSame('#ffffff', $r['background']);
        self::assertSame('text-primary', $r['foreground_token']);
        self::assertSame('bg-white', $r['background_token']);
        self::assertSame(21.0, $r['ratio']);
        self::assertTrue($r['passes_aa_normal']);
        self::assertTrue($r['passes_aa_large']);
        self::assertTrue($r['passes_aaa_normal']);
        self::assertTrue($r['passes_aaa_large']);
    }

    #[Test]
    public function toArrayWithFailingResult(): void
    {
        $result = new ContrastResult(
            new ParsedColor(200, 200, 200),
            new ParsedColor(220, 220, 220),
            'text-light',
            'bg-light',
            1.2,
        );

        $report = new ContrastReport([$result]);
        $data = $report->toArray();

        self::assertSame(0, $data['passing_aa']);
        self::assertSame(1, $data['failing_aa']);

        $r = $data['results'][0];
        self::assertFalse($r['passes_aa_normal']);
        self::assertFalse($r['passes_aa_large']);
        self::assertFalse($r['passes_aaa_normal']);
        self::assertFalse($r['passes_aaa_large']);
    }

    #[Test]
    public function allPassingReport(): void
    {
        $results = [
            $this->makeResult(21.0),
            $this->makeResult(10.0),
        ];

        $report = new ContrastReport($results);

        self::assertSame(2, $report->passingAa);
        self::assertSame(0, $report->failingAa);
        self::assertSame([], $report->failures());
        self::assertCount(2, $report->passes());
    }

    #[Test]
    public function allFailingReport(): void
    {
        $results = [
            $this->makeResult(1.5),
            $this->makeResult(2.0),
        ];

        $report = new ContrastReport($results);

        self::assertSame(0, $report->passingAa);
        self::assertSame(2, $report->failingAa);
        self::assertCount(2, $report->failures());
        self::assertSame([], $report->passes());
    }
}
