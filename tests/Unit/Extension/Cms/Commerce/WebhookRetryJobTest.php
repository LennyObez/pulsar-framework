<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Commerce;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Internal\Commerce\WebhookRetryJob;

/**
 * Tests for WebhookRetryJob.
 *
 * Note: The handle() method requires a real WebhookHandler instance
 * (which is final readonly), so it is tested at integration level.
 * This test covers the static methods and interface contract.
 */
#[CoversClass(WebhookRetryJob::class)]
final class WebhookRetryJobTest extends TestCase
{
    #[Test]
    #[DataProvider('calculateDelayProvider')]
    public function calculateDelayUsesExponentialBackoff(int $retryCount, int $expectedDelay): void
    {
        self::assertSame($expectedDelay, WebhookRetryJob::calculateDelay($retryCount));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function calculateDelayProvider(): iterable
    {
        yield 'retry 0' => [0, 60];    // 2^0 * 60 = 60
        yield 'retry 1' => [1, 120];   // 2^1 * 60 = 120
        yield 'retry 2' => [2, 240];   // 2^2 * 60 = 240
        yield 'retry 3' => [3, 480];   // 2^3 * 60 = 480
        yield 'retry 4' => [4, 960];   // 2^4 * 60 = 960
        yield 'retry 5' => [5, 1920];  // 2^5 * 60 = 1920
        yield 'retry 6' => [6, 3600];  // 2^6 * 60 = 3840, capped at 3600
        yield 'retry 7' => [7, 3600];  // capped at 3600
        yield 'retry 10' => [10, 3600]; // capped at 3600
    }

    #[Test]
    public function calculateDelayReturnsSixtyForZeroRetries(): void
    {
        self::assertSame(60, WebhookRetryJob::calculateDelay(0));
    }

    #[Test]
    public function calculateDelayNeverExceedsMaxBackoff(): void
    {
        for ($i = 0; $i <= 20; $i++) {
            self::assertLessThanOrEqual(3600, WebhookRetryJob::calculateDelay($i));
        }
    }

    #[Test]
    public function calculateDelayIncreasesMonotonically(): void
    {
        $previous = 0;
        for ($i = 0; $i <= 6; $i++) {
            $delay = WebhookRetryJob::calculateDelay($i);
            self::assertGreaterThanOrEqual($previous, $delay);
            $previous = $delay;
        }
    }

    #[Test]
    public function calculateDelayAtBoundary(): void
    {
        // retry 5: 2^5 * 60 = 1920 < 3600
        self::assertSame(1920, WebhookRetryJob::calculateDelay(5));
        // retry 6: 2^6 * 60 = 3840 > 3600, capped
        self::assertSame(3600, WebhookRetryJob::calculateDelay(6));
    }
}
