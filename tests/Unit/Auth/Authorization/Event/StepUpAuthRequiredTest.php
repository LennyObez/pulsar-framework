<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Auth\Authorization\Event;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Authorization\Event\StepUpAuthRequired;

use function strlen;

#[CoversClass(StepUpAuthRequired::class)]
final class StepUpAuthRequiredTest extends TestCase
{
    #[Test]
    public function constructionSetsAllProperties(): void
    {
        $now = new DateTimeImmutable();

        $event = new StepUpAuthRequired(
            identityId: 'user-1',
            permission: 'financial.transfer',
            trustScore: 0.45,
            requiredLevel: 'mfa',
            correlationId: 'corr-1',
            nonce: 'nonce101',
            occurredAt: $now,
        );

        self::assertSame('user-1', $event->identityId);
        self::assertSame('financial.transfer', $event->permission);
        self::assertSame(0.45, $event->trustScore);
        self::assertSame('mfa', $event->requiredLevel);
        self::assertSame('corr-1', $event->correlationId);
        self::assertSame('nonce101', $event->nonce);
        self::assertSame($now, $event->occurredAt);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, StepUpAuthRequired::SCHEMA_VERSION);
    }

    #[Test]
    public function toArrayFromArrayRoundTrip(): void
    {
        $now = new DateTimeImmutable('2025-06-15T10:30:00.000000+00:00');

        $event = new StepUpAuthRequired(
            identityId: 'user-1',
            permission: 'financial.transfer',
            trustScore: 0.75,
            requiredLevel: 'biometric',
            correlationId: 'corr-1',
            nonce: 'nonce101',
            occurredAt: $now,
        );

        $array = $event->toArray();
        $restored = StepUpAuthRequired::fromArray($array);

        self::assertSame($event->identityId, $restored->identityId);
        self::assertSame($event->permission, $restored->permission);
        self::assertSame($event->trustScore, $restored->trustScore);
        self::assertSame($event->requiredLevel, $restored->requiredLevel);
        self::assertSame($event->correlationId, $restored->correlationId);
        self::assertSame($event->nonce, $restored->nonce);
    }

    #[Test]
    public function createGeneratesNonceAndTimestamp(): void
    {
        $event = StepUpAuthRequired::create(
            identityId: 'user-2',
            permission: 'admin.settings',
            trustScore: 0.3,
            requiredLevel: 'hardware_key',
            correlationId: 'corr-2',
        );

        self::assertNotEmpty($event->nonce);
        self::assertSame(32, strlen($event->nonce));
    }

    #[Test]
    public function fromArrayHandlesIntegerTrustScore(): void
    {
        $data = [
            'identity_id' => 'user-1',
            'permission' => 'test',
            'trust_score' => 1,
            'required_level' => 'mfa',
            'correlation_id' => 'corr-1',
            'nonce' => 'abc',
            'occurred_at' => '2025-06-15T10:30:00.000000+00:00',
        ];

        $event = StepUpAuthRequired::fromArray($data);

        self::assertSame(1.0, $event->trustScore);
    }
}
