<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\WebhookEvent;
use Pulsar\Extension\Payments\Domain\WebhookEventType;
use ValueError;

final class WebhookEventTest extends TestCase
{
    #[Test]
    public function fromArrayBuildsEventCorrectly(): void
    {
        $event = WebhookEvent::fromArray([
            'id' => 'evt_1',
            'type' => 'payment_intent.created',
            'created_at' => 1700000000,
            'data' => ['intent_id' => 'pi_1'],
        ]);

        self::assertSame('evt_1', $event->id);
        self::assertSame(WebhookEventType::PaymentIntentCreated, $event->type);
        self::assertSame(1700000000, $event->createdAt->getTimestamp());
        self::assertSame(['intent_id' => 'pi_1'], $event->data);
    }

    #[Test]
    public function fromArrayUsesDefaultsForMissingFields(): void
    {
        $event = WebhookEvent::fromArray([
            'type' => 'charge.failed',
        ]);

        self::assertSame('', $event->id);
        self::assertSame(WebhookEventType::ChargeFailed, $event->type);
        self::assertSame([], $event->data);
    }

    #[Test]
    public function fromArrayThrowsOnInvalidEventType(): void
    {
        $this->expectException(ValueError::class);
        (void) WebhookEvent::fromArray(['type' => 'invalid.type']);
    }
}
