<?php

declare(strict_types=1);

namespace Pulsar\Audit;

use JsonException;
use Pulsar\Api\Api;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Random\RandomException;
use SodiumException;

/**
 * Context-aware audit logging contract.
 *
 * Implementations auto-enrich entries with correlation/causation IDs
 * from the current RequestContext when available.
 */
#[Api(since: '1.0.0')]
interface AuditLoggerInterface
{
    /**
     * Log an audit event.
     *
     * When $actor is null, the implementation auto-fills from RequestContext.actor
     * if available.
     *
     * @param array<string, mixed> $metadata
     *
     * @throws RandomException
     * @throws JsonException
     * @throws SodiumException
     */
    public function log(
        AuditEvent $event,
        AuditOutcome $outcome,
        ?string $actor,
        string $action,
        string $resource = '',
        array $metadata = [],
    ): AuditEntry;
}
