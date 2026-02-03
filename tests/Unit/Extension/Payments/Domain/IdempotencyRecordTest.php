<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Payments\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\IdempotencyRecord;

#[CoversClass(IdempotencyRecord::class)]
final class IdempotencyRecordTest extends TestCase
{
    #[Test]
    public function constructionSetsAllProperties(): void
    {
        $createdAt = new DateTimeImmutable('2025-01-01T00:00:00Z');
        $expiresAt = new DateTimeImmutable('2025-01-02T00:00:00Z');

        $record = new IdempotencyRecord(
            key: 'key-1',
            parametersHash: 'abc123',
            operation: 'createIntent',
            createdAt: $createdAt,
            expiresAt: $expiresAt,
        );

        self::assertSame('key-1', $record->key);
        self::assertSame('abc123', $record->parametersHash);
        self::assertSame('createIntent', $record->operation);
        self::assertSame($createdAt, $record->createdAt);
        self::assertSame($expiresAt, $record->expiresAt);
        self::assertNull($record->resultPayload);
    }

    #[Test]
    public function constructionWithResultPayload(): void
    {
        $record = new IdempotencyRecord(
            key: 'key-2',
            parametersHash: 'def456',
            operation: 'refund',
            createdAt: new DateTimeImmutable(),
            expiresAt: new DateTimeImmutable(),
            resultPayload: '{"id":"pi_123"}',
        );

        self::assertSame('{"id":"pi_123"}', $record->resultPayload);
    }
}
