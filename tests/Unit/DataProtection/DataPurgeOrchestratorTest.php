<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\DataProtection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\DataProtection\DataProtectionConfig;
use Pulsar\DataProtection\DataPurgeInterface;
use Pulsar\DataProtection\DataPurgeOrchestrator;
use Pulsar\DataProtection\DefaultRetentionPolicy;
use Pulsar\DataProtection\PurgeConfig;
use Pulsar\Security\Audit\AuditEntry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(DataPurgeOrchestrator::class)]
final class DataPurgeOrchestratorTest extends TestCase
{
    #[Test]
    public function purgeAllRunsAllPurgers(): void
    {
        $auditPurger = $this->createStub(DataPurgeInterface::class);
        $auditPurger->method('purge')->willReturn(10);

        $sessionPurger = $this->createStub(DataPurgeInterface::class);
        $sessionPurger->method('purge')->willReturn(3);

        $orchestrator = new DataPurgeOrchestrator(
            purgers: [
                'audit_logs' => $auditPurger,
                'sessions' => $sessionPurger,
            ],
            policies: [
                'audit_logs' => new DefaultRetentionPolicy('audit_logs', 2555, 'SOX 7-year'),
                'sessions' => new DefaultRetentionPolicy('sessions', 30),
            ],
            config: new DataProtectionConfig(),
        );

        $results = $orchestrator->purgeAll();

        self::assertCount(2, $results);
        self::assertSame('audit_logs', $results[0]->category);
        self::assertSame(10, $results[0]->purgedCount);
        self::assertFalse($results[0]->dryRun);
        self::assertSame('sessions', $results[1]->category);
        self::assertSame(3, $results[1]->purgedCount);
    }

    #[Test]
    public function purgeAllSkipsCategoriesWithoutPolicy(): void
    {
        $purger = $this->createStub(DataPurgeInterface::class);
        $purger->method('purge')->willReturn(5);

        $orchestrator = new DataPurgeOrchestrator(
            purgers: ['orphan_category' => $purger],
            policies: [], // No matching policy
            config: new DataProtectionConfig(),
        );

        $results = $orchestrator->purgeAll();

        self::assertCount(0, $results);
    }

    #[Test]
    public function dryRunUsesCountExpiredInsteadOfPurge(): void
    {
        $purger = $this->createStub(DataPurgeInterface::class);
        $purger->method('countExpired')->willReturn(7);
        $purger->method('purge')->willReturn(999); // Should not be called

        $orchestrator = new DataPurgeOrchestrator(
            purgers: ['audit_logs' => $purger],
            policies: ['audit_logs' => new DefaultRetentionPolicy('audit_logs', 365)],
            config: new DataProtectionConfig(),
        );

        $results = $orchestrator->dryRun();

        self::assertCount(1, $results);
        self::assertSame(7, $results[0]->purgedCount);
        self::assertTrue($results[0]->dryRun);
    }

    #[Test]
    public function configDryRunFlagUsesDryRunMode(): void
    {
        $purger = $this->createStub(DataPurgeInterface::class);
        $purger->method('countExpired')->willReturn(4);

        $config = new DataProtectionConfig(
            purge: new PurgeConfig(dryRun: true),
        );

        $orchestrator = new DataPurgeOrchestrator(
            purgers: ['audit_logs' => $purger],
            policies: ['audit_logs' => new DefaultRetentionPolicy('audit_logs', 365)],
            config: $config,
        );

        $results = $orchestrator->purgeAll();

        self::assertCount(1, $results);
        self::assertTrue($results[0]->dryRun);
        self::assertSame(4, $results[0]->purgedCount);
    }

    #[Test]
    public function purgeAllWithNoPurgersReturnsEmpty(): void
    {
        $orchestrator = new DataPurgeOrchestrator(
            purgers: [],
            policies: [],
            config: new DataProtectionConfig(),
        );

        self::assertSame([], $orchestrator->purgeAll());
    }

    #[Test]
    public function resultContainsDuration(): void
    {
        $purger = $this->createStub(DataPurgeInterface::class);
        $purger->method('purge')->willReturn(0);

        $orchestrator = new DataPurgeOrchestrator(
            purgers: ['test' => $purger],
            policies: ['test' => new DefaultRetentionPolicy('test', 30)],
            config: new DataProtectionConfig(),
        );

        $results = $orchestrator->purgeAll();

        self::assertCount(1, $results);
        self::assertGreaterThanOrEqual(0.0, $results[0]->durationMs);
    }

