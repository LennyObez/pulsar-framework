<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Reflection;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Grpc\Config\ReflectionConfig;
use Pulsar\Extension\Grpc\Security\GrpcSecurityEvent;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

/**
 * Controls when gRPC server reflection is allowed.
 *
 * In development: always allowed.
 * In production: disabled by default. If force-enabled via config,
 * emits a GrpcReflectionEnabled security event through the audit logger.
 */
#[Internal(reason: 'Reflection security enforcement')]
final readonly class ReflectionGuard
{
    public function __construct(
        private ReflectionConfig $config,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {}

    /**
     * Whether reflection is allowed in the current environment.
     *
     * When allowed in production (force-enabled), automatically emits
     * a GrpcReflectionEnabled security audit event.
     */
    public function isAllowed(bool $isProduction): bool
    {
        if (!$this->config->enabled) {
            return false;
        }

        if (!$isProduction) {
            return true;
        }

        if (!$this->config->allowInProduction) {
            return false;
        }

        // Reflection force-enabled in production; emit audit event
        $this->emitReflectionEnabled();

        return true;
    }

    /**
     * Emit a security event when reflection is enabled in production.
     *
     * Call this when reflection is activated in a production environment
     * to create an audit trail of this security-sensitive configuration.
     */
    public function onEnabled(AuditLoggerInterface $auditLogger): void
    {
        $auditLogger->log(
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Success,
            actor: 'system',
            action: GrpcSecurityEvent::GrpcReflectionEnabled->value,
            resource: 'grpc.reflection',
            metadata: [
                'allow_in_production' => $this->config->allowInProduction,
            ],
        );
    }

    private function emitReflectionEnabled(): void
    {
        $this->auditLogger?->log(
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Success,
            actor: 'system',
            action: GrpcSecurityEvent::GrpcReflectionEnabled->value,
            resource: 'grpc.reflection',
            metadata: [
                'allow_in_production' => $this->config->allowInProduction,
            ],
        );
    }
}
