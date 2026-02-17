<?php

declare(strict_types=1);

namespace Pulsar\Extension\Tickets\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Tickets\Contracts\TicketCategoryRepositoryInterface;
use Pulsar\Extension\Tickets\Domain\TicketCategory;

#[Internal(reason: 'Raw-DB repository; use TicketCategoryRepositoryInterface for public API')]
final readonly class DbTicketCategoryRepository implements TicketCategoryRepositoryInterface
{
    private const array UPSERT_COLUMNS = [
        'id', 'name', 'slug', 'description', 'parent_id', 'sort_order',
        'created_at', 'updated_at',
    ];

    private const array UPSERT_UPDATE = [
        'name', 'slug', 'description', 'parent_id', 'sort_order', 'updated_at',
    ];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findById(string $id): ?TicketCategory
    {
        $result = $this->connection->query(
            'SELECT * FROM ticket_categories WHERE id = :id',
            ['id' => $id],
        );

        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?TicketCategory
    {
        $result = $this->connection->query(
            'SELECT * FROM ticket_categories WHERE slug = :slug',
            ['slug' => $slug],
        );

        $row = $result->first();

        return $row !== null ? self::hydrate($row) : null;
    }

    /**
     * @return list<TicketCategory>
     */
    public function findAll(): array
    {
        $result = $this->connection->query(
            'SELECT * FROM ticket_categories ORDER BY sort_order ASC, name ASC',
            [],
        );

        return $result->map(self::hydrate(...));
    }

    /**
     * @return list<TicketCategory>
     */
    public function findByParent(?string $parentId): array
    {
        if ($parentId === null) {
            $result = $this->connection->query(
                'SELECT * FROM ticket_categories WHERE parent_id IS NULL ORDER BY sort_order ASC, name ASC',
                [],
            );
        } else {
            $result = $this->connection->query(
                'SELECT * FROM ticket_categories WHERE parent_id = :parent_id ORDER BY sort_order ASC, name ASC',
                ['parent_id' => $parentId],
            );
        }

        return $result->map(self::hydrate(...));
    }

    public function save(TicketCategory $category): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'ticket_categories',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'parent_id' => $category->parentId,
            'sort_order' => $category->sortOrder,
            'created_at' => $category->createdAt->format('c'),
            'updated_at' => $category->updatedAt->format('c'),
        ]);
    }

    public function delete(TicketCategory $category): void
    {
        $this->connection->execute(
            'DELETE FROM ticket_categories WHERE id = :id',
            ['id' => $category->id],
        );
    }

    private static function hydrate(Row $row): TicketCategory
    {
        return new TicketCategory(
            id: $row->getString('id'),
            name: $row->getString('name'),
            slug: $row->getString('slug'),
            description: $row->getNullableString('description'),
            parentId: $row->getNullableString('parent_id'),
            sortOrder: $row->getInt('sort_order'),
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
        );
    }
}