    #[Test]
    public function purgeAllLogsAuditEventWhenAuditEnabled(): void
    {
        $purger = $this->createStub(DataPurgeInterface::class);
        $purger->method('purge')->willReturn(5);

        $auditEntry = $this->createStub(AuditEntry::class);
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataModification,
                AuditOutcome::Success,
                'system:data_purge',
                'data_purge.execute',
                'audit_logs',
                self::callback(static function (array $meta): bool {
                    return $meta['purged_count'] === 5
                        && $meta['retention_days'] === 365
                        && $meta['dry_run'] === false;
                }),
            )
            ->willReturn($auditEntry);

        $config = new DataProtectionConfig(
            purge: new PurgeConfig(auditPurgeOperations: true),
        );

        $orchestrator = new DataPurgeOrchestrator(
            purgers: ['audit_logs' => $purger],
            policies: ['audit_logs' => new DefaultRetentionPolicy('audit_logs', 365, 'GDPR')],
            config: $config,
            auditLogger: $auditLogger,
        );

        $results = $orchestrator->purgeAll();

        self::assertCount(1, $results);
        self::assertSame(5, $results[0]->purgedCount);
    }

    #[Test]
    public function purgeAllLogsDryRunActionWhenConfigDryRun(): void
    {
        $purger = $this->createStub(DataPurgeInterface::class);
        $purger->method('countExpired')->willReturn(3);

        $auditEntry = $this->createStub(AuditEntry::class);
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataModification,
                AuditOutcome::Success,
                'system:data_purge',
                'data_purge.dry_run',
                'sessions',
                self::callback(static function (array $meta): bool {
                    return $meta['dry_run'] === true && $meta['purged_count'] === 3;
                }),
            )
            ->willReturn($auditEntry);

        $config = new DataProtectionConfig(
            purge: new PurgeConfig(auditPurgeOperations: true, dryRun: true),
        );

        $orchestrator = new DataPurgeOrchestrator(
            purgers: ['sessions' => $purger],
            policies: ['sessions' => new DefaultRetentionPolicy('sessions', 30)],
            config: $config,
            auditLogger: $auditLogger,
        );

        $orchestrator->purgeAll();
    }

    #[Test]
    public function purgeAllSkipsAuditWhenDisabled(): void
    {
        $purger = $this->createStub(DataPurgeInterface::class);
        $purger->method('purge')->willReturn(1);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('log');

        $config = new DataProtectionConfig(
            purge: new PurgeConfig(auditPurgeOperations: false),
        );

        $orchestrator = new DataPurgeOrchestrator(
            purgers: ['test' => $purger],
            policies: ['test' => new DefaultRetentionPolicy('test', 30)],
            config: $config,
            auditLogger: $auditLogger,
        );

        $orchestrator->purgeAll();
    }

    #[Test]
    public function purgeAllLogsWarningForMissingPolicy(): void
    {
        $purger = $this->createStub(DataPurgeInterface::class);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('orphan_cat'));

        $orchestrator = new DataPurgeOrchestrator(
            purgers: ['orphan_cat' => $purger],
            policies: [],
            config: new DataProtectionConfig(),
            logger: $logger,
        );

        $orchestrator->purgeAll();
    }

    #[Test]
    public function purgeAllLogsInfoForEachPurge(): void
    {
        $purger = $this->createStub(DataPurgeInterface::class);
        $purger->method('purge')->willReturn(2);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('(executed)'));

        $orchestrator = new DataPurgeOrchestrator(
            purgers: ['test' => $purger],
            policies: ['test' => new DefaultRetentionPolicy('test', 30)],
            config: new DataProtectionConfig(),
            logger: $logger,
        );

        $orchestrator->purgeAll();
    }

    #[Test]
    public function dryRunSkipsCategoriesWithoutPolicy(): void
    {
        $purger = $this->createStub(DataPurgeInterface::class);
        $purger->method('countExpired')->willReturn(10);

        $orchestrator = new DataPurgeOrchestrator(
            purgers: ['orphan' => $purger],
            policies: [],
            config: new DataProtectionConfig(),
        );

        $results = $orchestrator->dryRun();

        self::assertCount(0, $results);
    }

    #[Test]
    public function dryRunReturnsCountWithDuration(): void
    {
        $purger = $this->createStub(DataPurgeInterface::class);
        $purger->method('countExpired')->willReturn(15);

        $orchestrator = new DataPurgeOrchestrator(
            purgers: ['logs' => $purger],
            policies: ['logs' => new DefaultRetentionPolicy('logs', 90)],
            config: new DataProtectionConfig(),
        );

        $results = $orchestrator->dryRun();

        self::assertCount(1, $results);
        self::assertSame('logs', $results[0]->category);
        self::assertSame(15, $results[0]->purgedCount);
        self::assertTrue($results[0]->dryRun);
        self::assertGreaterThanOrEqual(0.0, $results[0]->durationMs);
    }
}
