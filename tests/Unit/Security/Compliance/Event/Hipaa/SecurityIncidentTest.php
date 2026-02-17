<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Hipaa;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Hipaa\SecurityIncident;

#[CoversClass(SecurityIncident::class)]
final class SecurityIncidentTest extends TestCase
{
    private function createEvent(): SecurityIncident
    {
        return new SecurityIncident(
            eventId: 'evt-si-001',
            occurredAt: new DateTimeImmutable('2026-01-20T16:00:00+00:00'),
            correlationId: 'corr-400',
            nonce: 'nonce-004',
            reporterIdentity: 'security-team@hospital.org',
            incidentType: 'unauthorized_access',
            severity: 'critical',
            description: 'Unauthorized login attempt from external IP',
            containmentActions: ['ip_blocked', 'account_locked', 'password_reset_forced'],
        );
    }

    #[Test]
    public function regulationReturnsHipaa(): void
    {
        self::assertSame('hipaa', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsSecurityIncident(): void
    {
        self::assertSame('security_incident', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-si-001', $array['event_id']);
        self::assertSame('corr-400', $array['correlation_id']);
        self::assertSame('security-team@hospital.org', $array['reporter_identity']);
        self::assertSame('unauthorized_access', $array['incident_type']);
        self::assertSame('critical', $array['severity']);
        self::assertSame('Unauthorized login attempt from external IP', $array['description']);
        self::assertSame(['ip_blocked', 'account_locked', 'password_reset_forced'], $array['containment_actions']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = SecurityIncident::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->reporterIdentity, $restored->reporterIdentity);
        self::assertSame($original->incidentType, $restored->incidentType);
        self::assertSame($original->severity, $restored->severity);
        self::assertSame($original->description, $restored->description);
        self::assertSame($original->containmentActions, $restored->containmentActions);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = SecurityIncident::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->reporterIdentity);
        self::assertSame('', $event->incidentType);
        self::assertSame('', $event->severity);
        self::assertSame('', $event->description);
        self::assertSame([], $event->containmentActions);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, SecurityIncident::SCHEMA_VERSION);
    }
}
