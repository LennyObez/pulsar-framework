<?php

declare(strict_types=1);

namespace Pulsar\Extension\Messaging\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Api\Pagination\PaginationResult;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Messaging\Contracts\MessageRepositoryInterface;
use Pulsar\Extension\Messaging\Domain\Message;
use Pulsar\Extension\Messaging\Domain\MessageType;

use function ceil;
use function max;
use function min;

/**
 * Database-backed message repository.
 *
 * Stores encrypted message ciphertext: the server never has access
 * to plaintext message content.
 */
#[Internal(reason: 'Use MessageRepositoryInterface for public API')]
final readonly class DbMessageRepository implements MessageRepositoryInterface
{
    private const string SQL_COUNT = <<<'SQL'
        SELECT COUNT(*) AS total FROM messaging_messages
        WHERE conversation_id = :conversation_id
        SQL;

    private const string SQL_FIND = <<<'SQL'
        SELECT * FROM messaging_messages
        WHERE conversation_id = :conversation_id
        ORDER BY timestamp DESC
        LIMIT :limit OFFSET :offset
        SQL;

    private const string SQL_INSERT = <<<'SQL'
        INSERT INTO messaging_messages (id, conversation_id, sender_id, encrypted_content, nonce, type, timestamp)
        VALUES (:id, :conversation_id, :sender_id, :encrypted_content, :nonce, :type, :timestamp)
        SQL;

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM messaging_messages WHERE id = :id
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findByConversation(
        string $conversationId,
        int $page = 1,
        int $perPage = 50,
    ): PaginationResult {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $countResult = $this->connection->query(self::SQL_COUNT, [
            'conversation_id' => $conversationId,
        ]);
        $total = $countResult->first()?->getInt('total') ?? 0;

        $dataResult = $this->connection->query(self::SQL_FIND, [
            'conversation_id' => $conversationId,
            'limit' => $perPage,
            'offset' => $offset,
        ]);

        $items = $dataResult->map(self::hydrate(...));
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;

        return new PaginationResult(
            items: $items,
            total: $total,
            hasMore: $page < $lastPage,
            perPage: $perPage,
            currentPage: $page,
            lastPage: $lastPage,
        );
    }

    public function save(Message $message): void
    {
        $this->connection->execute(self::SQL_INSERT, [
            'id' => $message->id,
            'conversation_id' => $message->conversationId,
            'sender_id' => $message->senderId,
            'encrypted_content' => $message->encryptedContent,
            'nonce' => $message->nonce,
            'type' => $message->type->value,
            'timestamp' => $message->timestamp->format('c'),
        ]);
    }

    public function delete(string $id): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $id]);
    }

    private static function hydrate(Row $row): Message
    {
        return new Message(
            id: $row->getString('id'),
            conversationId: $row->getString('conversation_id'),
            senderId: $row->getString('sender_id'),
            encryptedContent: $row->getString('encrypted_content'),
            nonce: $row->getString('nonce'),
            type: MessageType::from($row->getString('type')),
            timestamp: new DateTimeImmutable($row->getString('timestamp')),
        );
    }
}
