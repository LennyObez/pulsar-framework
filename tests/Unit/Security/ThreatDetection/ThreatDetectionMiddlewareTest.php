<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ThreatDetection;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Incident\IncidentInterface;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\ThreatDetection\ThreatCategory;
use Pulsar\Security\ThreatDetection\ThreatDetectionConfig;
use Pulsar\Security\ThreatDetection\ThreatDetectionEngine;
use Pulsar\Security\ThreatDetection\ThreatDetectionMiddleware;
use Pulsar\Security\ThreatDetection\ThreatEvent;
use Pulsar\Security\ThreatDetection\ThreatResponse;

#[CoversClass(ThreatDetectionMiddleware::class)]
final class ThreatDetectionMiddlewareTest extends TestCase
{
    public function testPassesThroughWhenNoThreats(): void
    {
        $engine = $this->createEngineReturning([]);
        $middleware = new ThreatDetectionMiddleware($engine);

        $expectedResponse = $this->createStub(ResponseInterface::class);
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $request = $this->createStub(ServerRequestInterface::class);
        $response = $middleware->process($request, $handler);

        self::assertSame($expectedResponse, $response);
    }

    public function testBlocksThreatWithBlockResponse(): void
    {
        $threat = new ThreatEvent(
            ThreatCategory::InjectionAttempt,
            ThreatResponse::Block,
            '10.0.0.1',
            'sqli',
            0.9,
            new DateTimeImmutable(),
        );

        $engine = $this->createEngineReturning([$threat]);
        $middleware = new ThreatDetectionMiddleware($engine);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $middleware->process($request, $handler);
        self::assertSame(403, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('threat_detected', $body['reason']);
    }

    public function testChallengeThreatReturns403WithChallengeIndicator(): void
    {
        $threat = new ThreatEvent(
            ThreatCategory::GeoAnomaly,
            ThreatResponse::Challenge,
            '10.0.0.1',
            'impossible travel',
            0.8,
            new DateTimeImmutable(),
        );

        $engine = $this->createEngineReturning([$threat]);
        $middleware = new ThreatDetectionMiddleware($engine);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $request = $this->createStub(ServerRequestInterface::class);

        $response = $middleware->process($request, $handler);
        self::assertSame(403, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('challenge_required', $body['reason']);
    }

    public function testRateLimitThreatAttachesAttributeAndContinues(): void
    {
        $threat = new ThreatEvent(
            ThreatCategory::BruteForce,
            ThreatResponse::RateLimit,
            '10.0.0.1',
            'brute force',
            0.6,
            new DateTimeImmutable(),
        );

        $engine = $this->createEngineReturning([$threat]);
        $middleware = new ThreatDetectionMiddleware($engine);

        $expectedResponse = $this->createStub(ResponseInterface::class);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->expects(self::once())
            ->method('withAttribute')
            ->with('threat_detection.threats', [$threat])
            ->willReturn($request);

        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $response = $middleware->process($request, $handler);
        self::assertSame($expectedResponse, $response);
    }

    /**
     * @param list<ThreatEvent> $threats
     */
    private function createEngineReturning(array $threats): ThreatDetectionEngine
    {
        $reporter = $this->createStub(IncidentReporterInterface::class);
        $reporter->method('report')->willReturn($this->createStub(IncidentInterface::class));

        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $config = new ThreatDetectionConfig(enabled: $threats !== []);

        // Create engine with no detectors — we'll make it return our threats via a custom detector
        if ($threats === []) {
            return new ThreatDetectionEngine([], $reporter, $auditLogger, $config);
        }

        $detector = $this->createStub(\Pulsar\Security\ThreatDetection\ThreatDetectorInterface::class);
        // Return threats one by one
        $callCount = 0;
        $detector->method('analyze')->willReturnCallback(function () use ($threats, &$callCount): ?ThreatEvent {
            return $threats[$callCount++] ?? null;
        });

        return new ThreatDetectionEngine([$detector], $reporter, $auditLogger, $config);
    }
}
