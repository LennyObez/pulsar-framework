<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Webhook\WebhookProcessingResult;
use Pulsar\Webhook\WebhookProcessingStatus;

#[CoversClass(WebhookProcessingResult::class)]
final class WebhookProcessingResultTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $result = new WebhookProcessingResult(
            status: WebhookProcessingStatus::Processed,
            eventId: 'evt-123',
            error: null,
        );

        self::assertSame(WebhookProcessingStatus::Processed, $result->status);
        self::assertSame('evt-123', $result->eventId);
        self::assertNull($result->error);
    }

    #[Test]
    public function constructorDefaultsEventIdAndErrorToNull(): void
    {
        $result = new WebhookProcessingResult(
            status: WebhookProcessingStatus::InvalidSignature,
        );

        self::assertNull($result->eventId);
        self::assertNull($result->error);
    }

    #[Test]
    public function handlerErrorIncludesEventIdAndError(): void
    {
        $result = new WebhookProcessingResult(
            status: WebhookProcessingStatus::HandlerError,
            eventId: 'evt-456',
            error: 'Handler failed',
        );

        self::assertSame(WebhookProcessingStatus::HandlerError, $result->status);
        self::assertSame('evt-456', $result->eventId);
        self::assertSame('Handler failed', $result->error);
    }
}
