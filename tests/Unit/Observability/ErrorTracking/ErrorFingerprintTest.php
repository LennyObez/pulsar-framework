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

    /**
     * F8.20: messages embedding user-controlled values (record ids,
     * UUIDs, quoted strings) must collapse to the same fingerprint so
     * the aggregator groups them. Hardcoding the message would mint a
     * new fingerprint per input and break grouping.
     *
     * The throwables share a `getFile()` / `getLine()` because they're
     * all instantiated on the same PHP source line — that lets the
     * test isolate message normalisation from file/line invariants.
     */
    #[Test]
    public function messagesDifferingOnlyInUserDataShareFingerprint(): void
    {
        $variants = [new RuntimeException('Order 42 not found'), new RuntimeException('Order 1024 not found'), new RuntimeException('Order 999999999 not found')];

        $first = ErrorFingerprint::fromThrowable($variants[0]);

        foreach ($variants as $variant) {
            self::assertSame(
                $first->value,
                ErrorFingerprint::fromThrowable($variant)->value,
                "Variant '{$variant->getMessage()}' should match the first fingerprint",
            );
        }
    }

    #[Test]
    public function uuidAndHexAndQuotedStringsAreNormalised(): void
    {
        $variants = [new RuntimeException('User "alice" not found, id=550e8400-e29b-41d4-a716-446655440000'), new RuntimeException('User "bob" not found, id=00000000-0000-0000-0000-000000000000'), new RuntimeException('User "charlie" not found, id=ffffffff-ffff-ffff-ffff-ffffffffffff')];

        $fpA = ErrorFingerprint::fromThrowable($variants[0]);
        $fpB = ErrorFingerprint::fromThrowable($variants[1]);
        $fpC = ErrorFingerprint::fromThrowable($variants[2]);

        self::assertSame($fpA->value, $fpB->value);
        self::assertSame($fpA->value, $fpC->value);
    }

    /**
     * Direct test of the file-normalisation contract. `Exception::$file`
     * and `$line` are protected + populated at construction; we use
     * Reflection to overwrite them after the fact so the test can
     * simulate staging-vs-prod path divergence without depending on
     * the actual file paths the test happens to live in.
     */
    #[Test]
    public function fileNormalisationStripsAbsolutePathPrefix(): void
    {
        $a = new RuntimeException('boom');
        $b = new RuntimeException('boom');

        $reflection = new \ReflectionClass(\Exception::class);
        $fileProp = $reflection->getProperty('file');
        $lineProp = $reflection->getProperty('line');

        $fileProp->setValue($a, '/var/www/staging/src/Service.php');
        $lineProp->setValue($a, 42);

        $fileProp->setValue($b, 'D:\\deploy\\prod\\src\\Service.php');
        $lineProp->setValue($b, 42);

        self::assertSame(
            ErrorFingerprint::fromThrowable($a)->value,
            ErrorFingerprint::fromThrowable($b)->value,
        );
    }
}
