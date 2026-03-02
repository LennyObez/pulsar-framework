<?php

declare(strict_types=1);

namespace Pulsar\Extension\Grpc\Security;

use Pulsar\Api\Api;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Grpc\Config\IdentityMappingEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

/**
 * Maps certificate SANs to service identities using a compiled mapping.
 *
 * The mapping is built from configuration at boot time: no runtime
 * interpretation or dynamic evaluation. Same SAN always resolves to the
 * same identity (deterministic).
 */
#[Api(since: '1.0.0')]
final readonly class MtlsIdentityMapper
{
    private IdentityMapping $mapping;

    /**
     * @param array<string, IdentityMappingEntry> $identityMap SAN → identity mapping from config
     */
    public function __construct(
        array $identityMap,
        private ?AuditLoggerInterface $auditLogger = null,
    ) {
        $this->mapping = new IdentityMapping($identityMap);
    }

    /**
     * Resolve a certificate SAN to a service identity.
     *
     * Returns null when the SAN is not present in the compiled identity map.
     * Emits an UnknownClientCertificate audit event when a SAN is not found.
     */
    public function resolve(string $san): ?ServiceIdentity
    {
        $identity = $this->mapping->lookup($san);

        if ($identity === null) {
            $this->auditLogger?->log(
                event: AuditEvent::SecurityEvent,
                outcome: AuditOutcome::Failure,
                actor: $san,
                action: GrpcSecurityEvent::UnknownClientCertificate->value,
                resource: 'grpc.mtls',
                metadata: [
                    'san' => $san,
                ],
            );
        }

        return $identity;
    }

    /**
     * Get the underlying compiled identity mapping.
     */
    public function identityMapping(): IdentityMapping
    {
        return $this->mapping;
    }
}
