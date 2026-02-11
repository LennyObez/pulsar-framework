<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio\Console\Event\Payload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\ExceptionPayload;

#[CoversClass(ExceptionPayload::class)]
final class ExceptionPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsException(): void
    {
        $payload = new ExceptionPayload(
            exceptionClass: 'RuntimeException',
            message: 'Failure',
            file: '/app/Handler.php',
            line: 42,
            fingerprint: 'fp123',
            stackTrace: [],
        );

        self::assertSame(EventType::Exception, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        $payload = new ExceptionPayload(
            exceptionClass: 'RuntimeException',
            message: 'test',
            file: '/test.php',
            line: 1,
            fingerprint: 'x',
            stackTrace: [],
        );

        self::assertSame(EventVersion::V1, $payload->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $trace = [
            ['file' => '/app/Handler.php', 'line' => 42, 'class' => 'App\\Handler', 'function' => 'handle'],
        ];

        $payload = new ExceptionPayload(
            exceptionClass: 'InvalidArgumentException',
            message: 'Invalid input',
            file: '/app/Controller.php',
            line: 15,
            fingerprint: 'fp-abc',
            stackTrace: $trace,
            previousClass: 'LogicException',
            previousMessage: 'Root cause',
        );

        $data = $payload->toArray();

        self::assertSame('InvalidArgumentException', $data['exception_class']);
        self::assertSame('Invalid input', $data['message']);
        self::assertSame('/app/Controller.php', $data['file']);
        self::assertSame(15, $data['line']);
        self::assertSame('fp-abc', $data['fingerprint']);
        /** @var list<mixed> $stackTrace */
        $stackTrace = $data['stack_trace'];
        self::assertCount(1, $stackTrace);
        self::assertSame('LogicException', $data['previous_class']);
        self::assertSame('Root cause', $data['previous_message']);
    }

    #[Test]
    public function toArrayHandlesNullPreviousFields(): void
    {
        $payload = new ExceptionPayload(
            exceptionClass: 'RuntimeException',
            message: 'test',
            file: '/test.php',
            line: 1,
            fingerprint: 'x',
            stackTrace: [],
        );

        $data = $payload->toArray();

        self::assertNull($data['previous_class']);
        self::assertNull($data['previous_message']);
    }
}
