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

        self::assertInstanceOf(ConnectionAuditor::class, $auditor);
    }

    #[Test]
    public function logDisconnectRecordsDisconnection(): void
    {
        $capturedContext = [];
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects(self::once())
            ->method('info')
            ->willReturnCallback(function (string $message, array $context) use (&$capturedContext): void {
                $capturedContext = $context;
            });

        $auditor = new ConnectionAuditor($logger);
        $auditor->logDisconnect('replica-1');

        self::assertSame('replica-1', $capturedContext['connection']);
    }

    #[Test]
    public function logErrorRecordsErrorDetails(): void
    {
        $capturedContext = [];
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects(self::once())
            ->method('error')
            ->willReturnCallback(function (string $message, array $context) use (&$capturedContext): void {
                $capturedContext = $context;
            });

        $auditor = new ConnectionAuditor($logger);
        $auditor->logError('primary', 'Connection refused');

        self::assertSame('primary', $capturedContext['connection']);
        self::assertSame('Connection refused', $capturedContext['error']);
    }

    #[Test]
    public function logFailoverRecordsFailoverInfo(): void
    {
        $capturedContext = [];
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects(self::once())
            ->method('warning')
            ->willReturnCallback(function (string $message, array $context) use (&$capturedContext): void {
                $capturedContext = $context;
            });

        $auditor = new ConnectionAuditor($logger);
        $auditor->logFailover('primary', 'replica-1', 'Connection timeout');

        self::assertSame('primary', $capturedContext['from']);
        self::assertSame('replica-1', $capturedContext['to']);
        self::assertSame('Connection timeout', $capturedContext['reason']);
    }
}
