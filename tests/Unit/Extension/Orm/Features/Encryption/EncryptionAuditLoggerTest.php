<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Orm\Features\Encryption;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Orm\Features\Encryption\EncryptionAuditLogger;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;

#[CoversClass(EncryptionAuditLogger::class)]
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
                'admin_user',
                'orm.column.encrypt',
                'User.email',
            );

        $logger = new EncryptionAuditLogger($auditLogger);
        $logger->logEncrypt('User', 'email', 'admin_user');
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
                'analyst',
                'orm.column.decrypt',
                'Patient.ssn',
            );

        $logger = new EncryptionAuditLogger($auditLogger);
        $logger->logDecrypt('Patient', 'ssn', 'analyst');
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
                'system',
                'orm.column.blind_index_lookup',
                'Account.tax_id',
            );

        $logger = new EncryptionAuditLogger($auditLogger);
        $logger->logBlindIndexLookup('Account', 'tax_id', 'system');
    }

    #[Test]
    public function logEncryptHandlesNullAuditLogger(): void
    {
        $this->expectNotToPerformAssertions();

        $logger = new EncryptionAuditLogger(null);
        $logger->logEncrypt('User', 'email', 'admin');
    }

    #[Test]
    public function logDecryptHandlesNullAuditLogger(): void
    {
        $this->expectNotToPerformAssertions();

        $logger = new EncryptionAuditLogger(null);
        $logger->logDecrypt('User', 'email', 'admin');
    }

    #[Test]
    public function logBlindIndexLookupHandlesNullAuditLogger(): void
    {
        $this->expectNotToPerformAssertions();

        $logger = new EncryptionAuditLogger(null);
        $logger->logBlindIndexLookup('User', 'email', 'admin');
    }

    #[Test]
    public function logEncryptFormatsResourceCorrectly(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                'Order.credit_card_number',
            );

        $logger = new EncryptionAuditLogger($auditLogger);
        $logger->logEncrypt('Order', 'credit_card_number', 'system');
    }
}
