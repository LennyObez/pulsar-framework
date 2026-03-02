<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Tests\Unit\Streaming;

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
        $controller = new BackpressureController();

        self::assertSame(64, $controller->highWatermark());
        self::assertSame(16, $controller->lowWatermark());
    }

    #[Test]
    public function customWatermarks(): void
    {
        $controller = new BackpressureController(highWatermark: 100, lowWatermark: 25);

        self::assertSame(100, $controller->highWatermark());
        self::assertSame(25, $controller->lowWatermark());
    }

    #[Test]
    public function canSendWhenBelowHighWatermark(): void
    {
        $controller = new BackpressureController(highWatermark: 3, lowWatermark: 1);

        self::assertTrue($controller->canSend());

        $controller->onMessageSent();
        self::assertTrue($controller->canSend());

        $controller->onMessageSent();
        self::assertTrue($controller->canSend());
    }

    #[Test]
    public function cannotSendAtHighWatermark(): void
    {
        $controller = new BackpressureController(highWatermark: 2, lowWatermark: 1);

        $controller->onMessageSent();
        $controller->onMessageSent();

        self::assertFalse($controller->canSend());
    }

    #[Test]
    public function canSendAfterReceiving(): void
    {
        $controller = new BackpressureController(highWatermark: 2, lowWatermark: 1);

        $controller->onMessageSent();
        $controller->onMessageSent();

        self::assertFalse($controller->canSend());

        $controller->onMessageReceived();

        self::assertTrue($controller->canSend());
    }

    #[Test]
    public function inFlightCountTracksMessages(): void
    {
        $controller = new BackpressureController();

        self::assertSame(0, $controller->inFlightCount());

        $controller->onMessageSent();
        $controller->onMessageSent();

        self::assertSame(2, $controller->inFlightCount());

        $controller->onMessageReceived();

        self::assertSame(1, $controller->inFlightCount());
    }

    #[Test]
    public function inFlightDoesNotGoBelowZero(): void
    {
        $controller = new BackpressureController();

        $controller->onMessageReceived();
        $controller->onMessageReceived();

        self::assertSame(0, $controller->inFlightCount());
    }

    #[Test]
    public function isResumedWhenAtOrBelowLowWatermark(): void
    {
        $controller = new BackpressureController(highWatermark: 4, lowWatermark: 2);

        // Start at 0: resumed
        self::assertTrue($controller->isResumed());

        // Fill to high watermark
        $controller->onMessageSent();
        $controller->onMessageSent();
        $controller->onMessageSent();
        $controller->onMessageSent();

        // At high watermark: not resumed
        self::assertFalse($controller->isResumed());

        // Drain to low watermark
        $controller->onMessageReceived();
        $controller->onMessageReceived();

        // At low watermark: resumed
        self::assertTrue($controller->isResumed());
    }

    #[Test]
    public function highWatermarkMustBeAtLeastOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('High watermark must be at least 1.');

        new BackpressureController(highWatermark: 0, lowWatermark: 0);
    }

    #[Test]
    public function lowWatermarkMustBeNonNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Low watermark must be non-negative.');

        new BackpressureController(highWatermark: 10, lowWatermark: -1);
    }

    #[Test]
    public function lowWatermarkMustBeLessThanHigh(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Low watermark must be less than high watermark.');

        new BackpressureController(highWatermark: 5, lowWatermark: 5);
    }

    #[Test]
    public function lowWatermarkCanBeZero(): void
    {
        $controller = new BackpressureController(highWatermark: 1, lowWatermark: 0);

        self::assertSame(0, $controller->lowWatermark());
        self::assertTrue($controller->isResumed());
    }
}
