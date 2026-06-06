<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Adapter;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\BulkAction;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\ResourceOperation;

use function array_filter;
use function array_map;

/**
 * A DataResourceInterface backed by database introspection.
 *
 * Represents a database table discovered at runtime, with fields
 * derived from column metadata rather than explicit model classes.
 */
#[Internal]
final readonly class IntrospectedResource implements DataResourceInterface
{
    /**
     * @param list<FieldDefinition> $fields
     */
    public function __construct(
        private string $tableName,
        private string $singularLabel,
        private string $pluralLabel,
        private string $primaryKeyField,
        private array $fields,
    ) {}

    #[Override]
    public function name(): string
    {
        return $this->tableName;
    }

    /**
     * Expose the raw table name for OrmResourceQuery compatibility.
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function tableName(): string
    {
        return $this->tableName;
    }

    #[Override]
    public function label(): string
    {
        return $this->singularLabel;
    }

    #[Override]
    public function pluralLabel(): string
    {
        return $this->pluralLabel;
    }

    #[Override]
    public function icon(): string
    {
        return 'table';
    }

    #[Override]
    public function fields(): array
    {
        return $this->fields;
    }

    #[Override]
    public function operations(): array
    {
        return [
            ResourceOperation::List,
            ResourceOperation::View,
            ResourceOperation::Create,
            ResourceOperation::Update,
            ResourceOperation::Delete,
            ResourceOperation::Export,
        ];
    }

    #[Override]
    public function bulkActions(): array
    {
        return [
            new BulkAction(name: 'delete', label: 'Delete Selected', destructive: true),
        ];
    }

    #[Override]
    public function exportableFields(): array
    {
        return array_values(array_map(
            static fn(FieldDefinition $f): string => $f->name,
            array_filter($this->fields, static fn(FieldDefinition $f): bool => $f->exportable),
        ));
    }

    #[Override]
    public function auditReads(): bool
    {
        return false;
    }

    #[Override]
    public function primaryKey(): string
    {
        return $this->primaryKeyField;
    }

    #[Override]
    public function defaultSortField(): string
    {
        return $this->primaryKeyField;
    }

    #[Override]
    public function defaultSortDirection(): string
    {
        return 'ASC';
    }
}
