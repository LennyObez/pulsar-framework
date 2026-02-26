<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Persistence;

use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;

#[Internal(reason: 'Raw-DB repository — used internally by DocVersionService')]
final readonly class DbDocVersionRepository
{
    private const string SQL_FIND_ALL = <<<'SQL'
        SELECT * FROM cms_doc_versions ORDER BY created_at DESC
        SQL;

    private const string SQL_FIND_DEFAULT = <<<'SQL'
        SELECT * FROM cms_doc_versions WHERE is_default = true LIMIT 1
        SQL;

    private const string SQL_CLEAR_DEFAULT = <<<'SQL'
        UPDATE cms_doc_versions SET is_default = false WHERE is_default = true
        SQL;

    private const string SQL_SET_DEFAULT = <<<'SQL'
        UPDATE cms_doc_versions SET is_default = true WHERE slug = :slug
        SQL;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * @return list<array{slug: string, label: string, is_default: bool, is_archived: bool, created_at: string}>
     */
    public function findAll(): array
    {
        $result = $this->connection->query(self::SQL_FIND_ALL);

        return $result->map(static fn(Row $row): array => [
            'slug' => $row->getString('slug'),
            'label' => $row->getString('label'),
            'is_default' => $row->getBool('is_default'),
            'is_archived' => $row->getBool('is_archived'),
            'created_at' => $row->getString('created_at'),
        ]);
    }

    /**
     * @return array{slug: string, label: string, is_default: bool, is_archived: bool, created_at: string}|null
     */
    public function findDefault(): ?array
    {
        $result = $this->connection->query(self::SQL_FIND_DEFAULT);
        $row = $result->first();

        if ($row === null) {
            return null;
        }

        return [
            'slug' => $row->getString('slug'),
            'label' => $row->getString('label'),
            'is_default' => $row->getBool('is_default'),
            'is_archived' => $row->getBool('is_archived'),
            'created_at' => $row->getString('created_at'),
        ];
    }

    public function setDefault(string $versionSlug): void
    {
        $this->connection->execute(self::SQL_CLEAR_DEFAULT);
        $this->connection->execute(self::SQL_SET_DEFAULT, ['slug' => $versionSlug]);
    }
}
