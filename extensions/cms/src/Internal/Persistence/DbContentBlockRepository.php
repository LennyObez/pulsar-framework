<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Portable\UpsertBuilder;
use Pulsar\Database\Row;
use Pulsar\Extension\Cms\Content\ContentBlock;
use Pulsar\Extension\Cms\Content\ContentBlockRepositoryInterface;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[Internal(reason: 'Raw-DB repository — use ContentBlockRepositoryInterface for public API')]
final readonly class DbContentBlockRepository implements ContentBlockRepositoryInterface
{
    private const string SQL_FIND_BY_ID = <<<'SQL'
        SELECT * FROM cms_content_blocks WHERE id = :id
        SQL;

    private const string SQL_FIND_BY_CONTENT_AND_LOCALE = <<<'SQL'
        SELECT * FROM cms_content_blocks
        WHERE content_id = :content_id AND locale = :locale
        ORDER BY sort_order ASC
        SQL;

    private const array UPSERT_COLUMNS = [
        'id', 'content_id', 'locale', 'block_type', 'sort_order', 'data',
        'created_at', 'updated_at',
    ];

    private const array UPSERT_UPDATE = ['block_type', 'sort_order', 'data', 'updated_at'];

    private const string SQL_DELETE = <<<'SQL'
        DELETE FROM cms_content_blocks WHERE id = :id
        SQL;

    private const string SQL_DELETE_BY_CONTENT_AND_LOCALE = <<<'SQL'
        DELETE FROM cms_content_blocks
        WHERE content_id = :content_id AND locale = :locale
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function findById(string $id): ?ContentBlock
    {
        $result = $this->connection->query(self::SQL_FIND_BY_ID, ['id' => $id]);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return self::hydrate($row);
    }

    public function findByContentAndLocale(string $contentId, string $locale): array
    {
        $result = $this->connection->query(self::SQL_FIND_BY_CONTENT_AND_LOCALE, [
            'content_id' => $contentId,
            'locale' => $locale,
        ]);

        return $result->map(self::hydrate(...));
    }

    public function save(ContentBlock $block): void
    {
        $sql = UpsertBuilder::compile(
            $this->connection->driver(),
            'cms_content_blocks',
            self::UPSERT_COLUMNS,
            ['id'],
            self::UPSERT_UPDATE,
        );

        $this->connection->execute($sql, [
            'id' => $block->id,
            'content_id' => $block->contentId,
            'locale' => $block->locale,
            'block_type' => $block->blockType,
            'sort_order' => $block->sortOrder,
            'data' => json_encode($block->data, JSON_THROW_ON_ERROR),
            'created_at' => $block->createdAt->format('c'),
            'updated_at' => $block->updatedAt->format('c'),
        ]);
    }

    public function saveAll(array $blocks): void
    {
        if ($blocks === []) {
            return;
        }

        $this->connection->transaction(function () use ($blocks): void {
            foreach ($blocks as $block) {
                $this->save($block);
            }
        });
    }

    public function delete(string $id): void
    {
        $this->connection->execute(self::SQL_DELETE, ['id' => $id]);
    }

    public function deleteByContentAndLocale(string $contentId, string $locale): void
    {
        $this->connection->execute(self::SQL_DELETE_BY_CONTENT_AND_LOCALE, [
            'content_id' => $contentId,
            'locale' => $locale,
        ]);
    }

    private static function hydrate(Row $row): ContentBlock
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($row->getString('data'), true, 512, JSON_THROW_ON_ERROR);

        return new ContentBlock(
            id: $row->getString('id'),
            contentId: $row->getString('content_id'),
            locale: $row->getString('locale'),
            blockType: $row->getString('block_type'),
            sortOrder: $row->getInt('sort_order'),
            data: $data,
            createdAt: new DateTimeImmutable($row->getString('created_at')),
            updatedAt: new DateTimeImmutable($row->getString('updated_at')),
        );
    }
}
