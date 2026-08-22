<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\Tickets\Contracts\TicketMessageRepositoryInterface;
use Pulsar\Extension\Tickets\Domain\TicketMessage;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[Internal(reason: 'Raw-DB repository; use TicketMessageRepositoryInterface for public API')]
final readonly class DbTicketMessageRepository implements TicketMessageRepositoryInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * @return list<TicketMessage>
     */
    public function findByTicket(string $ticketId, bool $includeInternal = false): array
    {
        $sql = 'SELECT * FROM ticket_messages WHERE ticket_id = :ticket_id';

        if (!$includeInternal) {
            $sql .= ' AND is_internal = :is_internal';
        }

        $sql .= ' ORDER BY created_at ASC';

        $bindings = ['ticket_id' => $ticketId];

        if (!$includeInternal) {
            $bindings['is_internal'] = false;
        }

        $result = $this->connection->query($sql, $bindings);

        return $result->map(self::hydrate(...));
    }

    public function save(TicketMessage $message): void
    {
        $this->connection->execute(
            <<<'SQL'
                INSERT INTO ticket_messages (id, ticket_id, author_id, author_name, body, is_internal, attachments, created_at)
                VALUES (:id, :ticket_id, :author_id, :author_name, :body, :is_internal, :attachments, :created_at)
                SQL,
            [
                'id' => $message->id,
                'ticket_id' => $message->ticketId,
                'author_id' => $message->authorId,
                'author_name' => $message->authorName,
                'body' => $message->body,
                'is_internal' => $message->isInternal,
                'attachments' => json_encode($message->attachments, JSON_THROW_ON_ERROR),
                'created_at' => $message->createdAt->format('c'),
            ],
        );
    }

    public function countByTicket(string $ticketId): int
    {
        $result = $this->connection->query(
            'SELECT COUNT(*) AS cnt FROM ticket_messages WHERE ticket_id = :ticket_id',
            ['ticket_id' => $ticketId],
        );

        return $result->first()?->getInt('cnt') ?? 0;
    }

    private static function hydrate(Row $row): TicketMessage
    {
        $attachmentsRaw = $row->getNullableString('attachments');
        /** @var list<string> $attachments */
        $attachments = $attachmentsRaw !== null && $attachmentsRaw !== '' && $attachmentsRaw !== '[]'
            ? (array) json_decode($attachmentsRaw, true, 512, JSON_THROW_ON_ERROR)
            : [];

        return new TicketMessage(
            id: $row->getString('id'),
            ticketId: $row->getString('ticket_id'),
            authorId: $row->getNullableString('author_id'),
            authorName: $row->getString('author_name'),
            body: $row->getString('body'),
            isInternal: $row->getBool('is_internal'),
            attachments: $attachments,
            createdAt: new DateTimeImmutable($row->getString('created_at')),
        );
    }
}
