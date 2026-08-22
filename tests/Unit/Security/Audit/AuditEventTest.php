<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Audit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Audit\AuditEvent;

#[CoversNothing]
final class AuditEventTest extends TestCase
{
    /**
     * @return iterable<string, array{AuditEvent, string}>
     */
    public static function eventProvider(): iterable
    {
        yield 'Authentication' => [AuditEvent::Authentication, 'authentication'];
        yield 'Authorization' => [AuditEvent::Authorization, 'authorization'];
        yield 'DataAccess' => [AuditEvent::DataAccess, 'data_access'];
        yield 'DataModification' => [AuditEvent::DataModification, 'data_modification'];
        yield 'ConfigurationChange' => [AuditEvent::ConfigurationChange, 'configuration_change'];
        yield 'SecurityEvent' => [AuditEvent::SecurityEvent, 'security_event'];
        yield 'SystemEvent' => [AuditEvent::SystemEvent, 'system_event'];
        yield 'SchemaModification' => [AuditEvent::SchemaModification, 'schema_modification'];
        yield 'Communication' => [AuditEvent::Communication, 'communication'];
    }

    #[Test]
    #[DataProvider('eventProvider')]
    public function backingValueMatchesExpected(AuditEvent $event, string $expected): void
    {
        self::assertSame($expected, $event->value);
    }

    #[Test]
    public function hasNineCases(): void
    {
        self::assertCount(9, AuditEvent::cases());
    }

    #[Test]
    public function canBeCreatedFromString(): void
    {
        self::assertSame(AuditEvent::DataAccess, AuditEvent::from('data_access'));
        self::assertSame(AuditEvent::SecurityEvent, AuditEvent::from('security_event'));
    }

    #[Test]
    public function tryFromReturnsNullForUnknownValue(): void
    {
        self::assertNull(AuditEvent::tryFrom('unknown_event'));
    }
}
