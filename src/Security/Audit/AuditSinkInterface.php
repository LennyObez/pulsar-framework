<?php

declare(strict_types=1);

namespace Pulsar\Security\Audit;

/**
 * Contract for audit log persistence backends.
 *
 * Implementations must guarantee that entries are durably written
 * before returning from write().
 */
interface AuditSinkInterface
{
    /**
     * Write an audit entry to the backing store.
     *
     * @throws \Pulsar\Security\Exception\SecurityException If the write fails
     */
    public function write(AuditEntry $entry): void;
}
