<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Collector;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Collector\ExceptionCollector;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\Payload\ExceptionPayload;
use Pulsar\Observability\Context\CorrelationContext;
use Pulsar\Observability\Context\CorrelationContextProviderInterface;
use Pulsar\Observability\ErrorTracking\ErrorEvent;
use Pulsar\Observability\ErrorTracking\ErrorFingerprint;
use RuntimeException;

use function date_create_immutable;

#[CoversClass(ExceptionCollector::class)]
final class ExceptionCollectorTest extends TestCase
{
    #[Test]
    public function handleErrorEmitsExceptionPayload(): void
    {
        $emitted = null;
        $contextProvider = $this->createStub(CorrelationContextProviderInterface::class);
        $contextProvider->method('current')->willReturn(null);

        $collector = new ExceptionCollector(
            $contextProvider,
            function (ConsoleEvent $event, ?CorrelationContext $ctx) use (&$emitted): void {
                $emitted = $event;
            },
        );

        $errorEvent = new ErrorEvent(
            fingerprint: new ErrorFingerprint('fp-abc'),
            exceptionClass: RuntimeException::class,
            message: 'Something broke',
            file: '/app/src/Service.php',
            line: 42,
            stackTrace: [['file' => '/app/src/Service.php', 'line' => 42, 'class' => null, 'function' => 'broken']],
            context: [],
            occurredAt: date_create_immutable('2026-01-01'),
        );

        $collector->handleError($errorEvent);

        self::assertInstanceOf(ExceptionPayload::class, $emitted);
        self::assertSame(RuntimeException::class, $emitted->exceptionClass);
        self::assertSame('Something broke', $emitted->message);
        self::assertSame('/app/src/Service.php', $emitted->file);
        self::assertSame(42, $emitted->line);
    }

    #[Test]
    public function handleErrorDoesNothingWhenDisabled(): void
    {
        $emitted = false;
        $contextProvider = $this->createStub(CorrelationContextProviderInterface::class);

        $collector = new ExceptionCollector(
            $contextProvider,
            function () use (&$emitted): void {
                $emitted = true;
            },
        );
        $collector->enabled = false;

        $errorEvent = new ErrorEvent(
            fingerprint: new ErrorFingerprint('fp'),
            exceptionClass: RuntimeException::class,
            message: 'test',
            file: '/tmp/test.php',
            line: 1,
            stackTrace: [],
            context: [],
            occurredAt: date_create_immutable('2026-01-01'),
        );

        $collector->handleError($errorEvent);

        self::assertFalse($emitted);
    }

    #[Test]
    public function handleErrorSilentlySwallowsEmitFailures(): void
    {
        $contextProvider = $this->createStub(CorrelationContextProviderInterface::class);
        $contextProvider->method('current')->willReturn(null);

        $collector = new ExceptionCollector(
            $contextProvider,
            function (): never {
                throw new RuntimeException('emit failed');
            },
        );

        $errorEvent = new ErrorEvent(
            fingerprint: new ErrorFingerprint('fp'),
            exceptionClass: RuntimeException::class,
            message: 'test',
            file: '/tmp/test.php',
            line: 1,
            stackTrace: [],
            context: [],
            occurredAt: date_create_immutable('2026-01-01'),
        );

        $collector->handleError($errorEvent);

        // No exception should propagate
        self::assertTrue(true, 'Emit failure was silently handled');
    }

    #[Test]
    public function enabledPropertyIsToggleable(): void
    {
        $contextProvider = $this->createStub(CorrelationContextProviderInterface::class);

        $collector = new ExceptionCollector(
            $contextProvider,
            function (): void {},
        );

        self::assertTrue($collector->enabled);

        $collector->enabled = false;
        self::assertFalse($collector->enabled);
    }
}
