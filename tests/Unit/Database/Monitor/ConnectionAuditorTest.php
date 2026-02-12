<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Monitor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Monitor\ConnectionAuditor;

#[CoversClass(ConnectionAuditor::class)]
final class ConnectionAuditorTest extends TestCase
{
    #[Test]
    public function logConnectRecordsConnectionInfo(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects(self::once())
            ->method('info')
            ->with(
                'Database connection established',
                self::callback(static function (array $context): bool {
                    return $context['connection'] === 'primary'
                        && $context['driver'] === 'mysql';
                }),
            );

        $auditor = new ConnectionAuditor($logger);
        $auditor->logConnect('primary', Driver::MySQL);
    }

    #[Test]
    public function logDisconnectRecordsDisconnection(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects(self::once())
            ->method('info')
            ->with(
                'Database connection closed',
                self::callback(static function (array $context): bool {
                    return $context['connection'] === 'replica-1';
                }),
            );

        $auditor = new ConnectionAuditor($logger);
        $auditor->logDisconnect('replica-1');
    }

    #[Test]
    public function logErrorRecordsErrorDetails(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Database connection error',
                self::callback(static function (array $context): bool {
                    return $context['connection'] === 'primary'
                        && $context['error'] === 'Connection refused';
                }),
            );

        $auditor = new ConnectionAuditor($logger);
        $auditor->logError('primary', 'Connection refused');
    }

    #[Test]
    public function logFailoverRecordsFailoverInfo(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'Database connection failover',
                self::callback(static function (array $context): bool {
                    return $context['from'] === 'primary'
                        && $context['to'] === 'replica-1'
                        && $context['reason'] === 'Connection timeout';
                }),
            );

        $auditor = new ConnectionAuditor($logger);
        $auditor->logFailover('primary', 'replica-1', 'Connection timeout');
    }
}
