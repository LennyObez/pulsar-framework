<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventFactory;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Redaction\RedactionPipeline;
use Pulsar\Extension\Studio\Console\Storage\EventStoreInterface;
use Pulsar\Extension\Studio\StudioManager;
use RuntimeException;

final class StudioManagerTest extends TestCase
{
    private function makeFactory(): EventFactory
    {
        return EventFactory::create('testing');
    }

    #[Test]
    public function store_returns_injected_store(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $factory = $this->makeFactory();
        $redaction = new RedactionPipeline();

        $manager = new StudioManager($store, $factory, $redaction);

        self::assertSame($store, $manager->store());
    }

    #[Test]
    public function emit_callback_returns_callable(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $factory = $this->makeFactory();
        $redaction = new RedactionPipeline();

        $manager = new StudioManager($store, $factory, $redaction);
        $callback = $manager->emitCallback();

        self::assertIsCallable($callback);
    }

    #[Test]
    public function ingest_swallows_exceptions(): void
    {
        $store = $this->createStub(EventStoreInterface::class);
        $store->method('store')->willThrowException(new RuntimeException('Test error'));
        $factory = $this->makeFactory();
        $redaction = new RedactionPipeline();

        $manager = new StudioManager($store, $factory, $redaction);

        // Create a real event to ingest
        $event = new class implements ConsoleEvent {
            public function eventType(): EventType
            {
                return EventType::Request;
            }

            public function schemaVersion(): EventVersion
            {
                return EventVersion::V1;
            }

            public function toArray(): array
            {
                return ['test' => 'data'];
            }
        };

        // Should not throw: Studio must never crash the app
        $manager->ingest($event);

        // Verifies the exception was swallowed (no throw reached here)
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function ingest_with_zero_sampling_rate_skips_all(): void
    {
        /** @var EventStoreInterface&MockObject $store */
        $store = $this->createMock(EventStoreInterface::class);
        $store->expects(self::never())->method('store');

        $factory = $this->makeFactory();
        $redaction = new RedactionPipeline();

        $manager = new StudioManager(
            store: $store,
            eventFactory: $factory,
            redactionPipeline: $redaction,
            samplingRate: 0.0,
        );

        $event = new class implements ConsoleEvent {
            public function eventType(): EventType
            {
                return EventType::Request;
            }

            public function schemaVersion(): EventVersion
            {
                return EventVersion::V1;
            }

            public function toArray(): array
            {
                return ['test' => 'data'];
            }
        };

        $manager->ingest($event);
    }
}
