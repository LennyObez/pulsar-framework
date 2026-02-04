<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\ExceptionPayload;

#[CoversClass(ExceptionPayload::class)]
final class ExceptionPayloadTest extends TestCase
{
    #[Test]
    public function implementsConsoleEventInterface(): void
    {
        $payload = new ExceptionPayload(
            exceptionClass: 'RuntimeException',
            message: 'An error occurred',
            file: '/app/src/Service.php',
            line: 42,
            fingerprint: 'abc123',
            stackTrace: [],
        );

        self::assertInstanceOf(ConsoleEvent::class, $payload);
    }

    #[Test]
    public function eventTypeReturnsException(): void
    {
        $payload = new ExceptionPayload(
            exceptionClass: 'RuntimeException',
            message: 'An error occurred',
            file: '/app/src/Service.php',
            line: 42,
            fingerprint: 'abc123',
            stackTrace: [],
        );

        self::assertSame(EventType::Exception, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        $payload = new ExceptionPayload(
            exceptionClass: 'RuntimeException',
            message: 'An error occurred',
            file: '/app/src/Service.php',
            line: 42,
            fingerprint: 'abc123',
            stackTrace: [],
        );

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function constructorSetsAllRequiredProperties(): void
    {
        $stackTrace = [
            ['file' => '/app/src/Controller.php', 'line' => 100, 'class' => 'App\\Controller', 'function' => 'handle'],
            ['file' => '/app/src/Router.php', 'line' => 50, 'class' => 'App\\Router', 'function' => 'dispatch'],
        ];

        $payload = new ExceptionPayload(
            exceptionClass: 'InvalidArgumentException',
            message: 'Invalid parameter provided',
            file: '/app/src/Validator.php',
            line: 75,
            fingerprint: 'def456',
            stackTrace: $stackTrace,
        );

        self::assertSame('InvalidArgumentException', $payload->exceptionClass);
        self::assertSame('Invalid parameter provided', $payload->message);
        self::assertSame('/app/src/Validator.php', $payload->file);
        self::assertSame(75, $payload->line);
        self::assertSame('def456', $payload->fingerprint);
        self::assertSame($stackTrace, $payload->stackTrace);
        self::assertNull($payload->previousClass);
        self::assertNull($payload->previousMessage);
    }

    #[Test]
    public function constructorSetsOptionalPreviousExceptionProperties(): void
    {
        $payload = new ExceptionPayload(
            exceptionClass: 'DatabaseException',
            message: 'Connection failed',
            file: '/app/src/Database.php',
            line: 30,
            fingerprint: 'ghi789',
            stackTrace: [],
            previousClass: 'PDOException',
            previousMessage: 'Could not connect to database server',
        );

        self::assertSame('PDOException', $payload->previousClass);
        self::assertSame('Could not connect to database server', $payload->previousMessage);
    }

    #[Test]
    public function toArrayContainsAllExpectedKeys(): void
    {
        $payload = new ExceptionPayload(
            exceptionClass: 'Exception',
            message: 'Test',
            file: '/test.php',
            line: 1,
            fingerprint: 'test',
            stackTrace: [],
        );

        $array = $payload->toArray();

        self::assertArrayHasKey('exception_class', $array);
        self::assertArrayHasKey('message', $array);
        self::assertArrayHasKey('file', $array);
        self::assertArrayHasKey('line', $array);
        self::assertArrayHasKey('fingerprint', $array);
        self::assertArrayHasKey('stack_trace', $array);
        self::assertArrayHasKey('previous_class', $array);
        self::assertArrayHasKey('previous_message', $array);
    }

    #[Test]
    public function toArrayReturnsCorrectValues(): void
    {
        $stackTrace = [
            ['file' => '/app/Handler.php', 'line' => 25, 'class' => 'Handler', 'function' => 'process'],
        ];

        $payload = new ExceptionPayload(
            exceptionClass: 'LogicException',
            message: 'Unexpected state',
            file: '/app/State.php',
            line: 123,
            fingerprint: 'jkl012',
            stackTrace: $stackTrace,
            previousClass: 'StateException',
            previousMessage: 'Invalid state transition',
        );

        $array = $payload->toArray();

        self::assertSame('LogicException', $array['exception_class']);
        self::assertSame('Unexpected state', $array['message']);
        self::assertSame('/app/State.php', $array['file']);
        self::assertSame(123, $array['line']);
        self::assertSame('jkl012', $array['fingerprint']);
        self::assertSame($stackTrace, $array['stack_trace']);
        self::assertSame('StateException', $array['previous_class']);
        self::assertSame('Invalid state transition', $array['previous_message']);
    }

    #[Test]
    public function toArrayHandlesNullPreviousException(): void
    {
        $payload = new ExceptionPayload(
            exceptionClass: 'RuntimeException',
            message: 'Error',
            file: '/app/App.php',
            line: 10,
            fingerprint: 'mno345',
            stackTrace: [],
        );

        $array = $payload->toArray();

        self::assertNull($array['previous_class']);
        self::assertNull($array['previous_message']);
    }

    #[Test]
    public function toArrayHandlesEmptyStackTrace(): void
    {
        $payload = new ExceptionPayload(
            exceptionClass: 'Exception',
            message: 'No stack trace',
            file: '/app/Minimal.php',
            line: 1,
            fingerprint: 'pqr678',
            stackTrace: [],
        );

        $array = $payload->toArray();

        self::assertSame([], $array['stack_trace']);
    }

    #[Test]
    public function handlesStackTraceWithNullClassAndFunction(): void
    {
        $stackTrace = [
            ['file' => '/app/global.php', 'line' => 5, 'class' => null, 'function' => null],
            ['file' => '/app/include.php', 'line' => 10, 'class' => null, 'function' => 'require'],
        ];

        $payload = new ExceptionPayload(
            exceptionClass: 'ParseError',
            message: 'Syntax error',
            file: '/app/broken.php',
            line: 15,
            fingerprint: 'stu901',
            stackTrace: $stackTrace,
        );

        $array = $payload->toArray();

        // Verify the full stack trace is preserved including null values
        self::assertSame($stackTrace, $array['stack_trace']);
        self::assertCount(2, $array['stack_trace']);

        // Verify structure of first frame (null class and function)
        self::assertArrayHasKey('class', $array['stack_trace'][0]);
        self::assertArrayHasKey('function', $array['stack_trace'][0]);

        // Verify second frame has non-null function
        self::assertSame('require', $array['stack_trace'][1]['function']);
    }

    #[Test]
    public function handlesLongExceptionMessages(): void
    {
        $longMessage = str_repeat('Error detail. ', 100);

        $payload = new ExceptionPayload(
            exceptionClass: 'VerboseException',
            message: $longMessage,
            file: '/app/Verbose.php',
            line: 999,
            fingerprint: 'vwx234',
            stackTrace: [],
        );

        self::assertSame($longMessage, $payload->message);
        self::assertSame($longMessage, $payload->toArray()['message']);
    }
}
