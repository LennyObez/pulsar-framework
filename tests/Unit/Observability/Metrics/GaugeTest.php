<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Metrics\Gauge;
use Pulsar\Observability\Metrics\LabelSet;

#[CoversClass(Gauge::class)]
final class GaugeTest extends TestCase
{
    #[Test]
    public function setsAbsoluteValue(): void
    {
        $gauge = new Gauge('temperature');
        $gauge->set(36.6);

        self::assertSame(36.6, $gauge->value());
    }

    #[Test]
    public function incrementsByOne(): void
    {
        $gauge = new Gauge('connections');
        $gauge->increment();

        self::assertSame(1.0, $gauge->value());
    }

    #[Test]
    public function incrementsByCustomValue(): void
    {
        $gauge = new Gauge('queue_size');
        $gauge->increment(value: 5.0);

        self::assertSame(5.0, $gauge->value());
    }

    #[Test]
    public function decrementsByOne(): void
    {
        $gauge = new Gauge('connections');
        $gauge->set(10.0);
        $gauge->decrement();

        self::assertSame(9.0, $gauge->value());
    }

    #[Test]
    public function decrementsByCustomValue(): void
    {
        $gauge = new Gauge('connections');
        $gauge->set(10.0);
        $gauge->decrement(value: 3.0);

        self::assertSame(7.0, $gauge->value());
    }

    #[Test]
    public function tracksValuesByLabelSet(): void
    {
        $gauge = new Gauge('pool_size');
        $db = new LabelSet(['pool' => 'database']);
        $cache = new LabelSet(['pool' => 'cache']);

        $gauge->set(5.0, $db);
        $gauge->set(10.0, $cache);

        self::assertSame(5.0, $gauge->value($db));
        self::assertSame(10.0, $gauge->value($cache));
    }

    #[Test]
    public function resetClearsAllValues(): void
    {
        $gauge = new Gauge('test');
        $gauge->set(42.0);
        $gauge->reset();

        self::assertSame(0.0, $gauge->value());
        self::assertSame([], $gauge->values());
    }
}
