<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Admin\Domain\BulkAction;
use Pulsar\Extension\Admin\Domain\FieldDefinition;
use Pulsar\Extension\Admin\Domain\ResourceOperation;

/**
 * Defines an admin-manageable data resource.
 *
 * Each resource declares its fields, supported operations, bulk actions,
 * export policy, and audit configuration.
 */
#[Api(since: '1.0.0')]
interface DataResourceInterface
{
    /**
     * Unique resource name (e.g., "users", "orders").
     */
    public function name(): string;

    /**
     * Human-readable label for the resource.
     */
    public function label(): string;

    /**
     * Plural label for collection views.
     */
    public function pluralLabel(): string;

    /**
     * Icon identifier for the admin sidebar.
     */
    public function icon(): string;

    /**
     * Field definitions for this resource.
     *
     * @return list<FieldDefinition>
     */
    public function fields(): array;

    /**
     * Operations supported by this resource.
     *
     * @return list<ResourceOperation>
     */
    public function operations(): array;

    /**
     * Bulk actions available for this resource.
     *
     * @return list<BulkAction>
     */
    public function bulkActions(): array;

    /**
     * Field names eligible for export (allowlist).
     *
     * @return list<string>
     */
    public function exportableFields(): array;

    /**
     * Whether read operations on this resource should be audit-logged.
     */
    public function auditReads(): bool;

    /**
     * Primary key field name.
     */
    public function primaryKey(): string;

    /**
     * Default sort field.
     */
    public function defaultSortField(): string;

    /**
     * Default sort direction.
     */
    public function defaultSortDirection(): string;
}
