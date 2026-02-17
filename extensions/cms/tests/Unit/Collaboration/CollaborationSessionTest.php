<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Collaboration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Collaboration\CollaborationSession;

#[CoversClass(CollaborationSession::class)]
final class CollaborationSessionTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $connected = new DateTimeImmutable('2026-03-28T10:00:00Z');
        $lastSeen = new DateTimeImmutable('2026-03-28T10:05:00Z');

        $session = new CollaborationSession(
            id: 'sess-001',
            contentId: 'content-001',
            userId: 'user-001',
            userName: 'Alice',
            cursorPosition: '42',
            selectionRange: '42-58',
            connectedAt: $connected,
            lastSeenAt: $lastSeen,
        );

        self::assertSame('sess-001', $session->id);
        self::assertSame('content-001', $session->contentId);
        self::assertSame('user-001', $session->userId);
        self::assertSame('Alice', $session->userName);
        self::assertSame('42', $session->cursorPosition);
        self::assertSame('42-58', $session->selectionRange);
        self::assertSame($connected, $session->connectedAt);
        self::assertSame($lastSeen, $session->lastSeenAt);
    }

    #[Test]
    public function cursorAndSelectionAreNullable(): void
    {
        $session = new CollaborationSession(
            id: 'sess-002',
            contentId: 'content-001',
            userId: 'user-002',
            userName: 'Bob',
            cursorPosition: null,
            selectionRange: null,
            connectedAt: new DateTimeImmutable(),
            lastSeenAt: new DateTimeImmutable(),
        );

        self::assertNull($session->cursorPosition);
        self::assertNull($session->selectionRange);
    }
}
