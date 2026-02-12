<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Collector;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Collector\ExceptionCollector;
use Pulsar\Extension\Studio\Console\Collector\FeatureFlagCollector;
use Pulsar\Extension\Studio\Console\Collector\LogCollector;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\Payload\ExceptionPayload;
use Pulsar\Extension\Studio\Console\Event\Payload\FeatureFlagPayload;
use Pulsar\Extension\Studio\Console\Event\Payload\LogEntryPayload;
use Pulsar\FeatureFlag\FlagContext;
use Pulsar\FeatureFlag\FlagEvaluation;
use Pulsar\FeatureFlag\FlagEvaluationReason;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Observability\Context\CorrelationContextProviderInterface;
use Pulsar\Observability\ErrorTracking\ErrorEvent;
use Pulsar\Observability\ErrorTracking\ErrorFingerprint;
use Pulsar\Observability\Log\LogEntry;
use Pulsar\Observability\Log\LogLevel;
use RuntimeException;

#[CoversClass(ExceptionCollector::class)]
#[CoversClass(LogCollector::class)]
#[CoversClass(FeatureFlagCollector::class)]
final class CollectorTest extends TestCase
{
    // --- ExceptionCollector ---

    #[Test]
    public function exceptionCollectorEmitsExceptionPayload(): void
    {
        $emitted = [];
        $contextProvider = $this->buildContextProvider();
        $emit = static function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$emitted): void {
            $emitted[] = ['event' => $event, 'ctx' => $ctx];
        };

        $collector = new ExceptionCollector($contextProvider, $emit);
        $errorEvent = $this->buildErrorEvent();

        $collector->handleError($errorEvent);

        self::assertCount(1, $emitted);
        $payload = $emitted[0]['event'];
        self::assertInstanceOf(ExceptionPayload::class, $payload);
        self::assertSame('RuntimeException', $payload->exceptionClass);
        self::assertSame('test error', $payload->message);
        self::assertSame('test.php', $payload->file);
        self::assertSame(42, $payload->line);
    }

    #[Test]
    public function exceptionCollectorDoesNotEmitWhenDisabled(): void
    {
        $emitted = [];
        $emit = static function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$emitted): void {
            $emitted[] = $event;
        };

        $collector = new ExceptionCollector($this->buildContextProvider(), $emit);
        $collector->enabled = false;

        $collector->handleError($this->buildErrorEvent());

        self::assertCount(0, $emitted);
    }

    #[Test]
    public function exceptionCollectorSwallowsEmitExceptions(): void
    {
        $emit = static function (): void {
            throw new RuntimeException('emit failed');
        };

        $collector = new ExceptionCollector($this->buildContextProvider(), $emit);
        // Should not throw
        $collector->handleError($this->buildErrorEvent());

        $this->addToAssertionCount(1); // No exception propagated
    }

    // --- LogCollector ---

    #[Test]
    public function logCollectorEmitsLogEntryPayload(): void
    {
        $emitted = [];
        $emit = static function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$emitted): void {
            $emitted[] = $event;
        };

        $collector = new LogCollector($this->buildContextProvider(), $emit);
        $entry = new LogEntry(
            level: LogLevel::Warning,
            message: 'Low disk space',
            context: ['free_mb' => 100],
            channel: 'system',
            timestamp: new DateTimeImmutable(),
        );

        $collector->write($entry);

        self::assertCount(1, $emitted);
        $payload = $emitted[0];
        self::assertInstanceOf(LogEntryPayload::class, $payload);
        self::assertSame('warning', $payload->level);
        self::assertSame('Low disk space', $payload->message);
        self::assertSame('system', $payload->channel);
        self::assertSame(['free_mb' => 100], $payload->context);
    }

    #[Test]
    public function logCollectorDoesNotEmitWhenDisabled(): void
    {
        $emitted = [];
        $emit = static function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$emitted): void {
            $emitted[] = $event;
        };

        $collector = new LogCollector($this->buildContextProvider(), $emit);
        $collector->enabled = false;

        $collector->write(new LogEntry(LogLevel::Info, 'ignored', [], 'app', new DateTimeImmutable()));

        self::assertCount(0, $emitted);
    }

    #[Test]
    public function logCollectorSwallowsEmitExceptions(): void
    {
        $emit = static function (): void {
            throw new RuntimeException('emit failed');
        };

        $collector = new LogCollector($this->buildContextProvider(), $emit);
        $collector->write(new LogEntry(LogLevel::Error, 'msg', [], 'app', new DateTimeImmutable()));

        $this->addToAssertionCount(1); // No exception propagated
    }

    // --- FeatureFlagCollector ---

    #[Test]
    public function featureFlagCollectorEmitsPayload(): void
    {
        $emitted = [];
        $emit = static function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$emitted): void {
            $emitted[] = $event;
        };

        $collector = new FeatureFlagCollector($this->buildContextProvider(), $emit);
        $evaluation = new FlagEvaluation(
            flagName: 'dark_mode',
            result: true,
            reason: FlagEvaluationReason::UserMatch,
            context: new FlagContext(userId: 'user-1'),
            evaluatedAt: new DateTimeImmutable(),
        );

        $collector->handleEvaluation($evaluation);

        self::assertCount(1, $emitted);
        $payload = $emitted[0];
        self::assertInstanceOf(FeatureFlagPayload::class, $payload);
        self::assertSame('dark_mode', $payload->flagName);
        self::assertTrue($payload->result);
        self::assertSame('user_match', $payload->reason);
        self::assertSame('user-1', $payload->contextIdentifier);
    }

    #[Test]
    public function featureFlagCollectorUseTenantIdWhenNoUserId(): void
    {
        $emitted = [];
        $emit = static function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$emitted): void {
            $emitted[] = $event;
        };

        $collector = new FeatureFlagCollector($this->buildContextProvider(), $emit);
        $evaluation = new FlagEvaluation(
            flagName: 'beta_feature',
            result: false,
            reason: FlagEvaluationReason::FlagDisabled,
            context: new FlagContext(tenantId: 'tenant-1'),
            evaluatedAt: new DateTimeImmutable(),
        );

        $collector->handleEvaluation($evaluation);

        $payload = $emitted[0];
        self::assertInstanceOf(FeatureFlagPayload::class, $payload);
        self::assertSame('tenant-1', $payload->contextIdentifier);
    }

    #[Test]
    public function featureFlagCollectorDoesNotEmitWhenDisabled(): void
    {
        $emitted = [];
        $emit = static function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$emitted): void {
            $emitted[] = $event;
        };

        $collector = new FeatureFlagCollector($this->buildContextProvider(), $emit);
        $collector->enabled = false;

        $collector->handleEvaluation(new FlagEvaluation(
            flagName: 'test',
            result: true,
            reason: FlagEvaluationReason::FlagEnabled,
            context: new FlagContext(),
            evaluatedAt: new DateTimeImmutable(),
        ));

        self::assertCount(0, $emitted);
    }

    private function buildContextProvider(): CorrelationContextProviderInterface
    {
        $provider = $this->createStub(CorrelationContextProviderInterface::class);
        $provider->method('current')->willReturn(new CorrelationContext(requestId: 'req-1'));

        return $provider;
    }

    private function buildErrorEvent(): ErrorEvent
    {
        return new ErrorEvent(
            fingerprint: new ErrorFingerprint('fp-hash'),
            exceptionClass: 'RuntimeException',
            message: 'test error',
            file: 'test.php',
            line: 42,
            stackTrace: [['file' => 'test.php', 'line' => 42, 'class' => null, 'function' => 'main']],
            context: [],
            occurredAt: new DateTimeImmutable(),
        );
    }
}
