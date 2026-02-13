<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Collaboration\CollaborationRepositoryInterface;
use Pulsar\Extension\Cms\Collaboration\CollaborationSession;
use Pulsar\Extension\Cms\Collaboration\CrdtDocument;

#[Internal(reason: 'Raw-DB repository — use CollaborationRepositoryInterface for public API')]
final readonly class DbCollaborationRepository implements CollaborationRepositoryInterface
{
    private const string SQL_GET_DOCUMENT = <<<'SQL'
        SELECT content_id, state_vector, version, updated_at
        FROM cms_collaboration_states
        WHERE content_id = :content_id
        SQL;

    private const array UPSERT_DOC_COLUMNS = ['content_id', 'state_vector', 'version', 'updated_at'];
    private const array UPSERT_DOC_UPDATE = ['state_vector', 'version', 'updated_at'];

    private const string SQL_GET_ACTIVE_SESSIONS = <<<'SQL'
        SELECT id, content_id, user_id, user_name, cursor_position,
               selection_range, connected_at, last_seen_at
        FROM cms_collaboration_sessions
        WHERE content_id = :content_id
        ORDER BY connected_at ASC
        SQL;

    private const array UPSERT_SESSION_COLUMNS = [
        'id', 'content_id', 'user_id', 'user_name', 'cursor_position',
        'selection_range', 'connected_at', 'last_seen_at',
    ];
    private const array UPSERT_SESSION_UPDATE = ['cursor_position', 'selection_range', 'last_seen_at'];

    private const string SQL_REMOVE_SESSION = <<<'SQL'
        DELETE FROM cms_collaboration_sessions WHERE id = :id
        SQL;

    private const string SQL_CLEANUP_EXPIRED = <<<'SQL'
        DELETE FROM cms_collaboration_sessions
        WHERE last_seen_at < :cutoff
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function getDocument(string $contentId): ?CrdtDocument
    {
        $result = $this->connection->query(self::SQL_GET_DOCUMENT, [
            'content_id' => $contentId,
        ]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrateDocument($row);
    }

    #[Override]
    public function saveDocument(CrdtDocument $document): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_collaboration_states',
            self::UPSERT_DOC_COLUMNS,
            ['content_id'],
            self::UPSERT_DOC_UPDATE,
        );

        $this->connection->execute($sql, [
            'content_id' => $document->contentId,
            'state_vector' => $document->stateVector,
            'version' => $document->version,
            'updated_at' => $document->updatedAt->format('c'),
        ]);
    }

    #[Override]
    public function getActiveSessions(string $contentId): array
    {
        $result = $this->connection->query(self::SQL_GET_ACTIVE_SESSIONS, [
            'content_id' => $contentId,
        ]);

        return $result->map(self::hydrateSession(...));
    }

    #[Override]
    public function saveSession(CollaborationSession $session): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_collaboration_sessions',
            self::UPSERT_SESSION_COLUMNS,
            ['id'],
            self::UPSERT_SESSION_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $session->id,
            'content_id' => $session->contentId,
            'user_id' => $session->userId,
            'user_name' => $session->userName,
            'cursor_position' => $session->cursorPosition,
            'selection_range' => $session->selectionRange,
            'connected_at' => $session->connectedAt->format('c'),
            'last_seen_at' => $session->lastSeenAt->format('c'),
        ]);
    }

    #[Override]
    public function removeSession(string $sessionId): void
    {
        $this->connection->execute(self::SQL_REMOVE_SESSION, [
            'id' => $sessionId,
        ]);
    }

    #[Override]
    public function cleanupExpiredSessions(int $maxAgeMinutes = 30): int
    {
        $cutoff = new DateTimeImmutable("-$maxAgeMinutes minutes");

        return $this->connection->execute(self::SQL_CLEANUP_EXPIRED, [
            'cutoff' => $cutoff->format('c'),
        ]);
    }

    private static function hydrateDocument(Row $row): CrdtDocument
    {
        return new CrdtDocument(
            contentId: $row->getString('content_id'),
            stateVector: $row->getString('state_vector'),
            version: $row->getInt('version'),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
        );
    }

    private static function hydrateSession(Row $row): CollaborationSession
    {
        return new CollaborationSession(
            id: $row->getString('id'),
            contentId: $row->getString('content_id'),
            userId: $row->getString('user_id'),
            userName: $row->getString('user_name'),
            cursorPosition: $row->getNullableString('cursor_position'),
            selectionRange: $row->getNullableString('selection_range'),
            connectedAt: new DateTimeImmutable($row->getString('connected_at')),
            lastSeenAt: new DateTimeImmutable($row->getString('last_seen_at')),
        );
    }
}
