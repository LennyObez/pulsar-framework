<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Storage;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Admin\Domain\SavedView;

use function is_string;

/**
 * Database-backed saved view store using ConnectionInterface.
 */
#[Internal]
final readonly class DbSavedViewStore implements SavedViewStoreInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function listForResource(string $resourceName): array
    {
        $result = $this->connection->query(
            'SELECT * FROM admin_saved_views WHERE resource_name = :resource ORDER BY is_default DESC, label ASC',
            ['resource' => $resourceName],
        );

        $views = [];
        foreach ($result->rows as $row) {
            $views[] = $this->hydrate($row->toArray());
        }
        return $views;
    }

    #[Override]
    public function find(string $id): ?SavedView
    {
        $result = $this->connection->query(
            'SELECT * FROM admin_saved_views WHERE id = :id',
            ['id' => $id],
        );

        if ($result->rows === []) {
            return null;
        }

        return $this->hydrate($result->rows[0]->toArray());
    }

    #[Override]
    public function save(SavedView $view): void
    {
        $existing = $this->find($view->id);

        if ($existing !== null) {
            $this->connection->execute(
                'UPDATE admin_saved_views SET label = :label, filters = :filters, sort = :sort, per_page = :per_page, is_default = :is_default WHERE id = :id',
                [
                    'id' => $view->id,
                    'label' => $view->label,
                    'filters' => json_encode($view->filters, JSON_THROW_ON_ERROR),
                    'sort' => json_encode($view->sort, JSON_THROW_ON_ERROR),
                    'per_page' => $view->perPage,
                    'is_default' => $view->isDefault ? 1 : 0,
                ],
            );
        } else {
            $this->connection->execute(
                'INSERT INTO admin_saved_views (id, resource_name, label, filters, sort, per_page, created_by, is_default, created_at) VALUES (:id, :resource_name, :label, :filters, :sort, :per_page, :created_by, :is_default, :created_at)',
                [
                    'id' => $view->id,
                    'resource_name' => $view->resourceName,
                    'label' => $view->label,
                    'filters' => json_encode($view->filters, JSON_THROW_ON_ERROR),
                    'sort' => json_encode($view->sort, JSON_THROW_ON_ERROR),
                    'per_page' => $view->perPage,
                    'created_by' => $view->createdBy,
                    'is_default' => $view->isDefault ? 1 : 0,
                    'created_at' => $view->createdAt,
                ],
            );
        }
    }

    #[Override]
    public function delete(string $id): void
    {
        $this->connection->execute(
            'DELETE FROM admin_saved_views WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SavedView
    {
        $filtersJson = isset($row['filters']) && is_string($row['filters']) ? $row['filters'] : '{}';
        $sortJson = isset($row['sort']) && is_string($row['sort']) ? $row['sort'] : '{}';
        /** @var array<string, mixed> $filters */
        $filters = json_decode($filtersJson, true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, string> $sort */
        $sort = json_decode($sortJson, true, 512, JSON_THROW_ON_ERROR);

        $id = isset($row['id']) && is_string($row['id']) ? $row['id'] : '';
        $resourceName = isset($row['resource_name']) && is_string($row['resource_name']) ? $row['resource_name'] : '';
        $label = isset($row['label']) && is_string($row['label']) ? $row['label'] : '';
        $createdBy = isset($row['created_by']) && is_string($row['created_by']) ? $row['created_by'] : '';

        return new SavedView(
            id: $id,
            resourceName: $resourceName,
            label: $label,
            filters: $filters,
            sort: $sort,
            perPage: isset($row['per_page']) && is_numeric($row['per_page']) ? (int) $row['per_page'] : 25,
            createdBy: $createdBy,
            isDefault: !empty($row['is_default']),
            createdAt: isset($row['created_at']) && is_numeric($row['created_at']) ? (int) $row['created_at'] : 0,
        );
    }
}
