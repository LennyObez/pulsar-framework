<?php

declare(strict_types=1);

namespace Pulsar\Api\Security;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Exception\ApiException;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

use function get_class;
use function is_object;
use function sprintf;

/**
 * Enforces the entity auto-serialization ban.
 *
 * When a controller returns a domain entity directly instead of wrapping
 * it in an ApiResource, this guard:
 * - In dev: throws a framework error with a descriptive message
 * - In prod: returns 500 and logs an audit event with the correlation ID
 *
 * This is a Finding E invariant: entities must never bypass the resource
 * transformation layer.
 */
#[Internal]
final readonly class EntitySerializationGuard
{
    /**
     * @param list<string> $entityNamespacePatterns Namespace prefixes that indicate an entity class
     */
    public function __construct(
        private bool $debugMode,
        private array $entityNamespacePatterns,
        private ?AuditLoggerInterface $auditLogger = null,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Check if a controller return value is a banned entity.
     *
     * @param mixed $value The value returned from a controller action
     * @param string $correlationId Request correlation ID for audit logging
     *
     * @throws ApiException In debug mode, when an entity is detected
     */
    public function check(mixed $value, string $correlationId): bool
    {
        if (!is_object($value)) {
            return false;
        }

        $class = get_class($value);

        if (!$this->isEntity($class)) {
            return false;
        }

        // Log audit event
        $this->auditLogger?->log(
            event: AuditEvent::SecurityEvent,
            outcome: AuditOutcome::Denied,
            actor: 'system',
            action: 'entity_serialization_banned',
            resource: $class,
            metadata: [
                'correlation_id' => $correlationId,
                'entity_class' => $class,
            ],
        );

        $this->logger?->critical(
            sprintf(
                'Entity "%s" returned directly from controller (correlation: %s)',
                $class,
                $correlationId,
            ),
        );

        if ($this->debugMode) {
            throw ApiException::entitySerializationBanned($class, $correlationId);
        }

        return true;
    }

    /**
     * Check if a class name matches any entity namespace pattern.
     */
    private function isEntity(string $class): bool
    {
        foreach ($this->entityNamespacePatterns as $pattern) {
            if (str_starts_with($class, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
