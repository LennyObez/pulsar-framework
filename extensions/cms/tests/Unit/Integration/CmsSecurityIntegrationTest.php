<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Tests\Unit\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\Container;
use Pulsar\Extension\Cms\CmsSecurityIntegration;
use Pulsar\Http\Message\Response;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\Middleware\CallableRequestHandler;
use Pulsar\Http\Middleware\CompressionMiddleware;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Http\RateLimit\AdaptiveRateLimiter;
use Pulsar\Security\Dlp\DlpScanMiddleware;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\Incident\InMemoryIncidentReporter;
use Pulsar\Security\JustifiedAccess\JustificationStoreInterface;
use Pulsar\Security\JustifiedAccess\JustifiedAccessMiddleware;
use Pulsar\Security\Session\HijackDetector;
use Pulsar\Security\ThreatDetection\BotDetectionMiddleware;
use Pulsar\Security\ThreatDetection\ThreatDetectionMiddleware;
use Pulsar\Security\Waf\WafMiddleware;

#[CoversClass(CmsSecurityIntegration::class)]
final class CmsSecurityIntegrationTest extends TestCase
{
    private Container $container;
    private MiddlewareRegistry $registry;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->registry = new MiddlewareRegistry();

        // Provide logger
        $this->container->instance(\Psr\Log\LoggerInterface::class, new NullLogger());

        // Provide audit logger
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->container->instance(AuditLoggerInterface::class, $auditLogger);

