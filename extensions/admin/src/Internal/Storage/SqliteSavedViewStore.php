<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Storage;

use Override;
use PDO;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Domain\SavedView;

/**
 * SQLite-backed saved view store.
 */
#[Internal]
final class SqliteSavedViewStore implements SavedViewStoreInterface
{
    private bool $initialized = false;

    public function __construct(
        private readonly PDO $pdo,
    ) {}

    #[Override]
    public function listForResource(string $resourceName): array
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(
            'SELECT * FROM admin_saved_views WHERE resource_name = :resource ORDER BY is_default DESC, label ASC',
        );
        $stmt->execute(['resource' => $resourceName]);

        $views = [];
        /** @var array<string, mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $views[] = $this->hydrate($row);
        }
        return $views;
    }

    #[Override]
    public function find(string $id): ?SavedView
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare('SELECT * FROM admin_saved_views WHERE id = :id');
        $stmt->execute(['id' => $id]);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->hydrate($row);
    }

    #[Override]
    public function save(SavedView $view): void
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare(
            'INSERT OR REPLACE INTO admin_saved_views (id, resource_name, label, filters, sort, per_page, created_by, is_default, created_at)
             VALUES (:id, :resource_name, :label, :filters, :sort, :per_page, :created_by, :is_default, :created_at)',
        );
        $stmt->execute([
            'id' => $view->id,
            'resource_name' => $view->resourceName,
            'label' => $view->label,
            'filters' => json_encode($view->filters, JSON_THROW_ON_ERROR),
            'sort' => json_encode($view->sort, JSON_THROW_ON_ERROR),
            'per_page' => $view->perPage,
            'created_by' => $view->createdBy,
            'is_default' => $view->isDefault ? 1 : 0,
            'created_at' => $view->createdAt,
        ]);
    }

    #[Override]
    public function delete(string $id): void
    {
        $this->ensureSchema();

        $stmt = $this->pdo->prepare('DELETE FROM admin_saved_views WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function ensureSchema(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS admin_saved_views (
                id TEXT PRIMARY KEY,
                resource_name TEXT NOT NULL,
                label TEXT NOT NULL,
                filters TEXT NOT NULL DEFAULT "{}",
                sort TEXT NOT NULL DEFAULT "{}",
                per_page INTEGER NOT NULL DEFAULT 25,
                created_by TEXT NOT NULL,
                is_default INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0
            )',
        );

        $this->initialized = true;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SavedView
    {
        /** @var array{id: string, resource_name: string, label: string, filters: string, sort: string, per_page: int, created_by: string, is_default: int, created_at: int} $row */
        /** @var array<string, mixed> $filters */
        $filters = json_decode($row['filters'], true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, string> $sort */
        $sort = json_decode($row['sort'], true, 512, JSON_THROW_ON_ERROR);

        return new SavedView(
            id: $row['id'],
            resourceName: $row['resource_name'],
            label: $row['label'],
            filters: $filters,
            sort: $sort,
            perPage: $row['per_page'],
            createdBy: $row['created_by'],
            isDefault: (bool) $row['is_default'],
            createdAt: $row['created_at'],
        );
    }
}
