<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Webhook\WebhookProcessingResult;
use Pulsar\Webhook\WebhookProcessingStatus;

#[CoversClass(WebhookProcessingResult::class)]
#[CoversClass(WebhookProcessingStatus::class)]
final class WebhookProcessingResultExtendedTest extends TestCase
{
    #[Test]
    public function processedStatusWithEventId(): void
    {
        $result = new WebhookProcessingResult(
            status: WebhookProcessingStatus::Processed,
            eventId: 'evt-123',
        );

        self::assertSame(WebhookProcessingStatus::Processed, $result->status);
        self::assertSame('evt-123', $result->eventId);
        self::assertNull($result->error);
    }

    #[Test]
    public function invalidSignatureStatusWithoutEventId(): void
    {
        $result = new WebhookProcessingResult(
            status: WebhookProcessingStatus::InvalidSignature,
        );

        self::assertSame(WebhookProcessingStatus::InvalidSignature, $result->status);
        self::assertNull($result->eventId);
        self::assertNull($result->error);
    }

    #[Test]
    public function replayStatusWithEventId(): void
    {
        $result = new WebhookProcessingResult(
            status: WebhookProcessingStatus::Replay,
            eventId: 'evt-456',
        );

        self::assertSame(WebhookProcessingStatus::Replay, $result->status);
        self::assertSame('evt-456', $result->eventId);
    }

    #[Test]
    public function handlerErrorStatusWithErrorMessage(): void
    {
        $result = new WebhookProcessingResult(
            status: WebhookProcessingStatus::HandlerError,
            eventId: 'evt-789',
            error: 'Handler threw an exception',
        );

        self::assertSame(WebhookProcessingStatus::HandlerError, $result->status);
        self::assertSame('evt-789', $result->eventId);
        self::assertSame('Handler threw an exception', $result->error);
    }

    #[Test]
    public function defaultsAreNull(): void
    {
        $result = new WebhookProcessingResult(WebhookProcessingStatus::Processed);

        self::assertNull($result->eventId);
        self::assertNull($result->error);
    }

    #[Test]
    public function processingStatusEnumHasFourCases(): void
    {
        $cases = WebhookProcessingStatus::cases();

        self::assertCount(4, $cases);
        self::assertContains(WebhookProcessingStatus::Processed, $cases);
        self::assertContains(WebhookProcessingStatus::Replay, $cases);
        self::assertContains(WebhookProcessingStatus::InvalidSignature, $cases);
        self::assertContains(WebhookProcessingStatus::HandlerError, $cases);
    }
}
