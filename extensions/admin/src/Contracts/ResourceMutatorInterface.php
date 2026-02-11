<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Contracts;

use Pulsar\Api\Api;
use Pulsar\Audit\MutationContext;
use Pulsar\Extension\Admin\Domain\ActionResult;

/**
 * Write-side mutation interface for admin resources.
 *
 * All writes require a MutationContext to ensure audit traceability.
 */
#[Api(since: '1.0.0')]
interface ResourceMutatorInterface
{
    /**
     * Create a new record.
     *
     * @param array<string, mixed> $data
     */
    public function create(
        DataResourceInterface $resource,
        array $data,
        MutationContext $context,
    ): ActionResult;

    /**
     * Update an existing record.
     *
     * @param array<string, mixed> $data
     */
    public function update(
        DataResourceInterface $resource,
        string $id,
        array $data,
        MutationContext $context,
    ): ActionResult;

    /**
     * Delete a record.
     */
    public function delete(
        DataResourceInterface $resource,
        string $id,
        MutationContext $context,
    ): ActionResult;

    /**
     * Execute a bulk action on multiple records.
     *
     * @param list<string> $ids
     * @param array<string, mixed> $parameters
     */
    public function bulkAction(
        DataResourceInterface $resource,
        string $action,
        array $ids,
        array $parameters,
        MutationContext $context,
    ): ActionResult;
}
