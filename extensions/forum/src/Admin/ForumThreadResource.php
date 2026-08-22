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
use Pulsar\Extension\Forum\Domain\ThreadStatus;
use Pulsar\Extension\Forum\Domain\ThreadType;

use function array_map;

/**
 * Admin resource definition for forum threads.
 *
 * Exposes thread management operations including lock, unlock, pin,
 * unpin, and bulk delete through the admin panel.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ForumThreadResource implements DataResourceInterface
{
    #[Override]
    public function name(): string
    {
        return 'forum_threads';
    }

    #[Override]
    public function label(): string
    {
        return 'Forum Thread';
    }

    #[Override]
    public function pluralLabel(): string
    {
        return 'Forum Threads';
    }

    #[Override]
    public function icon(): string
    {
        return 'message-square';
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
                name: 'title',
                type: FieldType::String,
                label: 'Title',
                sortable: true,
                searchable: true,
            ),
            new FieldDefinition(
                name: 'slug',
                type: FieldType::String,
                label: 'Slug',
                visibleOnList: false,
            ),
            new FieldDefinition(
                name: 'category_id',
                type: FieldType::Relation,
                label: 'Category',
                relationResource: 'forum_categories',
            ),
            new FieldDefinition(
                name: 'author_id',
                type: FieldType::String,
                label: 'Author',
                editable: false,
            ),
            new FieldDefinition(
                name: 'type',
                type: FieldType::Enum,
                label: 'Type',
                filterable: true,
                enumValues: array_map(
                    static fn(ThreadType $t): string => $t->value,
                    ThreadType::cases(),
                ),
            ),
            new FieldDefinition(
                name: 'status',
                type: FieldType::Enum,
                label: 'Status',
                filterable: true,
                enumValues: array_map(
                    static fn(ThreadStatus $s): string => $s->value,
                    ThreadStatus::cases(),
                ),
            ),
            new FieldDefinition(
                name: 'is_pinned',
                type: FieldType::Boolean,
                label: 'Pinned',
                filterable: true,
            ),
            new FieldDefinition(
                name: 'is_locked',
                type: FieldType::Boolean,
                label: 'Locked',
                filterable: true,
            ),
            new FieldDefinition(
                name: 'reply_count',
                type: FieldType::Integer,
                label: 'Replies',
                sortable: true,
                editable: false,
            ),
            new FieldDefinition(
                name: 'view_count',
                type: FieldType::Integer,
                label: 'Views',
                sortable: true,
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
                name: 'created_at',
                type: FieldType::DateTime,
                label: 'Created',
                sortable: true,
                editable: false,
            ),
            new FieldDefinition(
                name: 'updated_at',
                type: FieldType::DateTime,
                label: 'Updated',
                sortable: true,
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
            new BulkAction(name: 'pin', label: 'Pin', icon: 'pin'),
            new BulkAction(name: 'unpin', label: 'Unpin', icon: 'pin-off'),
            new BulkAction(name: 'delete', label: 'Delete', destructive: true, icon: 'trash'),
        ];
    }

    #[Override]
    public function exportableFields(): array
    {
        return [
            'id', 'title', 'slug', 'category_id', 'author_id', 'type', 'status',
            'is_pinned', 'is_locked', 'reply_count', 'view_count', 'vote_score',
            'created_at', 'updated_at',
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
