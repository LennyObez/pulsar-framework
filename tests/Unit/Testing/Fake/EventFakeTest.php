<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Testing\Fake;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\CausationId;
use Pulsar\Context\CorrelationId;
use Pulsar\Event\EventEnvelope;
use Pulsar\Event\EventMetadata;
use Pulsar\Testing\Fake\EventFake;
use RuntimeException;
use stdClass;

#[CoversClass(EventFake::class)]
final class EventFakeTest extends TestCase
{
    private EventFake $fake;

    protected function setUp(): void
    {
        $this->fake = new EventFake();
    }

    #[Test]
    public function dispatch_records_event(): void
    {
        $event = new stdClass();

        $result = $this->fake->dispatch($event);

        self::assertSame($event, $result);
        self::assertCount(1, $this->fake->dispatched());
    }

    #[Test]
    public function dispatch_envelope_records_envelope(): void
    {
        $envelope = EventEnvelope::wrap(
            'test.event',
            1,
            ['key' => 'value'],
            new EventMetadata(CorrelationId::generate(), CausationId::generate()),
        );

        $result = $this->fake->dispatchEnvelope($envelope);

        self::assertSame($envelope, $result);
        self::assertCount(1, $this->fake->envelopes());
    }

    #[Test]
    public function assert_dispatched_passes_when_event_exists(): void
    {
        $this->fake->dispatch(new stdClass());

        $this->fake->assertDispatched(stdClass::class);
    }

    #[Test]
    public function assert_dispatched_fails_when_event_missing(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('Expected event [stdClass] to be dispatched');

        $this->fake->assertDispatched(stdClass::class);
    }

    #[Test]
    public function assert_dispatched_with_exact_count(): void
    {
        $this->fake->dispatch(new stdClass());
        $this->fake->dispatch(new stdClass());

        $this->fake->assertDispatched(stdClass::class, 2);
    }

    #[Test]
    public function assert_dispatched_with_wrong_count_fails(): void
    {
        $this->fake->dispatch(new stdClass());

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('Expected 3 dispatch(es)');

        $this->fake->assertDispatched(stdClass::class, 3);
    }

    #[Test]
    public function assert_dispatched_with_callback(): void
    {
        $this->fake->dispatch(new stdClass());

        $this->fake->assertDispatchedWith(
            stdClass::class,
            static fn(object $e): bool => $e instanceof stdClass,
        );
    }

    #[Test]
    public function assert_not_dispatched_passes_when_absent(): void
    {
        $this->fake->assertNotDispatched(stdClass::class);
    }

    #[Test]
    public function assert_not_dispatched_fails_when_present(): void
    {
        $this->fake->dispatch(new stdClass());

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('NOT to be dispatched');

        $this->fake->assertNotDispatched(stdClass::class);
    }

    #[Test]
    public function assert_nothing_dispatched_passes_when_empty(): void
    {
        $this->fake->assertNothingDispatched();
    }

    #[Test]
    public function assert_nothing_dispatched_fails_when_not_empty(): void
    {
        $this->fake->dispatch(new stdClass());

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessageIsOrContains('Expected no events');

        $this->fake->assertNothingDispatched();
    }

    #[Test]
    public function assert_envelope_dispatched_by_type(): void
    {
        $envelope = EventEnvelope::wrap(
            'order.created',
            1,
            ['order_id' => '123'],
            new EventMetadata(CorrelationId::generate(), CausationId::generate()),
        );

        $this->fake->dispatchEnvelope($envelope);

        $this->fake->assertEnvelopeDispatched('order.created');
    }

    #[Test]
    public function events_of_type_returns_filtered_list(): void
    {
        $this->fake->dispatch(new stdClass());
        $this->fake->dispatch(new RuntimeException('test'));
        $this->fake->dispatch(new stdClass());

        $events = $this->fake->eventsOfType(stdClass::class);

        self::assertCount(2, $events);
    }

    #[Test]
    public function reset_clears_all_state(): void
    {
        $this->fake->dispatch(new stdClass());
        $envelope = EventEnvelope::wrap('test', 1, [], new EventMetadata(CorrelationId::generate(), CausationId::generate()));
        $this->fake->dispatchEnvelope($envelope);

        $this->fake->reset();

        self::assertCount(0, $this->fake->dispatched());
        self::assertCount(0, $this->fake->envelopes());
    }
}
