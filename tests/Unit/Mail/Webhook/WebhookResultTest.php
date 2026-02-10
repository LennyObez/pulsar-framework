<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Webhook\WebhookEventType;
use Pulsar\Mail\Webhook\WebhookResult;

#[CoversClass(WebhookResult::class)]
final class WebhookResultTest extends TestCase
{
    #[Test]
    public function acceptedFactoryCreatesAcceptedResult(): void
    {
        $result = WebhookResult::accepted('evt-001', WebhookEventType::Bounce, 'msg-001');

        self::assertTrue($result->accepted);
        self::assertSame('evt-001', $result->eventId);
        self::assertSame(WebhookEventType::Bounce, $result->eventType);
        self::assertSame('msg-001', $result->messageId);
    }

    #[Test]
    public function acceptedWithoutMessageId(): void
    {
        $result = WebhookResult::accepted('evt-002', WebhookEventType::Delivery);

        self::assertTrue($result->accepted);
        self::assertNull($result->messageId);
    }

    #[Test]
    public function rejectedFactoryCreatesRejectedResult(): void
    {
        $result = WebhookResult::rejected('evt-003', WebhookEventType::Complaint);

        self::assertFalse($result->accepted);
        self::assertSame('evt-003', $result->eventId);
        self::assertSame(WebhookEventType::Complaint, $result->eventType);
        self::assertNull($result->messageId);
    }

    #[Test]
    public function constructorStoresAllProperties(): void
    {
        $result = new WebhookResult(
            accepted: true,
            eventId: 'evt-004',
            eventType: WebhookEventType::Open,
            messageId: 'msg-004',
        );

        self::assertTrue($result->accepted);
        self::assertSame('evt-004', $result->eventId);
        self::assertSame(WebhookEventType::Open, $result->eventType);
        self::assertSame('msg-004', $result->messageId);
    }
}
