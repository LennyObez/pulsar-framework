<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Tests\Unit\Audit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Fhir\Audit\FhirAuditEventMapper;
use Pulsar\Extension\Fhir\Resource\Coding;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(FhirAuditEventMapper::class)]
#[CoversClass(Coding::class)]
final class FhirAuditEventMapperTest extends TestCase
{
    private FhirAuditEventMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new FhirAuditEventMapper();
    }

    #[Test]
    public function toFhirAuditEventProducesValidResourceStructure(): void
    {
        $entry = $this->createEntry(
            id: 'audit-001',
            event: AuditEvent::Authentication,
            outcome: AuditOutcome::Success,
            actor: 'user-42',
            action: 'user.login',
            resource: 'session/abc',
        );

        $result = $this->mapper->toFhirAuditEvent($entry);

        self::assertSame('AuditEvent', $result['resourceType']);
        self::assertSame('audit-001', $result['id']);
        self::assertIsArray($result['type']);
        self::assertIsArray($result['subtype']);
        self::assertCount(1, $result['subtype']);
        self::assertSame('user.login', $result['subtype'][0]['code']);
        self::assertSame('0', $result['outcome']);
        self::assertSame('user-42', $result['agent'][0]['who']['display']);
        self::assertTrue($result['agent'][0]['requestor']);
        self::assertSame('Pulsar Framework', $result['source']['observer']['display']);
        self::assertCount(1, $result['entity']);
        self::assertSame('session/abc', $result['entity'][0]['what']['display']);
    }

    #[Test]
    public function emptyResourceProducesEmptyEntityArray(): void
    {
        $entry = $this->createEntry(resource: '');

        $result = $this->mapper->toFhirAuditEvent($entry);

        self::assertSame([], $result['entity']);
    }

    /**
     * @return iterable<string, array{AuditEvent, string}>
     */
    public static function eventTypeMappingProvider(): iterable
    {
        yield 'Authentication → 110114' => [AuditEvent::Authentication, '110114'];
        yield 'Authorization → 110114' => [AuditEvent::Authorization, '110114'];
        yield 'DataAccess → 110110' => [AuditEvent::DataAccess, '110110'];
        yield 'DataModification → 110112' => [AuditEvent::DataModification, '110112'];
        yield 'ConfigurationChange → 110113' => [AuditEvent::ConfigurationChange, '110113'];
        yield 'SecurityEvent → 110127' => [AuditEvent::SecurityEvent, '110127'];
        yield 'SystemEvent → 110100' => [AuditEvent::SystemEvent, '110100'];
        yield 'SchemaModification → 110113' => [AuditEvent::SchemaModification, '110113'];
        yield 'Communication → 110111' => [AuditEvent::Communication, '110111'];
    }

    #[Test]
    #[DataProvider('eventTypeMappingProvider')]
    public function eventTypeMapsToCorrectDicomCode(AuditEvent $event, string $expectedCode): void
    {
        $entry = $this->createEntry(event: $event);

        $result = $this->mapper->toFhirAuditEvent($entry);

        self::assertSame($expectedCode, $result['type']['code']);
        self::assertSame('http://dicom.nema.org/resources/ontology/DCM', $result['type']['system']);
    }

    /**
     * @return iterable<string, array{AuditOutcome, string}>
     */
    public static function outcomeMappingProvider(): iterable
    {
        yield 'Success → 0' => [AuditOutcome::Success, '0'];
        yield 'Failure → 8' => [AuditOutcome::Failure, '8'];
        yield 'Denied → 4' => [AuditOutcome::Denied, '4'];
        yield 'Error → 12' => [AuditOutcome::Error, '12'];
    }

    #[Test]
    #[DataProvider('outcomeMappingProvider')]
    public function outcomeMapsToCorrectFhirCode(AuditOutcome $outcome, string $expectedCode): void
    {
        $entry = $this->createEntry(outcome: $outcome);

        $result = $this->mapper->toFhirAuditEvent($entry);

        self::assertSame($expectedCode, $result['outcome']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function actionMappingProvider(): iterable
    {
        yield 'create → C' => ['user.create', 'C'];
        yield 'add → C' => ['role.add', 'C'];
        yield 'register → C' => ['device.register', 'C'];
        yield 'read → R' => ['record.read', 'R'];
        yield 'view → R' => ['patient.view', 'R'];
        yield 'get → R' => ['data.get', 'R'];
        yield 'list → R' => ['patients.list', 'R'];
        yield 'update → U' => ['record.update', 'U'];
        yield 'modify → U' => ['config.modify', 'U'];
        yield 'change → U' => ['password.change', 'U'];
        yield 'delete → D' => ['user.delete', 'D'];
        yield 'remove → D' => ['entry.remove', 'D'];
        yield 'revoke → D' => ['token.revoke', 'D'];
        yield 'login → E (default)' => ['user.login', 'E'];
        yield 'export → E (default)' => ['data.export', 'E'];
    }

    #[Test]
    #[DataProvider('actionMappingProvider')]
    public function actionStringMapsToCorrectFhirActionCode(string $action, string $expectedCode): void
    {
        $entry = $this->createEntry(action: $action);

        $result = $this->mapper->toFhirAuditEvent($entry);

        self::assertSame($expectedCode, $result['action']);
    }

    #[Test]
    public function toBundleArrayWrapsMultipleEntries(): void
    {
        $entries = [
            $this->createEntry(id: 'a1'),
            $this->createEntry(id: 'a2'),
            $this->createEntry(id: 'a3'),
        ];

        $bundle = $this->mapper->toBundleArray($entries);

        self::assertSame('Bundle', $bundle['resourceType']);
        self::assertSame('collection', $bundle['type']);
        self::assertSame(3, $bundle['total']);
        self::assertCount(3, $bundle['entry']);
        self::assertSame('AuditEvent/a1', $bundle['entry'][0]['fullUrl']);
        self::assertSame('AuditEvent', $bundle['entry'][0]['resource']['resourceType']);
    }

    #[Test]
    public function toBundleArrayEmptyEntries(): void
    {
        $bundle = $this->mapper->toBundleArray([]);

        self::assertSame(0, $bundle['total']);
        self::assertSame([], $bundle['entry']);
    }

    #[Test]
    public function recordedTimestampUsesIso8601Format(): void
    {
        $ts = new DateTimeImmutable('2026-03-15T10:30:00.123+00:00');
        $entry = $this->createEntry(timestamp: $ts);

        $result = $this->mapper->toFhirAuditEvent($entry);

        self::assertStringStartsWith('2026-03-15T10:30:00', $result['recorded']);
    }

    private function createEntry(
        string $id = 'test-id',
        AuditEvent $event = AuditEvent::SystemEvent,
        AuditOutcome $outcome = AuditOutcome::Success,
        string $actor = 'test-actor',
        string $action = 'test.action',
        string $resource = 'test-resource',
        ?DateTimeImmutable $timestamp = null,
    ): AuditEntry {
        return new AuditEntry(
            id: $id,
            event: $event,
            outcome: $outcome,
            actor: $actor,
            action: $action,
            resource: $resource,
            timestamp: $timestamp ?? new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            metadata: [],
            previousHmac: '',
            hmac: 'test-hmac',
        );
    }
}
