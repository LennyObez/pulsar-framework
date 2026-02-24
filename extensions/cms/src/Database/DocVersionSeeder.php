<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Database;

use Pulsar\Api\Api;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Seeder\SeederInterface;

/**
 * Seeds default documentation versions.
 *
 * Creates "1.0" as the current stable version and "master" as the
 * development version. Uses INSERT with the cms_doc_versions table schema
 * from DbDocVersionRepository.
 *
 * @psalm-api Discovered + run by the SeederRunner from the seeders
 *            directory; not new'd by name.
 */
#[Api(since: '1.0.0')]
final readonly class DocVersionSeeder implements SeederInterface
{
    private const string SQL_INSERT = <<<'SQL'
        INSERT INTO cms_doc_versions (slug, label, is_default, is_archived, created_at)
        VALUES (:slug, :label, :is_default, :is_archived, :created_at)
        SQL;

    public function identifier(): string
    {
        return 'cms:doc-versions';
    }

    public function run(ConnectionInterface $connection): void
    {
        foreach (self::definitions() as $definition) {
            $connection->execute(self::SQL_INSERT, [
                'slug' => $definition['slug'],
                'label' => $definition['label'],
                'is_default' => $definition['isCurrent'],
                'is_archived' => false,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * @return list<array{slug: string, label: string, isCurrent: bool}>
     */
    public static function definitions(): array
    {
        return [
            [
                'slug' => '1.0',
                'label' => '1.0 (Stable)',
                'isCurrent' => true,
            ],
            [
                'slug' => 'master',
                'label' => 'Master (Development)',
                'isCurrent' => false,
            ],
        ];
    }
}
