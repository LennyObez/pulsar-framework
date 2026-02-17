<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\BodyTooLargeException;
use RuntimeException;

#[CoversClass(BodyTooLargeException::class)]
final class BodyTooLargeExceptionTest extends TestCase
{
    #[Test]
    public function exceedsLimitMessageContainsSizeAndMax(): void
    {
        $exception = BodyTooLargeException::exceedsLimit(2048, 1024);

        self::assertStringContainsString('2048', $exception->getMessage());
        self::assertStringContainsString('1024', $exception->getMessage());
    }

    #[Test]
    public function exceedsLimitExtendsRuntimeException(): void
    {
        $exception = BodyTooLargeException::exceedsLimit(100, 50);

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function sizeProvider(): iterable
    {
        yield 'zero max' => [100, 0];
        yield 'equal values' => [1024, 1024];
        yield 'large body' => [10_485_760, 2_097_152];
        yield 'one byte over' => [1025, 1024];
    }

    #[Test]
    #[DataProvider('sizeProvider')]
    public function exceedsLimitFormatsVariousSizes(int $size, int $maxBytes): void
    {
        $exception = BodyTooLargeException::exceedsLimit($size, $maxBytes);

        self::assertStringContainsString((string) $size, $exception->getMessage());
        self::assertStringContainsString((string) $maxBytes, $exception->getMessage());
        self::assertStringContainsString('bytes', $exception->getMessage());
    }
}
