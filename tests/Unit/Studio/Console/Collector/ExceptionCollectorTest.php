<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Collector;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\ErrorTracking\ErrorEvent;
use Pulsar\Observability\ErrorTracking\ErrorFingerprint;
use Pulsar\Studio\Console\Collector\ExceptionCollector;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\Payload\ExceptionPayload;
use Pulsar\Studio\CorrelationContext;
use Pulsar\Studio\CorrelationContextProviderInterface;
use RuntimeException;

#[CoversClass(ExceptionCollector::class)]
final class ExceptionCollectorTest extends TestCase
{
    /** @var list<array{event: ConsoleEvent, context: ?CorrelationContext}> */
    private array $emittedEvents = [];

    protected function setUp(): void
    {
        $this->emittedEvents = [];
    }

    #[Test]
    public function handleErrorEmitsExceptionPayload(): void
    {
        $collector = $this->createCollector();
        $errorEvent = $this->createErrorEvent();

        $collector->handleError($errorEvent);

        self::assertCount(1, $this->emittedEvents);
        self::assertInstanceOf(ExceptionPayload::class, $this->emittedEvents[0]['event']);
    }

    #[Test]
    public function handleErrorRecordsExceptionClass(): void
    {
        $collector = $this->createCollector();
        $errorEvent = $this->createErrorEvent(exceptionClass: RuntimeException::class);

        $collector->handleError($errorEvent);

        /** @var ExceptionPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame(RuntimeException::class, $payload->exceptionClass);
    }

    #[Test]
    public function handleErrorRecordsMessage(): void
    {
        $collector = $this->createCollector();
        $errorEvent = $this->createErrorEvent(message: 'Something went wrong');

        $collector->handleError($errorEvent);

        /** @var ExceptionPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('Something went wrong', $payload->message);
    }

    #[Test]
    public function handleErrorRecordsFile(): void
    {
        $collector = $this->createCollector();
        $errorEvent = $this->createErrorEvent(file: '/path/to/file.php');

        $collector->handleError($errorEvent);

        /** @var ExceptionPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('/path/to/file.php', $payload->file);
    }

    #[Test]
    public function handleErrorRecordsLine(): void
    {
        $collector = $this->createCollector();
        $errorEvent = $this->createErrorEvent(line: 42);

        $collector->handleError($errorEvent);

        /** @var ExceptionPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame(42, $payload->line);
    }

    #[Test]
    public function handleErrorRecordsFingerprint(): void
    {
        $collector = $this->createCollector();
        $fingerprint = new ErrorFingerprint('abc123fingerprint');
        $errorEvent = $this->createErrorEvent(fingerprint: $fingerprint);

        $collector->handleError($errorEvent);

        /** @var ExceptionPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame('abc123fingerprint', $payload->fingerprint);
    }

    #[Test]
    public function handleErrorRecordsStackTrace(): void
    {
        $collector = $this->createCollector();
        $stackTrace = [
            ['file' => '/app/src/Service.php', 'line' => 10, 'class' => 'App\\Service', 'function' => 'process'],
            ['file' => '/app/src/Handler.php', 'line' => 25, 'class' => 'App\\Handler', 'function' => 'handle'],
        ];
        $errorEvent = $this->createErrorEvent(stackTrace: $stackTrace);

        $collector->handleError($errorEvent);

        /** @var ExceptionPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertCount(2, $payload->stackTrace);
        self::assertSame('/app/src/Service.php', $payload->stackTrace[0]['file']);
        self::assertSame(10, $payload->stackTrace[0]['line']);
        self::assertSame('App\\Service', $payload->stackTrace[0]['class']);
        self::assertSame('process', $payload->stackTrace[0]['function']);
    }

    #[Test]
    public function handleErrorUsesCurrentCorrelationContext(): void
    {
        $context = new CorrelationContext(
            requestId: 'req-123',
            traceId: 'trace-456',
            spanId: 'span-789',
        );
        $contextProvider = $this->createContextProvider($context);
        $collector = $this->createCollector($contextProvider);
        $errorEvent = $this->createErrorEvent();

        $collector->handleError($errorEvent);

        self::assertSame($context, $this->emittedEvents[0]['context']);
    }

    #[Test]
    public function handleErrorHandlesNullCorrelationContext(): void
    {
        $contextProvider = $this->createContextProvider(null);
        $collector = $this->createCollector($contextProvider);
        $errorEvent = $this->createErrorEvent();

        $collector->handleError($errorEvent);

        self::assertNull($this->emittedEvents[0]['context']);
    }

    #[Test]
    public function handleErrorSkipsEmissionWhenDisabled(): void
    {
        $collector = $this->createCollector();
        $collector->setEnabled(false);
        $errorEvent = $this->createErrorEvent();

        $collector->handleError($errorEvent);

        self::assertCount(0, $this->emittedEvents);
    }

    #[Test]
    public function isEnabledReturnsTrueByDefault(): void
    {
        $collector = $this->createCollector();

        self::assertTrue($collector->isEnabled());
    }

    #[Test]
    public function setEnabledChangesEnabledState(): void
    {
        $collector = $this->createCollector();

        $collector->setEnabled(false);
        self::assertFalse($collector->isEnabled());

        $collector->setEnabled(true);
        self::assertTrue($collector->isEnabled());
    }

    #[Test]
    public function handleErrorSilentlySwallowsEmitExceptions(): void
    {
        $contextProvider = $this->createContextProvider(null);
        $collector = new ExceptionCollector(
            contextProvider: $contextProvider,
            emit: function (ConsoleEvent $event, ?CorrelationContext $context): void {
                throw new RuntimeException('Emit failed');
            },
        );
        $errorEvent = $this->createErrorEvent();

        // Should not throw - verification is that we reach the end without exception
        $this->expectNotToPerformAssertions();

        $collector->handleError($errorEvent);
    }

    #[Test]
    public function handleErrorWithEmptyStackTrace(): void
    {
        $collector = $this->createCollector();
        $errorEvent = $this->createErrorEvent(stackTrace: []);

        $collector->handleError($errorEvent);

        /** @var ExceptionPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertSame([], $payload->stackTrace);
    }

    #[Test]
    public function handleErrorWithNullStackTraceFields(): void
    {
        $collector = $this->createCollector();
        $stackTrace = [
            ['file' => '/app/src/Handler.php', 'line' => 25, 'class' => null, 'function' => null],
        ];
        $errorEvent = $this->createErrorEvent(stackTrace: $stackTrace);

        $collector->handleError($errorEvent);

        /** @var ExceptionPayload $payload */
        $payload = $this->emittedEvents[0]['event'];
        self::assertNull($payload->stackTrace[0]['class']);
        self::assertNull($payload->stackTrace[0]['function']);
    }

