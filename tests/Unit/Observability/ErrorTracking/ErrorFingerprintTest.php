<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability\ErrorTracking;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\ErrorTracking\ErrorFingerprint;
use RuntimeException;

use function strlen;

#[CoversClass(ErrorFingerprint::class)]
final class ErrorFingerprintTest extends TestCase
{
    #[Test]
    public function fromThrowableProducesDeterministicHash(): void
    {
        $exception = new RuntimeException('test error');
        $fp1 = ErrorFingerprint::fromThrowable($exception);
        $fp2 = ErrorFingerprint::fromThrowable($exception);

        self::assertSame($fp1->value, $fp2->value);
        self::assertSame(64, strlen($fp1->value)); // sha256 = 64 hex chars
    }

    #[Test]
    public function differentExceptionsProduceDifferentFingerprints(): void
    {
        $e1 = new RuntimeException('error one');
        $e2 = new RuntimeException('error two');

        $fp1 = ErrorFingerprint::fromThrowable($e1);
        $fp2 = ErrorFingerprint::fromThrowable($e2);

        self::assertNotSame($fp1->value, $fp2->value);
    }

    #[Test]
    public function equalsComparesValues(): void
    {
        $fp1 = new ErrorFingerprint('abc123');
        $fp2 = new ErrorFingerprint('abc123');
        $fp3 = new ErrorFingerprint('xyz789');

        self::assertTrue($fp1->equals($fp2));
        self::assertFalse($fp1->equals($fp3));
        self::assertSame('abc123', (string) $fp1);
    }
}
