<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Canary;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Canary\CanaryAlertEvent;
use Pulsar\Security\Canary\CanaryToken;

#[CoversClass(CanaryToken::class)]
#[CoversClass(CanaryAlertEvent::class)]
final class CanaryTokenTest extends TestCase
{
    #[Test]
    public function canaryTokenStoresAllProperties(): void
    {
        $now = new DateTimeImmutable('2026-03-15T10:00:00+00:00');
        $token = new CanaryToken(
            id: 'tok-1',
            label: 'customer-export',
            marker: 'CNRY-abc123',
            context: 'csv-download',
            createdAt: $now,
            createdBy: 'admin@example.com',
        );

        self::assertSame('tok-1', $token->id);
        self::assertSame('customer-export', $token->label);
        self::assertSame('CNRY-abc123', $token->marker);
        self::assertSame('csv-download', $token->context);
        self::assertSame($now, $token->createdAt);
        self::assertSame('admin@example.com', $token->createdBy);
    }

    #[Test]
    public function createdByDefaultsToNull(): void
    {
        $token = new CanaryToken(
            id: 'tok-2',
            label: 'test',
            marker: 'CNRY-xyz',
            context: 'unit-test',
            createdAt: new DateTimeImmutable(),
        );

        self::assertNull($token->createdBy);
    }

    #[Test]
    public function alertEventStoresProperties(): void
    {
        $token = $this->createToken();
        $now = new DateTimeImmutable('2026-03-15T12:00:00+00:00');

        $alert = new CanaryAlertEvent(
            token: $token,
            detectedLocation: 'https://pastebin.example/leak',
            detectedAt: $now,
            metadata: ['scanner' => 'dark-web-monitor'],
        );

        self::assertSame($token, $alert->token);
        self::assertSame('https://pastebin.example/leak', $alert->detectedLocation);
        self::assertSame($now, $alert->detectedAt);
        self::assertSame(['scanner' => 'dark-web-monitor'], $alert->metadata);
    }

    #[Test]
    public function alertEventMetadataDefaultsToEmpty(): void
    {
        $token = $this->createToken();

        $alert = new CanaryAlertEvent(
            token: $token,
            detectedLocation: 'somewhere',
            detectedAt: new DateTimeImmutable(),
        );

        self::assertSame([], $alert->metadata);
    }

    #[Test]
    public function alertEventCreateFactory(): void
    {
        $token = $this->createToken();
        $before = new DateTimeImmutable();

        $alert = CanaryAlertEvent::create(
            token: $token,
            detectedLocation: 'external-site',
            metadata: ['source' => 'monitoring'],
        );

        self::assertSame($token, $alert->token);
        self::assertSame('external-site', $alert->detectedLocation);
        self::assertSame(['source' => 'monitoring'], $alert->metadata);
        self::assertGreaterThanOrEqual(
            $before->getTimestamp(),
            $alert->detectedAt->getTimestamp(),
        );
    }

    #[Test]
    public function alertEventCreateDefaultsMetadata(): void
    {
        $token = $this->createToken();
        $alert = CanaryAlertEvent::create($token, 'loc');

        self::assertSame([], $alert->metadata);
    }

    private function createToken(): CanaryToken
    {
        return new CanaryToken(
            id: 'tok-helper',
            label: 'helper-label',
            marker: 'CNRY-helper',
            context: 'test',
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }
}
