<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\SecurityConfig;
use Pulsar\Config\ZeroTrustConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Event\EventDispatcherInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Crypto\KeyRingInterface;
use Pulsar\Security\ZeroTrust\ContinuousVerification\ContinuousVerificationInterface;
use Pulsar\Security\ZeroTrust\ContinuousVerification\Internal\ContinuousVerificationManager;
use Pulsar\Security\ZeroTrust\DeviceIdentity\DeviceRegistryInterface;
use Pulsar\Security\ZeroTrust\DeviceIdentity\Internal\InMemoryDeviceRegistry;
use Pulsar\Security\ZeroTrust\Middleware\ZeroTrustMiddleware;
use Pulsar\Security\ZeroTrust\Policy\Internal\PolicyEngine;
use Pulsar\Security\ZeroTrust\Policy\PolicyEngineInterface;
use Pulsar\Security\ZeroTrust\Privacy\Internal\HmacPseudonymizer;
use Pulsar\Security\ZeroTrust\Privacy\PseudonymizerInterface;
use Pulsar\Security\ZeroTrust\Signal\BehaviorBaselineInterface;
use Pulsar\Security\ZeroTrust\Signal\GeoLocationResolverInterface;
use Pulsar\Security\ZeroTrust\Signal\Internal\BehaviorSignalProvider;
use Pulsar\Security\ZeroTrust\Signal\Internal\DeviceSignalProvider;
use Pulsar\Security\ZeroTrust\Signal\Internal\LocationSignalProvider;
use Pulsar\Security\ZeroTrust\Signal\Internal\NetworkSignalProvider;
use Pulsar\Security\ZeroTrust\Signal\Internal\TimeSignalProvider;
use Pulsar\Security\ZeroTrust\Signal\NetworkIntelligenceInterface;
use Pulsar\Security\ZeroTrust\Signal\SignalProviderInterface;
use Pulsar\Security\ZeroTrust\StepUp\Internal\StepUpManager;

/**
 * Wires the zero-trust access-control subsystem (policy engine, signal
 * providers, step-up, continuous verification) and exposes the enforcement
 * middleware as a `zerotrust` alias.
 *
 * Opt-in: nothing is wired unless `security.zero_trust.enabled` is true, so
 * existing deployments are unaffected. The policy engine is **deny-by-default**,
 * so an enabled zero-trust route with no matching rule denies the request —
 * define `zero_trust.rules` and apply the `zerotrust` middleware only to the
 * routes it should guard.
 *
 * Runs after SecurityWiring and EventWiring: it needs the event dispatcher and
 * audit logger (required) and the crypto key ring (for the signal-retention
 * pseudonymizer, optional).
 */
#[Internal]
final readonly class ZeroTrustWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();
        $config = $repository->has(SecurityConfig::class)
            ? $repository->get(SecurityConfig::class)->zeroTrust
            : new ZeroTrustConfig();

        $container->instance(ZeroTrustConfig::class, $config);

        if (!$config->enabled) {
            return;
        }

        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;

        /** @var LoggerInterface|null $logger */

        // The enforcement middleware and policy engine both require the event
        // dispatcher and audit logger; without them zero-trust cannot record or
        // announce its decisions, so it stays unwired rather than run blind.
        if (!$container->has(EventDispatcherInterface::class) || !$container->has(AuditLoggerInterface::class)) {
            $logger?->warning(
                'Zero-trust is enabled but the event dispatcher or audit logger is unavailable; middleware not wired.',
                ['component' => 'security.zero_trust'],
            );

            return;
        }

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get(EventDispatcherInterface::class);
        /** @var AuditLoggerInterface $auditLogger */
        $auditLogger = $container->get(AuditLoggerInterface::class);

        $signalProviders = $this->buildSignalProviders($container);

        $policyEngine = new PolicyEngine($config->rules, $dispatcher, $auditLogger);
        $container->instance(PolicyEngineInterface::class, $policyEngine);

        // Signal-retention pseudonymizer (privacy): keyed from the crypto key
        // ring when one is available.
        if ($container->has(KeyRingInterface::class)) {
            /** @var KeyRingInterface $keyRing */
            $keyRing = $container->get(KeyRingInterface::class);
            $container->instance(PseudonymizerInterface::class, new HmacPseudonymizer($keyRing));
        }

        $container->instance(
            ContinuousVerificationInterface::class,
            new ContinuousVerificationManager(
                $signalProviders,
                $policyEngine,
                $config->continuousVerificationIntervalSeconds,
            ),
        );

        $stepUpManager = new StepUpManager($dispatcher);

        $zeroTrustMiddleware = new ZeroTrustMiddleware(
            $config,
            $signalProviders,
            $policyEngine,
            $dispatcher,
            $auditLogger,
            $stepUpManager,
        );
        $container->instance(ZeroTrustMiddleware::class, $zeroTrustMiddleware);

        // Opt-in per route/group; never piped globally (deny-by-default would
        // otherwise reject every route the moment zero-trust is enabled).
        $middlewareRegistry->alias('zerotrust', ZeroTrustMiddleware::class);
    }

    /**
     * The built-in signal providers, each constructed with its optional
     * collaborator when one is bound (a device registry, geo resolver, network
     * intelligence, or behaviour baseline), else its safe default.
     *
     * @return list<SignalProviderInterface>
     */
    private function buildSignalProviders(ContainerInterface $container): array
    {
        // The device registry needs the crypto key ring (it HMACs device ids).
        // Prefer a bound registry; else build the in-memory default when a key
        // ring exists; otherwise leave it null so the device signal degrades
        // gracefully rather than the whole subsystem failing to wire.
        $deviceRegistry = null;

        if ($container->has(DeviceRegistryInterface::class)) {
            /** @var DeviceRegistryInterface $deviceRegistry */
            $deviceRegistry = $container->get(DeviceRegistryInterface::class);
        } elseif ($container->has(KeyRingInterface::class)) {
            /** @var KeyRingInterface $keyRing */
            $keyRing = $container->get(KeyRingInterface::class);
            $deviceRegistry = new InMemoryDeviceRegistry($keyRing);
            $container->instance(DeviceRegistryInterface::class, $deviceRegistry);
        }

        $geoResolver = $container->has(GeoLocationResolverInterface::class)
            ? $container->get(GeoLocationResolverInterface::class)
            : null;
        /** @var GeoLocationResolverInterface|null $geoResolver */

        $networkIntelligence = $container->has(NetworkIntelligenceInterface::class)
            ? $container->get(NetworkIntelligenceInterface::class)
            : null;
        /** @var NetworkIntelligenceInterface|null $networkIntelligence */

        $behaviorBaseline = $container->has(BehaviorBaselineInterface::class)
            ? $container->get(BehaviorBaselineInterface::class)
            : null;
        /** @var BehaviorBaselineInterface|null $behaviorBaseline */

        return [
            new DeviceSignalProvider($deviceRegistry),
            new LocationSignalProvider($geoResolver),
            new NetworkSignalProvider($networkIntelligence),
            new TimeSignalProvider(),
            new BehaviorSignalProvider($behaviorBaseline),
        ];
    }
}