    private function createCollector(
        ?CorrelationContextProviderInterface $contextProvider = null,
    ): ExceptionCollector {
        return new ExceptionCollector(
            contextProvider: $contextProvider ?? $this->createContextProvider(null),
            emit: function (ConsoleEvent $event, ?CorrelationContext $context): void {
                $this->emittedEvents[] = ['event' => $event, 'context' => $context];
            },
        );
    }

    private function createContextProvider(?CorrelationContext $context): CorrelationContextProviderInterface
    {
        return new class ($context) implements CorrelationContextProviderInterface {
            public function __construct(
                private readonly ?CorrelationContext $context,
            ) {}

            public function current(): ?CorrelationContext
            {
                return $this->context;
            }
        };
    }

    /**
     * @param list<array{file: string, line: int, class: ?string, function: ?string}> $stackTrace
     */
    private function createErrorEvent(
        string $exceptionClass = RuntimeException::class,
        string $message = 'Test error message',
        string $file = '/app/src/TestClass.php',
        int $line = 100,
        ?ErrorFingerprint $fingerprint = null,
        array $stackTrace = [],
    ): ErrorEvent {
        return new ErrorEvent(
            fingerprint: $fingerprint ?? new ErrorFingerprint('test-fingerprint-hash'),
            exceptionClass: $exceptionClass,
            message: $message,
            file: $file,
            line: $line,
            stackTrace: $stackTrace,
            context: [],
            occurredAt: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }
}
