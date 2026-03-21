<?php

declare(strict_types=1);

namespace Pulsar\Audit;

use JsonException;
use Pulsar\Api\Api;
use Pulsar\Audit\Exception\AuditActorMissingException;
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
 * @api
 */
#[Api(since: '1.0.0')]
interface AuditLoggerInterface
{
    /**
     * Log an audit event.
     *
     * `$actor` may be:
     * - an `AuditActor` value object (preferred — explicit kind classification),
     * - a non-empty string identifier (legacy form, accepted for migration),
     * - `null` only if the active `RequestContext` already carries an actor;
     *   otherwise an `AuditActorMissingException` is raised. There is no
     *   silent `'system'` fallback (F25.10).
     *
     * @param array<string, mixed> $metadata
     *
     * @throws AuditActorMissingException when no actor can be resolved.
     * @throws RandomException
     * @throws JsonException
     * @throws SodiumException
     */
    public function log(
        AuditEvent $event,
        AuditOutcome $outcome,
        AuditActor|string|null $actor,
        string $action,
        string $resource = '',
        array $metadata = [],
    ): AuditEntry;
}
