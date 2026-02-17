<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ThreatDetection;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Incident\IncidentInterface;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\ThreatDetection\ThreatCategory;
use Pulsar\Security\ThreatDetection\ThreatDetectionConfig;
use Pulsar\Security\ThreatDetection\ThreatDetectionEngine;
use Pulsar\Security\ThreatDetection\ThreatDetectorInterface;
use Pulsar\Security\ThreatDetection\ThreatEvent;
use Pulsar\Security\ThreatDetection\ThreatResponse;

#[CoversClass(ThreatDetectionEngine::class)]
final class ThreatDetectionEngineTest extends TestCase
{
    public function testAnalyzeReturnsEmptyWhenDisabled(): void
    {
        $engine = $this->createEngine(
            detectors: [],
            config: new ThreatDetectionConfig(enabled: false),
        );

        $request = $this->createStub(ServerRequestInterface::class);
        self::assertSame([], $engine->analyze($request));
    }

    public function testAnalyzeReturnsEmptyWhenNoThreats(): void
    {
        $detector = $this->createStub(ThreatDetectorInterface::class);
        $detector->method('analyze')->willReturn(null);

        $engine = $this->createEngine([$detector]);
        $request = $this->createStub(ServerRequestInterface::class);

        self::assertSame([], $engine->analyze($request));
    }

    public function testAnalyzeCollectsThreatsFromAllDetectors(): void
    {
        $threat1 = new ThreatEvent(
            ThreatCategory::BruteForce,
            ThreatResponse::RateLimit,
            '10.0.0.1',
            'brute force',
            0.8,
            new DateTimeImmutable(),
        );
        $threat2 = new ThreatEvent(
            ThreatCategory::InjectionAttempt,
            ThreatResponse::Block,
            '10.0.0.1',
            'sqli',
            0.9,
            new DateTimeImmutable(),
        );

        $d1 = $this->createStub(ThreatDetectorInterface::class);
        $d1->method('analyze')->willReturn($threat1);

        $d2 = $this->createStub(ThreatDetectorInterface::class);
        $d2->method('analyze')->willReturn($threat2);

        $engine = $this->createEngine([$d1, $d2]);
        $request = $this->createStub(ServerRequestInterface::class);

        $threats = $engine->analyze($request);
        self::assertCount(2, $threats);
    }

    public function testAnalyzeReportsIncidentForEachThreat(): void
    {
        $threat = new ThreatEvent(
            ThreatCategory::BruteForce,
            ThreatResponse::Block,
            '10.0.0.1',
            'attack',
            1.0,
            new DateTimeImmutable(),
        );

        $detector = $this->createStub(ThreatDetectorInterface::class);
        $detector->method('analyze')->willReturn($threat);

        $reporter = $this->createMock(IncidentReporterInterface::class);
        $reporter->expects(self::once())
            ->method('report')
            ->willReturn($this->createStub(IncidentInterface::class));

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('log');

        $engine = new ThreatDetectionEngine(
            [$detector],
            $reporter,
            $auditLogger,
            new ThreatDetectionConfig(),
        );

        $engine->analyze($this->createStub(ServerRequestInterface::class));
    }

    public function testRecordEventDisabledDoesNothing(): void
    {
        $detector = $this->createMock(ThreatDetectorInterface::class);
        $detector->expects(self::never())->method('recordEvent');

        $engine = $this->createEngine(
            [$detector],
            new ThreatDetectionConfig(enabled: false),
        );

        $engine->recordEvent('auth.failure', ['ip' => '10.0.0.1']);
    }

    public function testRecordEventDelegatesToAllDetectors(): void
    {
        $d1 = $this->createMock(ThreatDetectorInterface::class);
        $d1->expects(self::once())->method('recordEvent')
            ->with('auth.failure', ['ip' => '10.0.0.1']);

        $d2 = $this->createMock(ThreatDetectorInterface::class);
        $d2->expects(self::once())->method('recordEvent')
            ->with('auth.failure', ['ip' => '10.0.0.1']);

        $engine = $this->createEngine([$d1, $d2]);
        $engine->recordEvent('auth.failure', ['ip' => '10.0.0.1']);
    }

    public function testHighestSeverityActionReturnsNullForEmpty(): void
    {
        self::assertNull(ThreatDetectionEngine::highestSeverityAction([]));
    }

    public function testHighestSeverityActionReturnsBlock(): void
    {
        $threats = [
            new ThreatEvent(ThreatCategory::BruteForce, ThreatResponse::RateLimit, '', '', 0.5, new DateTimeImmutable()),
            new ThreatEvent(ThreatCategory::InjectionAttempt, ThreatResponse::Block, '', '', 0.9, new DateTimeImmutable()),
            new ThreatEvent(ThreatCategory::ApiAbuse, ThreatResponse::Alert, '', '', 0.3, new DateTimeImmutable()),
        ];

        self::assertSame(ThreatResponse::Block, ThreatDetectionEngine::highestSeverityAction($threats));
    }

    public function testHighestSeverityActionWithSingleThreat(): void
    {
        $threats = [
            new ThreatEvent(ThreatCategory::GeoAnomaly, ThreatResponse::Challenge, '', '', 0.7, new DateTimeImmutable()),
        ];

        self::assertSame(ThreatResponse::Challenge, ThreatDetectionEngine::highestSeverityAction($threats));
    }

    /**
     * @param list<ThreatDetectorInterface> $detectors
     */
    private function createEngine(
        array $detectors,
        ?ThreatDetectionConfig $config = null,
    ): ThreatDetectionEngine {
        $reporter = $this->createStub(IncidentReporterInterface::class);
        $reporter->method('report')->willReturn($this->createStub(IncidentInterface::class));

        $auditLogger = $this->createStub(AuditLoggerInterface::class);

        return new ThreatDetectionEngine(
            $detectors,
            $reporter,
            $auditLogger,
            $config ?? new ThreatDetectionConfig(),
        );
    }
}
