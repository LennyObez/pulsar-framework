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
 * Admin resource definition for forum user profiles.
 *
 * Provides listing, viewing, and editing of per-user forum metadata
 * including reputation, activity counts, and ban management.
 */
#[Api(since: '1.0.0')]
final readonly class ForumProfileResource implements DataResourceInterface
{
    #[Override]
    public function name(): string
    {
        return 'forum_profiles';
    }

    #[Override]
    public function label(): string
    {
        return 'Forum Profile';
    }

    #[Override]
    public function pluralLabel(): string
    {
        return 'Forum Profiles';
    }

    #[Override]
    public function icon(): string
    {
        return 'user';
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
                name: 'user_id',
                type: FieldType::String,
                label: 'User',
                searchable: true,
                editable: false,
            ),
            new FieldDefinition(
                name: 'reputation_score',
                type: FieldType::Integer,
                label: 'Reputation',
                sortable: true,
                editable: false,
            ),
            new FieldDefinition(
                name: 'post_count',
                type: FieldType::Integer,
                label: 'Posts',
                sortable: true,
                editable: false,
            ),
            new FieldDefinition(
                name: 'thread_count',
                type: FieldType::Integer,
                label: 'Threads',
                sortable: true,
                editable: false,
            ),
            new FieldDefinition(
                name: 'is_banned',
                type: FieldType::Boolean,
                label: 'Banned',
                filterable: true,
            ),
            new FieldDefinition(
                name: 'ban_reason',
                type: FieldType::String,
                label: 'Ban Reason',
                visibleOnList: false,
            ),
            new FieldDefinition(
                name: 'banned_at',
                type: FieldType::DateTime,
                label: 'Banned At',
                editable: false,
                visibleOnList: false,
            ),
            new FieldDefinition(
                name: 'ban_expires_at',
                type: FieldType::DateTime,
                label: 'Ban Expires',
                visibleOnList: false,
            ),
            new FieldDefinition(
                name: 'created_at',
                type: FieldType::DateTime,
                label: 'Joined',
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
        ];
    }

    #[Override]
    public function bulkActions(): array
    {
        return [
            new BulkAction(name: 'ban', label: 'Ban', icon: 'ban'),
            new BulkAction(name: 'unban', label: 'Unban', icon: 'check-circle'),
        ];
    }

    #[Override]
    public function exportableFields(): array
    {
        return [
            'id', 'user_id', 'reputation_score', 'post_count', 'thread_count',
            'is_banned', 'ban_reason', 'banned_at', 'ban_expires_at', 'created_at',
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
        return 'reputation_score';
    }

    #[Override]
    public function defaultSortDirection(): string
    {
        return 'desc';
    }
}
