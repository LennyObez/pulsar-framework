<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\Log\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\Log\Exception\LogException;
use RuntimeException;

#[CoversClass(LogException::class)]
final class LogExceptionTest extends TestCase
{
    #[Test]
    public function sinkWriteFailedIncludesSinkAndReason(): void
    {
        $exception = LogException::sinkWriteFailed('file', 'Permission denied');

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertStringContainsString('file', $exception->getMessage());
        self::assertStringContainsString('Permission denied', $exception->getMessage());
    }

    #[Test]
    public function invalidDriverIncludesDriverName(): void
    {
        $exception = LogException::invalidDriver('nonexistent');

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertStringContainsString('nonexistent', $exception->getMessage());
    }

    #[Test]
    public function factoriesReturnDistinctInstances(): void
    {
        $a = LogException::sinkWriteFailed('s1', 'r1');
        $b = LogException::sinkWriteFailed('s2', 'r2');

        self::assertNotSame($a, $b);
    }
}
