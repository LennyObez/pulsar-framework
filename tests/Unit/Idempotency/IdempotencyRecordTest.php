<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Idempotency;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Idempotency\IdempotencyRecord;

#[CoversClass(IdempotencyRecord::class)]
final class IdempotencyRecordTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $createdAt = new DateTimeImmutable('2024-01-01T00:00:00+00:00');
        $expiresAt = new DateTimeImmutable('2024-01-02T00:00:00+00:00');

        $record = new IdempotencyRecord(
            key: 'key-1',
            parametersHash: 'hash-abc',
            operation: 'createPayment',
            createdAt: $createdAt,
            expiresAt: $expiresAt,
            resultPayload: '{"status":"success"}',
        );

        self::assertSame('key-1', $record->key);
        self::assertSame('hash-abc', $record->parametersHash);
        self::assertSame('createPayment', $record->operation);
        self::assertSame($createdAt, $record->createdAt);
        self::assertSame($expiresAt, $record->expiresAt);
        self::assertSame('{"status":"success"}', $record->resultPayload);
    }

    #[Test]
    public function resultPayloadDefaultsToNull(): void
    {
        $now = new DateTimeImmutable();

        $record = new IdempotencyRecord(
            key: 'key-1',
            parametersHash: 'hash-abc',
            operation: 'op',
            createdAt: $now,
            expiresAt: $now,
        );

        self::assertNull($record->resultPayload);
    }
}
