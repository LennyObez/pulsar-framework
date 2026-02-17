<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\JustifiedAccess;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\DataClassification;
use Pulsar\Security\JustifiedAccess\JustificationCategory;
use Pulsar\Security\JustifiedAccess\JustificationRecord;
use Pulsar\Security\JustifiedAccess\ReviewStatus;

#[CoversClass(JustificationRecord::class)]
final class JustificationRecordTest extends TestCase
{
    public function testConstructionAndProperties(): void
    {
        $now = new DateTimeImmutable();
        $record = new JustificationRecord(
            id: 'rec-001',
            actorId: 'user-42',
            actorName: 'Dr. Smith',
            actorRole: 'physician',
            resourceType: 'patient_record',
            resourceId: 'patient-123',
            category: JustificationCategory::CustomerRequest,
            justificationText: 'Patient requested copy of records',
            dataClassification: DataClassification::Restricted,
            accessTimestamp: $now,
            sessionId: 'sess-abc',
            ipAddress: '10.0.0.1',
            supervisorApproval: true,
            reviewStatus: ReviewStatus::Pending,
        );

        self::assertSame('rec-001', $record->id);
        self::assertSame('user-42', $record->actorId);
        self::assertSame(DataClassification::Restricted, $record->dataClassification);
        self::assertTrue($record->supervisorApproval);
        self::assertFalse($record->breakTheGlass);
    }

    public function testToArray(): void
    {
        $now = new DateTimeImmutable('2026-03-14T12:00:00+00:00');
        $record = new JustificationRecord(
            id: 'rec-002',
            actorId: 'user-1',
            actorName: 'Admin',
            actorRole: 'admin',
            resourceType: 'account',
            resourceId: 'acct-456',
            category: JustificationCategory::InternalAudit,
            justificationText: 'Quarterly compliance audit',
            dataClassification: DataClassification::Confidential,
            accessTimestamp: $now,
            sessionId: 'sess-xyz',
            ipAddress: '192.168.1.1',
            supervisorApproval: null,
            reviewStatus: ReviewStatus::Approved,
        );

        $array = $record->toArray();
        self::assertSame('rec-002', $array['id']);
        self::assertSame('audit', $array['category']);
        self::assertSame('confidential', $array['data_classification']);
        self::assertSame('approved', $array['review_status']);
        self::assertNull($array['supervisor_approval']);
        self::assertFalse($array['break_the_glass']);
    }

    public function testFromArray(): void
    {
        $data = [
            'id' => 'rec-003',
            'actor_id' => 'user-5',
            'actor_name' => 'Jane',
            'actor_role' => 'manager',
            'resource_type' => 'report',
            'resource_id' => 'rpt-789',
            'category' => 'dispute',
            'justification_text' => 'Customer dispute investigation',
            'data_classification' => 'restricted',
            'access_timestamp' => '2026-03-14T12:00:00+00:00',
            'session_id' => 'sess-123',
            'ip_address' => '10.0.0.5',
            'supervisor_approval' => true,
            'review_status' => 'flagged',
            'break_the_glass' => true,
            'metadata' => ['ticket' => 'DISP-001'],
        ];

        $record = JustificationRecord::fromArray($data);

        self::assertSame('rec-003', $record->id);
        self::assertSame(JustificationCategory::DisputeResolution, $record->category);
        self::assertSame(DataClassification::Restricted, $record->dataClassification);
        self::assertTrue($record->supervisorApproval);
        self::assertSame(ReviewStatus::Flagged, $record->reviewStatus);
        self::assertTrue($record->breakTheGlass);
        self::assertSame(['ticket' => 'DISP-001'], $record->metadata);
    }

    public function testFromArrayWithDefaults(): void
    {
        $record = JustificationRecord::fromArray([]);

        self::assertSame('', $record->id);
        self::assertSame(JustificationCategory::CustomerRequest, $record->category);
        self::assertSame(DataClassification::Internal, $record->dataClassification);
        self::assertNull($record->supervisorApproval);
        self::assertSame(ReviewStatus::Pending, $record->reviewStatus);
        self::assertFalse($record->breakTheGlass);
    }
}
