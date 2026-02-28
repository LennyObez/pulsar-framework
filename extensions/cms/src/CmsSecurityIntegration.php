<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Middleware\CompressionMiddleware;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\RateLimit\AdaptiveRateLimiter;
use Pulsar\Http\RateLimit\EndpointRateLimitPolicy;
use Pulsar\Http\RateLimit\SlidingWindowRateLimiter;
use Pulsar\Security\Dlp\DlpConfig;
use Pulsar\Security\Dlp\DlpScanMiddleware;
use Pulsar\Security\Dlp\SensitivePatternRegistry;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\JustifiedAccess\InMemoryJustificationStore;
use Pulsar\Security\JustifiedAccess\JustificationStoreInterface;
use Pulsar\Security\JustifiedAccess\JustifiedAccessConfig;
use Pulsar\Security\JustifiedAccess\JustifiedAccessMiddleware;
use Pulsar\Security\Session\HijackDetector;
use Pulsar\Security\Session\HijackPolicy;
use Pulsar\Security\ThreatDetection\BotDetectionMiddleware;
use Pulsar\Security\ThreatDetection\BotDetector;
use Pulsar\Security\ThreatDetection\BruteForceDetector;
use Pulsar\Security\ThreatDetection\CredentialStuffingDetector;
use Pulsar\Security\ThreatDetection\InjectionAttemptDetector;
use Pulsar\Security\ThreatDetection\ThreatDetectionConfig;
use Pulsar\Security\ThreatDetection\ThreatDetectionEngine;
use Pulsar\Security\ThreatDetection\ThreatDetectionMiddleware;
use Pulsar\Security\Waf\OwaspCoreRuleSet;
use Pulsar\Security\Waf\ResponseFactoryInterface as WafResponseFactoryInterface;
use Pulsar\Security\Waf\WafConfig;
use Pulsar\Security\Waf\WafEngine;
use Pulsar\Security\Waf\WafMiddleware;

/**
 * Wires all framework security, performance, and detection middleware into the CMS.
 *
 * This provider creates CMS-specific instances of:
 * - WAF (OWASP CRS) on all public and API routes
 * - DLP scanning on API response bodies (PII leakage prevention)
 * - Threat detection on authentication and form submission routes
 * - Bot detection on comment and form submission endpoints
 * - Session hijack detection for admin sessions
 * - Justified access for admin customer data routes
 * - Adaptive rate limiting with per-endpoint policies
 * - Response compression on public content routes
 *
 * Middleware groups registered:
 * - cms.security.public : WAF + compression + bot detection
 * - cms.security.api    : WAF + DLP + compression
 * - cms.security.admin  : WAF + hijack detection + justified access
 * - cms.security.forms  : WAF + threat detection + bot detection
 * - cms.security.auth   : WAF + threat detection + adaptive rate limiting
 *
 * @psalm-api Instantiated by name from the CMS extension boot routine to
 *            register the CMS security middleware groups.
 */
