<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Collector;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\QueueConfig;
use Pulsar\Extension\Studio\Console\Collector\InstrumentedQueueManager;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\Payload\JobPayload;
use Pulsar\Extension\Studio\FiberScopedContextProvider;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Queue\QueueDriverInterface;
use Pulsar\Queue\QueueManager;
use RuntimeException;

use function is_array;

#[CoversClass(InstrumentedQueueManager::class)]
final class InstrumentedQueueManagerTest extends TestCase
{
    private QueueDriverInterface&\PHPUnit\Framework\MockObject\Stub $driver;
    private QueueManager $inner;
    private FiberScopedContextProvider $contextProvider;

    /** @var list<array{event: ConsoleEvent, context: ?CorrelationContext}> */
    private array $emittedEvents;

    protected function setUp(): void
    {
        $this->driver = $this->createStub(QueueDriverInterface::class);
        $config = new QueueConfig(defaultQueue: 'default');
        $this->inner = new QueueManager($config, $this->driver);
        $this->contextProvider = new FiberScopedContextProvider();
        $this->emittedEvents = [];
    }

    private function createInstrumented(): InstrumentedQueueManager
    {
        return new InstrumentedQueueManager(
            $this->inner,
            $this->contextProvider,
            function (ConsoleEvent $event, ?CorrelationContext $context): void {
                $this->emittedEvents[] = ['event' => $event, 'context' => $context];
            },
        );
    }

    #[Test]
    public function it_dispatches_to_inner_and_returns_job_id(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('push')
            ->with(
                'default',
                'App\\Jobs\\SendEmail',
                self::callback(static function (string $serialized): bool {
                    $envelope = json_decode($serialized, true);

                    return is_array($envelope)
                        && $envelope['jobClass'] === 'App\\Jobs\\SendEmail'
                        && $envelope['payload'] === '{"to":"a@b.com"}'
                        && $envelope['queue'] === 'default';
                }),
                0,
            );

        $config = new QueueConfig(defaultQueue: 'default');
        $inner = new QueueManager($config, $driver);
        $manager = new InstrumentedQueueManager(
            $inner,
            $this->contextProvider,
            function (ConsoleEvent $event, ?CorrelationContext $context): void {
                $this->emittedEvents[] = ['event' => $event, 'context' => $context];
            },
        );
        $id = $manager->dispatch('App\\Jobs\\SendEmail', '{"to":"a@b.com"}');

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    #[Test]
    public function it_emits_job_queued_event_on_dispatch(): void
    {
        $this->driver->method('push')->willReturn('job-002');

        $manager = $this->createInstrumented();
        $manager->dispatch('App\\Jobs\\ProcessReport', '{}', 'reports');

        self::assertCount(1, $this->emittedEvents);

        /** @var JobPayload $event */
        $event = $this->emittedEvents[0]['event'];
        self::assertInstanceOf(JobPayload::class, $event);
        self::assertSame('App\\Jobs\\ProcessReport', $event->jobClass);
        self::assertSame('queued', $event->status);
        self::assertSame('reports', $event->queue);
    }

    #[Test]
    public function it_does_not_emit_event_when_disabled(): void
    {
        $this->driver->method('push')->willReturn('job-003');

        $manager = $this->createInstrumented();
        $manager->enabled = false;

        $manager->dispatch('App\\Jobs\\Noop', '{}');

        self::assertCount(0, $this->emittedEvents);
    }

    #[Test]
    public function it_delegates_size_to_inner(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('size')
            ->with('default')
            ->willReturn(7);

        $config = new QueueConfig(defaultQueue: 'default');
        $inner = new QueueManager($config, $driver);
        $manager = new InstrumentedQueueManager(
            $inner,
            $this->contextProvider,
            function (ConsoleEvent $event, ?CorrelationContext $context): void {
                $this->emittedEvents[] = ['event' => $event, 'context' => $context];
            },
        );

        self::assertSame(7, $manager->size());
    }

    #[Test]
    public function it_delegates_size_with_explicit_queue(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('size')
            ->with('emails')
            ->willReturn(3);

        $config = new QueueConfig(defaultQueue: 'default');
        $inner = new QueueManager($config, $driver);
        $manager = new InstrumentedQueueManager(
            $inner,
            $this->contextProvider,
            function (ConsoleEvent $event, ?CorrelationContext $context): void {
                $this->emittedEvents[] = ['event' => $event, 'context' => $context];
            },
        );

        self::assertSame(3, $manager->size('emails'));
    }

    #[Test]
    public function it_exposes_inner_queue_manager(): void
    {
        $manager = $this->createInstrumented();

        self::assertSame($this->inner, $manager->inner());
    }

    #[Test]
    public function it_dispatches_with_delay(): void
    {
        $driver = $this->createMock(QueueDriverInterface::class);
        $driver
            ->expects(self::once())
            ->method('push')
            ->with(
                'default',
                'App\\Jobs\\Delayed',
                self::callback(static function (string $serialized): bool {
                    $envelope = json_decode($serialized, true);

                    return is_array($envelope)
                        && $envelope['jobClass'] === 'App\\Jobs\\Delayed'
                        && $envelope['payload'] === '{}'
                        && $envelope['queue'] === 'default';
                }),
                120,
            );

        $config = new QueueConfig(defaultQueue: 'default');
        $inner = new QueueManager($config, $driver);
        $manager = new InstrumentedQueueManager(
            $inner,
            $this->contextProvider,
            function (ConsoleEvent $event, ?CorrelationContext $context): void {
                $this->emittedEvents[] = ['event' => $event, 'context' => $context];
            },
        );
        $id = $manager->dispatch('App\\Jobs\\Delayed', '{}', null, 120);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        self::assertCount(1, $this->emittedEvents);
    }

    #[Test]
    public function it_passes_correlation_context_to_emit(): void
    {
        $this->driver->method('push')->willReturn('job-ctx');

        $correlationContext = new CorrelationContext(
            requestId: 'req-001',
            traceId: 'trace-001',
        );
        $scope = $this->contextProvider->enter($correlationContext);

        try {
            $manager = $this->createInstrumented();
            $manager->dispatch('App\\Jobs\\Noop', '{}');

            self::assertCount(1, $this->emittedEvents);
            self::assertSame($correlationContext, $this->emittedEvents[0]['context']);
        } finally {
            $scope->close();
        }
    }

    #[Test]
    public function it_passes_null_context_when_no_scope_active(): void
    {
        $this->driver->method('push')->willReturn('job-no-ctx');

        $manager = $this->createInstrumented();
        $manager->dispatch('App\\Jobs\\Noop', '{}');

        self::assertCount(1, $this->emittedEvents);
        self::assertNull($this->emittedEvents[0]['context']);
    }

    #[Test]
    public function it_swallows_emit_exceptions(): void
    {
        $this->driver->method('push')->willReturn('job-emit-fail');

        $manager = new InstrumentedQueueManager(
            $this->inner,
            $this->contextProvider,
            function (ConsoleEvent $event, ?CorrelationContext $context): void {
                throw new RuntimeException('Emit failure');
            },
        );

        // Should not throw — emit errors are silently caught
        $id = $manager->dispatch('App\\Jobs\\Noop', '{}');

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    #[Test]
    public function it_starts_enabled_by_default(): void
    {
        $manager = $this->createInstrumented();

        self::assertTrue($manager->enabled);
    }
}
