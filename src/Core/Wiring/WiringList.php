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
            // Before RoutingWiring, precisely so its prepend() lands *behind* the
            // canonicalization middleware that RoutingWiring prepends next. Two
            // reasons, and they agree: RoutingWiring documents CanonicalPathMiddleware
            // as structurally outermost, and hints spent on a request that is about to
            // be 301'd are hints the browser throws away.
            new EarlyHintsWiring(),
            new RoutingWiring(),
            new I18nWiring(),
            new LoggingWiring(),
            new TracingWiring(),
            // Resolves the compliance profile and tightens security-relevant config
            // (e.g. session idle timeout) BEFORE SecurityWiring/AuthWiring build their
            // services from it; runs after LoggingWiring so a logger is bound for the
            // tighten-warning path.
            new ComplianceWiring(),
            new SecurityWiring(),
            new ComplianceLoggingWiring(),
            new MetricsWiring(),
            new RequestContextWiring(),
            new EventWiring(),
            new ZeroTrustWiring(),
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
            // After it, for the same reason: compliance verification reports the
            // profile requirements that configuration CANNOT tighten (audit
            // tamper-evidence, encryption actually active, data retention, breach
            // and consent), judging the fully wired container rather than config.
            new ComplianceVerificationWiring(),
        ];
    }
}
