<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Tests\Unit\Features\Encryption;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Orm\Features\Encryption\EncryptionAuditLogger;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

final class EncryptionAuditLoggerTest extends TestCase
{
    #[Test]
    public function logEncryptCallsAuditLogger(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataAccess,
                AuditOutcome::Success,
                'admin',
                'orm.column.encrypt',
                'User.email',
            );

        $logger = new EncryptionAuditLogger($auditLogger);
        $logger->logEncrypt('User', 'email', 'admin');
    }

    #[Test]
    public function logDecryptCallsAuditLogger(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataAccess,
                AuditOutcome::Success,
                'system',
                'orm.column.decrypt',
                'Order.ssn',
            );

        $logger = new EncryptionAuditLogger($auditLogger);
        $logger->logDecrypt('Order', 'ssn', 'system');
    }

    #[Test]
    public function logBlindIndexLookupCallsAuditLogger(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataAccess,
                AuditOutcome::Success,
                'api-client',
                'orm.column.blind_index_lookup',
                'Patient.ssn',
            );

        $logger = new EncryptionAuditLogger($auditLogger);
        $logger->logBlindIndexLookup('Patient', 'ssn', 'api-client');
    }

    #[Test]
    public function operatesGracefullyWithNullAuditLogger(): void
    {
        $logger = new EncryptionAuditLogger(null);

        // Should not throw
        $logger->logEncrypt('User', 'email', 'admin');
        $logger->logDecrypt('User', 'email', 'admin');
        $logger->logBlindIndexLookup('User', 'email', 'admin');

        $this->addToAssertionCount(1);
    }
}
