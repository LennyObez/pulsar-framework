<?php

declare(strict_types=1);

namespace Pulsar\Live\Admin;

use Pulsar\Api\Api;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\BulkAction;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\ResourceOperation;

/**
 * Declarative base class for admin resource definitions.
 *
 * Provides a fluent, expressive API for defining admin CRUD resources
 * that auto-generate list views, create/edit forms, and detail pages.
 *
 * Example:
 *   final class UserResource extends AdminResource {
 *       protected string $resourceName = 'users';
 *       protected string $resourceLabel = 'User';
 *       protected string $resourcePluralLabel = 'Users';
 *
 *       public function fields(): array {
 *           return [
 *               TextField::make('name')->required()->searchable(),
 *               EmailField::make('email')->unique()->searchable(),
 *               SelectField::make('role')->options(Role::cases()),
 *               DateField::make('created_at')->readonly()->sortable(),
 *           ];
 *       }
 *
 *       public function filters(): array {
 *           return [
 *               SelectFilter::make('role'),
 *               DateRangeFilter::make('created_at'),
 *           ];
 *       }
 *   }
 * @api
 */
#[Api(since: '1.0.0')]
abstract class AdminResource implements DataResourceInterface
{
    protected string $resourceName = '';

    protected string $resourceLabel = '';

    protected string $resourcePluralLabel = '';

    protected string $resourceIcon = 'database';

    protected string $primaryKeyField = 'id';

    protected string $defaultSort = 'id';

    protected string $defaultSortDir = 'desc';

    protected bool $auditReadAccess = false;

    /**
     * Define the fields for this resource.
     *
     * @return list<Field>
     */
    abstract public function defineFields(): array;

    /**
     * Define the filters for the list view.
     *
     * @return list<Filter>
     */
    public function filters(): array
    {
        return [];
    }

    /**
     * Define bulk actions available on the list view.
     *
     * @return list<BulkAction>
     */
    public function resourceBulkActions(): array
    {
        return [
            new BulkAction(name: 'delete', label: 'Delete selected', destructive: true),
        ];
    }

    /**
     * Define which operations are supported.
     *
     * @return list<ResourceOperation>
     */
    public function resourceOperations(): array
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

    // --- DataResourceInterface implementation ---

    public function name(): string
    {
        return $this->resourceName;
    }

    public function label(): string
    {
        return $this->resourceLabel;
    }

    public function pluralLabel(): string
    {
        return $this->resourcePluralLabel !== '' ? $this->resourcePluralLabel : $this->resourceLabel . 's';
    }

    public function icon(): string
    {
        return $this->resourceIcon;
    }

    /** @return list<FieldDefinition> */
    public function fields(): array
    {
        return array_map(
            static fn(Field $field): FieldDefinition => $field->toFieldDefinition(),
            $this->defineFields(),
        );
    }

    /** @return list<ResourceOperation> */
    public function operations(): array
    {
        return $this->resourceOperations();
    }

    /** @return list<BulkAction> */
    public function bulkActions(): array
    {
        return $this->resourceBulkActions();
    }

    /** @return list<string> */
    public function exportableFields(): array
    {
        return array_values(array_map(
            static fn(Field $f): string => $f->fieldName,
            array_filter($this->defineFields(), static fn(Field $f): bool => $f->isExportable),
        ));
    }

    public function auditReads(): bool
    {
        return $this->auditReadAccess;
    }

    public function primaryKey(): string
    {
        return $this->primaryKeyField;
    }

    public function defaultSortField(): string
    {
        return $this->defaultSort;
    }

    public function defaultSortDirection(): string
    {
        return $this->defaultSortDir;
    }
}
