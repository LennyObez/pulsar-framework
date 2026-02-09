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
use Pulsar\Extension\Forum\Domain\ReportStatus;

use function array_map;

/**
 * Admin resource definition for forum moderation reports.
 *
 * Combines thread reports and post reports into a unified view
 * with target_type discrimination. Supports bulk dismiss and action.
 */
#[Api(since: '1.0.0')]
final readonly class ForumReportResource implements DataResourceInterface
{
    #[Override]
    public function name(): string
    {
        return 'forum_reports';
    }

    #[Override]
    public function label(): string
    {
        return 'Forum Report';
    }

    #[Override]
    public function pluralLabel(): string
    {
        return 'Forum Reports';
    }

    #[Override]
    public function icon(): string
    {
        return 'flag';
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
                name: 'target_type',
                type: FieldType::Enum,
                label: 'Target Type',
                filterable: true,
                editable: false,
                enumValues: ['thread', 'post'],
            ),
            new FieldDefinition(
                name: 'target_id',
                type: FieldType::String,
                label: 'Target',
                editable: false,
            ),
            new FieldDefinition(
                name: 'reporter_id',
                type: FieldType::String,
                label: 'Reporter',
                editable: false,
            ),
            new FieldDefinition(
                name: 'reason',
                type: FieldType::Text,
                label: 'Reason',
                editable: false,
                visibleOnList: false,
            ),
            new FieldDefinition(
                name: 'status',
                type: FieldType::Enum,
                label: 'Status',
                filterable: true,
                enumValues: array_map(
                    static fn(ReportStatus $s): string => $s->value,
                    ReportStatus::cases(),
                ),
            ),
            new FieldDefinition(
                name: 'moderator_id',
                type: FieldType::String,
                label: 'Moderator',
                editable: false,
                visibleOnList: false,
            ),
            new FieldDefinition(
                name: 'moderator_note',
                type: FieldType::Text,
                label: 'Moderator Note',
                visibleOnList: false,
            ),
            new FieldDefinition(
                name: 'reviewed_at',
                type: FieldType::DateTime,
                label: 'Reviewed At',
                editable: false,
                visibleOnList: false,
            ),
            new FieldDefinition(
                name: 'created_at',
                type: FieldType::DateTime,
                label: 'Reported',
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
            ResourceOperation::Update,
        ];
    }

    #[Override]
    public function bulkActions(): array
    {
        return [
            new BulkAction(name: 'dismiss', label: 'Dismiss', icon: 'x-circle'),
            new BulkAction(name: 'action', label: 'Take Action', icon: 'alert-triangle'),
        ];
    }

    #[Override]
    public function exportableFields(): array
    {
        return [
            'id', 'target_type', 'target_id', 'reporter_id', 'reason', 'status',
            'moderator_id', 'moderator_note', 'reviewed_at', 'created_at',
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
