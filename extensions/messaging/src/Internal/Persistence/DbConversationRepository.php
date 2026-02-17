<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Messaging\Contracts\ConversationRepositoryInterface;
use Pulsar\Extension\Messaging\Domain\Conversation;
use Pulsar\Extension\Messaging\Domain\ConversationType;

/**
 * Database-backed conversation repository.
 */
#[Internal(reason: 'Use ConversationRepositoryInterface for public API')]
final readonly class DbConversationRepository implements ConversationRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM messaging_conversations WHERE id = :id
        SQL;

    private const string SQL_FIND_BY_PARTICIPANT = <<<'SQL'
        SELECT c.* FROM messaging_conversations c
        INNER JOIN messaging_participants p ON p.conversation_id = c.id
        WHERE p.user_id = :user_id
        ORDER BY c.updated_at DESC
        SQL;

    private const string SQL_FIND_DIRECT = <<<'SQL'
        SELECT c.* FROM messaging_conversations c
        WHERE c.type = 'direct'
            AND c.id IN (
                SELECT p1.conversation_id FROM messaging_participants p1
                INNER JOIN messaging_participants p2 ON p1.conversation_id = p2.conversation_id
                WHERE p1.user_id = :user_a AND p2.user_id = :user_b
            )
        LIMIT 1
        SQL;

    private const string SQL_UPSERT = <<<'SQL'
        INSERT INTO messaging_conversations (id, type, title, created_at, updated_at)
        VALUES (:id, :type, :title, :created_at, :updated_at)
        ON CONFLICT (id) DO UPDATE SET
            title = :title,
            updated_at = :updated_at
        SQL;

    private const string SQL_INSERT_PARTICIPANT = <<<'SQL'
        INSERT INTO messaging_participants (user_id, conversation_id, joined_at)
        VALUES (:user_id, :conversation_id, :joined_at)
        ON CONFLICT (user_id, conversation_id) DO NOTHING
        SQL;

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM messaging_conversations WHERE id = :id
        SQL;

    private const string SQL_GET_PARTICIPANTS = <<<'SQL'
        SELECT user_id FROM messaging_participants WHERE conversation_id = :conversation_id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findById(string $id): ?Conversation
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findByParticipant(string $userId): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_PARTICIPANT, ['user_id' => $userId]);

        return $result->map(fn(Row $row): Conversation => $this->hydrate($row));
    }

    public function findDirect(string $userIdA, string $userIdB): ?Conversation
    {
        $result = $this->connection->query(self::SQL_FIND_DIRECT, [
            'user_a' => $userIdA,
            'user_b' => $userIdB,
        ]);

        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function save(Conversation $conversation): void
    {
        $this->connection->execute(self::SQL_UPSERT, [
            'id' => $conversation->id,
            'type' => $conversation->type->value,
            'title' => $conversation->title,
            'created_at' => $conversation->createdAt->format('c'),
            'updated_at' => $conversation->updatedAt->format('c'),
        ]);

        // Ensure all participants are registered
        foreach ($conversation->participantIds as $userId) {
            $this->connection->execute(self::SQL_INSERT_PARTICIPANT, [
                'user_id' => $userId,
                'conversation_id' => $conversation->id,
                'joined_at' => $conversation->createdAt->format('c'),
            ]);
        }
    }

    public function delete(string $id): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $id]);
    }

    private function hydrate(Row $row): Conversation
    {
        $participantIds = $this->loadParticipantIds($row->getString('id'));

        return new Conversation(
            id: $row->getString('id'),
            type: ConversationType::from($row->getString('type')),
            participantIds: $participantIds,
            title: $row->getNullableString('title'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
        );
    }

    /**
     * @return list<string>
     */
    private function loadParticipantIds(string $conversationId): array
    {
        $result = $this->connection->query(self::SQL_GET_PARTICIPANTS, [
            'conversation_id' => $conversationId,
        ]);

        return $result->map(static fn(Row $row): string => $row->getString('user_id'));
    }
}
