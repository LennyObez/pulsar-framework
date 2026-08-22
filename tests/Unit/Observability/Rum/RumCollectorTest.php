<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Rum;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\Rum\RumCollectionResult;
use Pulsar\Observability\Rum\RumCollector;

#[CoversClass(RumCollector::class)]
#[CoversClass(RumCollectionResult::class)]
final class RumCollectorTest extends TestCase
{
    private MetricRegistry $metrics;
    private RumCollector $collector;

    protected function setUp(): void
    {
        $this->metrics = new MetricRegistry();
        $this->collector = new RumCollector($this->metrics);
    }

    #[Test]
    public function collectAcceptsValidMetrics(): void
    {
        $payload = [
            'metrics' => [
                ['name' => 'lcp', 'value' => 1250.5, 'url' => '/home'],
                ['name' => 'fid', 'value' => 12.3, 'url' => '/home'],
                ['name' => 'cls', 'value' => 0.05, 'url' => '/home'],
            ],
        ];

        $result = $this->collector->collect($payload);

        self::assertSame(3, $result->accepted);
        self::assertSame(0, $result->rejected);
    }

    #[Test]
    public function collectRejectsUnknownMetricNames(): void
    {
        $payload = [
            'metrics' => [
                ['name' => 'unknown_metric', 'value' => 100, 'url' => '/'],
            ],
        ];

        $result = $this->collector->collect($payload);

        self::assertSame(0, $result->accepted);
        self::assertSame(1, $result->rejected);
    }

    #[Test]
    public function collectRejectsMissingName(): void
    {
        $payload = [
            'metrics' => [
                ['value' => 100, 'url' => '/'],
            ],
        ];

        $result = $this->collector->collect($payload);

        self::assertSame(0, $result->accepted);
        self::assertSame(1, $result->rejected);
    }

    #[Test]
    public function collectRejectsMissingValue(): void
    {
        $payload = [
            'metrics' => [
                ['name' => 'lcp', 'url' => '/'],
            ],
        ];

        $result = $this->collector->collect($payload);

        self::assertSame(0, $result->accepted);
        self::assertSame(1, $result->rejected);
    }

    #[Test]
    public function collectRejectsNonNumericValue(): void
    {
        $payload = [
            'metrics' => [
                ['name' => 'lcp', 'value' => 'not-a-number', 'url' => '/'],
            ],
        ];

        $result = $this->collector->collect($payload);

        self::assertSame(0, $result->accepted);
        self::assertSame(1, $result->rejected);
    }

    #[Test]
    public function collectHandlesEmptyPayload(): void
    {
        $result = $this->collector->collect([]);

        self::assertSame(0, $result->accepted);
        self::assertSame(0, $result->rejected);
    }

    #[Test]
    public function collectHandlesMissingMetricsKey(): void
    {
        $result = $this->collector->collect(['other' => 'data']);

        self::assertSame(0, $result->accepted);
        self::assertSame(0, $result->rejected);
    }

    #[Test]
    public function collectLimitsBatchSize(): void
    {
        $metrics = [];
        for ($i = 0; $i < 150; $i++) {
            $metrics[] = ['name' => 'lcp', 'value' => 1000 + $i, 'url' => '/'];
        }

        $result = $this->collector->collect(['metrics' => $metrics]);

        // Should cap at MAX_BATCH_SIZE (100)
        self::assertSame(100, $result->accepted);
    }

    /** @return iterable<string, array{string}> */
    public static function validMetricNameProvider(): iterable
    {
        return [
            'lcp' => ['lcp'],
            'fid' => ['fid'],
            'cls' => ['cls'],
            'page_load' => ['page_load'],
            'dom_content_loaded' => ['dom_content_loaded'],
            'ttfb' => ['ttfb'],
            'js_error' => ['js_error'],
            'unhandled_rejection' => ['unhandled_rejection'],
        ];
    }

    #[Test]
    #[DataProvider('validMetricNameProvider')]
    public function collectAcceptsAllDefinedMetricNames(string $name): void
    {
        $payload = [
            'metrics' => [
                ['name' => $name, 'value' => 42.0, 'url' => '/test'],
            ],
        ];

        $result = $this->collector->collect($payload);

        self::assertSame(1, $result->accepted);
    }

    #[Test]
    public function collectRecordsCounterForJsErrors(): void
    {
        $payload = [
            'metrics' => [
                ['name' => 'js_error', 'value' => 1, 'url' => '/broken'],
            ],
        ];

        $this->collector->collect($payload);

        $metrics = $this->metrics->all();

        self::assertArrayHasKey('rum_js_error', $metrics, 'rum_js_error metric should be recorded');
    }

    #[Test]
    public function collectMixesAcceptedAndRejected(): void
    {
        $payload = [
            'metrics' => [
                ['name' => 'lcp', 'value' => 1200, 'url' => '/'],
                ['name' => 'invalid', 'value' => 100, 'url' => '/'],
                ['name' => 'fid', 'value' => 50, 'url' => '/'],
            ],
        ];

        $result = $this->collector->collect($payload);

        self::assertSame(2, $result->accepted);
        self::assertSame(1, $result->rejected);
    }
}
