<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal\Adapter;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\BulkAction;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\ResourceOperation;

/**
 * Adapts an ORM entity class to the DataResourceInterface.
 *
 * Provides default implementations based on entity metadata
 * for use in the admin panel.
 */
#[Internal]
final readonly class OrmResourceAdapter implements DataResourceInterface
{
    /**
     * @param list<FieldDefinition> $fields
     * @param list<ResourceOperation> $operations
     * @param list<BulkAction> $bulkActions
     * @param list<string> $exportableFields
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public function __construct(
        private string $resourceName,
        private string $resourceLabel,
        private string $resourcePluralLabel,
        private string $resourceIcon,
        private string $tableName,
        private array $fields,
        private array $operations = [
            ResourceOperation::List,
            ResourceOperation::View,
            ResourceOperation::Create,
            ResourceOperation::Update,
            ResourceOperation::Delete,
            ResourceOperation::Export,
        ],
        private array $bulkActions = [],
        private array $exportableFields = [],
        private bool $auditReadsEnabled = false,
        private string $pk = 'id',
        private string $sortField = 'id',
        private string $sortDirection = 'desc',
    ) {}

    #[Override]
    public function name(): string
    {
        return $this->resourceName;
    }

    #[Override]
    public function label(): string
    {
        return $this->resourceLabel;
    }

    #[Override]
    public function pluralLabel(): string
    {
        return $this->resourcePluralLabel;
    }

    #[Override]
    public function icon(): string
    {
        return $this->resourceIcon;
    }

    #[Override]
    public function fields(): array
    {
        return $this->fields;
    }

    #[Override]
    public function operations(): array
    {
        return $this->operations;
    }

    #[Override]
    public function bulkActions(): array
    {
        return $this->bulkActions;
    }

    #[Override]
    public function exportableFields(): array
    {
        if ($this->exportableFields !== []) {
            return $this->exportableFields;
        }

        return array_values(array_map(
            static fn(FieldDefinition $f): string => $f->name,
            array_filter(
                $this->fields,
                static fn(FieldDefinition $f): bool => $f->exportable && !$f->redacted,
            ),
        ));
    }

    #[Override]
    public function auditReads(): bool
    {
        return $this->auditReadsEnabled;
    }

    #[Override]
    public function primaryKey(): string
    {
        return $this->pk;
    }

    #[Override]
    public function defaultSortField(): string
    {
        return $this->sortField;
    }

    #[Override]
    public function defaultSortDirection(): string
    {
        return $this->sortDirection;
    }

    public function tableName(): string
    {
        return $this->tableName;
    }
}
