<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Canary;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Canary\CanaryAlertEvent;
use Pulsar\Security\Canary\CanaryToken;

#[CoversClass(CanaryAlertEvent::class)]
final class CanaryAlertEventTest extends TestCase
{
    private function createToken(string $id): CanaryToken
    {
        return new CanaryToken(
            id: $id,
            label: 'Test Canary',
            marker: 'marker-' . $id,
            context: 'test-context',
            createdAt: new DateTimeImmutable(),
        );
    }

    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $token = $this->createToken('tok-001');
        $detectedAt = new DateTimeImmutable('2026-01-15T10:30:00+00:00');
        $metadata = ['source_ip' => '10.0.0.1', 'user_agent' => 'curl/8.0'];

        $event = new CanaryAlertEvent(
            token: $token,
            detectedLocation: 'darkweb-forum',
            detectedAt: $detectedAt,
            metadata: $metadata,
        );

        self::assertSame($token, $event->token);
        self::assertSame('darkweb-forum', $event->detectedLocation);
        self::assertSame($detectedAt, $event->detectedAt);
        self::assertSame($metadata, $event->metadata);
    }

    #[Test]
    public function createFactorySetsTokenAndLocation(): void
    {
        $token = $this->createToken('tok-002');
        $metadata = ['alert_channel' => 'slack'];

        $event = CanaryAlertEvent::create($token, 'external-paste-site', $metadata);

        self::assertSame($token, $event->token);
        self::assertSame('external-paste-site', $event->detectedLocation);
        self::assertSame($metadata, $event->metadata);
        self::assertInstanceOf(DateTimeImmutable::class, $event->detectedAt);
    }

    #[Test]
    public function createFactoryDefaultsToEmptyMetadata(): void
    {
        $token = $this->createToken('tok-003');

        $event = CanaryAlertEvent::create($token, 'unknown');

        self::assertSame([], $event->metadata);
    }

    #[Test]
    public function createFactorySetsRecentTimestamp(): void
    {
        $before = new DateTimeImmutable();
        $token = $this->createToken('tok-004');
        $event = CanaryAlertEvent::create($token, 'test-location');
        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $event->detectedAt);
        self::assertLessThanOrEqual($after, $event->detectedAt);
    }

    #[Test]
    public function constructorDefaultsToEmptyMetadata(): void
    {
        $token = $this->createToken('tok-005');

        $event = new CanaryAlertEvent(
            token: $token,
            detectedLocation: 'somewhere',
            detectedAt: new DateTimeImmutable(),
        );

        self::assertSame([], $event->metadata);
    }
}
