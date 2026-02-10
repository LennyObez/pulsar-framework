<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Storage\TransitionRecord;

#[CoversClass(TransitionRecord::class)]
final class TransitionRecordTest extends TestCase
{
    #[Test]
    public function test_constructor_stores_all_fields(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-15 10:30:00');

        $record = new TransitionRecord(
            id: 'rec-1',
            instanceId: 'inst-1',
            fromState: 'draft',
            toState: 'review',
            transitionName: 'submit',
            actor: 'user-42',
            reason: 'Ready for review',
            metadata: ['priority' => 'high'],
            instanceVersion: 2,
            createdAt: $createdAt,
        );

        self::assertSame('rec-1', $record->id);
        self::assertSame('inst-1', $record->instanceId);
        self::assertSame('draft', $record->fromState);
        self::assertSame('review', $record->toState);
        self::assertSame('submit', $record->transitionName);
        self::assertSame('user-42', $record->actor);
        self::assertSame('Ready for review', $record->reason);
        self::assertSame(['priority' => 'high'], $record->metadata);
        self::assertSame(2, $record->instanceVersion);
        self::assertSame($createdAt, $record->createdAt);
    }

    #[Test]
    public function test_reason_can_be_null(): void
    {
        $record = new TransitionRecord(
            id: 'rec-2',
            instanceId: 'inst-1',
            fromState: 'a',
            toState: 'b',
            transitionName: 'go',
            actor: 'user-1',
            reason: null,
            metadata: [],
            instanceVersion: 1,
            createdAt: new DateTimeImmutable(),
        );

        self::assertNull($record->reason);
    }

    #[Test]
    public function test_metadata_can_be_empty(): void
    {
        $record = new TransitionRecord(
            id: 'rec-3',
            instanceId: 'inst-1',
            fromState: 'a',
            toState: 'b',
            transitionName: 'go',
            actor: 'user-1',
            reason: null,
            metadata: [],
            instanceVersion: 1,
            createdAt: new DateTimeImmutable(),
        );

        self::assertSame([], $record->metadata);
    }
}
