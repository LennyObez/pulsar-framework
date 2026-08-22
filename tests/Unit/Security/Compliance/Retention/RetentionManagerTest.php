<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Retention;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditActor;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use Pulsar\Security\Compliance\Retention\RetentionManager;
use Pulsar\Security\Compliance\Retention\RetentionSchedule;

use function count;

/**
 * Stub audit logger that captures log calls for testing.
 */
final class StubAuditLogger implements AuditLoggerInterface
{
    /** @var list<array{event: AuditEvent, outcome: AuditOutcome, actor: string, action: string, resource: string, metadata: array<string, mixed>}> */
    public array $calls = [];

    #[Override]
    public function log(
        AuditEvent $event,
        AuditOutcome $outcome,
        AuditActor|string|null $actor,
        string $action,
        string $resource = '',
        array $metadata = [],
    ): AuditEntry {
        $resolved = match (true) {
            $actor instanceof AuditActor => $actor->id,
            $actor === null || $actor === '' => 'system',
            default => $actor,
        };
        $this->calls[] = [
            'event' => $event,
            'outcome' => $outcome,
            'actor' => $resolved,
            'action' => $action,
            'resource' => $resource,
            'metadata' => $metadata,
        ];

        return new AuditEntry(
            id: 'stub-' . count($this->calls),
            event: $event,
            outcome: $outcome,
            actor: $resolved,
            action: $action,
            resource: $resource,
            timestamp: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            metadata: $metadata,
            previousHmac: 'stub-hmac',
            hmac: 'stub-hmac-result',
        );
    }
}

#[CoversClass(RetentionManager::class)]
final class RetentionManagerTest extends TestCase
{
    private StubAuditLogger $auditLogger;
    private RetentionManager $manager;

    protected function setUp(): void
    {
        $this->auditLogger = new StubAuditLogger();
        $this->manager = new RetentionManager(
            RetentionSchedule::default(),
            $this->auditLogger,
        );
    }

    #[Test]
    public function purgeDryRunDoesNotEmitAuditEvent(): void
    {
        $result = $this->manager->purge('SOX', dryRun: true, operatorIdentity: 'admin');

        self::assertTrue($result->dryRun);
        self::assertCount(0, $this->auditLogger->calls);
    }

    #[Test]
    public function purgeActualEmitsAuditEvent(): void
    {
        $result = $this->manager->purge('SOX', dryRun: false, operatorIdentity: 'admin');

        self::assertFalse($result->dryRun);
        self::assertCount(1, $this->auditLogger->calls);

        $call = $this->auditLogger->calls[0];
        self::assertSame(AuditEvent::DataModification, $call['event']);
        self::assertSame(AuditOutcome::Success, $call['outcome']);
        self::assertSame('admin', $call['actor']);
        self::assertSame('retention.purge', $call['action']);
        self::assertSame('SOX', $call['resource']);
    }

    #[Test]
    public function purgeReturnsPolicyDetails(): void
    {
        $result = $this->manager->purge('SOX', dryRun: true);

        self::assertSame('sox-default', $result->policyId);
        self::assertSame(1, $result->policyVersion);
    }

    #[Test]
    public function purgeReturnsDefaultOperatorWhenNotProvided(): void
    {
        $result = $this->manager->purge('SOX', dryRun: true);

        self::assertSame('system', $result->operatorIdentity);
    }

    #[Test]
    public function purgeHandlesUnknownRegulation(): void
    {
        $result = $this->manager->purge('UNKNOWN', dryRun: true);

        self::assertSame('', $result->policyId);
        self::assertSame(0, $result->policyVersion);
        self::assertSame(0, $result->recordCount);
    }

    #[Test]
    public function isExpiredReturnsTrueForOldRecords(): void
    {
        // SOX is 2555 days (7 years), so a record from 8 years ago should be expired
        $oldDate = new DateTimeImmutable('-8 years', new DateTimeZone('UTC'));

        self::assertTrue($this->manager->isExpired('SOX', $oldDate));
    }

    #[Test]
    public function isExpiredReturnsFalseForRecentRecords(): void
    {
        // SOX is 2555 days (7 years), so a record from yesterday should not be expired
        $recentDate = new DateTimeImmutable('-1 day', new DateTimeZone('UTC'));

        self::assertFalse($this->manager->isExpired('SOX', $recentDate));
    }

    #[Test]
    public function isExpiredReturnsFalseForUnknownRegulation(): void
    {
        $date = new DateTimeImmutable('-100 years', new DateTimeZone('UTC'));

        self::assertFalse($this->manager->isExpired('UNKNOWN', $date));
    }

    #[Test]
    public function policyForDelegatesToSchedule(): void
    {
        $policy = $this->manager->policyFor('SOX');

        self::assertNotNull($policy);
        self::assertSame('SOX', $policy->regulation);
        self::assertSame(2555, $policy->retentionPeriodDays);
    }

    #[Test]
    public function policyForReturnsNullForUnknown(): void
    {
        $policy = $this->manager->policyFor('NONEXISTENT');

        self::assertNull($policy);
    }

    #[Test]
    public function purgeResultContainsCutoffDate(): void
    {
        $result = $this->manager->purge('PCI-DSS', dryRun: true);

        // PCI-DSS = 365 days, cutoff should be approximately 1 year ago
        $expectedCutoff = new DateTimeImmutable('-365 days', new DateTimeZone('UTC'));

        // Allow 2 seconds of drift for test execution time
        $diff = abs($result->affectedEndDate->getTimestamp() - $expectedCutoff->getTimestamp());
        self::assertLessThan(2, $diff, 'Cutoff date should be approximately 365 days ago');
    }

    #[Test]
    public function isExpiredWorksWithHipaaRetention(): void
    {
        // HIPAA = 2190 days (6 years)
        $oldRecord = new DateTimeImmutable('-7 years', new DateTimeZone('UTC'));
        $recentRecord = new DateTimeImmutable('-5 years', new DateTimeZone('UTC'));

        self::assertTrue($this->manager->isExpired('HIPAA', $oldRecord));
        self::assertFalse($this->manager->isExpired('HIPAA', $recentRecord));
    }
}
