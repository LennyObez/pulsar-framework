<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Mail\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Mail\Webhook\WebhookEventType;

#[CoversClass(WebhookEventType::class)]
final class WebhookEventTypeTest extends TestCase
{
    #[Test]
    public function allCasesHaveExpectedValues(): void
    {
        self::assertSame('bounce', WebhookEventType::Bounce->value);
        self::assertSame('complaint', WebhookEventType::Complaint->value);
        self::assertSame('delivery', WebhookEventType::Delivery->value);
        self::assertSame('open', WebhookEventType::Open->value);
        self::assertSame('click', WebhookEventType::Click->value);
    }

    #[Test]
    public function fiveCasesExist(): void
    {
        self::assertCount(5, WebhookEventType::cases());
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        self::assertNull(WebhookEventType::tryFrom('unsubscribe'));
    }
}
