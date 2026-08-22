<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ThreatDetection;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\ThreatDetection\ThreatCategory;
use Pulsar\Security\ThreatDetection\ThreatEvent;
use Pulsar\Security\ThreatDetection\ThreatResponse;

#[CoversClass(ThreatEvent::class)]
final class ThreatEventTest extends TestCase
{
    public function testConstructor(): void
    {
        $detectedAt = new DateTimeImmutable();
        $event = new ThreatEvent(
            category: ThreatCategory::BruteForce,
            recommendedAction: ThreatResponse::Block,
            sourceIp: '192.168.1.1',
            description: 'Test threat',
            confidence: 0.95,
            detectedAt: $detectedAt,
            metadata: ['key' => 'value'],
        );

        self::assertSame(ThreatCategory::BruteForce, $event->category);
        self::assertSame(ThreatResponse::Block, $event->recommendedAction);
        self::assertSame('192.168.1.1', $event->sourceIp);
        self::assertSame('Test threat', $event->description);
        self::assertSame(0.95, $event->confidence);
        self::assertSame($detectedAt, $event->detectedAt);
        self::assertSame(['key' => 'value'], $event->metadata);
    }

    public function testCreateFactory(): void
    {
        $event = ThreatEvent::create(
            category: ThreatCategory::InjectionAttempt,
            recommendedAction: ThreatResponse::Block,
            sourceIp: '10.0.0.1',
            description: 'SQL injection attempt',
            confidence: 0.9,
            metadata: ['type' => 'sqli'],
        );

        self::assertSame(ThreatCategory::InjectionAttempt, $event->category);
        self::assertSame(ThreatResponse::Block, $event->recommendedAction);
        self::assertSame('10.0.0.1', $event->sourceIp);
        self::assertSame('SQL injection attempt', $event->description);
        self::assertSame(0.9, $event->confidence);
        self::assertInstanceOf(DateTimeImmutable::class, $event->detectedAt);
        self::assertSame(['type' => 'sqli'], $event->metadata);
    }

    public function testCreateFactoryWithDefaults(): void
    {
        $event = ThreatEvent::create(
            category: ThreatCategory::BotTraffic,
            recommendedAction: ThreatResponse::Log,
            sourceIp: '1.2.3.4',
            description: 'Bot detected',
        );

        self::assertSame(1.0, $event->confidence);
        self::assertSame([], $event->metadata);
    }

    public function testDefaultMetadataIsEmpty(): void
    {
        $event = new ThreatEvent(
            category: ThreatCategory::Reconnaissance,
            recommendedAction: ThreatResponse::Alert,
            sourceIp: '10.0.0.1',
            description: 'Recon scan',
            confidence: 1.0,
            detectedAt: new DateTimeImmutable(),
        );

        self::assertSame([], $event->metadata);
    }
}
