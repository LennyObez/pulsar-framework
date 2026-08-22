<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Admin;

use Override;
use Pulsar\Api\Api;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Domain\BulkAction;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\FieldType;
use Pulsar\Extension\Admin\Domain\ResourceOperation;

/**
 * Admin resource definition for forum categories.
 *
 * Supports full CRUD operations and bulk lock/unlock for managing
 * the hierarchical category taxonomy.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ForumCategoryResource implements DataResourceInterface
{
    #[Override]
    public function name(): string
    {
        return 'forum_categories';
    }

    #[Override]
    public function label(): string
    {
        return 'Forum Category';
    }

    #[Override]
    public function pluralLabel(): string
    {
        return 'Forum Categories';
    }

    #[Override]
    public function icon(): string
    {
        return 'folder';
    }

    #[Override]
    public function fields(): array
    {
        return [
            new FieldDefinition(
                name: 'id',
                type: FieldType::String,
                label: 'ID',
                editable: false,
                visibleOnList: false,
                visibleOnForm: false,
            ),
            new FieldDefinition(
                name: 'name',
                type: FieldType::String,
                label: 'Name',
                searchable: true,
            ),
            new FieldDefinition(
                name: 'slug',
                type: FieldType::String,
                label: 'Slug',
            ),
            new FieldDefinition(
                name: 'description',
                type: FieldType::Text,
                label: 'Description',
                visibleOnList: false,
            ),
            new FieldDefinition(
                name: 'parent_id',
                type: FieldType::Relation,
                label: 'Parent Category',
                relationResource: 'forum_categories',
            ),
            new FieldDefinition(
                name: 'sort_order',
                type: FieldType::Integer,
                label: 'Sort Order',
                sortable: true,
            ),
            new FieldDefinition(
                name: 'is_locked',
                type: FieldType::Boolean,
                label: 'Locked',
                filterable: true,
            ),
            new FieldDefinition(
                name: 'created_at',
                type: FieldType::DateTime,
                label: 'Created',
                editable: false,
            ),
        ];
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
        ];
    }

    #[Override]
    public function bulkActions(): array
    {
        return [
            new BulkAction(name: 'lock', label: 'Lock', icon: 'lock'),
            new BulkAction(name: 'unlock', label: 'Unlock', icon: 'unlock'),
        ];
    }

    #[Override]
    public function exportableFields(): array
    {
        return [
            'id', 'name', 'slug', 'description', 'parent_id',
            'sort_order', 'is_locked', 'created_at',
        ];
    }

    #[Override]
    public function auditReads(): bool
    {
        return false;
    }

    #[Override]
    public function primaryKey(): string
    {
        return 'id';
    }

    #[Override]
    public function defaultSortField(): string
    {
        return 'sort_order';
    }

    #[Override]
    public function defaultSortDirection(): string
    {
        return 'asc';
    }
}