        // Provide incident reporter
        $incidentReporter = new InMemoryIncidentReporter();
        $this->container->instance(IncidentReporterInterface::class, $incidentReporter);
    }

    #[Test]
    public function registers_waf_middleware_in_container(): void
    {
        $this->registerAll();

        self::assertTrue($this->container->has(WafMiddleware::class));
        self::assertInstanceOf(WafMiddleware::class, $this->container->get(WafMiddleware::class));
    }

    #[Test]
    public function registers_dlp_scan_middleware_in_container(): void
    {
        $this->registerAll();

        self::assertTrue($this->container->has(DlpScanMiddleware::class));
        self::assertInstanceOf(DlpScanMiddleware::class, $this->container->get(DlpScanMiddleware::class));
    }

    #[Test]
    public function registers_threat_detection_middleware_in_container(): void
    {
        $this->registerAll();

        self::assertTrue($this->container->has(ThreatDetectionMiddleware::class));
        self::assertInstanceOf(ThreatDetectionMiddleware::class, $this->container->get(ThreatDetectionMiddleware::class));
    }

    #[Test]
    public function registers_bot_detection_middleware_in_container(): void
    {
        $this->registerAll();

        self::assertTrue($this->container->has(BotDetectionMiddleware::class));
        self::assertInstanceOf(BotDetectionMiddleware::class, $this->container->get(BotDetectionMiddleware::class));
    }

    #[Test]
    public function registers_hijack_detector_in_container(): void
    {
        $this->registerAll();

        self::assertTrue($this->container->has(HijackDetector::class));
        self::assertInstanceOf(HijackDetector::class, $this->container->get(HijackDetector::class));
    }

    #[Test]
    public function registers_justified_access_middleware_in_container(): void
    {
        $this->registerAll();

        self::assertTrue($this->container->has(JustifiedAccessMiddleware::class));
        self::assertInstanceOf(JustifiedAccessMiddleware::class, $this->container->get(JustifiedAccessMiddleware::class));
    }

    #[Test]
    public function registers_justification_store_when_not_pre_bound(): void
    {
        $this->registerAll();

        self::assertTrue($this->container->has(JustificationStoreInterface::class));
    }

    #[Test]
    public function registers_compression_middleware_in_container(): void
    {
        $this->registerAll();

        self::assertTrue($this->container->has(CompressionMiddleware::class));
        self::assertInstanceOf(CompressionMiddleware::class, $this->container->get(CompressionMiddleware::class));
    }

    #[Test]
    public function registers_adaptive_rate_limiter_in_container(): void
    {
        $this->registerAll();

        self::assertTrue($this->container->has(AdaptiveRateLimiter::class));
        self::assertInstanceOf(AdaptiveRateLimiter::class, $this->container->get(AdaptiveRateLimiter::class));
    }

    #[Test]
    #[DataProvider('middlewareGroupProvider')]
    public function registers_middleware_group(string $groupName): void
    {
        $this->registerAll();

        self::assertTrue($this->registry->hasGroup($groupName));
        $resolved = $this->registry->resolve($groupName);
        self::assertNotEmpty($resolved, "Middleware group '$groupName' should contain at least one middleware");
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function middlewareGroupProvider(): iterable
    {
        yield 'public routes' => ['cms.security.public'];
        yield 'API routes' => ['cms.security.api'];
        yield 'admin routes' => ['cms.security.admin'];
        yield 'form routes' => ['cms.security.forms'];
        yield 'auth routes' => ['cms.security.auth'];
    }

    #[Test]
    public function public_group_contains_waf_bot_compression(): void
    {
        $this->registerAll();

        $resolved = $this->registry->resolve('cms.security.public');

        $hasWaf = false;
        $hasBot = false;
        $hasCompression = false;

        foreach ($resolved as $mw) {
            if ($mw instanceof WafMiddleware) {
                $hasWaf = true;
            }
            if ($mw instanceof BotDetectionMiddleware) {
                $hasBot = true;
            }
            if ($mw instanceof CompressionMiddleware) {
                $hasCompression = true;
            }
        }

        self::assertTrue($hasWaf, 'Public group should contain WAF');
        self::assertTrue($hasBot, 'Public group should contain bot detection');
        self::assertTrue($hasCompression, 'Public group should contain compression');
    }

    #[Test]
    public function api_group_contains_waf_dlp_compression(): void
    {
        $this->registerAll();

        $resolved = $this->registry->resolve('cms.security.api');

        $hasWaf = false;
        $hasDlp = false;
        $hasCompression = false;

        foreach ($resolved as $mw) {
            if ($mw instanceof WafMiddleware) {
                $hasWaf = true;
            }
            if ($mw instanceof DlpScanMiddleware) {
                $hasDlp = true;
            }
            if ($mw instanceof CompressionMiddleware) {
                $hasCompression = true;
            }
        }

        self::assertTrue($hasWaf, 'API group should contain WAF');
        self::assertTrue($hasDlp, 'API group should contain DLP');
        self::assertTrue($hasCompression, 'API group should contain compression');
    }

    #[Test]
    public function admin_group_contains_waf_and_justified_access(): void
    {
        $this->registerAll();

        $resolved = $this->registry->resolve('cms.security.admin');

        $hasWaf = false;
        $hasJustifiedAccess = false;

        foreach ($resolved as $mw) {
            if ($mw instanceof WafMiddleware) {
                $hasWaf = true;
            }
            if ($mw instanceof JustifiedAccessMiddleware) {
                $hasJustifiedAccess = true;
            }
        }

        self::assertTrue($hasWaf, 'Admin group should contain WAF');
        self::assertTrue($hasJustifiedAccess, 'Admin group should contain justified access');
    }

    #[Test]
    public function forms_group_contains_waf_threat_bot(): void
    {
        $this->registerAll();

        $resolved = $this->registry->resolve('cms.security.forms');

        $hasWaf = false;
        $hasThreat = false;
        $hasBot = false;

        foreach ($resolved as $mw) {
            if ($mw instanceof WafMiddleware) {
                $hasWaf = true;
            }
            if ($mw instanceof ThreatDetectionMiddleware) {
                $hasThreat = true;
            }
            if ($mw instanceof BotDetectionMiddleware) {
                $hasBot = true;
            }
        }

        self::assertTrue($hasWaf, 'Forms group should contain WAF');
        self::assertTrue($hasThreat, 'Forms group should contain threat detection');
        self::assertTrue($hasBot, 'Forms group should contain bot detection');
    }

    #[Test]
    public function auth_group_contains_waf_and_threat_detection(): void
    {
        $this->registerAll();

        $resolved = $this->registry->resolve('cms.security.auth');

        $hasWaf = false;
        $hasThreat = false;

        foreach ($resolved as $mw) {
            if ($mw instanceof WafMiddleware) {
                $hasWaf = true;
            }
            if ($mw instanceof ThreatDetectionMiddleware) {
                $hasThreat = true;
            }
        }

        self::assertTrue($hasWaf, 'Auth group should contain WAF');
        self::assertTrue($hasThreat, 'Auth group should contain threat detection');
    }

    #[Test]
    public function waf_blocks_sql_injection_attempt(): void
    {
        $this->registerAll();

        /** @var WafMiddleware $waf */
        $waf = $this->container->get(WafMiddleware::class);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/v1/content',
            queryParams: ['search' => "' OR 1=1 --"],
        );

        $handler = new CallableRequestHandler(
            static fn() => Response::text('OK'),
        );

        $response = $waf->process($request, $handler);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function waf_allows_clean_request(): void
    {
        $this->registerAll();

        /** @var WafMiddleware $waf */
        $waf = $this->container->get(WafMiddleware::class);

        $request = new ServerRequest(
            method: 'GET',
            uri: '/api/v1/content',
            queryParams: ['page' => '1'],
        );

        $handler = new CallableRequestHandler(
            static fn() => Response::text('OK'),
        );

        $response = $waf->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function adaptive_rate_limiter_has_cms_endpoint_policies(): void
    {
        $this->registerAll();

        /** @var AdaptiveRateLimiter $limiter */
        $limiter = $this->container->get(AdaptiveRateLimiter::class);

        // The 2FA endpoint should have a strict policy (5 attempts/10min)
        // Verify by hitting it: it should be very restrictive
        for ($i = 0; $i < 6; $i++) {
            $result = $limiter->hit('/admin/cms/2fa:127.0.0.1');
        }

        // After 6 hits, the 2FA endpoint (5 max) should be rate limited
        self::assertSame(0, $result->remaining);
    }

    #[Test]
    public function works_without_audit_logger(): void
    {
        // Reset container without audit logger
        $container = new Container();
        $registry = new MiddlewareRegistry();
        $container->instance(\Psr\Log\LoggerInterface::class, new NullLogger());

        new CmsSecurityIntegration()->register($container, $registry);

        // WAF, bot detection, compression, adaptive limiter should still work
        self::assertTrue($container->has(WafMiddleware::class));
        self::assertTrue($container->has(BotDetectionMiddleware::class));
        self::assertTrue($container->has(CompressionMiddleware::class));
        self::assertTrue($container->has(AdaptiveRateLimiter::class));
        self::assertTrue($container->has(HijackDetector::class));

        // DLP, threat detection, justified access require audit logger
        self::assertFalse($container->has(DlpScanMiddleware::class));
        self::assertFalse($container->has(JustifiedAccessMiddleware::class));

        // Groups should still be registered (with reduced middleware)
        self::assertTrue($registry->hasGroup('cms.security.public'));
        self::assertTrue($registry->hasGroup('cms.security.api'));
    }

    private function registerAll(): void
    {
        new CmsSecurityIntegration()->register($this->container, $this->registry);
    }
}
