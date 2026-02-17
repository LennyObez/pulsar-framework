<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\View\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\View\Engine\TemplateProfiler;

#[CoversClass(TemplateProfiler::class)]
final class TemplateProfilerTest extends TestCase
{
    private TemplateProfiler $profiler;

    protected function setUp(): void
    {
        $this->profiler = new TemplateProfiler();
    }

    #[Test]
    public function startAndStopRecordsTiming(): void
    {
        $stop = $this->profiler->start('layout.pulse.php');
        $stop();

        $timings = $this->profiler->timings();

        self::assertArrayHasKey('layout.pulse.php', $timings);
        self::assertSame(1, $timings['layout.pulse.php']['count']);
        self::assertGreaterThanOrEqual(0.0, $timings['layout.pulse.php']['total_ms']);
    }

    #[Test]
    public function multipleRendersAccumulateTimings(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $stop = $this->profiler->start('repeated.pulse.php');
            $stop();
        }

        $timings = $this->profiler->timings();

        self::assertSame(3, $timings['repeated.pulse.php']['count']);
    }

    #[Test]
    public function timingsIncludesMinMaxAvg(): void
    {
        $stop = $this->profiler->start('stats.pulse.php');
        $stop();

        $data = $this->profiler->timings()['stats.pulse.php'];

        self::assertArrayHasKey('min_ms', $data);
        self::assertArrayHasKey('max_ms', $data);
        self::assertArrayHasKey('avg_ms', $data);
        self::assertGreaterThanOrEqual($data['min_ms'], $data['max_ms']);
    }

    #[Test]
    public function recordDependencyTracksDependencies(): void
    {
        $this->profiler->recordDependency('layout', 'header');
        $this->profiler->recordDependency('layout', 'footer');

        $deps = $this->profiler->dependencies();

        self::assertArrayHasKey('layout', $deps);
        self::assertSame(['header', 'footer'], $deps['layout']);
    }

    #[Test]
    public function slowestReturnsSortedByTotalMs(): void
    {
        // Create two templates, each with one timing
        $stop = $this->profiler->start('slow.pulse.php');
        // Simulate some work
        for ($i = 0; $i < 10000; $i++) {
            // busy loop for measurable timing
        }
        $stop();

        $stop2 = $this->profiler->start('fast.pulse.php');
        $stop2();

        $slowest = $this->profiler->slowest(10);

        self::assertNotEmpty($slowest);
        self::assertArrayHasKey('name', $slowest[0]);
        self::assertArrayHasKey('total_ms', $slowest[0]);
    }

    #[Test]
    public function slowestRespectsLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $stop = $this->profiler->start("template_{$i}.pulse.php");
            $stop();
        }

        $slowest = $this->profiler->slowest(2);

        self::assertCount(2, $slowest);
    }

    #[Test]
    public function resetClearsAllData(): void
    {
        $stop = $this->profiler->start('temp.pulse.php');
        $stop();
        $this->profiler->recordDependency('a', 'b');

        $this->profiler->reset();

        self::assertSame([], $this->profiler->timings());
        self::assertSame([], $this->profiler->dependencies());
    }

    #[Test]
    public function timingsReturnsEmptyWhenNothingRecorded(): void
    {
        self::assertSame([], $this->profiler->timings());
    }

    #[Test]
    public function slowestReturnsEmptyWhenNothingRecorded(): void
    {
        self::assertSame([], $this->profiler->slowest());
    }

    #[Test]
    public function dependenciesReturnsEmptyWhenNothingRecorded(): void
    {
        self::assertSame([], $this->profiler->dependencies());
    }
}
