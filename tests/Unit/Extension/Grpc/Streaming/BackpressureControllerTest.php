<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Streaming;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Streaming\BackpressureController;

#[CoversClass(BackpressureController::class)]
final class BackpressureControllerTest extends TestCase
{
    #[Test]
    public function defaultWatermarks(): void
    {
        $bp = new BackpressureController();

        self::assertSame(64, $bp->highWatermark());
        self::assertSame(16, $bp->lowWatermark());
        self::assertSame(0, $bp->inFlightCount());
        self::assertTrue($bp->canSend());
        self::assertTrue($bp->isResumed());
    }

    #[Test]
    public function customWatermarks(): void
    {
        $bp = new BackpressureController(highWatermark: 100, lowWatermark: 25);

        self::assertSame(100, $bp->highWatermark());
        self::assertSame(25, $bp->lowWatermark());
    }

    #[Test]
    public function highWatermarkMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('High watermark must be at least 1');

        new BackpressureController(highWatermark: 0);
    }

    #[Test]
    public function lowWatermarkMustBeNonNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Low watermark must be non-negative');

        new BackpressureController(highWatermark: 10, lowWatermark: -1);
    }

    #[Test]
    public function lowMustBeLessThanHigh(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Low watermark must be less than high watermark');

        new BackpressureController(highWatermark: 10, lowWatermark: 10);
    }

    #[Test]
    public function canSendBlocksAtHighWatermark(): void
    {
        $bp = new BackpressureController(highWatermark: 3, lowWatermark: 1);

        $bp->onMessageSent();
        $bp->onMessageSent();
        self::assertTrue($bp->canSend());
        self::assertSame(2, $bp->inFlightCount());

        $bp->onMessageSent();
        self::assertFalse($bp->canSend());
        self::assertSame(3, $bp->inFlightCount());
    }

    #[Test]
    public function onMessageReceivedDecrementsInFlight(): void
    {
        $bp = new BackpressureController(highWatermark: 3, lowWatermark: 1);

        $bp->onMessageSent();
        $bp->onMessageSent();
        $bp->onMessageSent();
        self::assertFalse($bp->canSend());

        $bp->onMessageReceived();
        self::assertTrue($bp->canSend());
        self::assertSame(2, $bp->inFlightCount());
    }

    #[Test]
    public function onMessageReceivedDoesNotGoNegative(): void
    {
        $bp = new BackpressureController();

        $bp->onMessageReceived();
        $bp->onMessageReceived();

        self::assertSame(0, $bp->inFlightCount());
    }

    #[Test]
    public function isResumedAfterDrainToLowWatermark(): void
    {
        $bp = new BackpressureController(highWatermark: 5, lowWatermark: 2);

        for ($i = 0; $i < 5; $i++) {
            $bp->onMessageSent();
        }

        self::assertFalse($bp->isResumed());

        $bp->onMessageReceived(); // 4
        self::assertFalse($bp->isResumed());

        $bp->onMessageReceived(); // 3
        self::assertFalse($bp->isResumed());

        $bp->onMessageReceived(); // 2
        self::assertTrue($bp->isResumed());
    }

    #[Test]
    public function flowControlLifecycle(): void
    {
        $bp = new BackpressureController(highWatermark: 4, lowWatermark: 1);

        // Fill to capacity
        self::assertTrue($bp->canSend());
        $bp->onMessageSent(); // 1
        $bp->onMessageSent(); // 2
        $bp->onMessageSent(); // 3
        self::assertTrue($bp->canSend());
        $bp->onMessageSent(); // 4 — at high watermark
        self::assertFalse($bp->canSend());

        // Drain below low watermark
        $bp->onMessageReceived(); // 3
        $bp->onMessageReceived(); // 2
        self::assertFalse($bp->isResumed());
        $bp->onMessageReceived(); // 1 — at low watermark
        self::assertTrue($bp->isResumed());
        self::assertTrue($bp->canSend());
    }
}
