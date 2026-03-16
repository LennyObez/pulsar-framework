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

    /**
     * F22.20 / F22.7: a non-numeric `created_at` (`"abc"`,
     * `"1700-01-01"`, attacker-controlled garbage) MUST NOT
     * raise `DateMalformedStringException` from the inner
     * `new DateTimeImmutable('@<value>')`. The (int) cast in
     * the factory normalises everything non-numeric to 0,
     * which renders as `1970-01-01` — recognisable as the
     * "malformed timestamp" sentinel and safely caught by the
     * upstream handler's `Throwable` clause.
     */
    #[Test]
    public function fromArrayCoercesNonNumericCreatedAtToEpoch(): void
    {
        $event = WebhookEvent::fromArray([
            'id' => 'evt_garbage',
            'type' => 'charge.failed',
            'created_at' => 'abc',
            'data' => [],
        ]);

        self::assertSame(0, $event->createdAt->getTimestamp());
    }

    /**
     * F22.20: same coercion for an outright wrong type
     * (`true`, `array`, `null` after early-key-missing). PHP
     * casts these to 0 / 1 — neither produces a
     * DateMalformedStringException, but the boundary contract
     * still has to keep them in the sentinel-timestamp range
     * (epoch ± 1 second) so the upstream handler classifies
     * them as malformed.
     */
    #[Test]
    public function fromArrayCoercesArrayCreatedAtToSentinelEpoch(): void
    {
        $event = WebhookEvent::fromArray([
            'id' => 'evt_garbage',
            'type' => 'charge.failed',
            'created_at' => ['nested' => 'garbage'],
            'data' => [],
        ]);

        // PHP `(int)` of an array is 1; a real timestamp is far
        // beyond that. Pin to the sentinel-range upper bound.
        self::assertLessThanOrEqual(1, $event->createdAt->getTimestamp());
    }
}
