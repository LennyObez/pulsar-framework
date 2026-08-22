<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\KeyLifecycle;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Incident\IncidentInterface;
use Pulsar\Security\Incident\IncidentReporterInterface;
use Pulsar\Security\Incident\IncidentSeverity;
use Pulsar\Security\KeyLifecycle\KeyCompromiseResponse;
use Pulsar\Security\KeyLifecycle\KeyInventory;
use Pulsar\Security\KeyLifecycle\KeyInventoryEntry;
use Pulsar\Security\KeyLifecycle\KeyRotationExecutor;
use Pulsar\Security\KeyLifecycle\KeyType;

#[CoversClass(KeyCompromiseResponse::class)]
final class KeyCompromiseResponseTest extends TestCase
{
    public function testRespondToCompromisedKey(): void
    {
        $inventory = new KeyInventory();
        $entry = new KeyInventoryEntry(
            kid: 'compromised-key',
            type: KeyType::Encryption,
            createdAt: new DateTimeImmutable('-1 day'),
            lastRotatedAt: null,
            rotationIntervalSeconds: 86400,
            active: true,
            algorithm: 'aes-256-gcm',
            context: 'production',
        );
        $inventory->register($entry);

        $executor = new KeyRotationExecutor($inventory);

        $incident = $this->createStub(IncidentInterface::class);
        $incident->method('id')->willReturn('INC-001');

        $reporter = $this->createMock(IncidentReporterInterface::class);
        $reporter->expects(self::once())
            ->method('report')
            ->with(
                IncidentSeverity::Critical,
                self::stringContains('compromised-key'),
                self::anything(),
                'KeyCompromiseResponse',
                self::anything(),
            )
            ->willReturn($incident);

        $response = new KeyCompromiseResponse($executor, $inventory, $reporter);
        $result = $response->respond('compromised-key', 'leaked in logs');

        self::assertTrue($result->success);
        self::assertSame('compromised-key', $result->compromisedKid);
        self::assertNotEmpty($result->newKid);
        self::assertSame('INC-001', $result->incidentId);
        self::assertSame(KeyType::Encryption, $result->keyType);

        // Compromised key should be deactivated
        $old = $inventory->find('compromised-key');
        self::assertNotNull($old);
        self::assertFalse($old->active);
    }

    public function testRespondToUnknownKeyFails(): void
    {
        $inventory = new KeyInventory();
        $executor = new KeyRotationExecutor($inventory);
        $reporter = $this->createStub(IncidentReporterInterface::class);

        $response = new KeyCompromiseResponse($executor, $inventory, $reporter);
        $result = $response->respond('nonexistent', 'test');

        self::assertFalse($result->success);
    }
}
