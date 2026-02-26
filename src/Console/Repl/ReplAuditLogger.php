<?php

declare(strict_types=1);

namespace Pulsar\Console\Repl;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function count;
use function gettype;
use function is_array;
use function is_object;
use function is_scalar;
use function mb_substr;
use function sprintf;

/**
 * Audit logger adapter for REPL sessions.
 *
 * Wraps the framework audit logger with REPL-specific event types and
 * result summarization (never logs full output dumps).
 */
#[Internal]
final readonly class ReplAuditLogger
{
    public function __construct(
        private AuditLoggerInterface $auditLogger,
        private string $sessionId,
    ) {}

    /**
     * Log the start of a REPL session.
     */
    public function logSessionStart(string $actor, EnvironmentMode $mode, bool $safeMode): void
    {
        $this->auditLogger->log(
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: $actor,
            action: 'repl.session_start',
            resource: 'repl',
            metadata: [
                'session_id' => $this->sessionId,
                'environment' => $mode->value,
                'safe_mode' => $safeMode,
            ],
        );
    }

    /**
     * Log the end of a REPL session.
     */
    public function logSessionEnd(string $actor, ?int $commandCount, float $durationSeconds): void
    {
        $metadata = [
            'session_id' => $this->sessionId,
            'duration_seconds' => $durationSeconds,
        ];

        if ($commandCount !== null) {
            $metadata['command_count'] = $commandCount;
        }

        $this->auditLogger->log(
            event: AuditEvent::SystemEvent,
            outcome: AuditOutcome::Success,
            actor: $actor,
            action: 'repl.session_end',
            resource: 'repl',
            metadata: $metadata,
        );
    }

    /**
     * Log an individual REPL command execution.
     */
    public function logCommand(string $actor, string $command, ?string $resultSummary = null): void
    {
        $metadata = [
            'session_id' => $this->sessionId,
            'command' => $command,
        ];

        if ($resultSummary !== null) {
            $metadata['result_summary'] = $resultSummary;
        }

        $this->auditLogger->log(
            event: AuditEvent::DataAccess,
            outcome: AuditOutcome::Success,
            actor: $actor,
            action: 'repl.command',
            resource: 'repl',
            metadata: $metadata,
        );
    }

    /**
     * Log a production override acknowledgement.
     */
    public function logProductionOverride(string $actor): void
    {
        $this->auditLogger->log(
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Success,
            actor: $actor,
            action: 'repl.production_override',
            resource: 'repl',
            metadata: [
                'session_id' => $this->sessionId,
            ],
        );
    }

    /**
     * Summarize a result value for audit logging.
     *
     * Returns a type description + truncated preview, never a full dump.
     */
    public static function summarizeResult(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_scalar($value)) {
            $preview = (string) $value;

            return sprintf('%s(%s)', gettype($value), mb_substr($preview, 0, 200));
        }

        if (is_object($value)) {
            return sprintf('object(%s)', $value::class);
        }

        if (is_array($value)) {
            return sprintf('array(%d)', count($value));
        }

        return gettype($value);
    }
}
