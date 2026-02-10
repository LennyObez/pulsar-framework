<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Features\Encryption;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function sprintf;

/**
 * Audit logger for encryption operations on ORM columns.
 */
#[Internal]
final class EncryptionAuditLogger
{
    public function __construct(
        private readonly ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Log a column encryption event.
     */
    public function logEncrypt(string $entity, string $column, string $actor): void
    {
        $this->auditLogger?->log(
            AuditEvent::DataAccess,
            AuditOutcome::Success,
            $actor,
            'orm.column.encrypt',
            sprintf('%s.%s', $entity, $column),
        );
    }

    /**
     * Log a column decryption event.
     */
    public function logDecrypt(string $entity, string $column, string $actor): void
    {
        $this->auditLogger?->log(
            AuditEvent::DataAccess,
            AuditOutcome::Success,
            $actor,
            'orm.column.decrypt',
            sprintf('%s.%s', $entity, $column),
        );
    }

    /**
     * Log a blind index lookup event.
     */
    public function logBlindIndexLookup(string $entity, string $column, string $actor): void
    {
        $this->auditLogger?->log(
            AuditEvent::DataAccess,
            AuditOutcome::Success,
            $actor,
            'orm.column.blind_index_lookup',
            sprintf('%s.%s', $entity, $column),
        );
    }
}
