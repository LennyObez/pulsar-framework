<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Audit\NullAuditLogger;
use Pulsar\Config\ConfigManager;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\Wiring\Contract\DescribesWiring;
use Pulsar\Core\Wiring\Contract\WiringContract;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\Incident\InMemoryIncidentReporter;
use Pulsar\Security\ThreatDetection\ApiAbuseDetector;
use Pulsar\Security\ThreatDetection\BruteForceDetector;
use Pulsar\Security\ThreatDetection\CredentialStuffingDetector;
use Pulsar\Security\ThreatDetection\GeoAnomalyDetector;
use Pulsar\Security\ThreatDetection\HoneypotConfig;
use Pulsar\Security\ThreatDetection\HoneypotMiddleware;
use Pulsar\Security\ThreatDetection\InjectionAttemptDetector;
use Pulsar\Security\ThreatDetection\ThreatDetectionConfig;
use Pulsar\Security\ThreatDetection\ThreatDetectionEngine;
use Pulsar\Security\ThreatDetection\ThreatDetectionMiddleware;
use Pulsar\Security\ThreatDetection\ThreatDetectorInterface;
use Pulsar\Security\ZeroTrust\Signal\GeoLocationResolverInterface;

use function is_array;
use function is_file;

use const DIRECTORY_SEPARATOR;

/**
 * Activates request-time threat detection when config/security.php enables it.
 *
 * Composes the available detectors (brute-force, credential-stuffing,
 * API-abuse, injection; plus geo-anomaly when a {@see GeoLocationResolverInterface}
 * is bound) into a {@see ThreatDetectionEngine} and pipes the
 * {@see ThreatDetectionMiddleware} globally so it blocks or challenges on the
 * highest-severity hit and records a compliance-grade incident.
 *
 * Opt-in: the middleware is piped only when the `threat_detection` section is
 * present and `enabled`, so it never blocks legitimate traffic unexpectedly.
 * The incident reporter and audit logger are resolved from the container
 * (SecurityWiring binds the former), with safe in-memory/null defaults.
 */
#[Internal]
final readonly class ThreatDetectionWiring implements ServiceWiringInterface, DescribesWiring
{
    public function describeWiring(): WiringContract
    {
        return new WiringContract(
            component: 'threat-detection',
            configClass: ThreatDetectionConfig::class,
            configFile: 'security.php',
            provides: [
                ThreatDetectionEngine::class,
                ThreatDetectionMiddleware::class,
            ],
        );
    }

    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $section = $this->loadSection($configManager);

        if ($section === null) {
            return;
        }

        $config = ThreatDetectionConfig::fromArray($section);

        if (!$config->enabled) {
            return;
        }

        $incidentReporter = $container->has(IncidentReporterInterface::class)
            ? $container->get(IncidentReporterInterface::class)
            : new InMemoryIncidentReporter();
        /** @var IncidentReporterInterface $incidentReporter */

        $auditLogger = $container->has(AuditLoggerInterface::class)
            ? $container->get(AuditLoggerInterface::class)
            : new NullAuditLogger();
        /** @var AuditLoggerInterface $auditLogger */

        $engine = new ThreatDetectionEngine(
            $this->buildDetectors($config, $container),
            $incidentReporter,
            $auditLogger,
            $config,
        );
        $container->instance(ThreatDetectionEngine::class, $engine);

        $threatMiddleware = new ThreatDetectionMiddleware($engine);
        $container->instance(ThreatDetectionMiddleware::class, $threatMiddleware);

        // Honeypot paths (@api, config: threat_detection.honeypot). Fake
        // attacker-only routes (/wp-login.php, /.env, ...) with zero
        // false-positive risk. Piped BEFORE the detection engine: a honeypot
        // hit is deterministic and cheap (path match), so it must answer and
        // be audited before any scoring or challenge can preempt it.
        /** @var array{enabled?: bool|int|string, paths?: list<string>, block_ip?: bool, response_action?: string|null}|null $honeypotSection */
        $honeypotSection = is_array($section['honeypot'] ?? null) ? $section['honeypot'] : null;

        if ($honeypotSection !== null && ($honeypotSection['enabled'] ?? false) === true) {
            $honeypot = new HoneypotMiddleware(HoneypotConfig::fromArray($honeypotSection), $auditLogger);
            $container->instance(HoneypotMiddleware::class, $honeypot);
            $middleware->pipe($honeypot);
        }

        $middleware->pipe($threatMiddleware);
    }

    /**
     * @return list<ThreatDetectorInterface>
     */
    private function buildDetectors(ThreatDetectionConfig $config, ContainerInterface $container): array
    {
        $detectors = [
            new BruteForceDetector($config->bruteForceThreshold, $config->bruteForceWindowSeconds),
            new CredentialStuffingDetector($config->stuffingThreshold, $config->stuffingWindowSeconds),
            new ApiAbuseDetector($config->apiAbuseThreshold, $config->apiAbuseWindowSeconds),
        ];

        if ($config->injectionDetectionEnabled) {
            $detectors[] = new InjectionAttemptDetector();
        }

        // Geo-anomaly detection requires an application-bound geo resolver.
        if ($container->has(GeoLocationResolverInterface::class)) {
            /** @var GeoLocationResolverInterface $geoResolver */
            $geoResolver = $container->get(GeoLocationResolverInterface::class);
            $detectors[] = new GeoAnomalyDetector($geoResolver, $config->geoTravelSpeedKmh);
        }

        return $detectors;
    }

    /**
     * Read the `threat_detection` section from config/security.php, or null when
     * absent (the feature stays dormant).
     *
     * @return array<string, mixed>|null
     */
    private function loadSection(ConfigManager $configManager): ?array
    {
        $configPath = $configManager->configPath();

        if ($configPath === null) {
            return null;
        }

        $file = $configPath . DIRECTORY_SEPARATOR . 'security.php';

        if (!is_file($file)) {
            return null;
        }

        /**
         * @psalm-suppress UnresolvableInclude
         * @var mixed $data
         */
        $data = require $file;

        if (!is_array($data) || !isset($data['threat_detection']) || !is_array($data['threat_detection'])) {
            return null;
        }

        /** @var array<string, mixed> $section */
        $section = $data['threat_detection'];

        return $section;
    }
}
