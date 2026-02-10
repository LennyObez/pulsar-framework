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
 * Admin resource definition for forum tags.
 *
 * Supports full CRUD for managing thread classification labels.
 */
#[Api(since: '1.0.0')]
final readonly class ForumTagResource implements DataResourceInterface
{
    #[Override]
    public function name(): string
    {
        return 'forum_tags';
    }

    #[Override]
    public function label(): string
    {
        return 'Forum Tag';
    }

    #[Override]
    public function pluralLabel(): string
    {
        return 'Forum Tags';
    }

    #[Override]
    public function icon(): string
    {
        return 'tag';
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
                name: 'usage_count',
                type: FieldType::Integer,
                label: 'Usage',
                sortable: true,
                editable: false,
            ),
            new FieldDefinition(
                name: 'created_at',
                type: FieldType::DateTime,
                label: 'Created',
                editable: false,
                visibleOnList: false,
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

    /** @return list<BulkAction> */
    #[Override]
    public function bulkActions(): array
    {
        return [];
    }

    #[Override]
    public function exportableFields(): array
    {
        return ['id', 'name', 'slug', 'description', 'usage_count', 'created_at'];
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
        return 'usage_count';
    }

    #[Override]
    public function defaultSortDirection(): string
    {
        return 'desc';
    }
}
