<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Rum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Rum\RumCollector;
use Pulsar\Observability\Rum\RumUrlLabels;

use function str_repeat;

/**
 * The label budget is the control that outlives the request.
 *
 * Origin checks and body caps bound what one caller can send. This bounds what
 * the process keeps: a metric label opens a time series, the registry is an
 * in-process array, and the value came out of a browser-supplied JSON body. The
 * previous code truncated the value to 200 characters, which limits how long
 * each label is and says nothing about how many there can be.
 */
#[CoversClass(RumUrlLabels::class)]
final class RumUrlLabelsTest extends TestCase
{
    #[Test]
    public function keepsThePathAndDropsTheRest(): void
    {
        $labels = new RumUrlLabels();

        self::assertSame('/orders', $labels->label('https://app.example.com/orders?token=secret#frag'));
    }

    /**
     * Query strings carry session tokens and search terms, and they multiply
     * cardinality per visitor. Two visits to the same page must land on one
     * series, not two.
     */
    #[Test]
    public function twoVisitsToOnePageWithDifferentQueriesShareOneLabel(): void
    {
        $labels = new RumUrlLabels();

        self::assertSame('/search', $labels->label('/search?q=alice'));
        self::assertSame('/search', $labels->label('/search?q=bob'));
        self::assertSame(1, $labels->distinctCount());
    }

    /**
     * The property this class exists for: a caller enumerating paths cannot
     * enumerate time series.
     */
    #[Test]
    public function collapsesEverythingPastTheBudgetOntoOneLabel(): void
    {
        $labels = new RumUrlLabels(limit: 3);

        self::assertSame('/a', $labels->label('/a'));
        self::assertSame('/b', $labels->label('/b'));
        self::assertSame('/c', $labels->label('/c'));

        self::assertSame(RumUrlLabels::OVERFLOW_LABEL, $labels->label('/d'));
        self::assertSame(RumUrlLabels::OVERFLOW_LABEL, $labels->label('/e'));
        self::assertSame(3, $labels->distinctCount(), 'the budget must not grow past its limit');
    }

    /** Paths already admitted keep working once the budget is spent. */
    #[Test]
    public function anAdmittedPathSurvivesTheBudgetBeingSpent(): void
    {
        $labels = new RumUrlLabels(limit: 1);

        self::assertSame('/home', $labels->label('/home'));
        self::assertSame(RumUrlLabels::OVERFLOW_LABEL, $labels->label('/other'));
        self::assertSame('/home', $labels->label('/home'));
    }

    /**
     * A label ends up in an OpenMetrics exposition line. Anything that could
     * break out of that line's quoting is refused rather than escaped, including
     * its percent-encoded form.
     */
    #[Test]
    public function refusesAPathThatCouldBreakTheExpositionFormat(): void
    {
        $labels = new RumUrlLabels();

        self::assertSame(RumUrlLabels::UNKNOWN_LABEL, $labels->label('/a"b'));
        self::assertSame(RumUrlLabels::UNKNOWN_LABEL, $labels->label("/a\nrum_lcp_total 99"));
        self::assertSame(RumUrlLabels::UNKNOWN_LABEL, $labels->label('/a%0Arum_lcp_total 99'));
        self::assertSame(0, $labels->distinctCount(), 'a refused path must not spend budget');
    }

    #[Test]
    public function refusesNonStringAndOverlongValues(): void
    {
        $labels = new RumUrlLabels();

        self::assertSame(RumUrlLabels::UNKNOWN_LABEL, $labels->label(null));
        self::assertSame(RumUrlLabels::UNKNOWN_LABEL, $labels->label(42));
        self::assertSame(RumUrlLabels::UNKNOWN_LABEL, $labels->label('/' . str_repeat('x', 400)));
    }

    /**
     * End to end through the collector, because the budget is only a control if
     * the collector actually consults it.
     */
    #[Test]
    public function theCollectorRoutesBrowserSuppliedUrlsThroughTheBudget(): void
    {
        $labels = new RumUrlLabels(limit: 2);
        $collector = new RumCollector(new MetricRegistry(), $labels);

        $collector->collect([
            'metrics' => [
                ['name' => 'lcp', 'value' => 1, 'url' => '/one'],
                ['name' => 'lcp', 'value' => 1, 'url' => '/two'],
                ['name' => 'lcp', 'value' => 1, 'url' => '/three'],
                ['name' => 'lcp', 'value' => 1, 'url' => '/four'],
            ],
        ]);

        self::assertSame(2, $labels->distinctCount());
    }
}
