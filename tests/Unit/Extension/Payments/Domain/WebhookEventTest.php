<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\WebhookEvent;
use Pulsar\Extension\Payments\Domain\WebhookEventType;

#[CoversClass(WebhookEvent::class)]
final class WebhookEventTest extends TestCase
{
    #[Test]
    public function fromArrayBuildsEvent(): void
    {
        $payload = [
            'id' => 'evt_123',
            'type' => 'payment_intent.created',
            'created_at' => 1700000000,
            'data' => ['intent_id' => 'pi_test'],
        ];

        $event = WebhookEvent::fromArray($payload);

        self::assertSame('evt_123', $event->id);
        self::assertSame(WebhookEventType::PaymentIntentCreated, $event->type);
        self::assertSame(1700000000, $event->createdAt->getTimestamp());
        self::assertSame(['intent_id' => 'pi_test'], $event->data);
    }

    #[Test]
    public function fromArrayWithDefaultData(): void
    {
        $payload = [
            'id' => 'evt_456',
            'type' => 'charge.failed',
            'created_at' => 1700000000,
        ];

        $event = WebhookEvent::fromArray($payload);

        self::assertSame([], $event->data);
    }
}