#[Internal(reason: 'CMS integration wiring; not part of the public API')]
final readonly class CmsSecurityIntegration
{
    public function register(
        ContainerInterface $container,
        MiddlewareRegistry $middlewareRegistry,
    ): void {
        /** @var LoggerInterface $logger */
        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : new NullLogger();

        /** @var AuditLoggerInterface|null $auditLogger */
        $auditLogger = $container->has(AuditLoggerInterface::class)
            ? $container->get(AuditLoggerInterface::class)
            : null;

        // === WAF Engine (OWASP CRS) ===
        $wafMiddleware = $this->buildWafMiddleware($logger);
        $container->instance(WafMiddleware::class, $wafMiddleware);

        // === DLP Scan Middleware ===
        $dlpMiddleware = null;
        if ($auditLogger !== null) {
            $dlpMiddleware = $this->buildDlpMiddleware($auditLogger);
            $container->instance(DlpScanMiddleware::class, $dlpMiddleware);
        }

        // === Threat Detection Engine ===
        $threatMiddleware = null;
        if ($auditLogger !== null && $container->has(IncidentReporterInterface::class)) {
            /** @var IncidentReporterInterface $incidentReporter */
            $incidentReporter = $container->get(IncidentReporterInterface::class);
            $threatMiddleware = $this->buildThreatDetectionMiddleware($incidentReporter, $auditLogger);
            $container->instance(ThreatDetectionMiddleware::class, $threatMiddleware);
        }

        // === Bot Detection ===
        $botMiddleware = $this->buildBotDetectionMiddleware($logger);
        $container->instance(BotDetectionMiddleware::class, $botMiddleware);

        // === Session Hijack Detector ===
        $hijackDetector = $this->buildHijackDetector($logger, $auditLogger);
        $container->instance(HijackDetector::class, $hijackDetector);

        // === Justified Access ===
        $justifiedAccessMiddleware = null;
        if ($auditLogger !== null) {
            $justifiedAccessMiddleware = $this->buildJustifiedAccessMiddleware($container, $auditLogger);
            $container->instance(JustifiedAccessMiddleware::class, $justifiedAccessMiddleware);
        }

        // === Compression ===
        $compressionMiddleware = new CompressionMiddleware();
        $container->instance(CompressionMiddleware::class, $compressionMiddleware);

        // === Adaptive Rate Limiting ===
        $adaptiveRateLimiter = $this->buildAdaptiveRateLimiter();
        $container->instance(AdaptiveRateLimiter::class, $adaptiveRateLimiter);

        // === Register Middleware Groups ===
        $this->registerMiddlewareGroups(
            $middlewareRegistry,
            $wafMiddleware,
            $dlpMiddleware,
            $threatMiddleware,
            $botMiddleware,
            $justifiedAccessMiddleware,
            $compressionMiddleware,
        );
    }

    private function buildWafMiddleware(LoggerInterface $logger): WafMiddleware
    {
        $wafConfig = new WafConfig(enabled: true, paranoiaLevel: 1);
        $wafEngine = new WafEngine($wafConfig);
        $wafEngine->loadRules(OwaspCoreRuleSet::rules());

        $responseFactory = new class implements WafResponseFactoryInterface {
            public function createResponse(int $statusCode, string $reasonPhrase = ''): ResponseInterface
            {
                return Response::text($reasonPhrase !== '' ? $reasonPhrase : 'Forbidden', $statusCode);
            }
        };

        return new WafMiddleware($wafEngine, $logger, $responseFactory);
    }

    private function buildDlpMiddleware(AuditLoggerInterface $auditLogger): DlpScanMiddleware
    {
        $dlpConfig = new DlpConfig(
            enabled: true,
            scanResponses: true,
        );
        $patternRegistry = new SensitivePatternRegistry($dlpConfig);

        return new DlpScanMiddleware($patternRegistry, $dlpConfig, $auditLogger);
    }

    private function buildThreatDetectionMiddleware(
        IncidentReporterInterface $incidentReporter,
        AuditLoggerInterface $auditLogger,
    ): ThreatDetectionMiddleware {
        $threatConfig = new ThreatDetectionConfig(
            enabled: true,
            bruteForceThreshold: 5,
            bruteForceWindowSeconds: 600,
            stuffingThreshold: 10,
            stuffingWindowSeconds: 300,
        );

        $detectors = [
            new BruteForceDetector(
                $threatConfig->bruteForceThreshold,
                $threatConfig->bruteForceWindowSeconds,
            ),
            new CredentialStuffingDetector(
                $threatConfig->stuffingThreshold,
                $threatConfig->stuffingWindowSeconds,
            ),
            new InjectionAttemptDetector(),
        ];

        $engine = new ThreatDetectionEngine(
            $detectors,
            $incidentReporter,
            $auditLogger,
            $threatConfig,
        );

        return new ThreatDetectionMiddleware($engine);
    }

    private function buildBotDetectionMiddleware(LoggerInterface $logger): BotDetectionMiddleware
    {
        $botDetector = new BotDetector(threshold: 70);

        return new BotDetectionMiddleware(
            detector: $botDetector,
            logger: $logger,
            blockThreshold: 80,
        );
    }

    private function buildHijackDetector(
        LoggerInterface $logger,
        ?AuditLoggerInterface $auditLogger,
    ): HijackDetector {
        $auditLoggerInstance = $auditLogger instanceof \Pulsar\Security\Audit\AuditLogger
            ? $auditLogger
            : null;

        return new HijackDetector(
            logger: $logger,
            ipChangePolicy: HijackPolicy::Challenge,
            auditLogger: $auditLoggerInstance,
        );
    }

    private function buildJustifiedAccessMiddleware(
        ContainerInterface $container,
        AuditLoggerInterface $auditLogger,
    ): JustifiedAccessMiddleware {
        $config = new JustifiedAccessConfig(
            enabled: true,
            minJustificationLength: 10,
        );

        /** @var JustificationStoreInterface $store */
        $store = $container->has(JustificationStoreInterface::class)
            ? $container->get(JustificationStoreInterface::class)
            : new InMemoryJustificationStore();

        if (!$container->has(JustificationStoreInterface::class)) {
            $container->instance(JustificationStoreInterface::class, $store);
        }

        return new JustifiedAccessMiddleware($config, $store, $auditLogger);
    }

    private function buildAdaptiveRateLimiter(): AdaptiveRateLimiter
    {
        $baseLimiter = new SlidingWindowRateLimiter(maxAttempts: 200, windowSeconds: 60);
        $adaptive = new AdaptiveRateLimiter($baseLimiter, baseLimit: 200);

        // CMS-specific endpoint policies (stricter for sensitive endpoints)
        $adaptive->registerEndpointPolicy(new EndpointRateLimitPolicy(
            pattern: '/admin/cms/2fa',
            maxAttempts: 5,
            windowSeconds: 600,
        ));
        $adaptive->registerEndpointPolicy(new EndpointRateLimitPolicy(
            pattern: '/forms/submit',
            maxAttempts: 10,
            windowSeconds: 60,
        ));
        $adaptive->registerEndpointPolicy(new EndpointRateLimitPolicy(
            pattern: '/api/cms/comments',
            maxAttempts: 20,
            windowSeconds: 60,
        ));
        $adaptive->registerEndpointPolicy(new EndpointRateLimitPolicy(
            pattern: '/api/cms/newsletter/subscribe',
            maxAttempts: 5,
            windowSeconds: 300,
        ));
        $adaptive->registerEndpointPolicy(new EndpointRateLimitPolicy(
            pattern: '/api/v1/content',
            maxAttempts: 100,
            windowSeconds: 60,
        ));
        $adaptive->registerEndpointPolicy(new EndpointRateLimitPolicy(
            pattern: '/api/v1/ai',
            maxAttempts: 10,
            windowSeconds: 60,
        ));
        $adaptive->registerEndpointPolicy(new EndpointRateLimitPolicy(
            pattern: '/webhooks',
            maxAttempts: 50,
            windowSeconds: 60,
        ));

        return $adaptive;
    }

    private function registerMiddlewareGroups(
        MiddlewareRegistry $registry,
        WafMiddleware $waf,
        ?DlpScanMiddleware $dlp,
        ?ThreatDetectionMiddleware $threat,
        BotDetectionMiddleware $bot,
        ?JustifiedAccessMiddleware $justifiedAccess,
        CompressionMiddleware $compression,
    ): void {
        // Public routes: WAF + bot detection + compression
        $publicStack = [$waf, $bot, $compression];
        $registry->group('cms.security.public', $publicStack);

        // API routes: WAF + DLP (response scanning) + compression
        $apiStack = [$waf, $compression];
        if ($dlp !== null) {
            $apiStack = [$waf, $dlp, $compression];
        }
        $registry->group('cms.security.api', $apiStack);

        // Admin routes: WAF + justified access (for customer data)
        $adminStack = [$waf];
        if ($justifiedAccess !== null) {
            $adminStack[] = $justifiedAccess;
        }
        $registry->group('cms.security.admin', $adminStack);

        // Form submission routes: WAF + threat detection + bot detection
        $formStack = [$waf];
        if ($threat !== null) {
            $formStack[] = $threat;
        }
        $formStack[] = $bot;
        $registry->group('cms.security.forms', $formStack);

        // Auth routes: WAF + threat detection
        $authStack = [$waf];
        if ($threat !== null) {
            $authStack[] = $threat;
        }
        $registry->group('cms.security.auth', $authStack);
    }
}
