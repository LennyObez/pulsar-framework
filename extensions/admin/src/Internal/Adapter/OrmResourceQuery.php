<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Adapter;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceQueryInterface;
use Pulsar\Extension\Admin\Domain\FieldDefinition;

use function array_map;
use function implode;
use function in_array;
use function max;

/**
 * SQL-based query implementation for ORM-backed admin resources.
 */
#[Internal]
final readonly class OrmResourceQuery implements ResourceQueryInterface
{
    use SqlIdentifierValidator;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function list(
        DataResourceInterface $resource,
        array $filters = [],
        array $sort = [],
        int $page = 1,
        int $perPage = 25,
    ): array {
        $table = $this->tableName($resource);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $whereClauses = [];
        $bindings = [];
        $this->buildWhere($resource, $filters, $whereClauses, $bindings);

        $whereStr = $whereClauses !== [] ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

        $orderBy = $this->buildOrderBy($resource, $sort);

        $countSql = "SELECT COUNT(*) AS cnt FROM $table$whereStr";
        $countResult = $this->connection->query($countSql, $bindings);
        $cntValue = $countResult->first()?->get('cnt');
        $total = is_numeric($cntValue) ? (int) $cntValue : 0;

        $dataSql = "SELECT * FROM $table$whereStr$orderBy LIMIT $perPage OFFSET $offset";
        $dataResult = $this->connection->query($dataSql, $bindings);

        $data = array_map(
            static fn($row): array => $row->toArray(),
            $dataResult->rows,
        );

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    #[Override]
    public function find(DataResourceInterface $resource, string $id): ?array
    {
        $table = $this->tableName($resource);
        $pk = $resource->primaryKey();

        $sql = "SELECT * FROM $table WHERE $pk = :id LIMIT 1";
        $result = $this->connection->query($sql, ['id' => $id]);

        if ($result->rows === []) {
            return null;
        }

        return $result->rows[0]->toArray();
    }

    #[Override]
    public function search(DataResourceInterface $resource, string $query, int $limit = 10): array
    {
        $table = $this->tableName($resource);
        $searchableFields = array_filter(
            $resource->fields(),
            static fn(FieldDefinition $f): bool => $f->searchable,
        );

        if ($searchableFields === []) {
            return [];
        }

        $conditions = array_map(
            static fn(FieldDefinition $f): string => "$f->name LIKE :search",
            array_values($searchableFields),
        );

        $whereStr = implode(' OR ', $conditions);
        $sql = "SELECT * FROM $table WHERE $whereStr LIMIT $limit";

        $result = $this->connection->query($sql, ['search' => "%$query%"]);

        return array_map(
            static fn($row): array => $row->toArray(),
            $result->rows,
        );
    }

    #[Override]
    public function count(DataResourceInterface $resource, array $filters = []): int
    {
        $table = $this->tableName($resource);

        $whereClauses = [];
        $bindings = [];
        $this->buildWhere($resource, $filters, $whereClauses, $bindings);

        $whereStr = $whereClauses !== [] ? ' WHERE ' . implode(' AND ', $whereClauses) : '';

        $sql = "SELECT COUNT(*) AS cnt FROM $table$whereStr";
        $result = $this->connection->query($sql, $bindings);
        $cntVal = $result->first()?->get('cnt');
        return is_numeric($cntVal) ? (int) $cntVal : 0;
    }

    private function tableName(DataResourceInterface $resource): string
    {
        if ($resource instanceof OrmResourceAdapter) {
            return $resource->tableName();
        }
        return $resource->name();
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string> $whereClauses
     * @param array<string, mixed> $bindings
     */
    private function buildWhere(
        DataResourceInterface $resource,
        array $filters,
        array &$whereClauses,
        array &$bindings,
    ): void {
        $filterableFields = array_map(
            static fn(FieldDefinition $f): string => $f->name,
            array_filter(
                $resource->fields(),
                static fn(FieldDefinition $f): bool => $f->filterable,
            ),
        );

        foreach ($filters as $field => $value) {
            if (!in_array($field, $filterableFields, true)) {
                continue;
            }
            $paramName = "filter_$field";
            $whereClauses[] = "$field = :$paramName";
            $bindings[$paramName] = $value;
        }
    }

    /**
     * @param array<string, string> $sort
     */
    private function buildOrderBy(DataResourceInterface $resource, array $sort): string
    {
        if ($sort === []) {
            $quotedField = $this->quoteIdentifier($resource->defaultSortField());
            $dir = strtoupper($resource->defaultSortDirection()) === 'ASC' ? 'ASC' : 'DESC';
            return " ORDER BY $quotedField $dir";
        }

        $sortableFields = array_map(
            static fn(FieldDefinition $f): string => $f->name,
            array_filter(
                $resource->fields(),
                static fn(FieldDefinition $f): bool => $f->sortable,
            ),
        );

        $clauses = [];
        foreach ($sort as $field => $direction) {
            if (!in_array($field, $sortableFields, true)) {
                continue;
            }
            $quotedField = $this->quoteIdentifier($field);
            $dir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
            $clauses[] = "$quotedField $dir";
        }

        if ($clauses === []) {
            $quotedField = $this->quoteIdentifier($resource->defaultSortField());
            $dir = strtoupper($resource->defaultSortDirection()) === 'ASC' ? 'ASC' : 'DESC';
            return " ORDER BY $quotedField $dir";
        }

        return ' ORDER BY ' . implode(', ', $clauses);
    }
}
