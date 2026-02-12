<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Config\EnvironmentMode;
use Pulsar\Console\Repl\ReplAuditLogger;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use stdClass;

use function str_repeat;
use function strlen;

#[CoversClass(ReplAuditLogger::class)]
final class ReplAuditLoggerTest extends TestCase
{
    #[Test]
    public function logSessionStart(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::SystemEvent,
                AuditOutcome::Success,
                'admin',
                'repl.session_start',
                'repl',
                self::callback(function (array $metadata): bool {
                    return $metadata['session_id'] === 'test-session-id'
                        && $metadata['environment'] === 'local'
                        && $metadata['safe_mode'] === true;
                }),
            );

        $logger = new ReplAuditLogger($auditLogger, 'test-session-id');
        $logger->logSessionStart('admin', EnvironmentMode::Local, true);
    }

    #[Test]
    public function logSessionEnd(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::SystemEvent,
                AuditOutcome::Success,
                'admin',
                'repl.session_end',
                'repl',
                self::callback(function (array $metadata): bool {
                    return $metadata['session_id'] === 'test-session-id'
                        && $metadata['command_count'] === 5
                        && $metadata['duration_seconds'] === 120.5;
                }),
            );

        $logger = new ReplAuditLogger($auditLogger, 'test-session-id');
        $logger->logSessionEnd('admin', 5, 120.5);
    }

    #[Test]
    public function logSessionEndOmitsCommandCountWhenNull(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::SystemEvent,
                AuditOutcome::Success,
                'admin',
                'repl.session_end',
                'repl',
                self::callback(function (array $metadata): bool {
                    return $metadata['session_id'] === 'test-session-id'
                        && !isset($metadata['command_count'])
                        && $metadata['duration_seconds'] === 60.0;
                }),
            );

        $logger = new ReplAuditLogger($auditLogger, 'test-session-id');
        $logger->logSessionEnd('admin', null, 60.0);
    }

    #[Test]
    public function logCommand(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataAccess,
                AuditOutcome::Success,
                'admin',
                'repl.command',
                'repl',
                self::callback(function (array $metadata): bool {
                    return $metadata['session_id'] === 'test-session-id'
                        && $metadata['command'] === '$users = User::all();'
                        && $metadata['result_summary'] === 'array(10)';
                }),
            );

        $logger = new ReplAuditLogger($auditLogger, 'test-session-id');
        $logger->logCommand('admin', '$users = User::all();', 'array(10)');
    }

    #[Test]
    public function logCommandWithoutResult(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataAccess,
                AuditOutcome::Success,
                'admin',
                'repl.command',
                'repl',
                self::callback(function (array $metadata): bool {
                    return $metadata['session_id'] === 'test-session-id'
                        && $metadata['command'] === 'echo "test";'
                        && !isset($metadata['result_summary']);
                }),
            );

        $logger = new ReplAuditLogger($auditLogger, 'test-session-id');
        $logger->logCommand('admin', 'echo "test";');
    }

    #[Test]
    public function logProductionOverride(): void
    {
        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::SecurityEvent,
                AuditOutcome::Success,
                'admin',
                'repl.production_override',
                'repl',
                self::callback(function (array $metadata): bool {
                    return $metadata['session_id'] === 'test-session-id';
                }),
            );

        $logger = new ReplAuditLogger($auditLogger, 'test-session-id');
        $logger->logProductionOverride('admin');
    }

    #[Test]
    public function summarizeResultNull(): void
    {
        self::assertSame('null', ReplAuditLogger::summarizeResult(null));
    }

    #[Test]
    public function summarizeResultScalar(): void
    {
        self::assertSame('string(hello)', ReplAuditLogger::summarizeResult('hello'));
        self::assertSame('integer(42)', ReplAuditLogger::summarizeResult(42));
        self::assertSame('boolean(1)', ReplAuditLogger::summarizeResult(true));
    }

    #[Test]
    public function summarizeResultObject(): void
    {
        $result = ReplAuditLogger::summarizeResult(new stdClass());

        self::assertSame('object(stdClass)', $result);
    }

    #[Test]
    public function summarizeResultArray(): void
    {
        self::assertSame('array(3)', ReplAuditLogger::summarizeResult([1, 2, 3]));
    }

    #[Test]
    public function summarizeResultTruncatesLongStrings(): void
    {
        $longString = str_repeat('a', 300);
        $result = ReplAuditLogger::summarizeResult($longString);

        self::assertStringStartsWith('string(', $result);
        // The preview should be truncated to 200 chars
        self::assertLessThanOrEqual(210, strlen($result));
    }
}
