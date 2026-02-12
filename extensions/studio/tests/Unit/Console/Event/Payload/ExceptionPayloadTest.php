<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Console\Event\Payload;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;
use Pulsar\Extension\Studio\Console\Event\Payload\ExceptionPayload;

final class ExceptionPayloadTest extends TestCase
{
    #[Test]
    public function eventTypeReturnsException(): void
    {
        $payload = $this->createPayload();

        self::assertSame(EventType::Exception, $payload->eventType());
    }

    #[Test]
    public function schemaVersionReturnsV1(): void
    {
        self::assertSame(EventVersion::V1, $this->createPayload()->schemaVersion());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $payload = $this->createPayload();
        $array = $payload->toArray();

        self::assertSame('RuntimeException', $array['exception_class']);
        self::assertSame('Something failed', $array['message']);
        self::assertSame('/app/Foo.php', $array['file']);
        self::assertSame(42, $array['line']);
        self::assertSame('fp_abc123', $array['fingerprint']);
        self::assertSame([], $array['stack_trace']);
        self::assertNull($array['previous_class']);
        self::assertNull($array['previous_message']);
    }

    #[Test]
    public function toArrayIncludesPreviousException(): void
    {
        $payload = new ExceptionPayload(
            exceptionClass: 'RuntimeException',
            message: 'Outer',
            file: '/app/Bar.php',
            line: 10,
            fingerprint: 'fp_xyz',
            stackTrace: [],
            previousClass: 'LogicException',
            previousMessage: 'Inner',
        );

        $array = $payload->toArray();

        self::assertSame('LogicException', $array['previous_class']);
        self::assertSame('Inner', $array['previous_message']);
    }

    private function createPayload(): ExceptionPayload
    {
        return new ExceptionPayload(
            exceptionClass: 'RuntimeException',
            message: 'Something failed',
            file: '/app/Foo.php',
            line: 42,
            fingerprint: 'fp_abc123',
            stackTrace: [],
        );
    }
}
