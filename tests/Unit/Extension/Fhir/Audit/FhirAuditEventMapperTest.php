<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Fhir\Audit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Audit\FhirAuditEventMapper;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(FhirAuditEventMapper::class)]
final class FhirAuditEventMapperTest extends TestCase
{
    private FhirAuditEventMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new FhirAuditEventMapper();
    }

    public function testMapToFhirAuditEvent(): void
    {
        $entry = new AuditEntry(
            id: 'audit-001',
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'user@example.com',
            action: 'login',
            resource: 'auth/session',
            timestamp: new DateTimeImmutable('2024-06-15T10:30:00Z'),
            metadata: ['ip' => '192.168.1.1'],
            previousHmac: '',
            hmac: 'abc123',
        );

        /** @var array<string, mixed> $fhirEvent */
        $fhirEvent = $this->mapper->toFhirAuditEvent($entry);

        self::assertSame('AuditEvent', $fhirEvent['resourceType']);
        self::assertSame('audit-001', $fhirEvent['id']);
        self::assertSame('0', $fhirEvent['outcome']);

        /** @var list<array<string, mixed>> $agents */
        $agents = $fhirEvent['agent'];
        /** @var array<string, mixed> $agent0 */
        $agent0 = $agents[0];
        /** @var array<string, string> $who */
        $who = $agent0['who'];
        self::assertSame('user@example.com', $who['display']);
        self::assertTrue($agent0['requestor']);

        /** @var array<string, mixed> $source */
        $source = $fhirEvent['source'];
        /** @var array<string, string> $observer */
        $observer = $source['observer'];
        self::assertSame('Pulsar Framework', $observer['display']);

        /** @var list<array<string, mixed>> $entities */
        $entities = $fhirEvent['entity'];
        self::assertCount(1, $entities);
        /** @var array<string, mixed> $entity0 */
        $entity0 = $entities[0];
        /** @var array<string, string> $what */
        $what = $entity0['what'];
        self::assertSame('auth/session', $what['display']);
    }

    public function testMapEmptyResourceOmitsEntity(): void
    {
        $entry = new AuditEntry(
            id: 'audit-002',
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: 'system',
            action: 'startup',
            resource: '',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: '',
            hmac: 'def456',
        );

        $fhirEvent = $this->mapper->toFhirAuditEvent($entry);

        self::assertSame([], $fhirEvent['entity']);
    }

    #[DataProvider('outcomeProvider')]
    public function testMapOutcomes(AuditOutcome $outcome, string $expected): void
    {
        $entry = new AuditEntry(
            id: 'audit-outcome',
            event: AuditEvent::DataAccess,
            outcome: $outcome,
            actor: 'test',
            action: 'read',
            resource: 'data',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: '',
            hmac: 'hmac',
        );

        $fhirEvent = $this->mapper->toFhirAuditEvent($entry);
        self::assertSame($expected, $fhirEvent['outcome']);
    }

    /**
     * @return iterable<string, array{AuditOutcome, string}>
     */
    public static function outcomeProvider(): iterable
    {
        yield 'success' => [AuditOutcome::Success, '0'];
        yield 'failure' => [AuditOutcome::Failure, '8'];
        yield 'denied' => [AuditOutcome::Denied, '4'];
        yield 'error' => [AuditOutcome::Error, '12'];
    }

    #[DataProvider('actionMappingProvider')]
    public function testMapActionCodes(string $action, string $expectedCode): void
    {
        $entry = new AuditEntry(
            id: 'audit-action',
            event: AuditEvent::DataModification,
            outcome: AuditOutcome::Success,
            actor: 'test',
            action: $action,
            resource: 'resource',
            timestamp: new DateTimeImmutable(),
            metadata: [],
            previousHmac: '',
            hmac: 'hmac',
        );

        $fhirEvent = $this->mapper->toFhirAuditEvent($entry);
        self::assertSame($expectedCode, $fhirEvent['action']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function actionMappingProvider(): iterable
    {
        yield 'create maps to C' => ['create_patient', 'C'];
        yield 'add maps to C' => ['add_record', 'C'];
        yield 'register maps to C' => ['register_device', 'C'];
        yield 'read maps to R' => ['read_data', 'R'];
        yield 'view maps to R' => ['view_report', 'R'];
        yield 'get maps to R' => ['get_patient', 'R'];
        yield 'update maps to U' => ['update_record', 'U'];
        yield 'modify maps to U' => ['modify_setting', 'U'];
        yield 'delete maps to D' => ['delete_record', 'D'];
        yield 'remove maps to D' => ['remove_user', 'D'];
        yield 'revoke maps to D' => ['revoke_token', 'D'];
        yield 'unknown maps to E' => ['process_request', 'E'];
    }

    public function testToBundleArray(): void
    {
        $entries = [
            new AuditEntry(
                id: 'a1',
                event: AuditEvent::Authentication,
                outcome: AuditOutcome::Success,
                actor: 'user1',
                action: 'login',
                resource: 'session',
                timestamp: new DateTimeImmutable(),
                metadata: [],
                previousHmac: '',
                hmac: 'h1',
            ),
            new AuditEntry(
                id: 'a2',
                event: AuditEvent::DataAccess,
                outcome: AuditOutcome::Success,
                actor: 'user1',
                action: 'read_patient',
                resource: 'Patient/123',
                timestamp: new DateTimeImmutable(),
                metadata: [],
                previousHmac: 'h1',
                hmac: 'h2',
            ),
        ];

        /** @var array<string, mixed> $bundle */
        $bundle = $this->mapper->toBundleArray($entries);

        self::assertSame('Bundle', $bundle['resourceType']);
        self::assertSame('collection', $bundle['type']);
        self::assertSame(2, $bundle['total']);

        /** @var list<array<string, mixed>> $bundleEntries */
        $bundleEntries = $bundle['entry'];
        self::assertCount(2, $bundleEntries);
        /** @var array<string, mixed> $entry0 */
        $entry0 = $bundleEntries[0];
        self::assertSame('AuditEvent/a1', $entry0['fullUrl']);
        /** @var array<string, string> $entryResource */
        $entryResource = $entry0['resource'];
        self::assertSame('AuditEvent', $entryResource['resourceType']);
    }
}
