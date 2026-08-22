<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Evidence;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Evidence\EvidenceRecord;

#[CoversClass(EvidenceRecord::class)]
final class EvidenceRecordTest extends TestCase
{
    public function testConstruction(): void
    {
        $collectedAt = new DateTimeImmutable('2026-03-15T10:00:00+00:00');

        $record = new EvidenceRecord(
            id: 'ev-001',
            controlId: 'CTRL-001',
            type: 'configuration',
            description: 'Encryption at rest enabled',
            data: ['algorithm' => 'AES-256-GCM'],
            collectedAt: $collectedAt,
        );

        self::assertSame('ev-001', $record->id);
        self::assertSame('CTRL-001', $record->controlId);
        self::assertSame('configuration', $record->type);
        self::assertSame('Encryption at rest enabled', $record->description);
        self::assertSame(['algorithm' => 'AES-256-GCM'], $record->data);
        self::assertSame($collectedAt, $record->collectedAt);
        self::assertNull($record->signature);
    }

    public function testConstructionWithSignature(): void
    {
        $record = new EvidenceRecord(
            id: 'ev-002',
            controlId: 'CTRL-002',
            type: 'audit_log',
            description: 'Audit log integrity verified',
            data: ['chain_valid' => true],
            collectedAt: new DateTimeImmutable(),
            signature: 'hmac_sha256:abcdef123456',
        );

        self::assertSame('hmac_sha256:abcdef123456', $record->signature);
    }

    public function testEmptyData(): void
    {
        $record = new EvidenceRecord(
            id: 'ev-003',
            controlId: 'CTRL-003',
            type: 'test_result',
            description: 'Test passed',
            data: [],
            collectedAt: new DateTimeImmutable(),
        );

        self::assertSame([], $record->data);
    }
}
