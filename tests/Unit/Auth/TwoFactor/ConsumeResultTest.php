<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\TwoFactor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\TwoFactor\ConsumeReason;
use Pulsar\Auth\TwoFactor\ConsumeResult;

#[CoversClass(ConsumeResult::class)]
final class ConsumeResultTest extends TestCase
{
    #[Test]
    public function successFactoryReturnsConsumedResult(): void
    {
        $result = ConsumeResult::success(3);

        self::assertTrue($result->consumed);
        self::assertSame(3, $result->codeIndex);
        self::assertSame(ConsumeReason::Consumed, $result->reason);
    }

    #[Test]
    #[DataProvider('successIndexProvider')]
    public function successWithVariousIndices(int $index): void
    {
        $result = ConsumeResult::success($index);

        self::assertTrue($result->consumed);
        self::assertSame($index, $result->codeIndex);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function successIndexProvider(): iterable
    {
        yield 'first code' => [0];
        yield 'middle code' => [4];
        yield 'last code' => [7];
    }

    #[Test]
    #[DataProvider('failureReasonProvider')]
    public function failureFactoryReturnsUnconsumedResult(ConsumeReason $reason): void
    {
        $result = ConsumeResult::failure($reason);

        self::assertFalse($result->consumed);
        self::assertSame(-1, $result->codeIndex);
        self::assertSame($reason, $result->reason);
    }

    /**
     * @return iterable<string, array{ConsumeReason}>
     */
    public static function failureReasonProvider(): iterable
    {
        yield 'NotFound' => [ConsumeReason::NotFound];
        yield 'AlreadyUsed' => [ConsumeReason::AlreadyUsed];
        yield 'NotEnrolled' => [ConsumeReason::NotEnrolled];
        yield 'RateLimited' => [ConsumeReason::RateLimited];
    }

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $result = new ConsumeResult(true, 5, ConsumeReason::Consumed);

        self::assertTrue($result->consumed);
        self::assertSame(5, $result->codeIndex);
        self::assertSame(ConsumeReason::Consumed, $result->reason);
    }

    #[Test]
    public function failureIndexIsAlwaysNegativeOne(): void
    {
        foreach (ConsumeReason::cases() as $reason) {
            if ($reason === ConsumeReason::Consumed) {
                continue;
            }

            $result = ConsumeResult::failure($reason);
            self::assertSame(-1, $result->codeIndex, "Failure for {$reason->value} should have index -1");
        }
    }
}
