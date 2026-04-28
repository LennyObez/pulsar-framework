<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Domain;

use InvalidArgumentException;
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

    /**
     * F22.13: payloads missing the `id` field used to flow
     * through with `''`, then a downstream replay-store would
     * happily key on the empty string. The boundary now rejects
     * the partial deserialization fast.
     */
    #[Test]
    public function fromArrayThrowsOnMissingId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WebhookEvent.id missing');

        (void) WebhookEvent::fromArray(['type' => 'charge.failed']);
    }

    /**
     * F22.13: `data` is typed as `array<string, mixed>` in the
     * constructor; a non-array supplied through fromArray now
     * fails at the boundary instead of constructing a domain
     * object with an invalid `data` field.
     */
    #[Test]
    public function fromArrayThrowsOnNonArrayData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('WebhookEvent.data is not an array');

        (void) WebhookEvent::fromArray([
            'id' => 'evt_x',
            'type' => 'charge.failed',
            'data' => 'this-should-be-an-array',
        ]);
    }

    #[Test]
    public function fromArrayThrowsOnInvalidEventType(): void
    {
        $this->expectException(ValueError::class);
        (void) WebhookEvent::fromArray([
            'id' => 'evt_x',
            'type' => 'invalid.type',
        ]);
    }
}
