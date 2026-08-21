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
 * `AssetWiring` is absent because it is conditional on the assets being present
 * on disk rather than on configuration, so the kernel appends it after this
 * list. It is no longer conditional on the route cache: a strict-cached boot
 * runs it like any other wiring and {@see \Pulsar\Routing\Router::add()} drops
 * the re-registration of a route the cached table already holds.
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
            // After AuthWiring (its default hook delegates to the Gate) and after
            // TenancyWiring (bound models resolve inside the tenant scope), and
            // before anything that could pipe route-aware middleware of its own.
            // Nothing later in the list depends on it: the pipeline it composes
            // is assembled at the END of boot, once extensions have registered a
            // ModelResolverPort and the project route files have declared their
            // explicit bindings.
            new ModelBindingWiring(),
            new SagaWiring(),
            new WorkflowWiring(),
            new FeatureFlagWiring(),
            new SchedulerWiring(),
            // Immediately after the scheduler, because it registers a job into the
            // registry SchedulerWiring builds — and after SecurityWiring, which is
            // where the purge orchestrator it schedules comes from. Retention was
            // declared in config/data_protection.php and executed by nothing until
            // this entry existed.
            new DataRetentionWiring(),
            new ResilienceWiring(),
            new QueueWiring(),
            new CacheWiring(),
            new FailoverWiring(),
            new AntiSpamWiring(),
            // Before threat detection, because a request the firewall refuses should
            // not be scored, logged and challenged first.
            new WafWiring(),
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
            // Last, and the position is load-bearing in one direction only. What it
            // BINDS is inert AND unbuilt — a lazy catalog of control declarations,
            // which carry no status, and an assessment function that holds no
            // evidence — so the catalog could sit anywhere; it builds nothing until
            // a report is asked for. What it must never do is GATHER, and the
            // gatherer is therefore bound as a lazy closure resolved when a report
            // is asked for. Gathering resolves TokenStoreInterface: run it during
            // wiring and it resolves before DatabaseWiring (seventeenth) has built a
            // connection, the container caches that answer for the process, and the
            // report would truthfully record an InMemoryTokenStore the running
            // application does not use — ADR-0041's ordering bug, re-created by the
            // thing built to detect it. Registering here also puts it after
            // SecurityPostureWiring and ComplianceVerificationWiring, whose report
            // and runtime checks the gathered evidence reads, so the dependency is
            // visible in this list rather than only in a docblock.
            new ComplianceCatalogWiring(),
        ];
    }
}
