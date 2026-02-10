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
 * Admin resource definition for forum posts.
 *
 * Provides read-only listing and viewing plus delete operations
 * for moderating individual replies within threads.
 */
#[Api(since: '1.0.0')]
final readonly class ForumPostResource implements DataResourceInterface
{
    #[Override]
    public function name(): string
    {
        return 'forum_posts';
    }

    #[Override]
    public function label(): string
    {
        return 'Forum Post';
    }

    #[Override]
    public function pluralLabel(): string
    {
        return 'Forum Posts';
    }

    #[Override]
    public function icon(): string
    {
        return 'message-circle';
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
                name: 'thread_id',
                type: FieldType::Relation,
                label: 'Thread',
                editable: false,
                relationResource: 'forum_threads',
            ),
            new FieldDefinition(
                name: 'parent_id',
                type: FieldType::String,
                label: 'Parent Post',
                editable: false,
                visibleOnList: false,
            ),
            new FieldDefinition(
                name: 'author_id',
                type: FieldType::String,
                label: 'Author',
                editable: false,
            ),
            new FieldDefinition(
                name: 'body',
                type: FieldType::Text,
                label: 'Body',
                editable: false,
                visibleOnList: false,
            ),
            new FieldDefinition(
                name: 'is_solution',
                type: FieldType::Boolean,
                label: 'Solution',
                editable: false,
            ),
            new FieldDefinition(
                name: 'vote_score',
                type: FieldType::Integer,
                label: 'Score',
                sortable: true,
                editable: false,
            ),
            new FieldDefinition(
                name: 'edit_count',
                type: FieldType::Integer,
                label: 'Edits',
                editable: false,
                visibleOnList: false,
            ),
            new FieldDefinition(
                name: 'created_at',
                type: FieldType::DateTime,
                label: 'Created',
                sortable: true,
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
            ResourceOperation::Delete,
        ];
    }

    #[Override]
    public function bulkActions(): array
    {
        return [
            new BulkAction(name: 'delete', label: 'Delete', destructive: true, icon: 'trash'),
        ];
    }

    #[Override]
    public function exportableFields(): array
    {
        return [
            'id', 'thread_id', 'parent_id', 'author_id', 'body',
            'is_solution', 'vote_score', 'edit_count', 'created_at',
        ];
    }

    #[Override]
    public function auditReads(): bool
    {
        return true;
    }

    #[Override]
    public function primaryKey(): string
    {
        return 'id';
    }

    #[Override]
    public function defaultSortField(): string
    {
        return 'created_at';
    }

    #[Override]
    public function defaultSortDirection(): string
    {
        return 'desc';
    }
}
