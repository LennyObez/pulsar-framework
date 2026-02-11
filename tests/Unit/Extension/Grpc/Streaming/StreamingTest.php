<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Grpc\Streaming;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Grpc\Streaming\BackpressureController;
use Pulsar\Extension\Grpc\Streaming\StreamContext;

#[CoversClass(StreamContext::class)]
#[CoversClass(BackpressureController::class)]
final class StreamingTest extends TestCase
{
    // --- StreamContext ---

    #[Test]
    public function initiallyNotCancelled(): void
    {
        $ctx = new StreamContext();

        self::assertFalse($ctx->isCancelled());
    }

    #[Test]
    public function cancelSetsCancelledFlag(): void
    {
        $ctx = new StreamContext();
        $ctx->cancel();

        self::assertTrue($ctx->isCancelled());
    }

    #[Test]
    public function cancelInvokesCallbacks(): void
    {
        $ctx = new StreamContext();
        $called = false;

        $ctx->onCancel(static function () use (&$called): void {
            $called = true;
        });

        $ctx->cancel();

        self::assertTrue($called);
    }

    #[Test]
    public function cancelInvokesMultipleCallbacksInOrder(): void
    {
        $ctx = new StreamContext();
        $order = [];

        $ctx->onCancel(static function () use (&$order): void {
            $order[] = 'first';
        });
        $ctx->onCancel(static function () use (&$order): void {
            $order[] = 'second';
        });

        $ctx->cancel();

        self::assertSame(['first', 'second'], $order);
    }

    #[Test]
    public function doubleCancelIsIdempotent(): void
    {
        $ctx = new StreamContext();
        $count = 0;

        $ctx->onCancel(static function () use (&$count): void {
            $count++;
        });

        $ctx->cancel();
        $ctx->cancel();

        self::assertSame(1, $count);
    }

    #[Test]
    public function onCancelInvokesImmediatelyIfAlreadyCancelled(): void
    {
        $ctx = new StreamContext();
        $ctx->cancel();

        $called = false;
        $ctx->onCancel(static function () use (&$called): void {
            $called = true;
        });

        self::assertTrue($called);
    }

    // --- BackpressureController ---

    #[Test]
    public function defaultWatermarks(): void
    {
        $ctrl = new BackpressureController();

        self::assertSame(64, $ctrl->highWatermark());
        self::assertSame(16, $ctrl->lowWatermark());
        self::assertSame(0, $ctrl->inFlightCount());
        self::assertTrue($ctrl->canSend());
        self::assertTrue($ctrl->isResumed());
    }

    #[Test]
    public function customWatermarks(): void
    {
        $ctrl = new BackpressureController(highWatermark: 10, lowWatermark: 3);

        self::assertSame(10, $ctrl->highWatermark());
        self::assertSame(3, $ctrl->lowWatermark());
    }

    #[Test]
    public function canSendBecomesFalseAtHighWatermark(): void
    {
        $ctrl = new BackpressureController(highWatermark: 3, lowWatermark: 1);

        $ctrl->onMessageSent();
        $ctrl->onMessageSent();
        self::assertTrue($ctrl->canSend());

        $ctrl->onMessageSent();
        self::assertFalse($ctrl->canSend());
        self::assertSame(3, $ctrl->inFlightCount());
    }

    #[Test]
    public function isResumedAfterDroppingToLowWatermark(): void
    {
        $ctrl = new BackpressureController(highWatermark: 3, lowWatermark: 1);

        $ctrl->onMessageSent();
        $ctrl->onMessageSent();
        $ctrl->onMessageSent();
        self::assertFalse($ctrl->isResumed());

        $ctrl->onMessageReceived();
        self::assertFalse($ctrl->isResumed());

        $ctrl->onMessageReceived();
        self::assertTrue($ctrl->isResumed());
    }

    #[Test]
    public function onMessageReceivedDoesNotGoBelowZero(): void
    {
        $ctrl = new BackpressureController();
        $ctrl->onMessageReceived();

        self::assertSame(0, $ctrl->inFlightCount());
    }

    #[Test]
    public function rejectsZeroHighWatermark(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('High watermark must be at least 1');

        new BackpressureController(highWatermark: 0, lowWatermark: 0);
    }

    #[Test]
    public function rejectsNegativeLowWatermark(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Low watermark must be non-negative');

        new BackpressureController(highWatermark: 10, lowWatermark: -1);
    }

    #[Test]
    public function rejectsLowWatermarkEqualToHigh(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Low watermark must be less than high watermark');

        new BackpressureController(highWatermark: 5, lowWatermark: 5);
    }

    #[Test]
    public function rejectsLowWatermarkGreaterThanHigh(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Low watermark must be less than high watermark');

        new BackpressureController(highWatermark: 5, lowWatermark: 10);
    }
}
