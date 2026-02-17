<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Metrics\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Metrics\Exception\MetricsException;
use RuntimeException;

#[CoversClass(MetricsException::class)]
final class MetricsExceptionTest extends TestCase
{
    #[Test]
    public function negativeIncrementIncludesValue(): void
    {
        $exception = MetricsException::negativeIncrement(-5.3);

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertStringContainsString('-5.3', $exception->getMessage());
        self::assertStringContainsString('non-negative', $exception->getMessage());
    }

    #[Test]
    public function negativeIncrementWithZeroValue(): void
    {
        $exception = MetricsException::negativeIncrement(0.0);

        self::assertStringContainsString('0', $exception->getMessage());
    }

    #[Test]
    public function typeMismatchIncludesAllParameters(): void
    {
        $exception = MetricsException::typeMismatch('http_requests', 'counter', 'histogram');

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertStringContainsString('http_requests', $exception->getMessage());
        self::assertStringContainsString('counter', $exception->getMessage());
        self::assertStringContainsString('histogram', $exception->getMessage());
    }

    #[Test]
    public function factoriesReturnDistinctInstances(): void
    {
        $a = MetricsException::negativeIncrement(-1.0);
        $b = MetricsException::negativeIncrement(-2.0);

        self::assertNotSame($a, $b);
    }
}
