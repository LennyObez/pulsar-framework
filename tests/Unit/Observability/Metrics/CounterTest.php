<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Metrics\Counter;
use Pulsar\Observability\Metrics\Exception\MetricsException;
use Pulsar\Observability\Metrics\LabelSet;

#[CoversClass(Counter::class)]
final class CounterTest extends TestCase
{
    #[Test]
    public function incrementsByOneByDefault(): void
    {
        $counter = new Counter('requests_total');
        $counter->increment();

        self::assertSame(1.0, $counter->value());
    }

    #[Test]
    public function incrementsByCustomValue(): void
    {
        $counter = new Counter('bytes_sent');
        $counter->increment(value: 42.5);

        self::assertSame(42.5, $counter->value());
    }

    #[Test]
    public function rejectsNegativeIncrement(): void
    {
        $counter = new Counter('test');

        $this->expectException(MetricsException::class);
        $this->expectExceptionMessageIsOrContains('non-negative');

        $counter->increment(value: -1.0);
    }

    #[Test]
    public function tracksValuesByLabelSet(): void
    {
        $counter = new Counter('http_requests');
        $get = new LabelSet(['method' => 'GET']);
        $post = new LabelSet(['method' => 'POST']);

        $counter->increment($get);
        $counter->increment($get);
        $counter->increment($post);

        self::assertSame(2.0, $counter->value($get));
        self::assertSame(1.0, $counter->value($post));
    }

    #[Test]
    public function returnsZeroForUnknownLabels(): void
    {
        $counter = new Counter('test');

        self::assertSame(0.0, $counter->value(new LabelSet(['x' => 'y'])));
    }

    #[Test]
    public function resetClearsAllValues(): void
    {
        $counter = new Counter('test');
        $counter->increment();
        $counter->reset();

        self::assertSame(0.0, $counter->value());
        self::assertSame([], $counter->values());
    }
}
