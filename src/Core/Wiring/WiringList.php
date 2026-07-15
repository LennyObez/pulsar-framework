<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Api\OpenApi\OpenApiWiring;

/**
 * Canonical, ordered list of the framework's always-on service wirings.
 *
 * Single source of truth shared by {@see \Pulsar\Core\Kernel} (which runs them
 * at boot) and the wiring-contract integration harness (which verifies their
 * declared contracts). Order is significant — a wiring may depend on a binding
 * an earlier one provides — so this list IS the boot order.
 *
 * `AssetWiring` is deliberately absent: the kernel appends it conditionally
 * (only when not serving from a strict route cache), so it is not "always on".
 */
#[Internal]
final readonly class WiringList
{
    /**
     * @return list<ServiceWiringInterface>
     */
    public static function default(): array
    {
        return [
            new ConfigWiring(),
            // Early: pipes the canonicalization middleware OUTERMOST (before the
            // locale-prefix strip) so it sees the full, un-rewritten request path.
            new RoutingWiring(),
            new I18nWiring(),
            new LoggingWiring(),
            new TracingWiring(),
            new SecurityWiring(),
            new ComplianceLoggingWiring(),
            new MetricsWiring(),
            new RequestContextWiring(),
            new EventWiring(),
            new ErrorTrackingWiring(),
            new ExceptionHandlerWiring(),
            new AuthWiring(),
            new DatabaseWiring(),
            new TenancyWiring(),
            new SagaWiring(),
            new WorkflowWiring(),
            new FeatureFlagWiring(),
            new SchedulerWiring(),
            new ResilienceWiring(),
            new QueueWiring(),
            new CacheWiring(),
            new FailoverWiring(),
            new AntiSpamWiring(),
            new ThreatDetectionWiring(),
            new MailWiring(),
            new NotificationWiring(),
            new BroadcastWiring(),
            new StorageWiring(),
            new CloudWiring(),
            new ServiceDiscoveryWiring(),
            new ApiWiring(),
            new OpenApiWiring(),
            new SupervisorWiring(),
            new IntegrityWiring(),
            new DeployWiring(),
            new RuntimeWiring(),
            new DiagnosticsWiring(),
            new IntrospectionWiring(),
            new ViewWiring(),
            new DocumentationWiring(),
            new EdgeWiring(),
            new ProfilerWiring(),
            // Last: the security-posture preflight evaluates the fully wired
            // container (so inert security features are detected) and, when
            // enforcement is enabled in production, aborts boot.
            new SecurityPostureWiring(),
        ];
    }
}
