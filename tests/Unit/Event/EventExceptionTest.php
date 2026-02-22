<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Event;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Event\Exception\EventException;
use RuntimeException;

#[CoversClass(EventException::class)]
final class EventExceptionTest extends TestCase
{
    #[Test]
    public function stormDetectedCreatesExceptionWithDetails(): void
    {
        $e = EventException::stormDetected('App\\Event\\OrderCreated', 33, 32);

        self::assertInstanceOf(RuntimeException::class, $e);
        self::assertStringContainsString('OrderCreated', $e->getMessage());
        self::assertStringContainsString('33', $e->getMessage());
        self::assertStringContainsString('32', $e->getMessage());
    }

    #[Test]
    public function loopDetectedCreatesExceptionWithCount(): void
    {
        $e = EventException::loopDetected('App\\Event\\OrderCreated', 3, 3);

        self::assertStringContainsString('loop detected', $e->getMessage());
        self::assertStringContainsString('3 times', $e->getMessage());
    }

    #[Test]
    public function envelopeRequiredCreatesExceptionWithClassName(): void
    {
        $e = EventException::envelopeRequired('App\\Event\\AuditEvent');

        self::assertStringContainsString('AuditEvent', $e->getMessage());
        self::assertStringContainsString('envelope', $e->getMessage());
    }

    #[Test]
    public function invalidListenerCreatesExceptionWithReason(): void
    {
        $e = EventException::invalidListener('method not found');

        self::assertStringContainsString('method not found', $e->getMessage());
    }

    #[Test]
    public function invalidEventClassNameCreatesExceptionWithDetails(): void
    {
        $e = EventException::invalidEventClassName('Bad@Class', 'contains @ character');

        self::assertStringContainsString('Bad@Class', $e->getMessage());
        self::assertStringContainsString('contains @ character', $e->getMessage());
    }
}
