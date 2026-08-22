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

    #[Test]
    public function fromArrayCoercesNonNumericCreatedAtToEpoch(): void
    {
        // `created_at` is attacker-controlled: a `?? 0` alone lets a
        // non-numeric value through to `new DateTimeImmutable('@<string>')`,
        // which raises DateMalformedStringException on PHP 8.3+. The
        // (int) cast normalises any non-numeric value to 0 so the
        // constructor receives the int shape it expects.
        $payload = [
            'id' => 'evt_garbage',
            'type' => 'charge.failed',
            'created_at' => 'not-a-timestamp',
        ];

        $event = WebhookEvent::fromArray($payload);

        self::assertSame(0, $event->createdAt->getTimestamp());
    }

    #[Test]
    public function fromArrayCoercesMissingCreatedAtToEpoch(): void
    {
        $payload = [
            'id' => 'evt_no_ts',
            'type' => 'charge.failed',
        ];

        $event = WebhookEvent::fromArray($payload);

        self::assertSame(0, $event->createdAt->getTimestamp());
    }

    #[Test]
    public function fromArrayCoercesNumericStringCreatedAt(): void
    {
        // Vendors that send timestamps as strings (Stripe-style)
        // should still produce the right epoch — the (int) cast
        // accepts numeric strings in the standard PHP semantics.
        $payload = [
            'id' => 'evt_str',
            'type' => 'charge.failed',
            'created_at' => '1700000000',
        ];

        $event = WebhookEvent::fromArray($payload);

        self::assertSame(1700000000, $event->createdAt->getTimestamp());
    }
}
