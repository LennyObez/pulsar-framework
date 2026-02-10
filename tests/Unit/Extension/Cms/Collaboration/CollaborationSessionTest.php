<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Collaboration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Collaboration\CollaborationSession;

#[CoversClass(CollaborationSession::class)]
final class CollaborationSessionTest extends TestCase
{
    #[Test]
    public function constructor_sets_all_properties(): void
    {
        $connectedAt = new DateTimeImmutable('2026-01-15T10:00:00+00:00');
        $lastSeenAt = new DateTimeImmutable('2026-01-15T10:05:00+00:00');

        $session = new CollaborationSession(
            id: 'session-001',
            contentId: 'content-001',
            userId: 'user-alice',
            userName: 'Alice',
            cursorPosition: '10:5',
            selectionRange: '10:5-10:20',
            connectedAt: $connectedAt,
            lastSeenAt: $lastSeenAt,
        );

        self::assertSame('session-001', $session->id);
        self::assertSame('content-001', $session->contentId);
        self::assertSame('user-alice', $session->userId);
        self::assertSame('Alice', $session->userName);
        self::assertSame('10:5', $session->cursorPosition);
        self::assertSame('10:5-10:20', $session->selectionRange);
        self::assertSame($connectedAt, $session->connectedAt);
        self::assertSame($lastSeenAt, $session->lastSeenAt);
    }

    #[Test]
    public function nullable_fields_can_be_null(): void
    {
        $now = new DateTimeImmutable();

        $session = new CollaborationSession(
            id: 'session-002',
            contentId: 'content-002',
            userId: 'user-bob',
            userName: 'Bob',
            cursorPosition: null,
            selectionRange: null,
            connectedAt: $now,
            lastSeenAt: $now,
        );

        self::assertNull($session->cursorPosition);
        self::assertNull($session->selectionRange);
    }
}
